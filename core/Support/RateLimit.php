<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Db;
use Throwable;

/**
 * Fixed-window rate limiting.
 *
 * Coarser than a sliding window — a caller can burst at a window boundary —
 * but it needs no background sweeper and no sorted-set store, neither of which
 * shared hosting reliably provides. For "stop someone brute-forcing a password"
 * the difference does not matter.
 *
 * Fails OPEN. If the counter table is unreachable, requests are allowed. This
 * is the opposite of the access checks, and deliberately so: rate limiting is
 * infrastructure protection, and having it break should not lock every user out
 * of a working site.
 */
final class RateLimit
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Consume one unit from a bucket.
     *
     * @param string $bucket          identifies what is being limited
     * @param int    $limit           attempts allowed per window
     * @param int    $windowSeconds   window length
     */
    public function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        // Hashed so a bucket built from an email address does not store that
        // address in a table nobody thinks of as holding personal data.
        $key = hash('sha256', $bucket);
        $windowStart = $this->windowStart($windowSeconds);

        try {
            // One statement, so two concurrent requests cannot both read a
            // count of 0 and both proceed. The window comparison inside the
            // UPDATE clause is what resets the counter for a new window.
            $this->db->execute(
                'INSERT INTO {rate_limits} (bucket, window_start, hits)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    hits = IF(window_start = VALUES(window_start), hits + 1, 1),
                    window_start = VALUES(window_start)',
                [$key, $windowStart]
            );

            $hits = (int) $this->db->value(
                'SELECT hits FROM {rate_limits} WHERE bucket = ?',
                [$key]
            );
        } catch (Throwable $e) {
            error_log('Portal: rate limiting unavailable, allowing request: ' . $e->getMessage());
            return true;
        }

        return $hits <= $limit;
    }

    /*
     * There was a remaining() here, "for a message that says something
     * useful". It never had a caller, and on inspection it should not get one.
     *
     * Every bucket in this application throttles something where telling the
     * actor how close they are helps an attacker more than a user:
     *
     *  - sign-in: paces a password guesser to just under the limit, and the
     *    count differing between a known and an unknown address is an
     *    enumeration oracle on its own.
     *  - the share gate: every failure response is byte-identical by design,
     *    which a remaining-attempts number would end.
     *  - registration: same enumeration problem.
     *
     * A person who has genuinely mistyped their password twice is not helped by
     * arithmetic; they are helped by being told when they can try again, which
     * the existing message already does.
     *
     * Written down rather than deleted silently, because "how many tries are
     * left" reads like an obvious missing courtesy and would otherwise be
     * rebuilt by the next person to look at this class.
     */

    /** Clear a bucket — called after a successful sign-in. */
    public function clear(string $bucket): void
    {
        try {
            $this->db->execute('DELETE FROM {rate_limits} WHERE bucket = ?', [hash('sha256', $bucket)]);
        } catch (Throwable) {
            // Not worth surfacing.
        }
    }

    /**
     * Round down to the current window boundary, so every request inside the
     * same window computes an identical value.
     */
    private function windowStart(int $windowSeconds): string
    {
        $windowSeconds = max(1, $windowSeconds);
        return date('Y-m-d H:i:s', intdiv(time(), $windowSeconds) * $windowSeconds);
    }
}
