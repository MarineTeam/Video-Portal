<?php

declare(strict_types=1);

namespace Portal\Tests;

use PHPUnit\Framework\TestCase;
use Portal\I18n\ContentLanguage;
use Portal\I18n\Locale;
use Portal\I18n\Translator;

/**
 * Choosing a language, and the two questions that are not the same question.
 */
final class LocaleTest extends TestCase
{
    /** @var list<string> */
    private array $available = ['en', 'es'];

    // -------------------------------------------------- the cookie comes first

    /**
     * THE RULE. A choice somebody made beats a guess their browser makes.
     *
     * And it keeps beating it on every later visit — otherwise somebody who
     * switched to Spanish gets English back tomorrow and concludes the picker
     * does not work.
     */
    public function testAStatedChoiceBeatsTheBrowsersGuess(): void
    {
        self::assertSame(
            'es',
            Locale::choose('es', 'en-GB,en;q=0.9', $this->available),
            'THE HEADER OVERRODE THE PICKER — the choice somebody made was ignored'
        );

        // And the other way round, or the rule above would be "always the
        // cookie's value" for a reason that has nothing to do with precedence.
        self::assertSame('en', Locale::choose('en', 'es', $this->available));
    }

    /** A cookie for a language this site does not have is ignored, not honoured. */
    public function testACookieForALanguageWeDoNotHaveFallsThrough(): void
    {
        self::assertSame(
            'es',
            Locale::choose('fr', 'es', $this->available),
            'a stale cookie from a site that once had French blocked the header'
        );
    }

    /** And a cookie somebody tampered with cannot reach the filesystem. */
    public function testACookieCannotNameAFile(): void
    {
        foreach (['../../config', '/etc/passwd', 'en/../../x', '..', 'en;rm'] as $nasty) {
            self::assertSame(
                'en',
                Locale::choose($nasty, null, $this->available),
                $nasty
            );
        }
    }

    // ------------------------------------------- the header's quality weights

    /**
     * THE OTHER RULE. `en;q=0.5, es` is a request for SPANISH.
     *
     * Reading the header left to right is the obvious implementation and gets
     * this exactly backwards — and the person it gets wrong is the
     * multilingual one whose browser is carefully expressing a preference.
     */
    public function testTheQualityWeightsDecideAndNotTheWrittenOrder(): void
    {
        self::assertSame(
            'es',
            Locale::choose(null, 'en;q=0.5, es', $this->available),
            'THE HEADER WAS READ LEFT TO RIGHT — a weighted preference was inverted'
        );

        self::assertSame(
            ['es', 'en'],
            Locale::preferences('en;q=0.5, es'),
            'the parse itself put them in the written order'
        );
    }

    /** An absent q is 1.0, so a bare tag outranks anything weighted below it. */
    public function testABareTagOutranksAWeightedOneHoweverLateItAppears(): void
    {
        self::assertSame(
            ['es', 'en', 'pt'],
            Locale::preferences('en;q=0.8, pt;q=0.3, es')
        );
    }

    /** Things wanted equally keep the order they were written in. */
    public function testEqualWeightsKeepTheirWrittenOrder(): void
    {
        self::assertSame(
            ['en', 'es', 'pt'],
            Locale::preferences('en;q=0.5, es;q=0.5, pt;q=0.5'),
            'a tie was broken arbitrarily, which makes the answer depend on PHP\'s sort'
        );
    }

    /**
     * q=0 is a refusal, not a weak preference.
     *
     * The specification says so, and the difference matters: sorted to the
     * bottom it could still be chosen when nothing else matched, so a browser
     * explicitly saying "not English" would get English.
     */
    public function testAZeroWeightIsARefusalRatherThanALastResort(): void
    {
        self::assertSame(['es'], Locale::preferences('en;q=0, es;q=0.1'));

        self::assertSame(
            'es',
            Locale::choose(null, 'en;q=0, es', $this->available)
        );
    }

    /**
     * `*` is not a preference for anything.
     *
     * Honouring it would mean a browser saying "I don't mind" chose a language
     * for somebody — and it would choose whichever locale happened to sort
     * first, which is not a decision anybody made.
     */
    public function testAWildcardChoosesNothing(): void
    {
        self::assertSame([], Locale::preferences('*'));
        self::assertSame([], Locale::preferences('*;q=0.5'));
    }

    /** A region-less request matches a region we have, and the reverse. */
    public function testARegionIsNotRequiredToMatch(): void
    {
        self::assertSame('en-GB', Locale::choose(null, 'en', ['en-GB', 'es']));

        /*
         * `en-AU` gets `en-GB` rather than nothing. A British interface is
         * enormously closer to what an Australian wants than a fallback
         * decided three steps later, and the alternative is a site in Spanish
         * for somebody who asked for English.
         */
        self::assertSame('en-GB', Locale::choose(null, 'en-AU', ['en-GB', 'es']));
    }

    /** An exact match is preferred to a near one. */
    public function testAnExactMatchWinsOverTheSameLanguageElsewhere(): void
    {
        self::assertSame('en-US', Locale::match('en-US', ['en-GB', 'en-US', 'es']));
    }

