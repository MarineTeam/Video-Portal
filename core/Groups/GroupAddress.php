<?php

declare(strict_types=1);

namespace Portal\Groups;

/**
 * THE ONLY FUNCTION IN THIS APPLICATION THAT MAY PRODUCE A SMALL GROUP'S
 * ADDRESS.
 *
 * A group meets in somebody's living room. The address is theirs, not this
 * website's, and the people entitled to it are the ones who are actually
 * coming — nobody else, at any point, for any reason.
 *
 * # HAVING ASKED IS NOT BEING IN
 *
 * The tempting version gives the address to anybody with a request in, because
 * they are "nearly" in the group and it saves the leader a message. That means
 * ANYBODY WITH AN ACCOUNT LEARNS WHERE A LEADER LIVES BY PRESSING A BUTTON,
 * which is the whole thing being guarded against. Waiting is not being in
 * either, and a request that was declined or a membership somebody left are
 * plainly not.
 *
 * So: members and leaders. That is the list, and it is a list rather than a
 * comparison because "further along" is not a thing states are.
 *
 * # WHY IT IS SAFE TO FORGET TO CALL THIS
 *
 * GroupCard, the type every listing and page receives, has no address property
 * at all. A template that forgets has nothing to print. This function is the
 * only way an address gets anywhere near a screen, so it is the only place to
 * read, the only place to test, and the only place a mutation can break.
 */
final class GroupAddress
{
    /**
     * The address, or null.
     *
     * Null is a perfectly good answer and every caller has to handle it: a
     * group in a hired hall may have no address recorded at all.
     *
     * @param array<string, mixed> $group a {small_groups} row
     * @param string|null $state what the person asking is to this group
     */
    public static function for(array $group, ?string $state): ?string
    {
        if (!self::mayHaveIt($state)) {
            return null;
        }

        $address = trim((string) ($group['address'] ?? ''));

        return $address === '' ? null : $address;
    }

    /**
     * Whether this state is entitled to the address.
     *
     * A list, not a comparison. States are not ordered — 'waiting' is not
     * "less than" 'member' in any sense a `>=` could be trusted with — and the
     * failure of getting that wrong is a stranger holding somebody's home
     * address.
     */
    public static function mayHaveIt(?string $state): bool
    {
        return in_array($state, [GroupRepository::MEMBER, GroupRepository::LEADING], true);
    }
}
