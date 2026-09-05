<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Reading and writing the permission model.
 *
 * Everything Capabilities resolves against, from the other direction. It exists
 * because a permission system nobody can inspect is a permission system nobody
 * can trust: until now roles, groups, and scoped grants were all real and all
 * invisible, editable only by writing SQL by hand.
 *
 * Two rules are enforced here rather than left to the UI, because a form is a
 * suggestion and a repository is a boundary:
 *
 *   The `admin` role cannot be edited. It holds nothing explicitly and
 *   short-circuits every check, so "editing" it would silently do nothing while
 *   appearing to work — and letting it be emptied would suggest an
 *   administrator could be partially disarmed, which is not how it behaves.
 *
 *   A site-only capability is always stored with scope 'site'. Accepting
 *   "manage plugins on the Sermons category" would record a grant implying a
 *   containment that does not exist, and Capabilities would ignore the scope
 *   anyway — so the stored row would not mean what its author read.
 */
final class PermissionRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ roles

    /**
     * Every role with the capabilities it holds.
     *
     * @return list<array{id: int, slug: string, name: string, isSystem: bool, capabilities: list<string>, users: int}>
     */
    public function roles(): array
    {
        $held = [];
        foreach ($this->db->all(
            'SELECT rc.role_id, c.slug
               FROM {role_capabilities} rc
               JOIN {capabilities} c ON c.id = rc.capability_id'
        ) as $row) {
            $held[(int) $row['role_id']][] = (string) $row['slug'];
        }

        $out = [];
        foreach ($this->db->all('SELECT * FROM {roles} ORDER BY is_system DESC, name') as $row) {
            $id = (int) $row['id'];

            $out[] = [
                'id'           => $id,
                'slug'         => (string) $row['slug'],
                'name'         => (string) $row['name'],
                'isSystem'     => (bool) ($row['is_system'] ?? false),
                'capabilities' => $held[$id] ?? [],
                'users'        => (int) $this->db->value(
                    'SELECT COUNT(*) FROM {users} WHERE role_id = ?',
                    [$id]
                ),
            ];
        }

        return $out;
    }

    /**
     * Replace a role's capabilities.
     *
     * @param list<string> $capabilitySlugs
     */
    public function setRoleCapabilities(int $roleId, array $capabilitySlugs): void
    {
        $slug = (string) $this->db->value('SELECT slug FROM {roles} WHERE id = ?', [$roleId]);

        if ($slug === '') {
            throw HttpException::notFound('That role does not exist.');
        }

        if ($slug === Capability::ROLE_ADMIN) {
            throw HttpException::badRequest(
                'The administrator role cannot be edited. It holds everything implicitly, '
                . 'so there is nothing here to change.'
            );
        }

        $this->db->transaction(function () use ($roleId, $capabilitySlugs): void {
            $this->db->execute('DELETE FROM {role_capabilities} WHERE role_id = ?', [$roleId]);

            foreach ($this->capabilityIds($capabilitySlugs) as $capabilityId) {
                $this->db->execute(
                    'INSERT IGNORE INTO {role_capabilities} (role_id, capability_id) VALUES (?, ?)',
                    [$roleId, $capabilityId]
                );
            }
        });
    }

    // ----------------------------------------------------------------- groups

    /**
     * @return list<array{id: int, name: string, description: ?string, capabilities: list<string>, members: list<string>}>
     */
    /**
     * The groups one person belongs to.
     *
     * Matched on EMAIL, not user id, because that is what {group_members}
     * stores — a person can be put in a group before they have an account, and
     * pre-authorisation was the reason that column is an address in the first
     * place. Matching on id alone would silently drop exactly those people.
     *
     * @return list<int>
     */
    public function groupIdsFor(?string $email): array
    {
        $email = $email === null ? '' : \Portal\Support\Str::normalizeEmail($email);

        if ($email === '') {
            return [];
        }

        return array_map(
            static fn (array $row): int => (int) $row['group_id'],
            $this->db->all('SELECT group_id FROM {group_members} WHERE email = ?', [$email])
        );
    }

    public function groups(): array
    {
        $capabilities = [];
        foreach ($this->db->all(
            'SELECT gc.group_id, c.slug
               FROM {group_capabilities} gc
               JOIN {capabilities} c ON c.id = gc.capability_id'
        ) as $row) {
            $capabilities[(int) $row['group_id']][] = (string) $row['slug'];
        }

        $members = [];
        foreach ($this->db->all('SELECT group_id, email FROM {group_members} ORDER BY email') as $row) {
            $members[(int) $row['group_id']][] = (string) $row['email'];
        }

        $out = [];
        foreach ($this->db->all('SELECT * FROM {permission_groups} ORDER BY name') as $row) {
            $id = (int) $row['id'];

            $out[] = [
                'id'           => $id,
                'name'         => (string) $row['name'],
                'description'  => $row['description'] !== null ? (string) $row['description'] : null,
                'capabilities' => $capabilities[$id] ?? [],
                'members'      => $members[$id] ?? [],
            ];
        }

        return $out;
    }

    public function createGroup(string $name, ?string $description = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw HttpException::badRequest('A group needs a name.');
        }

        return $this->db->insert('permission_groups', [
            'slug'        => $this->uniqueGroupSlug($name),
            'name'        => $name,
            'description' => $description,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteGroup(int $groupId): void
    {
        // Members and capabilities cascade. Deleting the group is what removes
        // the permission, which is the point of the action.
        $this->db->execute('DELETE FROM {permission_groups} WHERE id = ?', [$groupId]);
    }

    /** @param list<string> $capabilitySlugs */
    public function setGroupCapabilities(int $groupId, array $capabilitySlugs): void
    {
        $this->db->transaction(function () use ($groupId, $capabilitySlugs): void {
            $this->db->execute('DELETE FROM {group_capabilities} WHERE group_id = ?', [$groupId]);

            foreach ($this->capabilityIds($capabilitySlugs) as $capabilityId) {
                $this->db->execute(
                    'INSERT IGNORE INTO {group_capabilities} (group_id, capability_id) VALUES (?, ?)',
                    [$groupId, $capabilityId]
                );
            }
        });
    }

    /**
     * Add someone to a group by email.
     *
     * Always by email, never by picking an existing account: permissions can
     * then be prepared for somebody who has not signed in yet, and survive
     * their account being deleted and recreated. user_id is filled in as a
     * convenience when a matching account exists.
     */
    public function addGroupMember(int $groupId, string $email): void
    {
        $email = Str::normalizeEmail($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::badRequest('That is not a valid email address.');
        }

        $userId = $this->db->value('SELECT id FROM {users} WHERE email = ?', [$email]);

        $this->db->execute(
            'INSERT INTO {group_members} (group_id, email, user_id, added_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)',
            [$groupId, $email, $userId === null ? null : (int) $userId]
        );
    }

    public function removeGroupMember(int $groupId, string $email): void
    {
        $this->db->execute(
            'DELETE FROM {group_members} WHERE group_id = ? AND email = ?',
            [$groupId, Str::normalizeEmail($email)]
        );
    }

    // ----------------------------------------------------------------- grants

    /**
     * Every grant, described in words rather than ids.
     *
     * The subject and scope are resolved to names here because a list of
     * "user 7 holds capability 3 on category 12" is unreviewable, and an
     * unreviewable permission list is one nobody audits.
     *
     * @return list<array{id: int, subject: string, capability: string, scope: string, createdAt: string}>
     */
    public function grants(): array
    {
        $rows = $this->db->all(
            'SELECT g.*, c.slug AS capability
               FROM {grants} g
               JOIN {capabilities} c ON c.id = g.capability_id
              ORDER BY g.created_at DESC
              LIMIT 500'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'subject'    => $this->describeSubject($row),
                'capability' => (string) $row['capability'],
                'scope'      => $this->describeScope(
                    (string) $row['scope_type'],
                    (int) $row['scope_id']
                ),
                'createdAt'  => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Grant a capability, optionally within a scope.
     *
     * @param string $subjectType user | email | group | role
     */
    public function grant(
        string $subjectType,
        string $subjectValue,
        string $capabilitySlug,
        string $scopeType = 'site',
        int $scopeId = 0,
        ?string $grantedBy = null
    ): void {
        if (!in_array($subjectType, ['user', 'email', 'group', 'role'], true)) {
            throw HttpException::badRequest('Unknown kind of recipient.');
        }

        $capabilityId = $this->db->value('SELECT id FROM {capabilities} WHERE slug = ?', [$capabilitySlug]);
        if ($capabilityId === null) {
            throw HttpException::badRequest('That is not a capability this site knows about.');
        }

        // A site-only capability ignores scope when it is CHECKED, so storing a
        // scope would record a row that does not mean what its author read.
        if (!Capability::isScopable($capabilitySlug)) {
            $scopeType = 'site';
            $scopeId = 0;
        }

        if (!in_array($scopeType, ['site', 'category', 'series', 'video'], true)) {
            throw HttpException::badRequest('Unknown kind of scope.');
        }

        if ($scopeType === 'site') {
            $scopeId = 0;
        } elseif ($scopeId <= 0) {
            throw HttpException::badRequest('Choose something to attach this permission to.');
        }

        $subjectId = 0;
        $email = '';

        if ($subjectType === 'email') {
            $email = Str::normalizeEmail($subjectValue);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw HttpException::badRequest('That is not a valid email address.');
            }
        } else {
            $subjectId = (int) $subjectValue;
            if ($subjectId <= 0) {
                throw HttpException::badRequest('Choose who this permission is for.');
            }
        }

        $this->db->execute(
            'INSERT INTO {grants}
                (subject_type, subject_id, email, capability_id, scope_type, scope_id, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE created_at = created_at',
            [$subjectType, $subjectId, $email, (int) $capabilityId, $scopeType, $scopeId, $grantedBy]
        );
    }

    public function revoke(int $grantId): void
    {
        $this->db->execute('DELETE FROM {grants} WHERE id = ?', [$grantId]);
    }

    // ------------------------------------------------------------- internals

    /**
     * @param list<string> $slugs
     * @return list<int>
     */
    private function capabilityIds(array $slugs): array
    {
        $known = array_keys(Capability::all());

        // Filtered against the vocabulary rather than trusted: a capability
        // nothing ever checks is dead weight in a table people audit.
        $slugs = array_values(array_intersect(array_unique($slugs), $known));

        if ($slugs === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));

        return array_map(
            'intval',
            $this->db->column("SELECT id FROM {capabilities} WHERE slug IN ({$placeholders})", $slugs)
        );
    }

    /** @param array<string, mixed> $row */
    private function describeSubject(array $row): string
    {
        $id = (int) $row['subject_id'];

        return match ((string) $row['subject_type']) {
            'email' => (string) $row['email'] . ' (not signed up yet)',
            'user'  => (string) ($this->db->value('SELECT email FROM {users} WHERE id = ?', [$id])
                ?? 'a deleted account'),
            'group' => 'Group: ' . (string) ($this->db->value(
                'SELECT name FROM {permission_groups} WHERE id = ?',
                [$id]
            ) ?? 'deleted'),
            'role'  => 'Everyone with role: ' . (string) ($this->db->value(
                'SELECT name FROM {roles} WHERE id = ?',
                [$id]
            ) ?? 'deleted'),
            default => 'unknown',
        };
    }

    private function describeScope(string $type, int $id): string
    {
        if ($type === 'site') {
            return 'the whole site';
        }

        $name = match ($type) {
            'category' => $this->db->value('SELECT name FROM {categories} WHERE id = ?', [$id]),
            'series'   => $this->db->value('SELECT title FROM {series} WHERE id = ?', [$id]),
            'video'    => $this->db->value('SELECT title FROM {videos} WHERE id = ?', [$id]),
            default    => null,
        };

        // A grant whose target has been deleted still exists and still shows,
        // because a row nobody can see is a row nobody removes.
        return $name === null
            ? ucfirst($type) . ' #' . $id . ' (deleted)'
            : ucfirst($type) . ': ' . (string) $name;
    }

    private function uniqueGroupSlug(string $name): string
    {
        $base = substr(Str::slug($name), 0, 60);
        if ($base === '') {
            $base = 'group';
        }

        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {permission_groups} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
