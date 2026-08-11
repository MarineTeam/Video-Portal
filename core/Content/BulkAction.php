<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Auth\Capability;

/**
 * What a bulk action on the video library is allowed to be.
 *
 * The sharing screens have had bulk actions since Phase 2; the video library
 * never has, so a site with four hundred videos publishes them one at a time.
 * That is the gap. This is the part of closing it that is worth testing on its
 * own: which actions exist, what each one needs, and what a selection is
 * allowed to contain.
 *
 * Kept apart from the handler because the failure that matters here is not a
 * wrong query, it is a permission. An unrecognised action must not fall through
 * to a default that does something, and a bulk operation must not be reachable
 * with less permission than doing the same thing one row at a time — which is
 * the mistake worth guarding, because a bulk endpoint is where somebody
 * eventually forgets.
 */
final class BulkAction
{
    /**
     * The capability each action requires.
     *
     * Exactly the ones the single-row buttons already check. Publishing is
     * PUBLISH_CONTENT and not MANAGE_VIDEOS, deliberately: those are separate
     * permissions in this product, and an editor who may write but not publish
     * must not gain publishing by ticking two boxes instead of one.
     */
    private const ACTIONS = [
        'publish'   => Capability::PUBLISH_CONTENT,
        'unpublish' => Capability::PUBLISH_CONTENT,
        'categorise' => Capability::MANAGE_CATEGORIES,
        'trash'     => Capability::MANAGE_VIDEOS,
    ];

    /**
     * A cap on one request.
     *
     * Not a safety rule — it is a timeout rule. Each of these is a query per
     * video, and a selection of ten thousand on shared hosting hits the
     * execution limit halfway through, leaving a partial change nobody asked
     * for and no message saying what happened. Refusing is honest; a silent
     * half-completion is not.
     */
    public const MAX_PER_REQUEST = 200;

    public static function isKnown(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    /**
     * The capability this action needs, or null if there is no such action.
     *
     * Null rather than a default, so a caller that forgets to check gets a
     * TypeError rather than an action running under the wrong permission.
     */
    public static function capability(string $action): ?string
    {
        return self::ACTIONS[$action] ?? null;
    }

    /**
     * Clean a submitted selection into ids worth acting on.
     *
     * Deduplicated, because a form can post the same id twice and "12 videos
     * published" should not count one of them twice. Ordered, so the report
     * reads the way the list did. Capped, per above.
     *
     * @param mixed $raw whatever arrived in the request
     * @return list<int>
     */
    public static function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return array_slice($ids, 0, self::MAX_PER_REQUEST);
    }

    /**
     * Was the selection cut short?
     *
     * Asked separately so the screen can say so. A bulk action that quietly
     * handles the first two hundred of a thousand is one that gets run four
     * more times by somebody who cannot tell it worked.
     */
    public static function wasTruncated(mixed $raw): bool
    {
        if (!is_array($raw)) {
            return false;
        }

        $unique = [];
        foreach ($raw as $value) {
            if (is_scalar($value) && (int) $value > 0) {
                $unique[(int) $value] = true;
            }
        }

        return count($unique) > self::MAX_PER_REQUEST;
    }

    /**
     * The sentence describing what happened.
     *
     * Counts rather than a bare "Done", and it names the ones that did not
     * work. The sharing screens established this: every bulk action reports
     * per-item results rather than a single verdict, because "12 of 14" is the
     * only version somebody can act on.
     *
     * @param list<string> $failures
     */
    public static function report(string $action, int $changed, array $failures = []): string
    {
        $noun = $changed === 1 ? 'video' : 'videos';

        $verb = match ($action) {
            'publish'    => 'published',
            'unpublish'  => 'unpublished',
            'categorise' => 'added to the category',
            'trash'      => 'moved to the trash',
            default      => 'changed',
        };

        $sentence = sprintf('%d %s %s.', $changed, $noun, $verb);

        if ($failures !== []) {
            $sentence .= ' ' . count($failures) . ' could not be: ' . implode('; ', array_slice($failures, 0, 3));

            if (count($failures) > 3) {
                $sentence .= ' and ' . (count($failures) - 3) . ' more';
            }
        }

        return $sentence;
    }
}
