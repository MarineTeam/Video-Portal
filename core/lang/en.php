<?php

/**
 * The base catalogue. Every other locale is measured against this one.
 *
 * # KEEP IT SMALL, AND KEEP IT TO THE CHROME
 *
 * The strings here are the ones that appear on every page — navigation, the
 * account area, the words on a button. NOT page copy: the long explanatory
 * paragraphs this product is full of stay in their templates, deliberately.
 *
 * Two reasons. A paragraph that explains WHY a setting is dangerous is written
 * to be read by the person who runs the site, in the language they set the site
 * up in, and translating it badly is worse than leaving it in English — a
 * mistranslated warning is a warning that no longer warns. And every string
 * moved here is a string every future locale has to carry, so a catalogue that
 * grows to a thousand entries is one nobody ever finishes translating, which
 * ends with a site half in each language.
 *
 * # THE KEYS ARE NAMESPACED BY WHERE THEY APPEAR
 *
 * `nav.`, `account.`, `action.`, `time.`. Not by meaning, because the question
 * somebody asks when a string is wrong is "where did I see it", and a key that
 * answers that can be found by grepping the page it was on.
 *
 * A key is never reused across namespaces even when the English is identical.
 * "Save" as a button and "Save" as "keep for later" are the same word in
 * English and different words in most languages, and sharing the key makes one
 * of them wrong in a way no English-speaking reviewer can see.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // ------------------------------------------------------------ navigation
    'nav.home'        => 'Home',
    'nav.library'     => 'Library',
    'nav.search'      => 'Search',
    'nav.live'        => 'Live',
    'nav.calendar'    => 'Calendar',
    'nav.events'      => 'Events',
    'nav.groups'      => 'Small groups',
    'nav.prayer'      => 'Prayer',
    'nav.books'       => 'Books',
    'nav.account'     => 'Account',
    'nav.sign_in'     => 'Sign in',
    'nav.sign_out'    => 'Sign out',
    'nav.admin'       => 'Admin',
    'nav.menu'        => 'Menu',
    'nav.skip'        => 'Skip to content',

    // ---------------------------------------------------------------- actions
    'action.save'     => 'Save',
    'action.cancel'   => 'Cancel',
    'action.send'     => 'Send',
    'action.back'     => 'Back',
    'action.next'     => 'Next',
    'action.more'     => 'More',
    'action.play'     => 'Play',
    'action.watch'    => 'Watch',
    'action.download' => 'Download',
    'action.share'    => 'Share',
    'action.remove'   => 'Remove',
    'action.sign_in'  => 'Sign in',

    // ---------------------------------------------------------- the account
    'account.title'        => 'Your account',
    'account.saved'        => 'Saved',
    'account.offline'      => 'Saved for offline',
    'account.history'      => 'Your data',
    'account.notifications' => 'Notifications',
    'account.messages'     => 'Messages',
    'account.password'     => 'Password',
    'account.television'   => 'Television',
    'account.shared_links' => 'Links you have shared',
    'account.reminders'    => 'Reminders',

    // ------------------------------------------------------------- the library
    'library.continue'     => 'Continue watching',
    'library.latest'       => 'Latest',
    'library.featured'     => 'Featured',
    'library.most_watched' => 'Most watched',
    'library.related'      => 'More like this',
    'library.series'       => 'Series',
    'library.speakers'     => 'Speakers',
    'library.nothing'      => 'Nothing here yet.',
    'library.members_only' => 'Members only',

    /*
     * A count, with the number substituted in.
     *
     * ONE STRING PER PLURAL FORM, and English gets away with two. This is where
     * a catalogue starts lying to languages that have more — Welsh has five,
     * Arabic six — so the honest thing is to say plainly that this product does
     * not do plural rules and that a translator should write something that
     * works for every count. "Videos: 1" is clumsy in English and correct in
     * every language, which is the right trade for a site with no translation
     * team.
     */
    'library.count_videos' => 'Videos: :count',

    // ------------------------------------------------------------ the language
    'language.label'  => 'Language',
    'language.choose' => 'Choose a language',

    /*
     * What language the CONTENT is in, which is a different question from what
     * language this interface is in. See ContentLanguage — a Spanish-speaking
     * visitor reading an English site, and an English-speaking visitor watching
     * a Spanish sermon, are both ordinary.
     */
    'language.spoken' => 'Spoken in :language',

    // -------------------------------------------------------------- the times
    'time.today'     => 'Today',
    'time.tomorrow'  => 'Tomorrow',
    'time.yesterday' => 'Yesterday',

    // ------------------------------------------------------------- the refusals
    'error.not_found'   => 'There is nothing at that address.',
    'error.forbidden'   => 'That is not yours to see.',
    'error.sign_in'     => 'Sign in to see this.',
    'error.members_only' => 'This is for members.',
    'error.went_wrong'  => 'Something went wrong. Try again.',
];
