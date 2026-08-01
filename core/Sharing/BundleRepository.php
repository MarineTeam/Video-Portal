<?php

declare(strict_types=1);

namespace Portal\Sharing;

use DateTimeImmutable;
use DateTimeZone;
use Portal\Db;
use Portal\Support\Str;
use Throwable;

/**
 * Bundles: one page per recipient listing everything shared with them.
 *
 * Two rules shape this class.
 *
 * ONE BUNDLE PER RECIPIENT, enforced by a unique index rather than by checking
 * first. Two shares created in the same instant would both find no bundle and
 * both create one; the constraint makes that impossible rather than unlikely.
 *
 * IDS ONLY. Item titles and live/dead status are read from the shares on every
 * render, so revoking or extending a share shows up on the bundle page
 * immediately with no write to the bundle. Caching a title here would
 * eventually display a video that had been revoked.
 */
final class BundleRepository
{
    /**
     * How many live shares before someone gets a bundle.
     *
     * One share is better served by a direct link — sending someone to an
     * index page containing a single row is worse than just sending the row.
     */
    private const THRESHOLD = 2;

    public function __construct(
        private readonly Db $db,
        private readonly ShareRepository $shares,
    ) {
    }

    // ------------------------------------------------------------------ reads

    public function find(string $id): ?Bundle
    {
        if (!Bundle::isValidId($id)) {
            return null;
        }

        $row = $this->db->first('SELECT * FROM {bundles} WHERE id = ?', [$id]);

        return $row === null ? null : Bundle::fromRow($row);
    }

    /**
     * The bundle for one recipient, found by index rather than by scanning.
     */
    public function forRecipient(string $email): ?Bundle
    {
        $row = $this->db->first(
            'SELECT * FROM {bundles} WHERE recipient_email = ?',
            [Str::normalizeEmail($email)]
        );

        return $row === null ? null : Bundle::fromRow($row);
    }

    /**
     * The shares a bundle currently points at that are still usable.
     *
     * Re-read every time. A share revoked a second ago disappears from here
     * with no bundle write, which is the entire justification for storing ids
     * rather than a rendered list.
     *
     * Deliberately does NOT prune the ids it filters out. An earlier version
     * removed them as tidying, which quietly broke Restore: revoking a share
     * dropped its id from the bundle, so restoring it never brought it back.
     * Membership and usability are different questions, and only the second
     * one is decided here.
     *
     * Nothing needs pruning anyway — bundle_items cascades on share deletion,
     * so a permanently deleted share removes its own row.
     *
     * @return list<Share>
     */
    public function liveItems(string $bundleId): array
    {
        $ids = $this->itemIds($bundleId);

        if ($ids === []) {
            return [];
        }

        $shares = $this->shares->findMany($ids);

        $live = [];
        foreach ($ids as $id) {
            $share = $shares[$id] ?? null;

            if ($share !== null && $share->isLive()) {
                $live[] = $share;
            }
        }

        // Soonest to expire first: the ones needing attention are at the top.
        usort($live, static fn (Share $a, Share $b): int => $a->expiresAt <=> $b->expiresAt);

        return $live;
    }

    /** @return list<string> */
    public function itemIds(string $bundleId): array
    {
        if (!Bundle::isValidId($bundleId)) {
            return [];
        }

        return array_map('strval', $this->db->column(
            'SELECT share_id FROM {bundle_items} WHERE bundle_id = ? ORDER BY added_at',
            [$bundleId]
        ));
    }

    // --------------------------------------------------------------- writing

    /**
     * Make sure a recipient has a bundle if they have earned one.
     *
     * Called after creating shares. Below the threshold this does nothing and
     * the recipient keeps getting direct links.
     *
     * Crossing the threshold sweeps in every live share they already have, not
     * just the new one — otherwise their first bundle would list two of the
     * five videos they can actually watch, which reads as a bug.
     */
    public function ensureFor(string $email, string $accessMode = Share::MODE_ACCOUNT): ?Bundle
    {
        $email = Str::normalizeEmail($email);
        $live = $this->shares->liveForRecipient($email);

        $existing = $this->forRecipient($email);

        if ($existing === null && count($live) < self::THRESHOLD) {
            return null;
        }

        $bundle = $existing ?? $this->create($email, $accessMode);

        if ($bundle === null) {
            return null;
        }

        $this->add($bundle->id, array_map(static fn (Share $s): string => $s->id, $live));
        $this->extendToCover($bundle->id, $live);

        return $this->find($bundle->id);
    }

