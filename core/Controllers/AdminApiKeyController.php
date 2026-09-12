<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminApiKeyView;
use Portal\Api\ApiKeys;
use Portal\Api\Scope;
use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;

/**
 * Making and revoking keys for the read API.
 *
 * # THE NEW KEY IS RENDERED, NOT FLASHED
 *
 * Every other screen in this admin area answers a POST with a redirect and a
 * flash message. This one answers with the page itself, because the flash lives
 * in the session and sessions here are rows in MySQL — so flashing the
 * plaintext would write a live credential into the database, which is the one
 * thing the key table exists to avoid. The hash would be doing nothing if the
 * key were sitting in {sessions} alongside it, recoverable from any backup
 * taken in that window.
 *
 * The cost is real and is stated on the screen: refreshing that response asks
 * the browser to send the form again, which makes a SECOND key. That is a
 * visible, named, revocable row rather than a leak, and it grants nothing the
 * first one did not — so it is the cheaper of the two failures by a wide
 * margin.
 *
 * # SCOPES ARE CHOSEN, NEVER DEFAULTED
 *
 * There is no "all scopes" button and no preselected box. An integration that
 * needs one thing should be given one thing, and a form that starts with
 * everything ticked is a form where nobody unticks anything — which would put
 * a phone number list behind a key made for reading a video listing.
 */
final class AdminApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_API_KEYS);

        return $this->render();
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_API_KEYS);

        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create' => $this->create($request),
                'revoke' => $this->revoke($request),
                default  => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function create(Request $request): Response
    {
        $keys = $this->keys();

        /*
         * Only the boxes that were ticked, and Scope::clean() then drops
         * anything that is not one of the six. A posted scope is a string from
         * a browser like any other; issue() refuses a key left holding none
         * rather than storing an empty list, so a tampered form produces an
         * error and not a key that answers 403 to everything.
         */
        $chosen = $request->inputArray('scopes');

        $made = $keys->issue(
            (string) ($request->input('name') ?? ''),
            Scope::clean($chosen),
            (string) ($this->user()?->email ?? '')
        );

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'api_key.issue',
            'api_key',
            (string) $made['id'],
            // The scopes, in the log, because "a key was made" is not the
            // consequential fact — what it may read is.
            implode(' ', Scope::clean($chosen))
        );

        // Rendered rather than redirected. See the note on the class.
        return $this->render($made['key']);
    }

    private function revoke(Request $request): Response
    {
        $id = (int) ($request->input('id') ?? 0);

        if ($id <= 0) {
            return $this->back($request, 'Nothing was chosen.', 'error');
        }

        $this->keys()->revoke($id);

        Audit::log($this->db(), $this->user()?->email, 'api_key.revoke', 'api_key', (string) $id);

        return $this->back(
            $request,
            'Stopped. Anything using that key starts getting 401 on its next request.'
        );
    }

    private function keys(): ApiKeys
    {
        return new ApiKeys($this->db());
    }

    /**
     * @param string $made The plaintext of a key just issued, shown once.
     */
    private function render(string $made = ''): Response
    {
        $view = new AdminApiKeyView();

        return Response::html($view->render([
            'keys'     => $this->keys()->all(),
            'scopes'   => Scope::all(),
            'made'     => $made,
            'screen'   => 'api-keys',
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'baseUrl'  => rtrim((string) $this->config()->get('base_url', ''), '/'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))
            /*
             * private() is no-store, which is the one that matters: the
             * response carrying a new key has a credential in its body, and
             * neither the browser nor anything between should keep a copy.
             * X-Robots-Tag on top of it costs nothing and covers the case where
             * something authenticated is crawling this.
             */
            ->private()
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
