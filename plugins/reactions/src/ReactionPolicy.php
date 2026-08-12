<?php

declare(strict_types=1);

namespace Portal\Plugins\Reactions;

/**
 * What a reaction is, and what it is not.
 *
 * Ratings and reactions were one line in the plan — "ratings/reactions" — and
 * shipping only ratings was the right order, because building this second makes
 * the difference between them obvious:
 *
 *   A RATING is a judgement. How good was this, out of five. One per person,
 *   because a second answer replaces the first, and the useful output is an
 *   average that has to be defended against a single five-star vote.
 *
 *   A REACTION is a response. It has no scale, nothing to average, and no
 *   better or worse. "This moved me" and "I am praying about this" are not
 *   points on one line — they are different things to say, and somebody may
 *   truthfully say both.
 *
 * So a person may leave SEVERAL reactions on one video, one of each kind. That
 * is the whole design decision, and it is why this is not a second rating
 * system with pictures.
 */
final class ReactionPolicy
{
    /**
     * The vocabulary, fixed.
     *
     * Not free-form emoji, deliberately. Tags are free-form because a library
     * genuinely needs labels nobody anticipated; reactions are not that — an
     * open set gives one video 👍 and another 👍🏽 and a third 👍️ with a
     * variation selector, three counts that are one feeling, and no way to
     * merge them. A fixed set also means the buttons can be labelled in words,
     * which is what makes them legible to a screen reader.
     *
     * Chosen for a church media library, which is what this product is for.
     * A site wanting different ones edits this list; that is a code change on
     * purpose, because changing the vocabulary rewrites what past reactions
     * meant.
     *
     * @var array<string, array{emoji: string, label: string}>
     */
    private const KINDS = [
        'amen'      => ['emoji' => '🙏', 'label' => 'Amen'],
        'moved'     => ['emoji' => '❤️', 'label' => 'This moved me'],
        'helpful'   => ['emoji' => '💡', 'label' => 'Helpful'],
        'thankful'  => ['emoji' => '🙌', 'label' => 'Thankful'],
    ];

    /**
     * The most reactions one person may leave on one video.
     *
     * Equal to the vocabulary, so it is not really a limit — it is a statement
     * that the unique key does the work. Written down because "several per
     * person" invites the question and the answer should not have to be counted
     * out of an array by hand.
     */
    public static function maxPerPerson(): int
    {
        return count(self::KINDS);
    }

    /** @return array<string, array{emoji: string, label: string}> */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    public static function isKind(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }

    public static function label(string $kind): string
    {
        return self::KINDS[$kind]['label'] ?? $kind;
    }

    public static function emoji(string $kind): string
    {
        return self::KINDS[$kind]['emoji'] ?? '';
    }

    /**
     * The counts for a video, in vocabulary order and including zeroes.
     *
     * Zeroes matter. A reaction with no count still has to render as a button
     * somebody can press, and ordering by count would make the buttons move
     * around under the cursor as other people react — which is how you get
     * somebody pressing "Amen" and hitting "Helpful".
     *
     * @param  array<string, int> $counts as stored, sparse and unordered
     * @return array<string, int> every kind, in order
     */
    public static function fill(array $counts): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $out[$kind] = max(0, (int) ($counts[$kind] ?? 0));
        }

        return $out;
    }

    /**
     * Is this worth showing at all?
     *
     * A row of four zeroes under every video on a quiet site is noise, and it
     * tells a visitor the site is empty rather than that the feature is new.
     * The buttons still appear for somebody who may react — they are the only
     * person who can change the answer.
     */
    public static function worthShowing(array $counts, bool $viewerMayReact): bool
    {
        return $viewerMayReact || array_sum($counts) > 0;
    }
}