    /**
     * Create a bundle, tolerating a concurrent creation.
     *
     * The unique index on recipient_email is what actually prevents duplicates.
     * A duplicate-key error here is not a failure — it means someone else won
     * the race, and their bundle is exactly as good as the one we wanted.
     */
    private function create(string $email, string $accessMode): ?Bundle
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            $this->db->insert('bundles', [
                'id'              => Bundle::newId(),
                'recipient_email' => $email,
                'access_mode'     => $accessMode,
                'created_at'      => $now->format('Y-m-d H:i:s'),
                'expires_at'      => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Lost the race, or something else went wrong; either way the
            // lookup below settles it.
        }

        return $this->forRecipient($email);
    }

    /**
     * Point a bundle at some shares.
     *
     * @param list<string> $shareIds
     */
    public function add(string $bundleId, array $shareIds): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (array_unique($shareIds) as $shareId) {
            if (!Share::isValidId($shareId)) {
                continue;
            }

            try {
                // IGNORE rather than checking first: the primary key already
                // says an id appears once, so let it enforce that.
                $this->db->execute(
                    'INSERT IGNORE INTO {bundle_items} (bundle_id, share_id, added_at) VALUES (?, ?, ?)',
                    [$bundleId, $shareId, $now]
                );

                $this->db->execute(
                    'UPDATE {shares} SET bundle_id = ? WHERE id = ?',
                    [$bundleId, $shareId]
                );
            } catch (Throwable $e) {
                error_log('Portal: could not add a share to a bundle: ' . $e->getMessage());
            }
        }
    }

    /** @param list<string> $shareIds */
    public function forget(string $bundleId, array $shareIds): void
    {
        if ($shareIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($shareIds), '?'));

        try {
            $this->db->execute(
                "DELETE FROM {bundle_items} WHERE bundle_id = ? AND share_id IN ({$placeholders})",
                [$bundleId, ...$shareIds]
            );
        } catch (Throwable $e) {
            error_log('Portal: could not prune a bundle: ' . $e->getMessage());
        }
    }

    /**
     * Make sure the bundle outlives everything in it.
     *
     * Only ever extends. Shortening would mean a recipient's page stops working
     * while links it lists are still valid — the page must never be the thing
     * that expires first.
     *
     * @param list<Share> $shares
     */
    private function extendToCover(string $bundleId, array $shares): void
    {
        if ($shares === []) {
            return;
        }

        $latest = null;
        foreach ($shares as $share) {
            if ($latest === null || $share->expiresAt > $latest) {
                $latest = $share->expiresAt;
            }
        }

        if ($latest === null) {
            return;
        }

        $this->db->execute(
            'UPDATE {bundles} SET expires_at = GREATEST(expires_at, ?) WHERE id = ?',
            [$latest->format('Y-m-d H:i:s'), $bundleId]
        );
    }

    /**
     * Called after a share is extended, so its bundle keeps covering it.
     */
    public function refresh(string $bundleId): void
    {
        $live = $this->liveItems($bundleId);
        $this->extendToCover($bundleId, $live);
    }

    // ---------------------------------------------------------------- cleanup

    /**
     * Remove bundles with nothing left to show.
     *
     * A bundle whose every share has died is a page that says "nothing here",
     * which is worse than a link that plainly does not resolve.
     */
    public function purgeEmpty(): int
    {
        try {
            $removed = 0;

            $rows = $this->db->all('SELECT id FROM {bundles}');

            foreach ($rows as $row) {
                $id = (string) $row['id'];

                if ($this->liveItems($id) === []) {
                    $this->db->execute('DELETE FROM {bundles} WHERE id = ?', [$id]);
                    $removed++;
                }
            }

            return $removed;
        } catch (Throwable $e) {
            error_log('Portal: bundle cleanup failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Every bundle, for the admin screen's copy-link list.
     *
     * @return list<array{bundle: Bundle, liveCount: int}>
     */
    public function listForAdmin(): array
    {
        $out = [];

        foreach ($this->db->all('SELECT * FROM {bundles} ORDER BY recipient_email') as $row) {
            $bundle = Bundle::fromRow($row);
            $out[] = ['bundle' => $bundle, 'liveCount' => count($this->liveItems($bundle->id))];
        }

        return $out;
    }
}