    // ------------------------------------------------------- the last resorts

    public function testWithNothingToGoOnItUsesTheSiteSetting(): void
    {
        self::assertSame('es', Locale::choose(null, null, $this->available, 'es'));
    }

    public function testWithNothingAtAllItUsesTheBase(): void
    {
        self::assertSame(Locale::BASE, Locale::choose(null, null, $this->available));
        self::assertSame(Locale::BASE, Locale::choose(null, null, []));
    }

    /**
     * A site whose setting names a language it does not have still renders.
     *
     * The ordinary consequence of deleting a catalogue: the setting is stale,
     * and the answer has to be a locale that exists rather than one that does
     * not — otherwise every page carries a lang attribute for a catalogue
     * nothing can load.
     */
    public function testAStaleSiteSettingStillProducesALocaleThatExists(): void
    {
        self::assertSame('es', Locale::choose(null, null, ['es'], 'fr'));
    }

    // ---------------------------------------------------------- normalisation

    public function testTagsAreCanonical(): void
    {
        self::assertSame('en-GB', Locale::normalise('EN_gb'));
        self::assertSame('pt-BR', Locale::normalise(' pt-br '));
        self::assertSame('es', Locale::normalise('ES'));
        self::assertSame('en', Locale::language('en-GB'));
    }

    public function testNonsenseIsNotATag(): void
    {
        foreach (['', 'e', 'english', 'en-GBR', '../en', 'en-', '12', 'en-gb-oxford'] as $rubbish) {
            self::assertSame('', Locale::normalise($rubbish), $rubbish);
        }
    }

    // ------------------------------------------------- a missing key is never blank

    /**
     * THE THIRD RULE, at the lookup.
     *
     * A missing string falls back to English and never to an empty string — a
     * half-translated site is usable, and a blank button is a working page with
     * a hole in it that nobody reports because it does not look like an error.
     */
    public function testAMissingStringFallsBackToEnglishAndNeverToBlank(): void
    {
        $translator = new Translator('es');

        /*
         * A key that is genuinely in the base and, by the completeness test,
         * also in Spanish — so this asserts the FALLBACK path by asking for one
         * that cannot be in a translation: a key that exists nowhere.
         */
        self::assertSame(
            'nav.does_not_exist',
            $translator->get('nav.does_not_exist'),
            'A KEY IN NO CATALOGUE RENDERED BLANK — which is silence rather than a fault '
            . 'anybody can see'
        );

        self::assertSame(['nav.does_not_exist'], $translator->unknown());
    }

    /**
     * THE HEADLINE RULE OF THE CATALOGUE, against a partial locale.
     *
     * A key missing from the active locale falls back to the BASE STRING, never
     * to blank. A half-translated site shows English where Spanish is missing,
     * which is usable; a blank button is a working page with a hole in it that
     * nobody reports, because it does not look like an error.
     *
     * This needs a FIXTURE, and the reason is worth recording. The shipped
     * catalogues are complete — the completeness test enforces exactly that —
     * so no real key ever takes this path, and the test above (which asks for a
     * key in no catalogue at all) exercises the `unknown` branch instead. The
     * mutation that made this return an empty string survived the whole suite.
     *
     * A partially translated locale is the ORDINARY state of a site adding a
     * language, so the path has to be constrained even though nothing shipped
     * will take it.
     */
    public function testAKeyMissingFromATranslationFallsBackToTheBaseString(): void
    {
        $translator = new Translator('xx', __DIR__ . '/fixtures/lang');

        self::assertSame(
            'Only in the base',
            $translator->get('only.in_base'),
            'A MISSING TRANSLATION RENDERED BLANK — which is a hole in a page that looks like '
            . 'a working page, so nobody reports it'
        );

        // And the miss is recorded, which is what makes it fixable.
        self::assertSame(['only.in_base'], $translator->misses());

        // It is a MISS and not an unknown key: the string exists, the
        // translation does not, and those are different faults with different
        // owners.
        self::assertSame([], $translator->unknown());
    }

    /** A key the translation does have comes back translated, not from the base. */
    public function testAPresentTranslationIsUsed(): void
    {
        $translator = new Translator('xx', __DIR__ . '/fixtures/lang');

        self::assertSame('Traducido en ambos', $translator->get('both.translated'));
        self::assertSame([], $translator->misses(), 'a present translation was recorded as a miss');
    }

    /** And a placeholder survives the fallback path as well as the normal one. */
    public function testPlaceholdersAreFilledOnTheFallbackPath(): void
    {
        $translator = new Translator('xx', __DIR__ . '/fixtures/lang');

        self::assertSame('Hola Ana', $translator->get('with.placeholder', ['name' => 'Ana']));

        // The base string, filled, when the translation is absent.
        self::assertSame(
            'Only in the base',
            $translator->get('only.in_base', ['name' => 'ignored'])
        );
    }

