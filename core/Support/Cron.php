<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\App;
use Portal\Auth\Session;
use Portal\Content\VideoRepository;
use Portal\Db;
use Portal\Plugins\PluginManager;
use Portal\Video\VideoProvider;
use Throwable;

/**
 * Scheduled work on a host with no worker process.
 *
 * Two ways in, one runner:
 *
 *   Pseudo-cron  — a small fraction of ordinary requests check for due jobs
 *                  after the response has been sent. Zero setup, works on
 *                  every host, but only runs when someone visits.
 *   Real cron    — GET /cron?key=... from the host's scheduler. Same jobs, no
 *                  sampling, runs on a quiet site too.
 *
 * The lock is the interesting part. A shared host can kill a PHP process
 * mid-request at any moment, so a naive "set running=1" flag would eventually
 * be left set forever by a job that died, silently stopping the schedule with
 * no error anywhere. Locks therefore carry a timestamp and expire.
 */
final class Cron
{
    /** Roughly 1 request in 20 checks the schedule. */
    private const SAMPLE_RATE = 20;

    /** A lock older than this is assumed to belong to a dead process. */
    private const LOCK_TIMEOUT = 300;

    /** @var array<string, callable(App):string> */
    private array $handlers = [];

    public function __construct(
        private readonly Db $db,
        private readonly App $app,
    ) {
        $this->registerCoreJobs();
    }

