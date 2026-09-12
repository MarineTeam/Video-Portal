<?php

declare(strict_types=1);

namespace Portal\I18n;

/**
 * What language a sermon is IN.
 *
 * # THIS IS NOT THE INTERFACE LANGUAGE, AND CONFLATING THEM IS THE BUG
 *
 * They look like one question and they are two, and both of the mixed cases are
 * ordinary rather than exotic:
 *
 *   Somebody who reads the site in Spanish, watching an English sermon. A
 *   congregation with a Spanish-speaking membership and an English-speaking
 *   preacher is a normal church.
 *
 *   Somebody who reads the site in English, watching a Spanish sermon. The
 *   Spanish service at the same church, listed on the same site.
 *
 * So there is no code path here that reads the active locale, and none in
 * Locale that reads a video. A single "language" setting doing both jobs would
 * mean switching the interface to Spanish filtered the library to Spanish
 * sermons — hiding content from the people most likely to be looking for it,
 * and doing it silently.
 *
 * # VIDEO, THEN SERIES, THEN THE SITE
 *
 * The same precedence shape as watermark_mode and thumbnail_mode, which is
 * deliberate: this product already has an inheritance rule and a second one of
 * a different shape would be a second thing to learn. Nearest wins, and an
 * absent value means "ask the next one up" rather than "none".
 *
 * UNKNOWN IS A REAL ANSWER, distinct from the site default. A library imported
 * from a provider has no language on anything, and defaulting all of it to the
 * site's language would be asserting something nobody checked — then a filter
 * built on it would confidently exclude the wrong things. So resolve() answers
 * null when nothing anywhere says, and the caller decides whether to show it.
 */
final class ContentLanguage
{
    /**
     * What language this video is in, or null if nothing says.
     *
     * @param ?string $onVideo  the video's own language, if it has one
     * @param ?string $onSeries its series' language, if it has one
     * @param ?string $siteWide the site's setting, if it has one
     */
    public static function resolve(
        ?string $onVideo,
        ?string $onSeries = null,
        ?string $siteWide = null
    ): ?string {
        foreach ([$onVideo, $onSeries, $siteWide] as $candidate) {
            $tag = Locale::normalise((string) $candidate);

            if ($tag !== '') {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Whether this video is in a language somebody would want.
     *
     * `null` for the video's language — nothing said — answers TRUE, which is
     * the important half. An unlabelled library is the ordinary state of an
     * import, and a filter that excluded everything unlabelled would hide a
     * whole catalogue from the first person who touched the language picker.
     * Fails OPEN, deliberately, in the way the geo plugin does: this is a
     * preference, not a permission.
     *
     * @param list<string> $wanted the languages somebody asked for
     */
    public static function matches(?string $language, array $wanted): bool
    {
        if ($wanted === []) {
            return true;
        }

        if ($language === null || Locale::normalise($language) === '') {
            return true;
        }

        $language = Locale::language($language);

        foreach ($wanted as $tag) {
            /*
             * Compared on the LANGUAGE, not the full tag. Somebody asking for
             * Spanish wants the Mexican sermon and the Spanish one; a
             * region-exact match would make the picker look broken to anybody
             * whose browser sends a region — which is most of them.
             */
            if (Locale::language((string) $tag) === $language) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name of a language, in that language.
     *
     * Endonyms — "Español", not "Spanish" — because this appears in a picker,
     * and the person who needs to find Spanish in a list is the person who does
     * not read the language the list is written in. A picker labelled in the
     * current interface language is useless to exactly the person using it.
     *
     * A small table rather than `Locale::getDisplayRegion()` from intl: the
     * extension is not guaranteed on the hosts this product targets, and a
     * language picker that vanishes when an extension is missing is worse than
     * a short list. An unknown tag comes back as the tag itself, which is
     * legible enough to act on.
     */
    public static function name(string $tag): string
    {
        $tag = Locale::normalise($tag);

        if ($tag === '') {
            return '';
        }

        $names = [
            'en'    => 'English',
            'en-GB' => 'English (UK)',
            'en-US' => 'English (US)',
            'es'    => 'Español',
            'es-ES' => 'Español (España)',
            'es-MX' => 'Español (México)',
            'pt'    => 'Português',
            'pt-BR' => 'Português (Brasil)',
            'fr'    => 'Français',
            'de'    => 'Deutsch',
            'it'    => 'Italiano',
            'nl'    => 'Nederlands',
            'pl'    => 'Polski',
            'ro'    => 'Română',
            'cy'    => 'Cymraeg',
            'gd'    => 'Gàidhlig',
            'ga'    => 'Gaeilge',
            'zh'    => '中文',
            'ko'    => '한국어',
            'ta'    => 'தமிழ்',
            'ur'    => 'اردو',
            'ar'    => 'العربية',
            'fa'    => 'فارسی',
            'ru'    => 'Русский',
            'uk'    => 'Українська',
            'sw'    => 'Kiswahili',
            'am'    => 'አማርኛ',
            'tl'    => 'Tagalog',
            'hi'    => 'हिन्दी',
        ];

        if (isset($names[$tag])) {
            return $names[$tag];
        }

        // A region we have no specific name for falls back to the language's
        // own name — `es-AR` reads as "Español", which is right rather than
        // merely tolerable.
        $language = Locale::language($tag);

        return $names[$language] ?? $tag;
    }
}
