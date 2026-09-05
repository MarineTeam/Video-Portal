<?php

declare(strict_types=1);

namespace Portal\Rota;

/**
 * One ask: a person invited to serve on one team at one service.
 *
 * The three states are the whole model. There is deliberately no fourth for
 * "confirmed by the organiser": the only person who can answer an ask is the
 * person asked, and a builder who could mark somebody accepted would be
 * recording a fact nobody checked.
 */
final class Assignment
{
    public const INVITED = 'invited';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';

    /** @var list<string> */
    public const STATES = [self::INVITED, self::ACCEPTED, self::DECLINED];

    public function __construct(
        public readonly int $id,
        public readonly int $serviceId,
        public readonly int $teamId,
        public readonly ?int $positionId,
        public readonly int $userId,
        public readonly string $state = self::INVITED,
        public readonly ?string $reason = null,
        public readonly ?string $answeredAt = null,
        /** Filled by the listing queries; not part of the row. */
        public readonly string $personName = '',
        public readonly string $personEmail = '',
        public readonly string $teamName = '',
        public readonly ?string $positionName = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:         (int) $row['id'],
            serviceId:  (int) $row['service_id'],
            teamId:     (int) $row['team_id'],
            positionId: isset($row['position_id']) && $row['position_id'] !== null
                ? (int) $row['position_id']
                : null,
            userId:     (int) $row['user_id'],
            state:      self::normalizeState((string) ($row['state'] ?? self::INVITED)),
            reason:     isset($row['reason']) && $row['reason'] !== null ? (string) $row['reason'] : null,
            answeredAt: isset($row['answered_at']) && $row['answered_at'] !== null
                ? (string) $row['answered_at']
                : null,
            personName:   (string) ($row['person_name'] ?? ''),
            personEmail:  (string) ($row['person_email'] ?? ''),
            teamName:     (string) ($row['team_name'] ?? ''),
            positionName: isset($row['position_name']) && $row['position_name'] !== null
                ? (string) $row['position_name']
                : null,
        );
    }

    /**
     * An unrecognised state reads as INVITED.
     *
     * Which is the safe direction: an ask nobody has answered shows up on the
     * chasing list and on the person's own page, where an unrecognised value
     * treated as accepted would quietly report somebody as serving who never
     * said yes.
     */
    public static function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));

        return in_array($state, self::STATES, true) ? $state : self::INVITED;
    }

    public function isAnswered(): bool
    {
        return $this->state !== self::INVITED;
    }

    public function isAccepted(): bool
    {
        return $this->state === self::ACCEPTED;
    }

    /** What to call this ask on a screen: the position if there is one. */
    public function label(): string
    {
        return $this->positionName !== null && $this->positionName !== ''
            ? $this->teamName . ' — ' . $this->positionName
            : $this->teamName;
    }
}
