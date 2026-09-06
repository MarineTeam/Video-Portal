<?php

declare(strict_types=1);

namespace Portal\Support;

use RuntimeException;

/**
 * One list of key names that must never appear in anything this app hands out.
 *
 * Built before the exits that need it — the read API, calendar feeds, the
 * remaining exports — so that none of them has to have it retrofitted, and so
 * the answer to "is this payload safe" is the same everywhere rather than
 * whatever each endpoint's author remembered.
 *
 * IT THROWS. IT DOES NOT STRIP.
 *
 * That is the whole design and it is worth being explicit about, because
 * stripping is the instinct: it fails softly, the endpoint keeps working, and
 * nobody is woken up. But a forbidden key arriving here means a QUERY started
 * selecting something it should not — somebody wrote `SELECT *` where a column
 * list used to be, or a new column landed on a table an export already walks.
 * Quietly removing it fixes the symptom and hides the cause until the next
 * column arrives, and the next one may not be on this list.
 *
 * A caller seeing a 500 is recoverable in an afternoon. A leaked push key pair,
 * password hash or feed token is not recoverable at all: it is copied, cached
 * and forwarded before anybody notices.
 *
 * WHY STAFF NAMES ARE ON A LIST OF SECRETS
 *
 * They are not secrets in the cryptographic sense, and they are here anyway.
 * `added_by` and `actor_email` record which member of staff made a decision
 * ABOUT a person — approved them, suspended them, excused them a check. A
 * member-facing export carrying that turns an administrative record into a
 * personal one about somebody who never asked to be named in it, and an export
 * gets emailed to a laptop and forwarded onwards.
 *
 * An admin screen legitimately shows these, which is why admin screens render
 * HTML and do not go through here. If an admin endpoint ever does need to hand
 * one out as data, the throw is the conversation about whether it should —
 * which is the point.
 */
final class SecretGuard
{
    /**
     * Key names, lowercased, that may never leave.
     *
     * Read off the schema rather than imagined: every one of these is a real
     * column or setting in this application.
     *
     * @var list<string>
     */
    public const FORBIDDEN = [
        // Credentials and hashes.
        'password_hash',
        'credentials',
        'key_hash',
        'api_key',
        'secret',
        'app_key',
        'cron_secret',
        'gate_secret',
        'signin_registration_secret',

        /*
         * The push key pair, and the endpoint with it. The endpoint is not a
         * name — it is a capability URL, and anybody holding it plus the keys
         * can push to that device.
         */
        'p256dh',
        'auth_secret',
        'endpoint',

        /*
         * Anything that authenticates by being known. `token` covers the
         * unsubscribe token and the calendar-feed token that has yet to be
         * built — which is the point of writing this before that exists.
         */
        'token',
        'token_hash',
        'feed_token',
        'session_token',
        'verifier',

        // Staff names attached to decisions taken about a member. See above.
        'added_by',
        'actor_email',
        'reviewed_by',
    ];

    /**
     * Walk a finished payload and throw if anything forbidden is in it.
     *
     * Arrays included, at any depth, because nearly everything this app hands
     * out is a list of rows — a guard that only looked at the top level would
     * pass every export it was written for.
     *
     * $context names the exit, so the error says which one rather than leaving
     * somebody to find it. The offending PATH is included and the offending
     * VALUE never is: an exception message reaches a log, and a log is one of
     * the places a leaked secret ends up.
     *
     * @param mixed $payload
     */
    public static function assertClean(mixed $payload, string $context = 'payload'): void
    {
        self::walk($payload, $context, 0);
    }

    /**
     * True when the payload is clean. For a test or a caller that genuinely
     * wants to ask rather than assert — never for an exit, which must throw.
     */
    public static function isClean(mixed $payload): bool
    {
        try {
            self::assertClean($payload);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private static function walk(mixed $node, string $path, int $depth): void
    {
        /*
         * A depth stop, not a correctness rule. Nothing this app hands out
         * nests anywhere near this far, so reaching it means a cycle or a
         * structure nobody intended — and recursing forever inside a guard
         * would take the site down in the name of protecting it.
         */
        if ($depth > 32) {
            throw new RuntimeException(sprintf(
                'SecretGuard: %s nests more than 32 deep, which is not a shape anything here hands out.',
                $path
            ));
        }

        if ($node instanceof \JsonSerializable) {
            self::walk($node->jsonSerialize(), $path, $depth + 1);

            return;
        }

        // Objects are walked as their public properties, because that is what
        // json_encode will do with them a moment later.
        if (is_object($node)) {
            self::walk(get_object_vars($node), $path, $depth + 1);

            return;
        }

        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN, true)) {
                throw new RuntimeException(sprintf(
                    'SecretGuard: refused to hand out "%s" at %s.%s — a query is selecting '
                    . 'something it should not. Fix the query rather than this list.',
                    $key,
                    $path,
                    $key
                ));
            }

            self::walk($value, $path . '.' . (is_string($key) ? $key : '[]'), $depth + 1);
        }
    }
}
