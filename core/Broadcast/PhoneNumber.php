<?php

declare(strict_types=1);

namespace Portal\Broadcast;

/**
 * Reading a phone number well enough to send a text to it.
 *
 * # WHY THIS IS STRICT WHERE THE FORM FIELD IS LOOSE
 *
 * A connect card asks for a phone number so a person can ring it, and there a
 * strict pattern refuses a real number somebody typed correctly — the cost is
 * a card nobody can send. Here the number is handed to a gateway that charges
 * per attempt, so an unparseable one is a message that costs money and reaches
 * nobody, and there is no human to notice the typo.
 *
 * So: a number this cannot turn into E.164 is NOT SENT TO, and the reach
 * preview says how many of those there are rather than quietly dropping them.
 *
 * # WHY NOT A LIBRARY
 *
 * libphonenumber is the right answer for a product that texts the world. It is
 * also several megabytes of metadata that would have to be committed to the
 * release branch and updated by cutting a whole release, for a product whose
 * users are one church in one country. This does the part that matters — is
 * this a number, and which country is it in — and refuses everything it is not
 * sure about, which is the safe direction when the alternative is billing
 * somebody for a message to nowhere.
 */
final class PhoneNumber
{
    /**
     * Turn what somebody typed into E.164, or null.
     *
     * @param string $defaultCountry the dialling code to assume for a national
     *        number, e.g. "44". Configured per site, because "07700 900123" is
     *        only a number at all if you know where it is.
     */
    public static function e164(string $raw, string $defaultCountry = ''): ?string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        /*
         * Letters are refused rather than stripped. "Ring the office on 01234"
         * would otherwise become a number, and a note like "07700 900123 (not
         * before 6)" would become one with the 6 on the end — a different
         * phone, belonging to somebody else.
         */
        if (preg_match('/[a-z]/i', $trimmed) === 1) {
            return null;
        }

        $international = str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00');

        // Everything a person writes for legibility, and nothing else.
        $digits = (string) preg_replace('/[^0-9]/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($trimmed, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }

        if (!$international) {
            $country = (string) preg_replace('/[^0-9]/', '', $defaultCountry);

            if ($country === '') {
                // A national number with no country to read it in is not a
                // number. Guessing produces a real phone belonging to somebody
                // else, in a country nobody meant.
                return null;
            }

            // The trunk zero is national notation and is dropped when the
            // country code goes on the front. "07700 900123" in +44 is
            // +447700900123, never +44 07700900123.
            $digits = $country . ltrim($digits, '0');
        }

        /*
         * E.164 allows fifteen digits and needs at least a country code and
         * something to dial. The lower bound is what refuses a short code, an
         * extension somebody typed on its own, and half a number.
         */
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+' . $digits;
    }

    /** Whether a text could be sent to this at all. */
    public static function isSendable(string $raw, string $defaultCountry = ''): bool
    {
        return self::e164($raw, $defaultCountry) !== null;
    }
}
