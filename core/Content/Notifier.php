<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Config;
use Portal\Db;
use Portal\Mail\MailProvider;
use Throwable;

/**
 * Telling subscribers about new content.
 *
 * # Why nothing calls this when a video is published
 *
 * There is no "publish" moment to hook. A video with a future date becomes
 * visible because a comparison in a query started returning true — no code
 * runs, by design, because a cron-driven schedule would publish late on a quiet
 * site. So the notifier does the same thing in reverse: it asks which videos
 * are visible now and have never been announced.
 *
 * # The fire-once guarantee
 *
 * {announced_videos} has a PRIMARY KEY on video_id, and every announcement
 * starts with INSERT IGNORE. Two overlapping cron runs, a job that dies
 * halfway, a host that fires the same request twice — none of them can produce
 * a second email, because the row either inserts or it does not. This is the
 * table Phase 1 created for exactly this and never used.
 *
 * # The first run
 *
 * On a site that has been up for a year, the first run would otherwise announce
 * the entire back catalogue. Migration 0007 forecloses that by marking every
 * existing video as announced at the moment of upgrade — the only moment the
 * answer is unambiguous.
 *
 * The catch-up window below is the second line of the same defence, for a site
 * whose cron was broken for a month: anything that became visible longer ago
 * than that is recorded as announced and quietly skipped, so fixing cron does
 * not send six weeks of email at once.
 */
final class Notifier
{
    /**
     * How far back a newly-visible video still counts as news.
     *
     * A site whose cron stopped for six weeks should not, on the day it is
     * fixed, send six weeks of announcements at once. Anything older than this
     * is recorded as announced and quietly skipped.
     */
    public const CATCH_UP_DAYS = 7;

