<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Support\Str;

/**
 * Free-form tags across videos, series, and categories.
 *
 * The `{tags}` and `{taggables}` tables have been in the schema since Phase 1
 * and no code has ever touched them — the plan listed tags in the content model
 * and the tables were created, which made the gap invisible: the schema said the
 * feature existed. Found by auditing columns against the code, the same way an
 * unenforced capability was found by auditing capabilities against their checks.
 *
 * A tag is NOT a category, and the difference decides the whole design:
 *
 *   A category is a PLACE. There is one right answer, an editor picks it from a
 *   list somebody curated, and it nests.
 *   A tag is a LABEL. There are many, they are typed rather than chosen, they
 *   are created by being used, and they do not nest.
 *
 * So this creates on write and never asks anyone to manage a vocabulary first.
 * The cost is that "prayer" and "Prayer" and "prayers" become three tags, which
 * is why the slug is the identity and the display name is only the first
 * spelling seen.
 */
final class TagRepository
{
    /**
     * Types `{taggables}.taggable_type` accepts.
     *
     * Whitelisted rather than passed through: the value reaches an ENUM, and a
     * mismatch is a silently-dropped row rather than an error.
     */
    public const TYPES = ['video', 'series', 'category'];

    /**
     * The most tags one thing may carry.
     *
     * Not a database limit — a usability one. Twenty labels on a video is not
     * tagging, it is somebody pasting a transcript into the box, and the result
     * is a tag list nobody can browse. Extra ones are dropped rather than
     * refused, because losing the twenty-first tag is a smaller harm than
     * refusing to save the edit that carried it.
     */
    public const MAX_PER_ITEM = 20;

    /** Longest a single tag may be, matching {tags}.name. */
    public const MAX_LENGTH = 120;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- parsing

    /**
     * Turn what somebody typed into a clean list of names.
     *
     * Comma-separated, because that is what every tag field in the world uses
     * and a person who has met one elsewhere already knows the rule.
     *
     * @return list<string>
     */
    public static function parse(string $input): array
    {
        $names = [];

        foreach (explode(',', $input) as $raw) {
            $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

            if ($name === '') {
                continue;
            }

            // Counted in characters, not bytes. A twelve-character tag written
            // in Japanese is well inside the column; strlen would call it 36.
            if (mb_strlen($name) > self::MAX_LENGTH) {
                $name = mb_substr($name, 0, self::MAX_LENGTH);
            }

            if (!self::isSluggable($name)) {
                continue;
            }

            $slug = Str::slug($name);

            // Keyed by slug so "Prayer" and "prayer " in one submission
            // collapse to one, keeping the first spelling as the display name.
            $names[$slug] ??= $name;
        }

        return array_values(array_slice($names, 0, self::MAX_PER_ITEM));
    }

    // -------------------------------------------------------------- reading

