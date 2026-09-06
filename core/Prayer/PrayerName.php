<?php

declare(strict_types=1);

namespace Portal\Prayer;

/**
 * THE ONLY FUNCTION IN THIS APPLICATION THAT MAY PRODUCE A NAME FOR A PRAYER
 * REQUEST.
 *
 * That is not a stylistic preference. Anonymity on a prayer wall is a promise
 * made to somebody at the worst week of their life, and a promise that holds
 * everywhere except one forgotten template is not a promise. So there is one
 * place to read, one place to test, and one place a mutation can break — and
 * PrayerRequest, the type every screen receives, carries no user id and no raw
 * requester name at all, so a page that forgot has nothing to print.
 *
 * # IT ANSWERS THE SAME THING TO MODERATORS
 *
 * There is no $isModerator argument and there will not be one. "Anonymous
 * except to the people who run the church" is the version of this that people
 * assume they are getting and are not, and it is worse than no anonymity
 * because they act on the belief.
 *
 * The cost is stated on the moderation screen rather than hidden: an anonymous
 * request cannot be followed up and its author cannot be blocked. That is the
 * trade, made on purpose.
 */
final class PrayerName
{
    /** What an anonymous request is called, everywhere, to everybody. */
    public const ANONYMOUS = 'Anonymous';

    /**
     * @param array<string, mixed> $row a {prayer_requests} row
     */
    public static function for(array $row): string
    {
        if (!empty($row['is_anonymous'])) {
            return self::ANONYMOUS;
        }

        $given = trim((string) ($row['requester_name'] ?? ''));

        /*
         * A request that is not marked anonymous but carries no name is still
         * shown as Anonymous rather than as an empty space. Somebody who left
         * the box blank did not choose to be identified either, and a nameless
         * gap on a wall invites people to guess.
         */
        return $given === '' ? self::ANONYMOUS : mb_substr($given, 0, 120);
    }
}