    /** A ceiling per run, so one job cannot hold a shared host all afternoon. */
    public const MAX_PER_RUN = 25;

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly SubscriptionRepository $subscriptions,
        private readonly VideoRepository $videos,
        private readonly MailProvider $mail,
    ) {
    }

    /**
     * Announce anything new. Returns a line for the cron log.
     */
    public function run(): string
    {
        $pending = $this->unannounced();

        if ($pending === []) {
            return 'Nothing new to announce.';
        }

        // The catch-up rule, and the first-run rule, are the same rule.
        $cutoff = time() - (self::CATCH_UP_DAYS * 86400);

        $skipped = 0;
        $sent = 0;
        $failed = 0;

        foreach ($pending as $video) {
            $visibleSince = $this->visibleSince($video);

            /*
             * Claim the video BEFORE sending.
             *
             * If the send fails halfway through a list of recipients, the row
             * is already claimed and the rest do not get a second copy on the
             * next run. Losing an announcement is recoverable by a human;
             * sending it twice to everybody is not.
             */
            if (!$this->claim($video->id)) {
                continue;
            }

            if ($visibleSince < $cutoff) {
                $skipped++;
                continue;
            }

            $recipients = $this->subscriptions->recipientsFor($video);
            if ($recipients === []) {
                continue;
            }

            foreach ($recipients as $recipient) {
                if ($this->tell($recipient['email'], $recipient['token'], $video)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }

        $parts = [];
        if ($sent > 0) {
            $parts[] = "{$sent} email(s) sent";
        }
        if ($failed > 0) {
            $parts[] = "{$failed} failed";
        }
        if ($skipped > 0) {
            $parts[] = "{$skipped} older video(s) marked without sending";
        }

        return $parts === [] ? 'Nothing to announce.' : implode(', ', $parts) . '.';
    }

    // ------------------------------------------------------------- internals

    /**
     * Videos that are visible and have never been announced.
     *
     * The visibility conditions are the public ones, deliberately: an
     * announcement is a public statement, so a members-only video is not news
     * to a mailing list that anybody can join.
     *
     * @return list<Video>
     */
    private function unannounced(): array
    {
        $rows = $this->db->all(
            "SELECT v.* FROM {videos} v
               LEFT JOIN {announced_videos} a ON a.video_id = v.id
              WHERE a.video_id IS NULL
                AND v.deleted_at IS NULL
                AND v.status = 'ready'
                AND v.is_published = 1
                AND v.hidden = 0
                AND v.member_only = 0
                AND (v.published_at IS NULL OR v.published_at <= NOW())
                AND (v.unpublish_at IS NULL OR v.unpublish_at > NOW())
              ORDER BY COALESCE(v.published_at, v.recorded_at, v.id) ASC
              LIMIT " . self::MAX_PER_RUN
        );

        return array_map(static fn (array $row): Video => Video::fromRow($row), $rows);
    }

    /**
     * Claim a video for announcement, exactly once.
     *
     * The whole concurrency story in one statement: INSERT IGNORE against a
     * primary key. No read, no lock, no window between deciding and doing.
     *
     * Public because the return value is the guarantee, and it is invisible
     * from run() — a single-threaded pass never sees a claim fail, since
     * unannounced() has already filtered out anything claimed. The only way to
     * pin the property is to call this twice, which is what its test does. Two
     * cron runs overlapping on a busy host is not hypothetical: pseudo-cron
     * fires from ordinary requests, and two of those can arrive together.
     */
    public function claim(int $videoId): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO {announced_videos} (video_id, announced_at) VALUES (?, NOW())',
            [$videoId]
        ) > 0;
    }

    private function visibleSince(Video $video): int
    {
        foreach ([$video->publishedAt, $video->recordedAt, $video->providerCreatedAt] as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            try {
                return (new \DateTimeImmutable($candidate))->getTimestamp();
            } catch (Throwable) {
                continue;
            }
        }

        // No date at all: treat it as new rather than as ancient. A video with
        // nothing to date it by has almost certainly just arrived.
        return time();
    }

    private function tell(string $email, string $token, Video $video): bool
    {
        $site = (string) ($this->config->setting('site_name', 'Video Portal') ?? 'Video Portal');
        $url = $this->config->url($video->url());
        $unsubscribe = $this->config->url('/unsubscribe/' . $token);

        $title = e($video->title);
        $description = $video->description === null || $video->description === ''
            ? ''
            : '<p style="margin:0 0 16px;color:#475569">'
                . nl2br(e(\Portal\Support\Str::truncate($video->description, 400))) . '</p>';

        $html = <<<HTML
        <div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:34rem;margin:0 auto">
          <p style="margin:0 0 8px;color:#64748b;font-size:14px">New on {$site}</p>
          <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3">{$title}</h1>
          {$description}
          <p style="margin:0 0 24px">
            <a href="{$url}"
               style="background:#0284c7;color:#fff;padding:10px 18px;border-radius:8px;
                      text-decoration:none;display:inline-block">Watch it</a>
          </p>
          <p style="margin:0;color:#94a3b8;font-size:12px">
            You are getting this because you subscribed to updates from {$site}.
            <a href="{$unsubscribe}" style="color:#94a3b8">Unsubscribe</a>.
          </p>
        </div>
        HTML;

        $text = sprintf(
            "New on %s\n\n%s\n\n%s\n\nUnsubscribe: %s\n",
            $site,
            $video->title,
            $url,
            $unsubscribe
        );

        try {
            $result = $this->mail->send(
                $email,
                sprintf('%s — %s', $site, $video->title),
                $html,
                $text,
                /*
                 * List-Unsubscribe, so a mail client can offer a one-click
                 * button. Without it people use the spam button instead, which
                 * costs the whole site's deliverability rather than one
                 * subscription.
                 */
                ['headers' => [
                    'List-Unsubscribe' => '<' . $unsubscribe . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ]]
            );

            if ($result->sent) {
                $this->subscriptions->markSent($email);
            }

            return $result->sent;
        } catch (Throwable $e) {
            error_log('Could not announce a video: ' . $e->getMessage());

            return false;
        }
    }
}
