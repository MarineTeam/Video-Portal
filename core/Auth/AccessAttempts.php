<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Support\Str;
use Throwable;

/**
 * Everyone the door was shut on.
 *
 * The audit log records what accounts DID. Somebody refused at sign-in has no
 * account, so nothing anywhere says they tried — and the administrator finds
 * out only if that person can reach them some other way. On a site whose whole
 * membership model is "an administrator approves you", a refusal nobody can see
 * is the failure mode: the person concludes the site is broken and the
 * administrator concludes nobody wanted in.
 *
 * NO CREDENTIAL IS EVER STORED HERE. Only the address that was offered, which
 * is the thing an administrator needs in order to act — and which they were
 * given by the person, who typed it into a form on this site.
 */
final class AccessAttempts
{
    /** Kept three months. Long enough to notice a pattern, short enough not to
     * become a permanent register of everyone who ever mistyped an address. */
    public const RETAIN_DAYS = 90;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Record a refusal.
     *
     * Swallows its own errors, which inverts this project's usual rule and is
     * deliberate. It is a note about something that has already been decided:
     * letting it throw would turn a refusal — already the correct outcome —
     * into a 500, and would mean a full disk or a missing table presenting as
     * the sign-in being broken rather than as the sign-in being refused.
     */
    public function record(string $email, string $reason, ?string $provider = null, ?string $ip = null): void
    {
        try {
            $this->db->insert('access_attempts', [
                'email'      => mb_substr(Str::normalizeEmail($email), 0, 191),
                'reason'     => mb_substr($reason, 0, 32),
                'provider'   => $provider !== null ? mb_substr($provider, 0, 64) : null,
                'ip'         => $ip !== null ? mb_substr($ip, 0, 45) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('Could not record a refused sign-in: ' . $e->getMessage());
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, pages: int}
     */
    public function page(string $search = '', string $reason = '', int $page = 1, int $perPage = 50): array
    {
        $where = '1=1';
        $args = [];

        if (trim($search) !== '') {
            $where .= ' AND email LIKE ?';
            $args[] = '%' . $this->db->escapeLike(trim($search)) . '%';
        }

        if ($reason !== '') {
            $where .= ' AND reason = ?';
            $args[] = $reason;
        }

        $total = (int) $this->db->value("SELECT COUNT(*) FROM {access_attempts} WHERE {$where}", $args);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = $this->db->all(
            "SELECT * FROM {access_attempts} WHERE {$where}
              ORDER BY created_at DESC, id DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $args
        );

        return [
            'items' => $rows,
            'total' => $total,
            'pages' => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** How many refusals nobody has looked at, for the badge on the screen. */
    public function unreviewedCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM {access_attempts} WHERE reviewed_at IS NULL');
    }

    /**
     * Mark everything currently refused as dealt with.
     *
     * Bounded by a timestamp rather than clearing the whole table, so an
     * attempt arriving while the administrator was reading the page is not
     * marked reviewed by a click that happened before it.
     */
    public function markReviewed(string $upTo): int
    {
        return $this->db->execute(
            'UPDATE {access_attempts} SET reviewed_at = NOW()
              WHERE reviewed_at IS NULL AND created_at <= ?',
            [$upTo]
        );
    }

    /** Drop anything past the retention window. Run by cron. */
    public function prune(): int
    {
        return $this->db->execute(
            'DELETE FROM {access_attempts} WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [self::RETAIN_DAYS]
        );
    }
}
