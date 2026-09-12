<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\I18n\Locale;
use Portal\I18n\Translator;

/**
 * THIS TEST IS THE BUILD FAILING.
 *
 * The requirement is that a missing interface string cannot render blank. A
 * typed catalogue in a compiled language gets that from the compiler; PHP has
 * no compile step, so this file is the compiler. It walks every locale the
 * install ships against the base catalogue and fails on any key that is
 * missing, extra, or empty.
 *
 * Run on every commit, which is what makes it equivalent: the point of a build
 * failure is not that it happens at a particular moment but that nobody can
 * ship past it.
 */
final class LocaleCompletenessTest extends TestCase
{
    private function translator(): Translator
    {
        return new Translator(Locale::BASE);
    }

    /**
     * The premise, asserted first.
     *
     * With only one catalogue every check below passes trivially — the base
     * locale always has every key of the base locale. So a site that shipped
     * `en.php` alone would have a completeness test that could never fail, and
     * would not know it.
     *
     * This project has caught that shape of vacuous pass seven times in other
     * places; here it is asserted away up front.
     */
    public function testThereIsMoreThanOneLocaleToCompare(): void
    {
        $available = $this->translator()->available();

        self::assertContains(Locale::BASE, $available, 'the base catalogue is missing');

        self::assertGreaterThan(
            1,
            count($available),
            'ONLY ONE LOCALE SHIPS, so every check in this file passes for free. '
            . 'The completeness test is measuring nothing.'
        );
    }

    /** THE RULE. Every locale has every key of the base locale. */
    public function testEveryLocaleHasEveryKeyOfTheBase(): void
    {
        $translator = $this->translator();
        $base = $translator->catalogue(Locale::BASE);

        self::assertNotSame([], $base, 'the base catalogue is empty');

        foreach ($translator->available() as $locale) {
            if ($locale === Locale::BASE) {
                continue;
            }

            $missing = array_values(array_diff(
                array_keys($base),
                array_keys($translator->catalogue($locale))
            ));

            self::assertSame(
                [],
                $missing,
                "The {$locale} catalogue is missing keys, which would render as English in "
                . "the middle of a {$locale} page:\n  " . implode("\n  ", $missing)
            );
        }
    }

    /**
     * And no locale has keys the base does not.
     *
     * A key that exists only in a translation is a string nothing renders —
     * either a typo in the key, or a string somebody translated after it was
     * removed from the base. Both are silent: the page looks right, and the
     * work was wasted. This is the half of the rule people leave out, because
     * an extra key breaks nothing.
     */
    public function testNoLocaleHasKeysTheBaseDoesNot(): void
    {
        $translator = $this->translator();
        $base = array_keys($translator->catalogue(Locale::BASE));

        foreach ($translator->available() as $locale) {
            if ($locale === Locale::BASE) {
                continue;
            }

            $extra = array_values(array_diff(
                array_keys($translator->catalogue($locale)),
                $base
            ));

            self::assertSame(
                [],
                $extra,
                "The {$locale} catalogue has keys nothing renders — a typo in the key, or a "
                . "string translated after it was removed:\n  " . implode("\n  ", $extra)
            );
        }
    }

    /**
     * A blank translation is worse than a missing one.
     *
     * A missing key falls back to English and the page is usable; a key present
     * and empty renders the blank button this whole mechanism exists to
     * prevent, and it does it while satisfying every completeness check that
     * only compares key names.
     */
    public function testNoStringIsEmpty(): void
    {
        $translator = $this->translator();

        foreach ($translator->available() as $locale) {
            foreach ($translator->catalogue($locale) as $key => $value) {
                self::assertNotSame(
                    '',
                    trim($value),
                    "{$locale}.{$key} IS BLANK — it passes a key-name comparison and renders "
                    . 'an empty button, which is exactly the failure this catalogue prevents'
                );
            }
        }
    }

    /**
     * Every placeholder in the base survives translation.
     *
     * `Spoken in :language` translated without its `:language` renders a
     * sentence with the fact missing from it — grammatical, plausible, and
     * wrong. And a translation that INVENTS a placeholder renders a literal
     * `:count` on the page, because nothing will ever pass it.
     */
    public function testEveryPlaceholderSurvivesTranslation(): void
    {
        $translator = $this->translator();
        $base = $translator->catalogue(Locale::BASE);

        $placeholders = static function (string $string): array {
            preg_match_all('/:[a-z_]+/', $string, $m);
            $found = array_unique($m[0]);
            sort($found);

            return $found;
        };

        foreach ($translator->available() as $locale) {
            if ($locale === Locale::BASE) {
                continue;
            }

            foreach ($translator->catalogue($locale) as $key => $value) {
                if (!isset($base[$key])) {
                    continue;
                }

                self::assertSame(
                    $placeholders($base[$key]),
                    $placeholders($value),
                    "{$locale}.{$key} does not carry the same placeholders as the base string. "
                    . 'A missing one renders a sentence with the fact left out of it; an '
                    . 'invented one renders the placeholder itself.'
                );
            }
        }
    }

    /**
     * Every catalogue filename is a locale tag this product can actually use.
     *
     * The filename becomes a locale, which becomes an HTML lang attribute and
     * a file path. A catalogue called `english.php` would be silently ignored —
     * present on disk, absent from available(), and its translations never
     * rendered, with nothing anywhere saying why.
     */
    public function testEveryCatalogueFilenameIsAUsableLocaleTag(): void
    {
        $directory = $this->translator()->directory();
        $files = glob($directory . '/*.php') ?: [];

        self::assertNotSame([], $files, 'no catalogues were found at all');

        foreach ($files as $path) {
            $name = basename($path, '.php');

            self::assertNotSame(
                '',
                Locale::normalise($name),
                "{$name}.php is not a locale tag, so it is on disk and will never be loaded"
            );
        }
    }

    /**
     * A key is never reused across namespaces for the same English.
     *
     * Not a strict rule this can enforce — it is a habit — so what is asserted
     * is the one case that matters and that a reviewer cannot see: two keys
     * whose English is identical must be genuinely different keys rather than
     * one shared between two places. "Save" as a button and "Save" as "keep for
     * later" are one word in English and two in most languages, and sharing the
     * key makes one of them wrong invisibly.
     *
     * This asserts the shape rather than the absence: duplicated English is
     * ALLOWED, and it is allowed precisely because the keys are separate.
     */
    public function testDuplicatedEnglishStillHasSeparateKeys(): void
    {
        $base = $this->translator()->catalogue(Locale::BASE);

        $byString = [];

        foreach ($base as $key => $value) {
            $byString[$value][] = $key;
        }

        $shared = array_filter($byString, static fn (array $keys): bool => count($keys) > 1);

        // There IS duplicated English in the catalogue — "Sign in" appears as
        // both navigation and an action — so this check is not vacuous.
        self::assertNotSame([], $shared, 'no duplicated English, so this proves nothing');

        foreach ($shared as $string => $keys) {
            $namespaces = array_map(
                static fn (string $key): string => explode('.', $key)[0],
                $keys
            );

            self::assertSame(
                count($namespaces),
                count(array_unique($namespaces)),
                "\"{$string}\" is under two keys in the SAME namespace (" . implode(', ', $keys)
                . '), which is a duplicate rather than a deliberate distinction'
            );
        }
    }
}
