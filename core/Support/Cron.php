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

    /**
     * Lapsed share links removed per run.
     *
     * Without real cron this runs inside somebody's page view, so the cost has
     * to be bounded the way videos.sync is. The job runs daily, so a site that
     * has never been cleaned drains its backlog over a few days rather than
     * making one visitor wait for all of it.
     */
    private const SHARES_PER_RUN = 500;

    /**
     * How far videos.sync will page before giving up.
     *
     * 20 x 100 = 2,000 videos, which covers this product's scale with room to
     * spare. The cap exists because pseudo-cron runs inside a visitor's page
     * view: an unbounded loop over a huge library is a request the host kills,
     * and a killed request is exactly how you get a partial list.
     *
     * Hitting it is not silent. The missing-video check is skipped and the job
     * message says so, because "we did not look" and "nothing was missing" are
     * different answers and only one of them is safe to act on.
     */
    private const SYNC_MAX_PAGES = 20;
    private const SYNC_PER_PAGE  = 100;

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

            /*
             * Pages through, rather than reading page one and calling it the
             * library.
             *
             * This used to fetch a single page of 100 and hand it straight to
             * syncFromProvider(), which marks everything ABSENT from the list
             * as failed. So on any library over a hundred videos, every run
             * condemned the tail of it — silently, on a schedule, with nothing
             * in the audit log. It survived only because nothing here has run
             * against a library that big.
             *
             * Still bounded. An unbounded loop is how one page view becomes a
             * multi-minute request that a shared host kills halfway through,
             * and being killed halfway is what produces a partial list in the
             * first place.
             */
            $items = [];
            $complete = false;
            $pagesRead = 0;

            for ($n = 1; $n <= self::SYNC_MAX_PAGES; $n++) {
                $page = $provider->listVideos($n, self::SYNC_PER_PAGE);
                $pagesRead++;

                foreach ($page->items as $item) {
                    $items[] = $item;
                }

                // The provider itself says whether anything is left. Inferring
                // it from a short page would be wrong the moment a page comes
                // back short for any other reason.
                if (!$page->hasMore()) {
                    $complete = true;
                    break;
                }
            }

            $result = $repository->syncFromProvider($items, 'bunny', $complete);

            /*
             * The cap being hit is reported, not swallowed. It means the
             * missing-video check did not run, which an admin looking at a
             * video that ought to have disappeared needs to know.
             */
            return sprintf(
                '%d new, %d updated, %d missing%s.',
                $result['created'],
                $result['updated'],
                $result['missing'],
                $complete
                    ? ''
                    : sprintf(
                        ' — stopped after %d pages, so videos removed at the provider were not detected',
                        $pagesRead
                    )
            );
        };

        $this->handlers['shares.cleanup'] = static function (App $app): string {
            /*
             * Through the repository, which is the one place that knows what
             * "past the grace period" means.
             *
             * This used to inline its own DELETE, and the two had drifted: the
             * repository removes anything sixty days past expiry OR revocation,
             * while the statement here only ever touched revoked rows. So a
             * link that simply lapsed was never cleaned up by the scheduled
             * job — and the comment sitting above that statement described the
             * repository's rule rather than its own.
             *
             * An admin pressing "clean up" on the sharing screen calls the
             * correct one, which is why this was survivable; but the entire
             * point of a scheduled job is that nobody has to press anything.
             */
            $removed = $app->container()
                ->get(\Portal\Sharing\ShareRepository::class)
                ->purgeExpired(self::SHARES_PER_RUN);

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
        // Before selecting what is due, make sure everything that COULD be due
        // has a row. See ensurePluginJobs().
        $this->ensurePluginJobs();

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
     * Give every plugin-declared schedule a row, so it can become due.
     *
     * `PluginContext::addCronJob()` has existed since Phase 1 and put the
     * handler in a map on PluginManager. Nothing ever wrote a `{cron_jobs}`
     * row for it, and runDue() selects FROM that table — so a plugin's
     * scheduled job was registered, resolvable by slug, fully tested, and
     * never once due. No plugin cron job in this product has ever run.
     *
     * This is the same defect that shipped for `notifications.send` in Phase 4,
     * recorded then as "a job with no row is never due, so it does nothing,
     * silently, forever". `ensureCoreJobs()` fixed it for core jobs and the
     * plugin half was missed, which is what an uncalled `ensureJob()` sitting
     * beside it was evidence of.
     *
     * Called from the runner rather than from activation, because activation
     * already happened for every plugin currently installed — fixing it at
     * activation would leave exactly the sites that have the bug still having
     * it until somebody deactivated and reactivated.
     */
    private function ensurePluginJobs(): void
    {
        try {
            /** @var PluginManager $plugins */
            $plugins = $this->app->container()->get(PluginManager::class);

            foreach ($plugins->cronJobs() as $slug => $job) {
                $this->ensureJob($slug, (int) $job['interval']);
            }
        } catch (Throwable $e) {
            // Fails quiet and does not stop the core jobs from running. A
            // plugin that cannot be read is not a reason to stop purging
            // sessions.
            error_log('Portal: could not register plugin cron jobs: ' . $e->getMessage());
        }
    }

    /**
     * Register a job row for a plugin-declared schedule.
     *
     * INSERT IGNORE, not ON DUPLICATE KEY UPDATE. This runs on every cron tick,
     * and re-asserting `is_enabled = 1` would silently switch back on any job an
     * admin had deliberately turned off — every few minutes, with the Cron
     * screen showing it enabled and no clue as to who kept doing it.
     *
     * The cost is that changing a plugin's declared interval does not move an
     * existing row. That is the right way round: the admin's stored schedule
     * outranks the plugin's suggestion, and the suggestion only ever applied at
     * the moment the row was created anyway.
     */
    public function ensureJob(string $slug, int $intervalSeconds): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
             VALUES (?, ?, NOW(), 1)',
            [$slug, max(60, $intervalSeconds)]
        );
    }
}