    /** And a real string comes back in the active language. */
    public function testAStringComesBackInTheActiveLanguage(): void
    {
        self::assertSame('Inicio', (new Translator('es'))->get('nav.home'));
        self::assertSame('Home', (new Translator('en'))->get('nav.home'));
    }

    public function testPlaceholdersAreFilled(): void
    {
        self::assertSame(
            'Videos: 12',
            (new Translator('en'))->get('library.count_videos', ['count' => 12])
        );
    }

    // ------------------------------------- the two questions are not one question

    /**
     * THE RULE THAT MATTERS MOST.
     *
     * What language a sermon is IN is a separate question from what language
     * the interface is in, and both mixed cases are ordinary: a Spanish-reading
     * visitor watching an English sermon, and an English-reading visitor
     * watching a Spanish one.
     *
     * Asserted as an absence of coupling: nothing in ContentLanguage consults
     * the active locale, so the same video resolves the same way whatever the
     * interface is set to.
     */
    public function testTheContentLanguageDoesNotFollowTheInterface(): void
    {
        // The interface in Spanish. The video is in English and stays in
        // English, because those are different facts.
        self::assertSame(
            'en',
            ContentLanguage::resolve('en', null, 'es'),
            'THE SERMON CHANGED LANGUAGE BECAUSE SOMEBODY SWITCHED THE MENUS'
        );

        // And an English interface does not make a Spanish sermon English.
        self::assertSame('es', ContentLanguage::resolve('es', null, 'en'));
    }

    /** Video, then series, then the site. Nearest wins. */
    public function testTheNearestAnswerWins(): void
    {
        self::assertSame('es', ContentLanguage::resolve('es', 'en', 'en'));
        self::assertSame('es', ContentLanguage::resolve(null, 'es', 'en'));
        self::assertSame('es', ContentLanguage::resolve(null, null, 'es'));
    }

    /** An empty string means "nobody said", the same as null. */
    public function testBlankIsNotAnAnswer(): void
    {
        self::assertSame('en', ContentLanguage::resolve('', '  ', 'en'));
    }

    /**
     * UNKNOWN IS A REAL ANSWER, distinct from the site default.
     *
     * A library imported from a provider has no language on anything, and
     * answering the site default would assert something nobody checked — after
     * which a filter built on it would confidently exclude the wrong things.
     */
    public function testNothingAnywhereMeansNobodyHasSaid(): void
    {
        self::assertNull(
            ContentLanguage::resolve(null, null, null),
            'AN UNLABELLED LIBRARY WAS GIVEN A LANGUAGE NOBODY CHECKED'
        );
    }

    /**
     * A language filter FAILS OPEN on content nobody has labelled.
     *
     * The ordinary state of an import is unlabelled, and a filter that excluded
     * it would hide a whole catalogue from the first person who touched the
     * picker. This is a preference, not a permission — the same reasoning the
     * geo plugin's four fail-open paths carry.
     */
    public function testAnUnlabelledVideoIsNeverFilteredOut(): void
    {
        self::assertTrue(ContentLanguage::matches(null, ['es']));
        self::assertTrue(ContentLanguage::matches('', ['es']));

        // And asking for nothing matches everything.
        self::assertTrue(ContentLanguage::matches('en', []));
    }

    /** A labelled video in the wrong language IS filtered out. */
    public function testALabelledVideoInAnotherLanguageIsFilteredOut(): void
    {
        self::assertFalse(
            ContentLanguage::matches('en', ['es']),
            'the filter matched everything, which is the same as no filter'
        );

        self::assertTrue(ContentLanguage::matches('es', ['es']));
    }

    /**
     * Matched on the language, not the full tag.
     *
     * Somebody asking for Spanish wants the Mexican sermon and the Spanish one.
     * A region-exact match would make the picker look broken to anybody whose
     * browser sends a region, which is most of them.
     */
    public function testARegionDoesNotDefeatALanguageFilter(): void
    {
        self::assertTrue(ContentLanguage::matches('es-MX', ['es']));
        self::assertTrue(ContentLanguage::matches('es', ['es-ES']));
        self::assertFalse(ContentLanguage::matches('pt-BR', ['es-ES']));
    }

    // ------------------------------------------------------------- the names

    /**
     * A language is named in ITSELF, not in the current interface.
     *
     * The person who needs to find Spanish in a picker is the person who does
     * not read the language the picker is written in.
     */
    public function testALanguageIsNamedInItsOwnLanguage(): void
    {
        self::assertSame('Español', ContentLanguage::name('es'));
        self::assertSame('中文', ContentLanguage::name('zh'));
        self::assertSame('Cymraeg', ContentLanguage::name('cy'));
    }

    /** A region we have no name for falls back to the language's own name. */
    public function testAnUnnamedRegionUsesTheLanguagesName(): void
    {
        self::assertSame('Español', ContentLanguage::name('es-AR'));
    }

    /** And an unknown tag is legible rather than empty. */
    public function testAnUnknownLanguageIsStillLegible(): void
    {
        self::assertSame('xh', ContentLanguage::name('xh'));
        self::assertSame('', ContentLanguage::name('not a tag'));
    }
}
