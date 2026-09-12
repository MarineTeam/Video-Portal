<?php

declare(strict_types=1);

namespace Portal\Tv;

/**
 * The short code a television shows and somebody types on their phone.
 *
 * # THE ALPHABET IS THE DESIGN
 *
 * Somebody is reading this off a screen across a room — ten feet away, at an
 * angle, possibly without their glasses — and typing it on a phone. Every
 * character that can be mistaken for another is a pairing that fails for a
 * reason the person cannot see, and the failure looks like the television being
 * broken rather than like a misread letter.
 *
 * So the alphabet is Crockford's Base32: the digits, and the letters WITHOUT
 * I, L, O and U. That is a documented choice with a published rationale rather
 * than one invented here, and it does two separate things:
 *
 *   I, L and O are ABSENT FROM THE SCREEN, so the shapes that collide with 1
 *   and 0 are never shown. Somebody reading a 0 cannot be reading an O,
 *   because there are no Os.
 *
 *   U is absent as well, which is not about legibility: it is what stops a
 *   randomly generated code spelling something that gets a church a
 *   complaint.
 *
 * # AND LOOKALIKES ARE MAPPED BACK BEFORE THE LOOKUP
 *
 * The other half, and the half that is easy to leave out. Excluding O from the
 * alphabet does not stop somebody TYPING one — they read a 0 and type the
 * letter, which is the commonest thing that happens. So O becomes 0, and I and
 * L become 1, and the code is normalised before anything is compared.
 *
 * Without that mapping the alphabet alone is half a feature: the code on the
 * screen is unambiguous and the thing typed still does not match.
 */
final class PairingCode
{
    /**
     * Crockford Base32, displayed.
     *
     * 0-9 then A-Z without I, L, O, U. 32 characters, so an eight-character
     * code is 40 bits — far more than enough for a code that lives for ten
     * minutes and can be tried a handful of times.
     */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** How long a code is, before the separator. */
    public const LENGTH = 8;

    /**
     * Where the separator goes.
     *
     * Eight characters in a row is where somebody reading across a room loses
     * their place; four and four is what a person can hold in their head for
     * the two seconds it takes to look down at a phone. The separator is
     * cosmetic — normalise() throws it away — so it can be changed without
     * invalidating a code anybody is holding.
     */
    public const GROUP = 4;

    /**
     * A fresh code.
     *
     * random_bytes rather than a shuffled alphabet or mt_rand: this is a
     * credential, short-lived and short, and the one thing that makes it safe
     * to be short is that it cannot be predicted from another one.
     */
    public static function make(): string
    {
        $code = '';
        $size = strlen(self::ALPHABET);

        for ($i = 0; $i < self::LENGTH; $i++) {
            /*
             * random_int, not ord(random_bytes(1)) % 32. A modulo over 256 is
             * uniform only because 256 divides by 32 exactly — which is true
             * here and would stop being true the day somebody removes a
             * character from the alphabet, silently biasing the code.
             */
            $code .= self::ALPHABET[random_int(0, $size - 1)];
        }

        return $code;
    }

    /**
     * A code as it should be shown: grouped, and impossible to mistype invisibly.
     */
    public static function forDisplay(string $code): string
    {
        $code = self::normalise($code);

        if ($code === '') {
            return '';
        }

        return implode('-', str_split($code, self::GROUP));
    }

    /**
     * What somebody typed, turned into what to look up.
     *
     * THE LOOKALIKE MAPPING LIVES HERE, and every lookup goes through it —
     * which is the point of it being one function rather than a line in a
     * controller. A second code path that compared the raw input would work
     * perfectly in testing, where nobody misreads anything, and fail in a hall.
     *
     * Also: spaces, hyphens and case. A phone keyboard capitalises, a person
     * types the separator they can see, and autocorrect adds a trailing space.
     * None of those is the person getting it wrong.
     */
    public static function normalise(string $typed): string
    {
        $typed = strtoupper(trim($typed));

        // Anything that is not a code character goes: hyphens, spaces, the
        // stray characters a keyboard adds.
        $typed = (string) preg_replace('/[^0-9A-Z]/', '', $typed);

        /*
         * The lookalikes, mapped to what is actually in the alphabet.
         *
         * O and Q are both round; Crockford maps only O, and Q IS in the
         * alphabet — so mapping Q would make a real code unreachable. That is
         * the trap in extending this list: every addition removes a character
         * from the usable space, and if it removes one the generator can
         * produce, some codes stop working altogether.
         */
        /*
         * NOT truncated to LENGTH, which is what this did first and was wrong.
         *
         * Cutting to eight means "ABCD2345XXXX" is looked up as "ABCD2345" and
         * accepted — four characters the person typed are silently ignored, so
         * a string that is not the code matches the code. That is a small
         * broadening of what authenticates a device in exchange for nothing:
         * nobody legitimately types twelve characters, and the separators and
         * spaces that a phone genuinely adds have already been stripped above.
         *
         * Wrong length is refused by looksReal(), which is where shape belongs.
         */
        return strtr($typed, [
            'O' => '0',
            'I' => '1',
            'L' => '1',
        ]);
    }

    /**
     * Whether a normalised code could be one this site issued.
     *
     * Checked before the database is asked. A television pairing screen is
     * something people mash keys at, and none of that should become a query.
     */
    public static function looksReal(string $normalised): bool
    {
        if (strlen($normalised) !== self::LENGTH) {
            return false;
        }

        return strspn($normalised, self::ALPHABET) === self::LENGTH;
    }
}
