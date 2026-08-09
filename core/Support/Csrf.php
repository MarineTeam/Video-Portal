<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Auth\Session;
use Portal\Http\HttpException;
use Portal\Http\Request;

/**
 * The cross-site request forgery token, in one place.
 *
 * Extracted from Controller when the first plugin needed to render a form on
 * the public site. Two implementations of this would be one implementation and
 * one subtly different one — and the subtly different one is the plugin's,
 * written by somebody who does not have the rest of this codebase in their
 * head. A shared helper makes the correct version the only version.
 *
 * The token is derived from the session rather than stored separately, so it
 * cannot drift out of sync with the session it protects and needs no cleanup.
 */
final class Csrf
{
    public static function token(Session $session): string
    {
        $token = $session->get('csrf');

        if (!is_string($token) || $token === '') {
            $token = Crypto::token(16);
            $session->put('csrf', $token);
        }

        return $token;
    }

    /**
     * Reject a state-changing request that did not come from our own form.
     *
     * Without this, a page on another site can make a signed-in visitor's
     * browser perform actions as them simply by being visited.
     */
    public static function verify(Session $session, Request $request): void
    {
        $submitted = (string) ($request->post['_token'] ?? $request->header('x-csrf-token') ?? '');

        if ($submitted === '' || !Crypto::verify(self::token($session), $submitted)) {
            throw new HttpException(419, 'This form has expired. Reload the page and try again.');
        }
    }

    /** The hidden field, ready to drop into a form. */
    public static function field(Session $session): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token($session)) . '">';
    }
}