    /**
     * Does this name contain anything a slug can be built from?
     *
     * Asked HERE rather than by checking whether Str::slug() returned empty,
     * which is what the first version did and which never fired. Str::slug is
     * built for content, where a slug must always come back usable, so it falls
     * back to `item-<random>` rather than to nothing. That made the guard dead
     * code — and worse: "!!!" typed twice would have produced two different
     * slugs, so the same input became two tags, each linking to a page with
     * half the content and neither name readable in a URL.
     *
     * Letters or numbers in ANY script, so a tag written in Japanese or Arabic
     * passes while one made only of punctuation or emoji does not.
     */
    private static function isSluggable(string $name): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $name) === 1;
    }

    /** @return list<Tag> */
    public function all(): array
    {
        $rows = $this->db->all('SELECT id, slug, name FROM {tags} ORDER BY name');

        return array_map(static fn (array $row): Tag => Tag::fromRow($row), $rows);
    }

    public function findBySlug(string $slug): ?Tag
    {
        $row = $this->db->first('SELECT id, slug, name FROM {tags} WHERE slug = ?', [$slug]);

        return $row === null ? null : Tag::fromRow($row);
    }

    /**
     * The tags on one thing, in display order.
     *
     * @return list<Tag>
     */
    public function forItem(string $type, int $id): array
    {
        if (!in_array($type, self::TYPES, true)) {
            return [];
        }

        $rows = $this->db->all(
            'SELECT t.id, t.slug, t.name
               FROM {tags} t
               JOIN {taggables} tg ON tg.tag_id = t.id
              WHERE tg.taggable_type = ? AND tg.taggable_id = ?
              ORDER BY t.name',
            [$type, $id]
        );

        return array_map(static fn (array $row): Tag => Tag::fromRow($row), $rows);
    }

    /**
     * Tags for many things at once.
     *
     * One query for a whole page. The per-item version inside a loop is the
     * mistake the batched thumbnail modes and comment counts already exist to
     * avoid, and a listing is exactly where somebody reaches for it.
     *
     * @param  list<int> $ids
     * @return array<int, list<Tag>> item id => tags, omitting items with none
     */
    public function forItems(string $type, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === [] || !in_array($type, self::TYPES, true)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->all(
            "SELECT tg.taggable_id, t.id, t.slug, t.name
               FROM {tags} t
               JOIN {taggables} tg ON tg.tag_id = t.id
              WHERE tg.taggable_type = ? AND tg.taggable_id IN ({$placeholders})
              ORDER BY t.name",
            [$type, ...$ids]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['taggable_id']][] = Tag::fromRow($row);
        }

        return $out;
    }

    /**
     * Every tag in use, with how many things carry it.
     *
     * For the admin screen and for a tag cloud. Tags with no items are excluded
     * by the join rather than by a count column — a stored count is right until
     * the first thing that goes wrong and then wrong forever, which is the rule
     * the ratings cache follows too.
     *
     * @return list<array{tag: Tag, uses: int}>
     */
    public function withCounts(): array
    {
        $rows = $this->db->all(
            'SELECT t.id, t.slug, t.name, COUNT(tg.tag_id) AS uses
               FROM {tags} t
               JOIN {taggables} tg ON tg.tag_id = t.id
              GROUP BY t.id, t.slug, t.name
              ORDER BY uses DESC, t.name'
        );

        return array_map(
            static fn (array $row): array => ['tag' => Tag::fromRow($row), 'uses' => (int) $row['uses']],
            $rows
        );
    }

    // -------------------------------------------------------------- writing

    /**
     * Replace everything tagged on one item.
     *
     * Replace rather than merge, because this backs a text field: what is in
     * the box IS the answer, and a person who deletes a word expects it gone.
     * That is the opposite of the bulk "add to category" button, which appends
     * — the difference being that a bulk action names only what to add, while a
     * form shows the whole current state.
     *
     * @param list<string> $names already through parse()
     */
    public function setFor(string $type, int $id, array $names): void
    {
        if (!in_array($type, self::TYPES, true)) {
            return;
        }

        $ids = [];
        foreach ($names as $name) {
            $ids[] = $this->idFor($name);
        }
        $ids = array_values(array_unique(array_filter($ids)));

        $this->db->execute(
            'DELETE FROM {taggables} WHERE taggable_type = ? AND taggable_id = ?',
            [$type, $id]
        );

        foreach ($ids as $tagId) {
            // IGNORE because the primary key is (tag_id, type, id): a duplicate
            // in the submitted list is a no-op rather than an error.
            $this->db->execute(
                'INSERT IGNORE INTO {taggables} (tag_id, taggable_type, taggable_id) VALUES (?, ?, ?)',
                [$tagId, $type, $id]
            );
        }
    }

    /**
     * Delete tags nothing carries any more.
     *
     * Called after a save, so removing the last use of a tag removes the tag.
     * Without this the vocabulary only ever grows, and the admin screen fills
     * with labels that match nothing — which is worse than useless, because
     * each one is a link to an empty page.
     */
    public function pruneUnused(): int
    {
        return $this->db->execute(
            'DELETE t FROM {tags} t
               LEFT JOIN {taggables} tg ON tg.tag_id = t.id
              WHERE tg.tag_id IS NULL'
        );
    }

    public function rename(int $id, string $name): bool
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            return false;
        }

        $slug = Str::slug(mb_substr($name, 0, self::MAX_LENGTH));
        if ($slug === '') {
            return false;
        }

        /*
         * Renaming onto an existing slug MERGES rather than failing on the
         * unique key. Two spellings of one idea is the normal reason to rename
         * a tag at all, and "that name is taken" would leave somebody with no
         * way to combine them.
         */
        $existing = $this->findBySlug($slug);
        if ($existing !== null && $existing->id !== $id) {
            $this->db->execute(
                'UPDATE IGNORE {taggables} SET tag_id = ? WHERE tag_id = ?',
                [$existing->id, $id]
            );
            // Rows that collided with one the target already had are left
            // behind by UPDATE IGNORE, so they are removed rather than orphaned.
            $this->db->execute('DELETE FROM {taggables} WHERE tag_id = ?', [$id]);
            $this->db->execute('DELETE FROM {tags} WHERE id = ?', [$id]);

            return true;
        }

        return $this->db->execute(
            'UPDATE {tags} SET slug = ?, name = ? WHERE id = ?',
            [$slug, mb_substr($name, 0, self::MAX_LENGTH), $id]
        ) > 0;
    }

    /** Removing a tag unfiles everything it was on; the content is untouched. */
    public function delete(int $id): void
    {
        // {taggables} has ON DELETE CASCADE against {tags}, so this is one
        // statement — but the join rows are deleted explicitly anyway, because
        // whether the constraint exists depends on when the schema was created.
        $this->db->execute('DELETE FROM {taggables} WHERE tag_id = ?', [$id]);
        $this->db->execute('DELETE FROM {tags} WHERE id = ?', [$id]);
    }

    /**
     * The id for a name, creating the tag if this is its first use.
     *
     * INSERT IGNORE then SELECT, rather than SELECT then INSERT: two requests
     * saving the same new tag at the same moment would both find nothing and
     * both insert, and one would fail on the unique key. The constraint decides,
     * which is the same discipline as the fire-once guards elsewhere.
     */
    private function idFor(string $name): ?int
    {
        // Same rule as parse(), because setFor() is public and a caller may not
        // have gone through it.
        if (!self::isSluggable($name)) {
            return null;
        }

        $slug = Str::slug($name);

        $this->db->execute(
            'INSERT IGNORE INTO {tags} (slug, name) VALUES (?, ?)',
            [$slug, mb_substr($name, 0, self::MAX_LENGTH)]
        );

        $id = $this->db->value('SELECT id FROM {tags} WHERE slug = ?', [$slug]);

        return $id === null ? null : (int) $id;
    }
}
