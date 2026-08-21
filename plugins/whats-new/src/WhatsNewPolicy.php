<?php

declare(strict_types=1);

namespace Portal\Plugins\WhatsNew;

/**
 * What counts as a visit, and what counts as new.
 *
 * Every decision worth arguing about is here, where it can be tested without a
 * database and without a clock: the tracker below does the reading and writing
 * and asks this class what any of it means.
 */
final class WhatsNewPolicy
{
    /**
     * A gap this long means they went away and came back.
     *
     * Thirty minutes, matching the convention every analytics tool uses for a
     * session. The number is a compromise in one direction only: too short and
     * the marker rolls while somebody is still reading, clearing badges they
     * have not looked at yet; too long and a second visit the same afternoon is
     * treated as a continuation, which costs nothing but a few badges.
     *
     * So it errs long.
     */
    public const SESSION_GAP = 1800;

    /**
     * How stale the "still here" stamp may get before it is rewritten.
     *
     * This is a write on a page render, so it is throttled rather than done
     * every time. A minute is far below SESSION_GAP, so the stamp can never
     * drift far enough to make a continuing visit look like a new one.
     */
    public const TOUCH_INTERVAL = 60;

    public const DEFAULT_HORIZON_DAYS = 30;
    public const MAX_HORIZON_DAYS = 365;

    public const DEFAULT_LABEL = 'New';
    public const LABEL_MAX = 24;

    /**
     * Are we looking at somebody who has come back?
     *
     * An unreadable stamp answers yes. The roll it triggers copies the
     * unreadable value into the marker, cutoff() then refuses to badge
     * anything, and the next visit starts from a stamp this class wrote — so
     * the wrong answer costs one visit's badges and then repairs itself.
     * Answering no would leave the row stuck forever.
     */
    public static function isReturning(?string $seenAt, int $now): bool
    {
        if ($seenAt === null || $seenAt === '') {
            return false;
        }

        $stamp = strtotime($seenAt);

        return $stamp === false || ($now - $stamp) >= self::SESSION_GAP;
    }

    /** Is the "still here" stamp stale enough to be worth a write? */
    public static function shouldTouch(?string $seenAt, int $now): bool
    {
        if ($seenAt === null || $seenAt === '') {
            return false;
        }

        $stamp = strtotime($seenAt);

        return $stamp !== false && ($now - $stamp) >= self::TOUCH_INTERVAL;
    }

    /**
     * The moment to compare publication dates against, or null to badge nothing.
     *
     * The horizon is the part that is easy to leave out and is the whole
     * difference between a useful marker and a useless one. Somebody who last
     * signed in eighteen months ago has a perfectly valid marker, and honouring
     * it literally badges every video published since — which is the entire
     * library, on every card, saying nothing.
     *
     * So the marker can only ever narrow the window, never widen it past the
     * horizon. The cost is stated on the settings screen: come back after a
     * long absence and you are told what is new this month, not what you
     * missed.
     *
     * A marker in the future — a host whose clock has been corrected backwards,
     * which is a normal event on shared hosting — badges nothing rather than
     * everything. Quiet is the right failure for a decoration.
     */
    public static function cutoff(?string $markerAt, int $now, int $horizonDays): ?string
    {
        if ($markerAt === null || $markerAt === '') {
            return null;
        }

        $marker = strtotime($markerAt);
        if ($marker === false) {
            return null;
        }

        $floor = $now - (self::horizon($horizonDays) * 86400);

        return date('Y-m-d H:i:s', max($marker, $floor));
    }

    /**
     * Clamped rather than refused: this comes from a number field, and 5000
     * should become "a long time" rather than an error page.
     *
     * Zero is not kept. Unlike the up-next countdown, where zero means
     * "never play by itself" and is a setting somebody wants, a zero-day
     * horizon means "badge nothing", which is what deactivating the plugin is
     * for — and it would look like the feature was broken.
     */
    public static function horizon(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return self::DEFAULT_HORIZON_DAYS;
        }

        return max(1, min(self::MAX_HORIZON_DAYS, (int) $raw));
    }

    /** The word on the badge. Empty falls back rather than rendering a blank pill. */
    public static function label(mixed $raw): string
    {
        $label = trim((string) (is_scalar($raw) ? $raw : ''));

        return $label === '' ? self::DEFAULT_LABEL : mb_substr($label, 0, self::LABEL_MAX);
    }
}
