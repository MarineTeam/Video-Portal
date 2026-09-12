<?php

declare(strict_types=1);

namespace Portal\Api;

/**
 * What an API key is allowed to read.
 *
 * # SCOPES HAVE NO HIERARCHY
 *
 * `events:read` does not imply `events:registrations`. They share a prefix and
 * nothing else: the difference between them is a list of names, email addresses
 * and phone numbers.
 *
 * That is worth stating as loudly as possible because every instinct pulls the
 * other way. The names look nested. A `startsWith` check would feel tidy, and a
 * "read implies everything under it" rule would read as generous. Either one
 * hands somebody's phone number to an integration that asked how full an event
 * was — and nobody would notice, because it would keep working.
 *
 * So membership is EXACT. A granted scope allows precisely itself.
 *
 * # A GROUP'S ADDRESS HAS NO SCOPE AT ALL
 *
 * Not a special one, not an extra-privileged one — NONE. There is no string
 * anybody can put in a key's scope list that produces the address of the house a
 * small group meets in, because a permission that exists is a permission
 * somebody grants by mistake.
 *
 * `groups:read` gives a group's name, area and size. The address is not behind a
 * scope, it is simply absent from this interface, and Portal\Groups\GroupAddress
 * — the one function allowed to produce one — is never called from the API at
 * all.
 */
final class Scope
{
    /** Categories, series, videos and files, including drafts, with flags. */
    public const CONTENT = 'content:read';

    /** Events and how full each one is. NO NAMES. */
    public const EVENTS = 'events:read';

    /**
     * Who signed up: name, email, phone.
     *
     * Personal data, and the reason the no-hierarchy rule matters at all.
     */
    public const REGISTRATIONS = 'events:registrations';

    /** Schedules and dates, with the names on each. Personal data. */
    public const CALENDAR = 'calendar:read';

    /** Groups and their sizes. Never the address, never the members. */
    public const GROUPS = 'groups:read';

    /** Counts and totals. No individual's history. */
    public const ANALYTICS = 'analytics:read';

    /**
     * Every scope, with what it gives and whether it carries personal data.
     *
     * The `personal` flag is what the admin screen uses to mark the three that
     * hand over information about identifiable people — somebody choosing scopes
     * for an integration should be able to see which choices are consequential
     * without reading this file.
     *
     * @return array<string, array{gives: string, personal: bool}>
     */
    public static function all(): array
    {
        return [
            self::CONTENT => [
                'gives'    => 'Categories, series, videos and files — including drafts and '
                    . 'members-only ones, each flagged as such.',
                'personal' => false,
            ],
            self::EVENTS => [
                'gives'    => 'Events and how full each one is. No names.',
                'personal' => false,
            ],
            self::REGISTRATIONS => [
                'gives'    => 'Who signed up to an event, with their name, email address and '
                    . 'phone number.',
                'personal' => true,
            ],
            self::CALENDAR => [
                'gives'    => 'Schedules and their dates, with the names of the people on each.',
                'personal' => true,
            ],
            self::GROUPS => [
                'gives'    => 'Small groups, their areas and how many are in each. Never the '
                    . 'address, never who is in them.',
                'personal' => false,
            ],
            self::ANALYTICS => [
                'gives'    => 'Counts and totals. Nothing about any individual.',
                'personal' => false,
            ],
        ];
    }

    public static function exists(string $scope): bool
    {
        return isset(self::all()[$scope]);
    }

    /**
     * May a key holding these scopes read something needing that one?
     *
     * EXACT MEMBERSHIP. No prefix matching, no implication, no ordering — see
     * the class note. The two events scopes differ by a list of phone numbers
     * and they share a prefix, which is exactly the trap.
     *
     * @param list<string> $granted
     */
    public static function allows(array $granted, string $needed): bool
    {
        return in_array($needed, $granted, true);
    }

    /**
     * Clean a list somebody submitted.
     *
     * Anything unrecognised is DROPPED rather than stored. A scope string the
     * application does not know is one nothing will ever check, so keeping it
     * would put a line on the key's own screen describing a permission that does
     * not exist — and somebody would rely on it.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function clean(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : [];
        $out = [];

        foreach ($list as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $scope = trim((string) $candidate);

            if (self::exists($scope) && !in_array($scope, $out, true)) {
                $out[] = $scope;
            }
        }

        return $out;
    }

    /** Whether a set of scopes reaches any personal data. */
    public static function touchesPeople(array $granted): bool
    {
        foreach (self::all() as $scope => $about) {
            if ($about['personal'] && in_array($scope, $granted, true)) {
                return true;
            }
        }

        return false;
    }
}