    private function registerCoreJobs(): void
    {
        $this->handlers['sessions.purge'] = static function (App $app): string {
            $removed = (new Session(Db::instance()))->purgeExpired();
            return "Removed {$removed} expired session(s).";
        };

        $this->handlers['videos.sync'] = static function (App $app): string {
            $provider = $app->container()->get(VideoProvider::class);
            $repository = $app->container()->get(VideoRepository::class);

            // Bounded: a very large library must not turn one page view into a
            // multi-minute request that the host kills halfway through.
            $page = $provider->listVideos(1, 100);
            $result = $repository->syncFromProvider($page->items);

            return sprintf(
                '%d new, %d updated, %d missing.',
                $result['created'],
                $result['updated'],
                $result['missing']
            );
        };

        $this->handlers['shares.cleanup'] = static function (App $app): string {
            // Expired-but-not-revoked links are kept for a grace period so
            // Extend and Restore still work on them. Only rows past that
            // window are removed.
            $removed = Db::instance()->execute(
                'DELETE FROM {shares}
                  WHERE revoked_at IS NOT NULL
                    AND revoked_at < DATE_SUB(NOW(), INTERVAL 60 DAY)'
            );

            $gates = Db::instance()->execute(
                'DELETE FROM {gate_grants} WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );

            $limits = Db::instance()->execute(
                'DELETE FROM {rate_limits} WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );

            return "Removed {$removed} share(s), {$gates} expired link grant(s), {$limits} rate-limit row(s).";
        };

        /*
         * Announce anything that has become visible since the last run.
         *
         * There is no publish event to hook: a scheduled video appears because
         * a comparison in a query started returning true, with no code running.
         * So this asks the question in reverse — what is visible now that has
         * never been announced — and the fire-once guarantee comes from an
         * INSERT IGNORE against a primary key rather than from this job being
         * run exactly once.
         */
        $this->handlers['notifications.send'] = static function (App $app): string {
            $config = $app->container()->get(\Portal\Config::class);

            if (!$config->settingBool('subscriptions_enabled', true)) {
                return 'Subscriptions are switched off.';
            }

            $notifier = $app->container()->get(\Portal\Content\Notifier::class);

            $pruned = $app->container()
                ->get(\Portal\Content\SubscriptionRepository::class)
                ->pruneOrphans();

            $result = $notifier->run();

            return $pruned > 0
                ? $result . " Removed {$pruned} subscription(s) whose target had been deleted."
                : $result;
        };

        /*
         * Send the webhook queue, and notice anything newly published.
         *
         * Both halves are here rather than in two jobs because they are one
         * question — "what has happened that somebody should be told about" —
         * and splitting them would mean a publish detected by one job waiting
         * for the other job's schedule before it went anywhere.
         *
         * Publishing is asked in reverse, exactly as the announcement job asks
         * it: a scheduled video becomes visible when a comparison starts
         * returning true, and no code runs at that moment to hook.
         */
        $this->handlers['webhooks.deliver'] = static function (App $app): string {
            $webhooks = $app->container()->get(\Portal\Content\WebhookRepository::class);

            $announced = 0;
            foreach ($webhooks->unreportedPublishedVideos() as $video) {
                // The claim comes BEFORE the enqueue. Losing a notification is
                // recoverable by a person; sending the same one repeatedly to
                // somebody's integration is not.
                if (!$webhooks->claimVideo((int) $video['id'])) {
                    continue;
                }

                $webhooks->enqueue('video.published', [
                    'id'          => (int) $video['id'],
                    'slug'        => (string) $video['slug'],
                    'title'       => (string) $video['title'],
                    'publishedAt' => $video['published_at'],
                ]);

                $announced++;
            }

            $result = $app->container()->get(\Portal\Content\WebhookDispatcher::class)->run();

            return sprintf(
                '%d newly published, %d delivered, %d failed%s.',
                $announced,
                $result['sent'],
                $result['failed'],
                $result['disabled'] > 0
                    ? sprintf(', %d endpoint(s) switched off', $result['disabled'])
                    : ''
            );
        };

        /*
         * Read descriptions nobody has read yet for scripture references.
         *
         * A job rather than a one-off, because parsing happens in PHP and a
         * library of a thousand sermons cannot be worked through inside the
         * request that upgrades the schema. Batched, and it stops on its own
         * once every video carries a scanned-at stamp — including the ones that
         * turned out to mention no passage at all, which is why the stamp is a
         * column and not the presence of a reference.
         */
        $this->handlers['scripture.scan'] = static function (App $app): string {
            $scripture = $app->container()->get(\Portal\Content\ScriptureRepository::class);

            $videos = $scripture->unscanned();

            if ($videos === []) {
                return 'Every description has been read.';
            }

            $found = 0;
            foreach ($videos as $video) {
                $references = \Portal\Content\ScriptureParser::parse($video['description']);

                if ($references !== []) {
                    $found += $scripture->replace($video['id'], $references, 'parsed');
                }

                // Stamped whether or not anything was found, or a library full
                // of sermons that mention no passage would be re-read forever.
                $scripture->markScanned($video['id']);
            }

            return sprintf('Read %d description(s), found %d reference(s).', count($videos), $found);
        };

        $this->handlers['webhooks.cleanup'] = static function (App $app): string {
            $removed = $app->container()
                ->get(\Portal\Content\WebhookRepository::class)
                ->prune();

            return "Removed {$removed} old delivery record(s).";
        };
    }

    /**
     * Make sure every core job has a row.
     *
     * Core job rows have only ever been written by the INSTALLER, so a site
     * installed before a job existed never gets one — and a job with no row is
     * never due, so it silently does nothing forever. That is what happened to
     * notifications.send: it shipped in Phase 4, every install created before
     * then has no row for it, and subscriptions on those sites have been
     * quietly sending nothing since.
     *
     * Called after migrations apply, which is the only moment the deployed
     * code is known to have changed — doing it per request would be a write on
     * every page load to answer a question whose answer almost never changes.
     *
     * INSERT IGNORE, so a job an administrator deliberately disabled is left
     * disabled rather than being switched back on by an upgrade.
     */
    public function ensureCoreJobs(): void
    {
        foreach ([
            'sessions.purge'     => 3600,
            'videos.sync'        => 900,
            'shares.cleanup'     => 86400,
            'notifications.send' => 900,
            'webhooks.deliver'   => 60,
            'webhooks.cleanup'   => 86400,
            'scripture.scan'     => 300,
        ] as $slug => $interval) {
            $this->db->execute(
                'INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
                 VALUES (?, ?, NOW(), 1)',
                [$slug, $interval]
            );
        }
    }

    /**
     * Called after every response. Cheap in the common case: a random check
     * and, occasionally, one indexed query.
     */
    public function tick(): void
    {
        if (random_int(1, self::SAMPLE_RATE) !== 1) {
            return;
        }

        try {
            $this->runDue();
        } catch (Throwable $e) {
            error_log('Portal: pseudo-cron failed: ' . $e->getMessage());
        }
    }

    /**
     * Run every job that is due.
     *
     * @return list<array{slug: string, status: string, message: string}>
     */
    public function runDue(): array
    {
        $due = $this->db->all(
            'SELECT slug FROM {cron_jobs}
              WHERE is_enabled = 1
                AND next_run_at <= NOW()
                AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
              ORDER BY next_run_at
              LIMIT 5',
            [self::LOCK_TIMEOUT]
        );

        $results = [];
        foreach ($due as $row) {
            $results[] = $this->run((string) $row['slug']);
        }

        return $results;
    }

    /**
     * Run one job, if we can claim it.
     *
     * @return array{slug: string, status: string, message: string}
     */
    public function run(string $slug): array
    {
        if (!$this->claim($slug)) {
            return ['slug' => $slug, 'status' => 'skipped', 'message' => 'Already running elsewhere.'];
        }

        $handler = $this->handlers[$slug] ?? $this->pluginHandler($slug);

        if ($handler === null) {
            // A job row with no handler — usually a plugin that was
            // deactivated. Disable it rather than retrying forever.
            $this->db->execute(
                'UPDATE {cron_jobs}
                    SET is_enabled = 0, locked_at = NULL, last_status = ?, last_message = ?
                  WHERE slug = ?',
                ['error', 'No handler is registered for this job.', $slug]
            );
            return ['slug' => $slug, 'status' => 'error', 'message' => 'No handler registered.'];
        }

        try {
            $message = $handler($this->app);
            $status = 'ok';
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $status = 'error';
            error_log("Portal: cron job '{$slug}' failed: " . $message);
        }

        $this->release($slug, $status, $message);

        return ['slug' => $slug, 'status' => $status, 'message' => $message];
    }

    /**
     * Claim a job atomically.
     *
     * The WHERE clause is the lock: only one caller can match a row whose
     * locked_at is null or stale, so a concurrent request loses the race and
     * moves on rather than running the job twice.
     */
    private function claim(string $slug): bool
    {
        $claimed = $this->db->execute(
            'UPDATE {cron_jobs}
                SET locked_at = NOW()
              WHERE slug = ?
                AND is_enabled = 1
                AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND))',
            [$slug, self::LOCK_TIMEOUT]
        );

        return $claimed === 1;
    }

