<?php

declare(strict_types=1);

namespace Portal\Sharing;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;
use Throwable;

/**
 * Named address lists, and free-text tags on approved viewers.
 *
 * Both exist for one purpose: pulling a set of addresses into a share dialog
 * without typing them. "Share this with the elders" should not mean pasting
 * fourteen addresses and hoping none were mistyped.
 *
 * NEITHER GRANTS ACCESS. A group is a convenience for composing a list of
 * recipients, and the shares it produces are ordinary independent links. That
 * matters at both ends:
 *
 *  - Adding someone to a group does NOT give them anything. Access arrives
 *    only when a share is created for them.
 *  - Removing them, or deleting the group entirely, does NOT revoke anything
 *    already sent. The links were made for people, not for the group.
 *
 * Anything else would make a group a permission object, and the permission
 * system already exists for that — with scoped grants and capabilities that
 * this deliberately does not duplicate.
 */
final class ViewerGroups
{
    public const MAX_TAGS_PER_USER = 20;
    public const MAX_TAG_LENGTH    = 30;

    public function __construct(private readonly Db $db)
    {
    }

    // ----------------------------------------------------------------- groups

    /** @return list<array{id: int, slug: string, name: string, memberCount: int}> */
    public function all(): array
    {
        $rows = $this->db->all(
            'SELECT g.*, (SELECT COUNT(*) FROM {viewer_group_members} m WHERE m.group_id = g.id) AS member_count
               FROM {viewer_groups} g
              ORDER BY g.name'
        );

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'slug'        => (string) $r['slug'],
            'name'        => (string) $r['name'],
            'memberCount' => (int) $r['member_count'],
        ], $rows);
    }

    public function create(string $name): int
    {
        $name = trim($name);

        if ($name === '') {
            throw HttpException::badRequest('A group needs a name.');
        }

        $slug = Str::slug($name);
        $suffix = 1;

        while ($this->db->value('SELECT 1 FROM {viewer_groups} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = Str::slug($name) . '-' . $suffix;
        }

        return $this->db->insert('viewer_groups', [
            'slug'       => $slug,
            'name'       => mb_substr($name, 0, 120),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Delete a group.
     *
     * Members cascade. Shares created from it are untouched, because they
     * belong to the people they were sent to, not to the group.
     */
    public function delete(int $groupId): bool
    {
        return $this->db->execute('DELETE FROM {viewer_groups} WHERE id = ?', [$groupId]) > 0;
    }

    /**
     * Put addresses in a group.
     *
     * @param list<string> $emails
     * @return array{added: list<string>, invalid: list<string>}
     */
    public function addMembers(int $groupId, array $emails): array
    {
        $added = [];
        $invalid = [];
        $now = date('Y-m-d H:i:s');

        foreach ($emails as $raw) {
            $email = Str::normalizeEmail((string) $raw);

            if ($email === '') {
                continue;
            }

            if (!Str::isEmail($email)) {
                $invalid[] = (string) $raw;
                continue;
            }

            try {
                $this->db->execute(
                    'INSERT IGNORE INTO {viewer_group_members} (group_id, email, added_at) VALUES (?, ?, ?)',
                    [$groupId, $email, $now]
                );
                $added[] = $email;
            } catch (Throwable $e) {
                $invalid[] = $email;
                error_log('Portal: could not add a group member: ' . $e->getMessage());
            }
        }

        return ['added' => array_values(array_unique($added)), 'invalid' => array_values(array_unique($invalid))];
    }

    public function removeMember(int $groupId, string $email): bool
    {
        return $this->db->execute(
            'DELETE FROM {viewer_group_members} WHERE group_id = ? AND email = ?',
            [$groupId, Str::normalizeEmail($email)]
        ) > 0;
    }

    /**
     * The addresses in a group, for pulling into a share dialog.
     *
     * @return list<string>
     */
    public function emails(int $groupId): array
    {
        return array_map('strval', $this->db->column(
            'SELECT email FROM {viewer_group_members} WHERE group_id = ? ORDER BY email',
            [$groupId]
        ));
    }

    /**
     * Expand several groups into one deduplicated address list.
     *
     * @param list<int> $groupIds
     * @return list<string>
     */
    public function expand(array $groupIds): array
    {
        $groupIds = array_values(array_filter(array_map('intval', $groupIds)));

        if ($groupIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        return array_map('strval', $this->db->column(
            "SELECT DISTINCT email FROM {viewer_group_members}
              WHERE group_id IN ({$placeholders}) ORDER BY email",
            $groupIds
        ));
    }

    // ------------------------------------------------------------------- tags

    /**
     * Label an approved viewer.
     *
     * Tags live on the user rather than in a join table of their own, because
     * they are labels, not entities: "elders" is a word describing a person,
     * not an object with its own lifecycle.
     *
     * @param list<string> $tags
     */
    public function setTags(int $userId, array $tags): void
    {
        $clean = [];

        foreach ($tags as $tag) {
            $tag = trim(mb_substr((string) $tag, 0, self::MAX_TAG_LENGTH));

            if ($tag === '') {
                continue;
            }

            $clean[mb_strtolower($tag)] = $tag;

            if (count($clean) >= self::MAX_TAGS_PER_USER) {
                break;
            }
        }

        $this->db->transaction(function () use ($userId, $clean): void {
            $this->db->execute('DELETE FROM {user_tags} WHERE user_id = ?', [$userId]);

            foreach ($clean as $tag) {
                $this->db->execute(
                    'INSERT IGNORE INTO {user_tags} (user_id, tag) VALUES (?, ?)',
                    [$userId, $tag]
                );
            }
        });
    }

    /** @return list<string> */
    public function tagsFor(int $userId): array
    {
        return array_map('strval', $this->db->column(
            'SELECT tag FROM {user_tags} WHERE user_id = ? ORDER BY tag',
            [$userId]
        ));
    }

    /**
     * Every tag in use, with how many people carry it.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function allTags(): array
    {
        $rows = $this->db->all(
            'SELECT tag, COUNT(*) AS n FROM {user_tags} GROUP BY tag ORDER BY tag'
        );

        return array_map(static fn (array $r): array => [
            'tag'   => (string) $r['tag'],
            'count' => (int) $r['n'],
        ], $rows);
    }

    /**
     * Addresses of everyone carrying a tag.
     *
     * Restricted to authorized accounts: an unapproved account cannot watch
     * anything, so including them would produce links that land on the
     * "not approved yet" page. Confusing for them and for whoever sent it.
     *
     * @return list<string>
     */
    public function emailsForTag(string $tag): array
    {
        return array_map('strval', $this->db->column(
            'SELECT u.email
               FROM {users} u
               JOIN {user_tags} t ON t.user_id = u.id
              WHERE t.tag = ? AND u.authorized = 1
              ORDER BY u.email',
            [trim($tag)]
        ));
    }

    /**
     * Resolve everything a share dialog might have been given into one list.
     *
     * Typed addresses, chosen groups, and chosen tags all arrive together and
     * come out deduplicated, so overlapping selections cannot produce two
     * links for the same person.
     *
     * @param list<string> $typed
     * @param list<int>    $groupIds
     * @param list<string> $tags
     * @return array{valid: list<string>, invalid: list<string>}
     */
    public function resolveRecipients(array $typed = [], array $groupIds = [], array $tags = []): array
    {
        $parsed = Str::parseEmailList(implode(' ', array_map('strval', $typed)));

        $all = $parsed['valid'];

        foreach ($this->expand($groupIds) as $email) {
            $all[] = $email;
        }

        foreach ($tags as $tag) {
            foreach ($this->emailsForTag((string) $tag) as $email) {
                $all[] = $email;
            }
        }

        return [
            'valid'   => array_values(array_unique($all)),
            'invalid' => $parsed['invalid'],
        ];
    }
}
