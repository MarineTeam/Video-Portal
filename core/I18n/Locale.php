<?php

declare(strict_types=1);

namespace Portal\I18n;

/**
 * Which language the INTERFACE is in.
 *
 * # THE COOKIE FIRST, THEN THE HEADER
 *
 * And the order is the rule. A cookie is a choice somebody made on this site
 * with a picker; Accept-Language is a guess their browser makes on their
 * behalf, often from an operating system they did not configure and sometimes
 * from a device they borrowed. So a stated choice beats an inferred one, always,
 * and it keeps beating it on every later visit — otherwise somebody who
 * switched to Spanish gets English back tomorrow and concludes the picker does
 * not work.
 *
 * # AND THE HEADER IS PARSED WITH ITS QUALITY WEIGHTS
 *
 * `Accept-Language: en;q=0.5, es` means SPANISH, not English. Reading the
 * header left to right — which is the obvious implementation and what every
 * naive parser does — gets that exactly backwards, and the person who gets it
 * wrong is the multilingual one whose browser is carefully expressing a
 * preference.
 *
 * This is the language of the CHROME. What language a sermon is in is a
 * separate question with a separate answer — see ContentLanguage.
 */
final class Locale
{
    /** The cookie a picker sets. */
    public const COOKIE = 'portal_locale';

    /**
     * The base locale, which every other one is measured against.
     *
     * English, and not configurable. Something has to be the catalogue every
     * translation is compared to — see Translator and the completeness test —
     * and a site-settable base would mean the comparison changed depending on
     * whose install you were looking at.
     */
    public const BASE = 'en';

    /**
     * A locale tag, made safe and canonical.
     *
     * Lower-cased language, upper-cased region, a hyphen between: `en-GB`,
     * `pt-BR`. Anything that is not a plausible tag becomes an empty string
     * rather than being passed on — this value reaches a FILE PATH in
     * Translator and an HTML lang attribute, so it is validated here once
     * rather than at each use.
     */
    public static function normalise(string $tag): string
    {
        $tag = trim(str_replace('_', '-', $tag));

        if (preg_match('/^([A-Za-z]{2,3})(?:-([A-Za-z]{2}|[0-9]{3}))?$/', $tag, $m) !== 1) {
            return '';
        }

        return isset($m[2]) && $m[2] !== ''
            ? strtolower($m[1]) . '-' . strtoupper($m[2])
            : strtolower($m[1]);
    }

    /** Just the language part: `en` from `en-GB`. */
    public static function language(string $tag): string
    {
        $tag = self::normalise($tag);

        return $tag === '' ? '' : explode('-', $tag)[0];
    }

    /**
     * The locale to use, given everything there is to go on.
     *
     * @param ?string      $cookie    what the picker set, if anything
     * @param ?string      $header    the raw Accept-Language header
     * @param list<string> $available the locales this site actually has
     * @param string       $default   the site's own setting
     */
    public static function choose(
        ?string $cookie,
        ?string $header,
        array $available,
        string $default = self::BASE
    ): string {
        $available = self::clean($available);

        if ($available === []) {
            /*
             * No catalogues at all, which is not a state a shipped install can
             * be in — but a caller who has just deleted the lang directory
             * should get English rather than an empty lang attribute.
             */
            return self::BASE;
        }

        // 1. The choice somebody made.
        $chosen = self::match((string) $cookie, $available);

        if ($chosen !== null) {
            return $chosen;
        }

        // 2. What their browser asked for, in the order IT meant.
        foreach (self::preferences((string) $header) as $tag) {
            $matched = self::match($tag, $available);

            if ($matched !== null) {
                return $matched;
            }
        }

        // 3. The site's setting, and then the base.
        return self::match($default, $available) ?? self::match(self::BASE, $available)
            ?? $available[0];
    }

    /**
     * The tags in an Accept-Language header, best first.
     *
     * THE QUALITY WEIGHTS DECIDE, not the order they were written in.
     * `en;q=0.5, es` is a request for Spanish. An absent q is 1.0 per the
     * specification, so a bare tag outranks anything weighted below it however
     * late it appears.
     *
     * Ties keep the order they were written in, which is the only sensible
     * reading of two things wanted equally — and usort in PHP is not stable
     * across versions for equal elements, so the index is part of the sort
     * rather than trusted to it.
     *
     * @return list<string>
     */
    public static function preferences(string $header): array
    {
        $header = trim($header);

        if ($header === '') {
            return [];
        }

        $weighted = [];
        $index = 0;

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', $part);
            $tag = self::normalise(trim($bits[0]));

            /*
             * `*` is legal and means "anything", which is not a preference for
             * a particular language — so it is dropped rather than matched
             * against the first available locale. Honouring it would mean a
             * browser saying "I don't mind" chose a language for somebody.
             */
            if ($tag === '') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($bits, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $m) === 1) {
                    $quality = (float) $m[1];
                }
            }

            /*
             * q=0 means "explicitly not this one" per the specification, which
             * is a refusal rather than a weak preference — so it is dropped
             * rather than sorted to the bottom, where it could still be chosen
             * if nothing else matched.
             */
            if ($quality <= 0) {
                continue;
            }

            $weighted[] = ['tag' => $tag, 'q' => $quality, 'at' => $index++];
        }

        usort(
            $weighted,
            static fn (array $a, array $b): int => $b['q'] <=> $a['q'] ?: $a['at'] <=> $b['at']
        );

        return array_values(array_map(static fn (array $row): string => $row['tag'], $weighted));
    }

    /**
     * The best available locale for one requested tag, or null.
     *
     * An exact match first, then the same language with a different region —
     * `en-AU` should get `en-GB` rather than nothing, because a British
     * interface is enormously closer to what an Australian wants than an
     * English-as-fallback decision made three steps later. A region-less
     * request matches any region of that language.
     *
     * @param list<string> $available already cleaned
     */
    public static function match(string $tag, array $available): ?string
    {
        $tag = self::normalise($tag);

        if ($tag === '' || $available === []) {
            return null;
        }

        if (in_array($tag, $available, true)) {
            return $tag;
        }

        $language = self::language($tag);

        foreach ($available as $candidate) {
            if (self::language($candidate) === $language) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private static function clean(array $tags): array
    {
        $out = [];

        foreach ($tags as $tag) {
            $tag = self::normalise((string) $tag);

            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }
}