    private function release(string $slug, string $status, string $message): void
    {
        $this->db->execute(
            'UPDATE {cron_jobs}
                SET locked_at = NULL,
                    last_run_at = NOW(),
                    last_status = ?,
                    last_message = ?,
                    next_run_at = DATE_ADD(NOW(), INTERVAL interval_seconds SECOND)
              WHERE slug = ?',
            [$status, mb_substr($message, 0, 500), $slug]
        );
    }

    /** @return (callable(App):string)|null */
    private function pluginHandler(string $slug): ?callable
    {
        try {
            /** @var PluginManager $plugins */
            $plugins = $this->app->container()->get(PluginManager::class);
            $job = $plugins->cronJobs()[$slug] ?? null;

            if ($job === null) {
                return null;
            }

            $handler = $job['handler'];
            return static fn (App $app): string => (string) $handler($app);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    public function jobs(): array
    {
        return $this->db->all('SELECT * FROM {cron_jobs} ORDER BY slug');
    }

    /**
     * Register a job row for a plugin-declared schedule.
     */
    public function ensureJob(string $slug, int $intervalSeconds): void
    {
        $this->db->execute(
            'INSERT INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
             VALUES (?, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE interval_seconds = VALUES(interval_seconds), is_enabled = 1',
            [$slug, max(60, $intervalSeconds)]
        );
    }
}
