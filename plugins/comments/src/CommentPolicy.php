<?php

declare(strict_types=1);

namespace Portal\Plugins\Comments;

/**
 * What a comment is allowed to be, and whether it goes straight up.
 *
 * Pure: no database, no request. This is the part that decides whether a site
 * with no full-time moderator drowns, so it is worth being able to test
 * exhaustively rather than approximately.
 */
final class CommentPolicy
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SPAM     = 'spam';
    public const STATUS_REMOVED  = 'removed';

    /** Long enough for a considered reply, short enough not to be an essay. */
    public const MAX_LENGTH = 3000;
    public const MIN_LENGTH = 2;

    /** Moderation modes, in the order the settings screen offers them. */
    public const MODERATE_NEWCOMERS = 'newcomers';
    public const MODERATE_ALL       = 'all';
    public const MODERATE_NONE      = 'none';

    /**
     * Where a new comment lands.
     *
     * The default — hold newcomers, let established voices through — is the one
     * setting that makes unattended comments survivable. Holding everything
     * means a queue nobody empties, and a queue nobody empties means comments
     * that never appear, which is indistinguishable from the feature being
     * broken. Holding nothing means the first spam run is public.
     *
     * @param int $approvedBefore how many approved comments this author already has
     */
    public static function initialStatus(
        string $mode,
        int $approvedBefore,
        string $body
    ): string {
        // Obvious spam is held whatever the mode says, including "none". An
        // admin turning moderation off is saying they trust their audience, not
        // that they want link farms published unread.
        if (self::looksLikeSpam($body)) {
            return self::STATUS_PENDING;
        }

        return match ($mode) {
            self::MODERATE_NONE      => self::STATUS_APPROVED,
            self::MODERATE_ALL       => self::STATUS_PENDING,
            // MODERATE_NEWCOMERS, and anything unrecognised: the safe default.
            default => $approvedBefore > 0 ? self::STATUS_APPROVED : self::STATUS_PENDING,
        };
    }

    /**
     * A deliberately crude heuristic, and only ever used to HOLD rather than to
     * reject.
     *
     * Anything cleverer is an arms race this project has no business entering,
     * and the cost of a false positive here is that a real comment waits for a
     * human — which is the same thing that happens to every newcomer anyway.
     */
    public static function looksLikeSpam(string $body): bool
    {
        $links = preg_match_all('#https?://#i', $body);

        // Two links is a person citing something. Four is an advertisement.
        if ($links >= 4) {
            return true;
        }

        // A wall with no spaces is generated, not typed.
        if (preg_match('/\S{80,}/', $body) === 1) {
            return true;
        }

        // BBCode and raw anchors have no business here; the renderer escapes
        // everything, so their presence means someone is probing the field
        // rather than talking.
        return preg_match('/\[url[=\]]|<a\s+href/i', $body) === 1;
    }

    /**
     * Clean up what was typed, or explain why it cannot be posted.
     *
     * @return array{ok: bool, body?: string, error?: string}
     */
    public static function normalize(string $raw): array
    {
        // Normalise line endings first so the length check counts what a person
        // would count, and collapse runs of blank lines that are usually an
        // accident of pasting.
        $body = str_replace(["\r\n", "\r"], "\n", $raw);
        $body = (string) preg_replace('/\n{3,}/', "\n\n", $body);
        $body = trim($body);

        if (mb_strlen($body) < self::MIN_LENGTH) {
            return ['ok' => false, 'error' => 'Write something first.'];
        }

        if (mb_strlen($body) > self::MAX_LENGTH) {
            return ['ok' => false, 'error' => sprintf(
                'That is %d characters; the limit is %d.',
                mb_strlen($body),
                self::MAX_LENGTH
            )];
        }

        // Control characters other than newline and tab: invisible, and usually
        // an attempt to confuse whatever reads this next.
        $body = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);

        return ['ok' => true, 'body' => $body];
    }

    /**
     * Is this comment visible to an ordinary reader?
     *
     * A removed comment is still shown — as a tombstone — when it has replies,
     * because hiding it entirely would leave answers to a question nobody can
     * see. With no replies there is nothing to preserve and it simply goes.
     */
    public static function isVisible(string $status, int $replyCount): bool
    {
        return $status === self::STATUS_APPROVED
            || ($status === self::STATUS_REMOVED && $replyCount > 0);
    }

    /** @return list<string> the statuses a moderator may set */
    public static function moderatorStatuses(): array
    {
        return [self::STATUS_APPROVED, self::STATUS_SPAM, self::STATUS_REMOVED];
    }
}
