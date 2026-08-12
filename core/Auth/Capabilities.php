<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Db;
use Portal\Support\Str;
use Throwable;

/**
 * Permission resolution.
 *
 * A person may hold a capability through four routes, checked in this order:
 *
 *   1. The admin role — short-circuits everything.
 *   2. Their role's site-wide capabilities.
 *   3. A permission group they belong to (by user id, or by email if they have
 *      never signed in).
 *   4. A scoped grant: site-wide, or attached to a specific category, series,
 *      or video. Category grants are inherited by descendants, so granting
 *      "manage videos" on Sermons covers Sermons/2026/Advent without anyone
 *      having to re-grant it.
 *
 * Two rules that are not negotiable:
 *
 *   FAILS CLOSED. Any error — database down, malformed row, unknown capability
 *   — resolves to false. A permission system that grants access when it cannot
 *   think straight is worse than one that is merely offline.
 *
 *   NO SELF-ESCALATION. `admin` is a role slug, never a capability. Someone
 *   with MANAGE_PERMISSIONS can hand out every capability that exists and still
 *   cannot make themselves an administrator.
 */
final class Capabilities
{
    /**
     * Per-request memo. Permission checks happen dozens of times on a single
     * admin page render; without this each one is several queries.
     *
     * @var array<string, bool>
     */
    private array $memo = [];

    /** @var array<int, list<string>>|null user id => capability slugs */
    private ?array $siteWideCache = null;

    /** @var array<int, string>|null category id => materialized path */
    private ?array $categoryPaths = null;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * May $user do $capability, optionally within a specific scope?
     *
     * @param string|null $scopeType 'category' | 'series' | 'video', or null for site-wide
     */
    public function can(
        ?User $user,
        string $capability,
        ?string $scopeType = null,
        ?int $scopeId = null
    ): bool {
        // Anonymous holds nothing. Public browsing of published content is
        // decided by content visibility, not by capability.
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        // Everything below requires an authorized account. Authentication
        // establishes identity; authorization is the separate admin decision
        // about whether this person may do anything at all.
        if (!$user->authorized) {
            return false;
        }

        // A site-only capability ignores any scope it was asked about, rather
        // than quietly answering a different question than the caller posed.
        if (!Capability::isScopable($capability)) {
            $scopeType = null;
            $scopeId = null;
        }

        $key = sprintf('%d|%s|%s|%d', $user->id, $capability, $scopeType ?? 'site', $scopeId ?? 0);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        try {
            $allowed = $this->resolve($user, $capability, $scopeType, $scopeId);
        } catch (Throwable $e) {
            error_log('Portal: permission check failed, denying: ' . $e->getMessage());
            $allowed = false;
        }

        return $this->memo[$key] = $allowed;
    }

    /** Convenience for the common site-wide case. */
    public function cannot(?User $user, string $capability, ?string $scopeType = null, ?int $scopeId = null): bool
    {
        return !$this->can($user, $capability, $scopeType, $scopeId);
    }

    /**
     * Does this person hold $capability site-wide, or attached to any one
     * object?
     *
     * This is the question a LISTING asks — "is there anything on this screen
     * you could act on" — where there is no single object to name. It is
     * deliberately weaker than can(): a grant on one category answers yes, and
     * says nothing about any particular video.
     *
     * Never authorise a change with it. can($cap, $type, $id) is the question
     * that decides whether somebody may touch a thing, and it is the one every
     * action asks. This only decides whether the door is worth opening.
     */
    public function canAnywhere(?User $user, string $capability): bool
    {
        if ($this->can($user, $capability)) {
            return true;
        }

        // can() has already excluded anonymous and unauthorized accounts; both
        // return false above and neither can hold a grant.
        if ($user === null || !$user->authorized) {
            return false;
        }

        // A site-only capability has no scoped form, so the site-wide answer
        // above was the whole question.
        if (!Capability::isScopable($capability)) {
            return false;
        }

        return $this->hasAnyScopedGrant($user, $capability);
    }

