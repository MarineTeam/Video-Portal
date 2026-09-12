<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Http\Request;
use Portal\Http\Response;
use Portal\I18n\Locale;
use Portal\I18n\Translator;

/**
 * Choosing the language the interface is in.
 *
 * # NO CSRF TOKEN, AND THE REASON IS WRITTEN IN THREE PLACES
 *
 * Here, at the form in the header, and on the route. A token protects an action
 * that borrows the victim's authority; choosing a display language borrows
 * none — nothing is stored about them, nothing is sent, no permission changes,
 * and the picker in front of them undoes it in one press.
 *
 * The cost of requiring one is concrete and this project has already paid it
 * once: a token field in shared view data started a session and set a cookie
 * for every anonymous visitor to every public page, because generating a token
 * means having somewhere to keep it. The picker is in the header of every page,
 * which makes it the worst place in the product to do that again.
 *
 * # AND IT IS A COOKIE, NOT A COLUMN
 *
 * Not `users.locale`, even for somebody signed in. Two reasons, and the first
 * is the one that decides it: most visitors have no account, and a preference
 * that only worked for members would be missing for exactly the people most
 * likely to need it — somebody arriving at a sermon from a search engine who
 * cannot read the menus.
 *
 * The second is that a language is a property of the DEVICE at least as much as
 * of the person. A shared family tablet in the kitchen and a phone are
 * routinely set differently by the same household, and an account-level setting
 * would have one of them fighting the other.
 */
final class LocaleController extends Controller
{
    /**
     * How long the choice lasts.
     *
     * A year, which is "until they change it" in practice. A session cookie
     * would mean re-choosing every time the browser restarted, which reads as
     * the picker not working — and this project has the same note on the
     * schedules reminder preferences: a setting that silently reverts is worse
     * than one that was never offered.
     */
    private const REMEMBER_SECONDS = 31536000;

    public function set(Request $request): Response
    {
        $translator = $this->translator();

        /*
         * MATCHED AGAINST WHAT THIS SITE ACTUALLY HAS, not merely validated as
         * a tag. Otherwise a posted `zz` would be stored, every later request
         * would fail to load a catalogue, and the site would render in English
         * with a lang attribute claiming otherwise — which is worse than
         * ignoring the input, because a screen reader would believe it.
         */
        $wanted = Locale::match(
            (string) ($request->input('locale') ?? ''),
            $translator->available()
        );

        /*
         * The return path, sanitised. It comes from a form on whatever page the
         * picker was on, so it is attacker-influenced in the ordinary way, and
         * Request::sanitizeReturnTo is what the rest of the product uses for
         * exactly this.
         */
        $response = $this->back($request);

        if ($wanted === null) {
            /*
             * A language this site does not have: nothing is set and nothing is
             * said. There is no honest message — the only way to get here is a
             * hand-made request or a stale page, and a visitor who genuinely
             * chose from the picker cannot reach it.
             */
            return $response;
        }

        return $response->cookie(Locale::COOKIE, $wanted, [
            'expires'  => time() + self::REMEMBER_SECONDS,
            /*
             * Not httponly.
             *
             * Deliberate, and the opposite of what every other cookie in this
             * product does. A display preference is something a theme's script
             * may legitimately want to read — to pick a date format, or to
             * label something the server did not render — and there is nothing
             * in it to steal: it holds a language tag from a list of two.
             *
             * `samesite` stays Lax, so it is still not sent on a cross-site
             * POST.
             */
            'httponly' => false,
        ]);
    }

    /**
     * The interface language as a machine can read it.
     *
     * For a script that needs to know before it renders anything — and for
     * whoever is debugging why a page came out in the wrong language, which is
     * a question with four possible answers (a cookie, a header, a site
     * setting, the base) and no way to tell them apart from the outside.
     */
    public function current(Request $request): Response
    {
        $translator = $this->translator();

        return Response::json([
            'locale'    => $translator->locale(),
            'available' => $translator->available(),
            'chosen_by' => $this->explainChoice($request, $translator),
        ])->private();
    }

    /**
     * Which of the four inputs decided, said out loud.
     *
     * Re-derived rather than recorded, which is a real limitation and is worth
     * naming: this asks the same questions in the same order and reports the
     * first that answers, so it agrees with Locale::choose() because it
     * repeats it rather than because it observed it. If the two ever disagree
     * this will confidently report the wrong reason — which is why it is a
     * diagnostic and not something anything depends on.
     */
    private function explainChoice(Request $request, Translator $translator): string
    {
        $available = $translator->available();

        if (Locale::match((string) $request->cookie(Locale::COOKIE), $available) !== null) {
            return 'the language you chose';
        }

        foreach (Locale::preferences((string) $request->header('accept-language')) as $tag) {
            if (Locale::match($tag, $available) !== null) {
                return 'your browser\'s preference';
            }
        }

        $default = (string) ($this->config()->setting('site_locale', Locale::BASE) ?? Locale::BASE);

        return Locale::match($default, $available) !== null
            ? 'this site\'s setting'
            : 'the fallback';
    }
}
