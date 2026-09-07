<?php

declare(strict_types=1);

namespace Portal\Broadcast;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * Broadcasts, who they reach, and what has been sent.
 *
 * # RESOLVED BEFORE ANYTHING IS SENT
 *
 * audience() answers who is in scope, preview() says honestly how many of them
 * each channel will actually reach and why the rest will not, and resolve()
 * writes one row per person per channel. Only then does anything go out.
 *
 * The preview and the resolution call the SAME consent function, so the number
 * somebody is shown before pressing the button is the number that goes. Two
 * implementations of that would eventually disagree, and the disagreement would
 * be discovered by a church that told two hundred people the wrong thing.
 */
final class BroadcastRepository
{
    public const EVERYONE = 'everyone';
    public const GROUP    = 'group';
    public const EVENT    = 'event';

    public const DRAFT   = 'draft';
    public const SENDING = 'sending';
    public const SENT    = 'sent';
    public const STOPPED = 'stopped';

    public const PENDING = 'pending';
    public const OK      = 'sent';
    public const FAILED  = 'failed';

    public function __construct(
        private readonly Db $db,
        /** For reading a national phone number. See PhoneNumber. */
        private readonly string $defaultCountry = ''
    ) {
    }

    // ------------------------------------------------------------ the message

    public function create(string $subject, string $createdBy): int
    {
        $subject = trim($subject);

        if ($subject === '') {
            throw HttpException::badRequest('A broadcast needs something in the subject.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('broadcasts', [
            'subject'    => mb_substr($subject, 0, 300),
            'body'       => '',
            'state'      => self::DRAFT,
            'created_by' => mb_substr(trim($createdBy), 0, 190) ?: null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {broadcasts} WHERE id = ?', [$id]);
    }

    /** @return list<array<string, mixed>> */
    public function all(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT * FROM {broadcasts} ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        $current = $this->find($id);

        if ($current === null) {
            throw HttpException::notFound('There is no broadcast with that id.');
        }

        /*
         * A broadcast that has started cannot be edited. Half its recipients
         * may already have the old words, and the rest would get different
         * ones — which is worse than either version on its own.
         */
        if ((string) $current['state'] !== self::DRAFT) {
            throw HttpException::badRequest(
                'This one has already started going out, so it cannot be changed.'
            );
        }

        $sets = [];
        $params = [];

        foreach (['subject' => 300, 'body' => 20000] as $field => $limit) {
            if (array_key_exists($field, $attributes)) {
                $sets[] = "{$field} = ?";
                $params[] = mb_substr(trim((string) $attributes[$field]), 0, $limit);
            }
        }

        if (array_key_exists('audience_type', $attributes)) {
            $sets[] = 'audience_type = ?';
            $params[] = self::audienceType((string) $attributes['audience_type']);
            $sets[] = 'audience_id = ?';
            $id2 = (int) ($attributes['audience_id'] ?? 0);
            $params[] = $id2 > 0 ? $id2 : null;
        }

        // The marker the video form has used since Phase 4: absent and unticked
        // are both nothing, so a form that means "these are all my checkboxes"
        // has to say so.
        if (!empty($attributes['_whole_form'])) {
            foreach (['by_email', 'by_sms', 'by_push'] as $flag) {
                $sets[] = "{$flag} = ?";
                $params[] = !empty($attributes[$flag]) ? 1 : 0;
            }
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;

        $this->db->execute(
            'UPDATE {broadcasts} SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?',
            $params
        );
    }

    public static function audienceType(string $raw): string
    {
        return in_array($raw, [self::EVERYONE, self::GROUP, self::EVENT], true) ? $raw : self::EVERYONE;
    }

    /** @param array<string, mixed> $broadcast */
    public static function channelsOf(array $broadcast): array
    {
        $out = [];

        foreach ([
            Consent::EMAIL => 'by_email',
            Consent::SMS   => 'by_sms',
            Consent::PUSH  => 'by_push',
        ] as $channel => $column) {
            if (!empty($broadcast[$column])) {
                $out[] = $channel;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ the audience

    /**
     * Everybody in scope, with what is needed to decide about each channel.
     *
     * One row per PERSON, not per channel — the consent rules are applied on
     * top, once, by the code that both the preview and the send use.
     *
     * @param array<string, mixed> $broadcast
     * @return list<array<string, mixed>>
     */
    public function audience(array $broadcast): array
    {
        return match (self::audienceType((string) $broadcast['audience_type'])) {
            self::GROUP => $this->groupAudience((int) ($broadcast['audience_id'] ?? 0)),
            self::EVENT => $this->eventAudience((int) ($broadcast['audience_id'] ?? 0)),
            default     => $this->everyone(),
        };
    }

    /** @return list<array<string, mixed>> */
    private function everyone(): array
    {
        return $this->withPushCounts($this->db->all(
            'SELECT u.id AS user_id, u.email,
                    COALESCE(NULLIF(u.name, ""), u.email) AS person_name,
                    COALESCE(p.email_opt_out, 0) AS email_opt_out,
                    COALESCE(p.sms_opt_in, 0)    AS sms_opt_in,
                    p.phone
               FROM {users} u
               LEFT JOIN {broadcast_prefs} p ON p.user_id = u.id
              WHERE u.authorized = 1
              ORDER BY u.id'
        ));
    }

    /** @return list<array<string, mixed>> */
    private function groupAudience(int $groupId): array
    {
        /*
         * Permission-group membership is keyed by EMAIL, so somebody can be in
         * a group before they ever sign in. Those people have no preferences
         * row and no account — they are emailable and nothing else, which is
         * the correct answer rather than a gap.
         */
        return $this->withPushCounts($this->db->all(
            'SELECT u.id AS user_id, gm.email,
                    COALESCE(NULLIF(u.name, ""), gm.email) AS person_name,
                    COALESCE(p.email_opt_out, 0) AS email_opt_out,
                    COALESCE(p.sms_opt_in, 0)    AS sms_opt_in,
                    p.phone
               FROM {group_members} gm
               LEFT JOIN {users} u ON u.id = gm.user_id
               LEFT JOIN {broadcast_prefs} p ON p.user_id = u.id
              WHERE gm.group_id = ?
              ORDER BY gm.id',
            [$groupId]
        ));
    }

    /**
     * An event's sign-ups, INCLUDING THE ONES WITH NO ACCOUNT.
     *
     * That is most of the point of having events at all: the people a church
     * most wants to write to after an event are exactly the ones who never made
     * an account. Their phone comes from the sign-up form rather than from a
     * preferences row — and they have not opted in to texts, so they will be
     * counted as unreachable by SMS and told about by email. That is correct.
     *
     * @return list<array<string, mixed>>
     */
    private function eventAudience(int $eventId): array
    {
        return $this->withPushCounts($this->db->all(
            'SELECT s.user_id, s.email, s.name AS person_name,
                    COALESCE(p.email_opt_out, 0) AS email_opt_out,
                    COALESCE(p.sms_opt_in, 0)    AS sms_opt_in,
                    COALESCE(p.phone, s.phone)   AS phone
               FROM {event_signups} s
               LEFT JOIN {broadcast_prefs} p ON p.user_id = s.user_id
              WHERE s.event_id = ? AND s.state = "going"
              ORDER BY s.id',
            [$eventId]
        ));
    }

    /**
     * How many devices each person has registered.
     *
     * PUSH IS A PLUGIN. Its table exists only when the plugin has been
     * activated, so this asks first and answers "no devices" when it has not —
     * rather than fatalling on a site that never wanted push, which would take
     * the whole broadcast screen with it.
     *
     * @param list<array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    private function withPushCounts(array $people): array
    {
        $counts = [];

        if ($this->pushAvailable()) {
            foreach (
                $this->db->all(
                    'SELECT user_id, COUNT(*) AS devices FROM {push_subscriptions}
                      WHERE user_id IS NOT NULL GROUP BY user_id'
                ) as $row
            ) {
                $counts[(int) $row['user_id']] = (int) $row['devices'];
            }
        }

        foreach ($people as $i => $person) {
            $people[$i]['push_devices'] = $counts[(int) ($person['user_id'] ?? 0)] ?? 0;
        }

        return $people;
    }

    public function pushAvailable(): bool
    {
        try {
            $this->db->value('SELECT 1 FROM {push_subscriptions} LIMIT 1');

            return true;
        } catch (\Throwable) {
            // No table: the plugin was never activated. Not an error — a site
            // that does not want push is the ordinary case.
            return false;
        }
    }

    /**
     * HONEST COUNTS, before anything goes.
     *
     * Per channel: how many will be reached, and how many will not with the
     * reason named. "We will reach 214 of 300" is a number somebody can act on;
     * "300 recipients" is one that gets believed and then quietly is not true.
     *
     * @param array<string, mixed> $broadcast
     * @return array<string, array{reach: int, skipped: array<string, int>}>
     */
    public function preview(array $broadcast): array
    {
        $people = $this->audience($broadcast);
        $out = [];

        foreach (self::channelsOf($broadcast) as $channel) {
            $reach = 0;
            $skipped = [];

            foreach ($people as $person) {
                if (Consent::allows($channel, $person, $this->defaultCountry)) {
                    $reach += $channel === Consent::PUSH
                        // One message per device, because that is what a send
                        // actually does — a person with a phone and a laptop is
                        // two deliveries and the count has to say so.
                        ? (int) $person['push_devices']
                        : 1;

                    continue;
                }

                $why = Consent::why($channel, $person, $this->defaultCountry);
                $skipped[$why] = ($skipped[$why] ?? 0) + 1;
            }

            $out[$channel] = ['reach' => $reach, 'skipped' => $skipped];
        }

        return $out;
    }

    // ---------------------------------------------------------- the recipients

    /**
     * Write one row per person per channel, then mark the broadcast as going.
     *
     * INSERT IGNORE against the unique key, so calling this twice — which an
     * interrupted run will do — cannot produce a second row for anybody. That
     * key is the whole of the "a resumed run cannot send twice" guarantee.
     *
     * @param array<string, mixed> $broadcast
     * @return int how many rows are waiting to go
     */
    public function resolve(array $broadcast): int
    {
        $id = (int) $broadcast['id'];
        $people = $this->audience($broadcast);
        $now = date('Y-m-d H:i:s');

        foreach (self::channelsOf($broadcast) as $channel) {
            foreach ($people as $person) {
                if (!Consent::allows($channel, $person, $this->defaultCountry)) {
                    continue;
                }

                foreach ($this->addressesFor($channel, $person) as $address) {
                    $this->db->execute(
                        'INSERT IGNORE INTO {broadcast_recipients}
                            (broadcast_id, channel, address, user_id, person_name, state, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [
                            $id,
                            $channel,
                            $address,
                            ($person['user_id'] ?? null) === null ? null : (int) $person['user_id'],
                            mb_substr((string) ($person['person_name'] ?? ''), 0, 190) ?: null,
                            self::PENDING,
                            $now,
                        ]
                    );
                }
            }
        }

        $this->db->execute(
            'UPDATE {broadcasts} SET state = ?, started_at = COALESCE(started_at, NOW()),
                updated_at = NOW()
              WHERE id = ? AND state = ?',
            [self::SENDING, $id, self::DRAFT]
        );

        return $this->pendingCount($id);
    }

    /**
     * Where a message for this person on this channel actually goes.
     *
     * A list rather than one, because push is per DEVICE: somebody with a
     * phone and a laptop is two deliveries, and a single row keyed to the
     * person would silently reach only one of them.
     *
     * @param array<string, mixed> $person
     * @return list<string>
     */
    private function addressesFor(string $channel, array $person): array
    {
        if ($channel === Consent::EMAIL) {
            return [mb_strtolower(trim((string) $person['email']))];
        }

        if ($channel === Consent::SMS) {
            $number = PhoneNumber::e164((string) ($person['phone'] ?? ''), $this->defaultCountry);

            return $number === null ? [] : [$number];
        }

        if ($channel !== Consent::PUSH || !$this->pushAvailable()) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['endpoint'],
            $this->db->all(
                'SELECT endpoint FROM {push_subscriptions} WHERE user_id = ?',
                [(int) ($person['user_id'] ?? 0)]
            )
        );
    }

    public function pendingCount(int $broadcastId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {broadcast_recipients} WHERE broadcast_id = ? AND state = ?',
            [$broadcastId, self::PENDING]
        );
    }

    /**
     * @return array{sent: int, failed: int, pending: int}
     */
    public function tally(int $broadcastId): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'pending' => 0];

        foreach (
            $this->db->all(
                'SELECT state, COUNT(*) AS n FROM {broadcast_recipients}
                  WHERE broadcast_id = ? GROUP BY state',
                [$broadcastId]
            ) as $row
        ) {
            $out[(string) $row['state']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function nextBatch(int $broadcastId, int $limit): array
    {
        return $this->db->all(
            'SELECT * FROM {broadcast_recipients}
              WHERE broadcast_id = ? AND state = ?
              ORDER BY id LIMIT ' . max(1, min(500, $limit)),
            [$broadcastId, self::PENDING]
        );
    }

    /**
     * Take one row, exclusively.
     *
     * A conditional UPDATE, so two runs overlapping — which pseudo-cron makes
     * possible, since it fires inside whatever page views happen to arrive —
     * cannot both send to the same person. The one whose UPDATE matched sends;
     * the other moves on.
     *
     * It goes straight to 'sent', BEFORE the message is attempted, and that is
     * deliberate. A process killed between the claim and the delivery loses one
     * message; a claim taken afterwards would send that person a second copy
     * every time a run was interrupted. Losing one is recoverable by a human
     * who notices; sending everybody two is the thing people remember about a
     * church that texts them.
     */
    public function claim(int $recipientId): bool
    {
        return $this->db->execute(
            'UPDATE {broadcast_recipients}
                SET state = ?, attempts = attempts + 1
              WHERE id = ? AND state = ?',
            [self::OK, $recipientId, self::PENDING]
        ) > 0;
    }

    /**
     * Claimed and then found to have failed.
     *
     * The row goes to 'failed' rather than back to 'pending', because a retry
     * loop over a bad address is a bill and a rate limit rather than a
     * delivery. The error is kept in the provider's own words.
     */
    public function markFailed(int $recipientId, string $error): void
    {
        $this->db->execute(
            'UPDATE {broadcast_recipients} SET state = ?, error = ?, sent_at = NULL WHERE id = ?',
            [self::FAILED, mb_substr($error, 0, 500), $recipientId]
        );
    }

    public function markSent(int $recipientId): void
    {
        $this->db->execute(
            'UPDATE {broadcast_recipients} SET sent_at = NOW() WHERE id = ?',
            [$recipientId]
        );
    }

    public function finish(int $broadcastId): void
    {
        $this->db->execute(
            'UPDATE {broadcasts} SET state = ?, finished_at = NOW(), updated_at = NOW()
              WHERE id = ? AND state = ?',
            [self::SENT, $broadcastId, self::SENDING]
        );
    }

    public function stop(int $broadcastId): void
    {
        $this->db->execute(
            'UPDATE {broadcasts} SET state = ?, updated_at = NOW() WHERE id = ? AND state = ?',
            [self::STOPPED, $broadcastId, self::SENDING]
        );
    }

    /** @return list<array<string, mixed>> */
    public function recipients(int $broadcastId, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT * FROM {broadcast_recipients} WHERE broadcast_id = ?
              ORDER BY FIELD(state, ?, ?, ?), id LIMIT ' . max(1, min(1000, $limit)),
            [$broadcastId, self::FAILED, self::PENDING, self::OK]
        );
    }

    /** Broadcasts with rows still to go. */
    public function sending(int $limit = 5): array
    {
        return $this->db->all(
            'SELECT * FROM {broadcasts} WHERE state = ? ORDER BY id LIMIT ' . max(1, min(20, $limit)),
            [self::SENDING]
        );
    }

    // --------------------------------------------------------- preferences

    /**
     * What somebody has asked for.
     *
     * A missing row means EMAIL YES, SMS NO — the defaults the three rules
     * describe. Nobody has to visit a settings page to be emailed, and nobody
     * is texted because they never visited one.
     *
     * @return array{email_opt_out: bool, sms_opt_in: bool, phone: ?string}
     */
    public function prefs(int $userId): array
    {
        $row = $this->db->first('SELECT * FROM {broadcast_prefs} WHERE user_id = ?', [$userId]);

        return [
            'email_opt_out' => $row !== null && (bool) $row['email_opt_out'],
            'sms_opt_in'    => $row !== null && (bool) $row['sms_opt_in'],
            'phone'         => $row === null ? null : ($row['phone'] ?: null),
        ];
    }

    public function savePrefs(int $userId, bool $emailOptOut, bool $smsOptIn, string $phone): void
    {
        $now = date('Y-m-d H:i:s');
        $tidy = trim($phone);

        /*
         * The number is stored as typed rather than as E.164, so somebody can
         * see what they wrote and correct it. Consent::allows() parses it every
         * time it is asked, which means a number that stops being readable
         * stops being sent to rather than being silently repaired into a
         * different phone.
         */
        $this->db->execute(
            'INSERT INTO {broadcast_prefs}
                (user_id, email_opt_out, sms_opt_in, phone, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email_opt_out = VALUES(email_opt_out),
                sms_opt_in = VALUES(sms_opt_in), phone = VALUES(phone),
                updated_at = VALUES(updated_at)',
            [$userId, $emailOptOut ? 1 : 0, $smsOptIn ? 1 : 0, mb_substr($tidy, 0, 40) ?: null, $now, $now]
        );
    }
}