    /**
     * Does this person have any reason to see the admin area at all?
     *
     * Used to decide whether to render the admin link. Every individual admin
     * route still checks its own capability — this is navigation, not a guard.
     */
    public function canSeeAdmin(?User $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        /*
         * Every capability now counts. There used to be a skip for
         * VIEW_CONTENT, because holding it was true of every approved account
         * and would have let everybody into the admin area — but it was
         * enforced nowhere and has been removed from the vocabulary, so the
         * exception has nothing left to except.
         */
        foreach (array_keys(Capability::all()) as $capability) {
            // A scoped grant is also a reason to see the admin area, even
            // though the site-wide answer is no — which is the same question
            // every admin listing asks, so it is asked in one place.
            if ($this->canAnywhere($user, $capability)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------- resolution

    private function resolve(User $user, string $capability, ?string $scopeType, ?int $scopeId): bool
    {
        // Routes 2 and 3: role and group capabilities, both site-wide.
        if (in_array($capability, $this->siteWideCapabilities($user->id), true)) {
            return true;
        }

        // Route 4a: an explicitly site-wide grant satisfies any scope.
        if ($this->hasGrant($user, $capability, 'site', 0)) {
            return true;
        }

        if ($scopeType === null || $scopeId === null || $scopeId <= 0) {
            return false;
        }

        // Route 4b: a grant on this exact object.
        if ($this->hasGrant($user, $capability, $scopeType, $scopeId)) {
            return true;
        }

        // Route 4c: inheritance. A video is covered by a grant on its series
        // or any of its categories; a series by a grant on its category; a
        // category by a grant on any ancestor.
        foreach ($this->ancestorScopes($scopeType, $scopeId) as [$type, $id]) {
            if ($this->hasGrant($user, $capability, $type, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capabilities granted site-wide by role or group membership.
     *
     * One query, cached per request.
     *
     * @return list<string>
     */
    private function siteWideCapabilities(int $userId): array
    {
        if (isset($this->siteWideCache[$userId])) {
            return $this->siteWideCache[$userId];
        }

        $slugs = $this->db->column(
            'SELECT c.slug
               FROM {capabilities} c
               JOIN {role_capabilities} rc ON rc.capability_id = c.id
               JOIN {users} u ON u.role_id = rc.role_id
              WHERE u.id = ?
              UNION
             SELECT c.slug
               FROM {capabilities} c
               JOIN {group_capabilities} gc ON gc.capability_id = c.id
               JOIN {group_members} gm ON gm.group_id = gc.group_id
               JOIN {users} u2 ON u2.id = ?
              WHERE gm.user_id = u2.id OR gm.email = u2.email',
            [$userId, $userId]
        );

        $this->siteWideCache ??= [];
        return $this->siteWideCache[$userId] = array_map('strval', $slugs);
    }

    /**
     * Is there a grant of $capability at this exact scope for this person?
     *
     * Matches the user directly, by role, by group membership, or by email —
     * the last of which is how permissions can be prepared for someone who has
     * not yet signed in for the first time.
     */
    private function hasGrant(User $user, string $capability, string $scopeType, int $scopeId): bool
    {
        $found = $this->db->value(
            'SELECT 1
               FROM {grants} g
               JOIN {capabilities} c ON c.id = g.capability_id
               LEFT JOIN {users} u ON u.id = ?
              WHERE c.slug = ?
                AND g.scope_type = ?
                AND g.scope_id = ?
                AND (
                     (g.subject_type = "user"  AND g.subject_id = ?)
                  OR (g.subject_type = "email" AND g.email = ?)
                  OR (g.subject_type = "role"  AND g.subject_id = u.role_id)
                  OR (g.subject_type = "group" AND g.subject_id IN (
                        SELECT gm.group_id FROM {group_members} gm
                         WHERE gm.user_id = ? OR gm.email = ?
                      ))
                )
              LIMIT 1',
            [$user->id, $capability, $scopeType, $scopeId, $user->id, $user->email, $user->id, $user->email]
        );

        return $found !== null;
    }

    private function hasAnyScopedGrant(User $user, string $capability): bool
    {
        try {
            $found = $this->db->value(
                'SELECT 1
                   FROM {grants} g
                   JOIN {capabilities} c ON c.id = g.capability_id
                   LEFT JOIN {users} u ON u.id = ?
                  WHERE c.slug = ?
                    AND (
                         (g.subject_type = "user"  AND g.subject_id = ?)
                      OR (g.subject_type = "email" AND g.email = ?)
                      OR (g.subject_type = "role"  AND g.subject_id = u.role_id)
                      OR (g.subject_type = "group" AND g.subject_id IN (
                            SELECT gm.group_id FROM {group_members} gm
                             WHERE gm.user_id = ? OR gm.email = ?
                          ))
                    )
                  LIMIT 1',
                [$user->id, $capability, $user->id, $user->email, $user->id, $user->email]
            );
            return $found !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Scopes that contain the given object, nearest first.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function ancestorScopes(string $scopeType, int $scopeId): array
    {
        return match ($scopeType) {
            'video'    => $this->videoAncestors($scopeId),
            'series'   => $this->seriesAncestors($scopeId),
            'category' => $this->categoryAncestors($scopeId),
            default    => [],
        };
    }

    /** @return list<array{0: string, 1: int}> */
    private function videoAncestors(int $videoId): array
    {
        $row = $this->db->first('SELECT series_id FROM {videos} WHERE id = ?', [$videoId]);

        $scopes = [];
        if ($row !== null && $row['series_id'] !== null) {
            $seriesId = (int) $row['series_id'];
            $scopes[] = ['series', $seriesId];
            foreach ($this->seriesAncestors($seriesId) as $ancestor) {
                $scopes[] = $ancestor;
            }
        }

        // A video can sit in several categories; a grant on any of them counts.
        $categoryIds = $this->db->column(
            'SELECT category_id FROM {video_categories} WHERE video_id = ?',
            [$videoId]
        );
        foreach ($categoryIds as $categoryId) {
            $scopes[] = ['category', (int) $categoryId];
            foreach ($this->categoryAncestors((int) $categoryId) as $ancestor) {
                $scopes[] = $ancestor;
            }
        }

        return $this->dedupe($scopes);
    }

    /** @return list<array{0: string, 1: int}> */
    private function seriesAncestors(int $seriesId): array
    {
        $categoryId = $this->db->value('SELECT category_id FROM {series} WHERE id = ?', [$seriesId]);
        if ($categoryId === null) {
            return [];
        }

        $scopes = [['category', (int) $categoryId]];
        foreach ($this->categoryAncestors((int) $categoryId) as $ancestor) {
            $scopes[] = $ancestor;
        }

        return $this->dedupe($scopes);
    }

    /**
     * Ancestors of a category, from parent upward.
     *
     * Read from the materialized `path` column ("/1/7/22/") rather than by
     * walking parent_id one row at a time, so depth costs one query regardless
     * of how deep the tree goes.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function categoryAncestors(int $categoryId): array
    {
        $paths = $this->categoryPaths();
        $path = $paths[$categoryId] ?? null;
        if ($path === null) {
            return [];
        }

        $ids = array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== '');

        $scopes = [];
        foreach (array_reverse($ids) as $id) {
            $id = (int) $id;
            if ($id !== 0 && $id !== $categoryId) {
                $scopes[] = ['category', $id];
            }
        }

        return $scopes;
    }

    /** @return array<int, string> */
    private function categoryPaths(): array
    {
        if ($this->categoryPaths !== null) {
            return $this->categoryPaths;
        }

        $paths = [];
        foreach ($this->db->all('SELECT id, path FROM {categories}') as $row) {
            $paths[(int) $row['id']] = (string) $row['path'];
        }

        return $this->categoryPaths = $paths;
    }

    /**
     * @param list<array{0: string, 1: int}> $scopes
     * @return list<array{0: string, 1: int}>
     */
    private function dedupe(array $scopes): array
    {
        $seen = [];
        $out = [];
        foreach ($scopes as $scope) {
            $key = $scope[0] . ':' . $scope[1];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $scope;
            }
        }
        return $out;
    }

    /**
     * Drop cached state. Call after changing roles, grants, or the category
     * tree within a request, or the rest of that request answers from stale
     * data — which on a permissions screen means showing the admin the state
     * they just changed away from.
     */
    public function flush(): void
    {
        $this->memo = [];
        $this->siteWideCache = null;
        $this->categoryPaths = null;
    }
}
