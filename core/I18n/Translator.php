<?php

declare(strict_types=1);

namespace Portal\I18n;

/**
 * Interface strings, in whatever language the interface is in.
 *
 * # "A MISSING KEY FAILS THE BUILD" — IN A LANGUAGE WITH NO BUILD
 *
 * The requirement is that a missing key cannot render blank. A typed catalogue
 * in a compiled language gets that for free; PHP has no compile step, so it is
 * arranged here in three parts, and all three are needed:
 *
 *   1. A MISSING KEY FALLS BACK TO THE BASE LOCALE, never to an empty string.
 *      A half-translated site shows English where Spanish is missing, which is
 *      usable. A blank button is not, and is the failure this is really about —
 *      blank renders as a working page with a hole in it, and nobody reports it
 *      because it does not look like an error.
 *
 *   2. A key MISSING FROM THE BASE TOO returns the key itself. Ugly on purpose:
 *      `account.saved_offline` on a screen is obviously wrong, gets reported,
 *      and is trivially greppable. An empty string is silence.
 *
 *   3. EVERY MISS IS RECORDED, and a test asserts the recording is empty after
 *      walking every locale against the base. That test is this product's
 *      version of the build failing — see LocaleCompletenessTest.
 *
 * # AND IT IS NOT A DATABASE TABLE
 *
 * Catalogues are PHP files returning arrays, loaded once per request and cached
 * by opcache like any other file. A `translations` table would mean a query on
 * every page render for values that change when somebody deploys, and it would
 * put the interface of the site behind the availability of the database — so a
 * site with a database problem would show a page of blank labels instead of an
 * error anybody could act on.
 */
final class Translator
{
    /** @var array<string, array<string, string>> loaded catalogues by locale */
    private array $catalogues = [];

    /** @var list<string> keys asked for and not found in the active locale */
    private array $misses = [];

    /** @var list<string> keys not found in the base locale either */
    private array $unknown = [];

    public function __construct(
        private readonly string $locale = Locale::BASE,
        private readonly string $directory = '',
    ) {
    }

    /**
     * Where the catalogues live.
     *
     * A method rather than a constant so a test can point somewhere else, and
     * so a plugin could one day add a directory without this class knowing how
     * plugins work.
     */
    public function directory(): string
    {
        return $this->directory !== ''
            ? $this->directory
            : dirname(__DIR__) . '/lang';
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Every locale this install has a catalogue for.
     *
     * Read from the filesystem rather than from a list, so adding `de.php`
     * makes German available without also editing a registry — two places to
     * declare a locale is one place to forget.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $found = [];

        foreach (glob($this->directory() . '/*.php') ?: [] as $path) {
            $tag = Locale::normalise(basename($path, '.php'));

            if ($tag !== '') {
                $found[] = $tag;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * A string, in the active locale.
     *
     * @param array<string, string|int> $replacements `:name` in the string
     */
    public function get(string $key, array $replacements = []): string
    {
        $active = $this->catalogue($this->locale);

        if (isset($active[$key])) {
            return self::fill($active[$key], $replacements);
        }

        /*
         * Recorded before the fallback, so the test can see it. A miss is not
         * an error at runtime — the page renders in English and the visitor is
         * fine — but it IS a failure of the catalogue, and the only way that
         * gets fixed is if something notices.
         */
        if ($this->locale !== Locale::BASE) {
            $this->misses[] = $key;
        }

        $base = $this->catalogue(Locale::BASE);

        if (isset($base[$key])) {
            return self::fill($base[$key], $replacements);
        }

        /*
         * Not in the base either, so nobody ever wrote this string. The key
         * comes back, which is deliberately ugly — see the note on the class.
         */
        $this->unknown[] = $key;

        return $key;
    }

    /**
     * Keys that were missing from the active locale.
     *
     * @return list<string>
     */
    public function misses(): array
    {
        return array_values(array_unique($this->misses));
    }

    /**
     * Keys that are in no catalogue at all.
     *
     * Separate from misses() because they are different faults with different
     * owners: a miss is a translation nobody has done yet, and an unknown key
     * is a developer referring to a string that does not exist.
     *
     * @return list<string>
     */
    public function unknown(): array
    {
        return array_values(array_unique($this->unknown));
    }

    /**
     * One locale's catalogue.
     *
     * @return array<string, string>
     */
    public function catalogue(string $locale): array
    {
        $locale = Locale::normalise($locale);

        if ($locale === '') {
            return [];
        }

        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }

        /*
         * The path is built from a NORMALISED tag, which is why normalise()
         * refuses anything that is not a plausible language tag: this value
         * becomes a filename, and a locale of `../../config` would otherwise
         * be a file read. The regex there is the guard, and it is the reason
         * this method does not need one of its own.
         */
        $path = $this->directory() . '/' . $locale . '.php';

        if (!is_file($path)) {
            return $this->catalogues[$locale] = [];
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        if (!is_array($loaded)) {
            /*
             * A catalogue that is not an array is a broken file, and the honest
             * answer is an empty catalogue plus the base-locale fallback rather
             * than a fatal. A site does not deserve a white page because
             * somebody's Spanish file has a syntax slip in it.
             */
            error_log("Portal: the {$locale} catalogue did not return an array.");

            return $this->catalogues[$locale] = [];
        }

        $strings = [];

        foreach ($loaded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $strings[$key] = $value;
            }
        }

        return $this->catalogues[$locale] = $strings;
    }

    /** @param array<string, string|int> $replacements */
    private static function fill(string $string, array $replacements): string
    {
        if ($replacements === []) {
            return $string;
        }

        $find = [];
        $put = [];

        foreach ($replacements as $name => $value) {
            $find[] = ':' . $name;
            $put[] = (string) $value;
        }

        return str_replace($find, $put, $string);
    }
}
