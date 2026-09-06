<?php

declare(strict_types=1);

namespace Portal\Prayer;

/**
 * A prayer request, as anything that renders one may see it.
 *
 * # WHAT IS NOT ON THIS TYPE IS THE POINT
 *
 * No user id. No raw requester name. No account, no email, nothing that could
 * identify the person behind an anonymous request — so a template that forgets
 * to check `is_anonymous` has nothing to leak, and a new screen written in a
 * hurry cannot reintroduce the bug by accident.
 *
 * The moderation queue receives THIS TYPE TOO. That is the whole of the rule:
 * "anonymous except to moderators" is the version people assume they are
 * getting and are not.
 *
 * `name` comes from PrayerName and nowhere else.
 */
final class PrayerRequest
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $body,
        public readonly string $visibility,
        public readonly string $status,
        public readonly bool $isAnonymous,
        public readonly ?string $answerNote,
        public readonly ?string $answeredAt,
        public readonly int $prayedCount,
        public readonly string $createdAt,
        public readonly ?string $approvedBy,
    ) {
    }

    /**
     * @param array<string, mixed> $row a {prayer_requests} row
     */
    public static function from(array $row): self
    {
        return new self(
            (int) $row['id'],
            // The one function. Never $row['requester_name'] directly, here or
            // anywhere else.
            PrayerName::for($row),
            (string) $row['body'],
            (string) $row['visibility'],
            (string) $row['status'],
            (bool) $row['is_anonymous'],
            $row['answer_note'] === null ? null : (string) $row['answer_note'],
            $row['answered_at'] === null ? null : (string) $row['answered_at'],
            (int) $row['prayed_count'],
            (string) $row['created_at'],
            $row['approved_by'] === null ? null : (string) $row['approved_by'],
        );
    }

    public function isAnswered(): bool
    {
        return $this->answeredAt !== null;
    }
}
