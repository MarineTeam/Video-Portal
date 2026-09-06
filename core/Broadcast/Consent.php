<?php

declare(strict_types=1);

namespace Portal\Broadcast;

/**
 * Whether this person may be reached on this channel.
 *
 * # THREE RULES, DELIBERATELY NOT ONE
 *
 * The tempting design is a single "wants announcements" flag that governs all
 * three channels. It is wrong, because the channels are not equivalent:
 *
 *   EMAIL goes UNLESS the person turned announcements off. Opt-out. An email
 *   costs nothing, arrives in a place built for things arriving, and a church
 *   that cannot email its own members about a service time is not usable.
 *
 *   SMS needs an EXPLICIT OPT-IN and a number this can actually parse. Opt-in,
 *   both halves required. A text costs the church money and the recipient their
 *   attention, it arrives on the lock screen at whatever hour it is sent, and
 *   in most places sending one without consent is illegal. "They gave us their
 *   number" is not consent to be texted; it is a way to be rung.
 *
 *   PUSH needs a REGISTERED DEVICE. Not a preference at all — a browser
 *   subscription is consent, given to the browser, and its absence is not
 *   something a setting can override. There is nowhere to send to.
 *
 * Folding these into one flag means either texting people who never agreed, or
 * refusing to email people who never objected. Both are wrong and only one of
 * them is illegal.
 *
 * Pure: a person's stored preferences in, a verdict out. The reach preview and
 * the sender both call this, so the number somebody is shown before pressing
 * send is produced by the code that decides afterwards.
 */
final class Consent
{
    public const EMAIL = 'email';
    public const SMS   = 'sms';
    public const PUSH  = 'push';

    /** @return list<string> */
    public static function channels(): array
    {
        return [self::EMAIL, self::SMS, self::PUSH];
    }

    /**
     * May this person be sent this?
     *
     * @param array<string, mixed> $person
     *        email, email_opt_out, sms_opt_in, phone, push_devices
     * @param string $defaultCountry for reading a national phone number
     */
    public static function allows(string $channel, array $person, string $defaultCountry = ''): bool
    {
        return match ($channel) {
            self::EMAIL => self::email($person),
            self::SMS   => self::sms($person, $defaultCountry),
            self::PUSH  => self::push($person),
            // A channel this does not know about is not one anybody consented
            // to. Refusing is the only safe answer to a question this cannot
            // understand.
            default     => false,
        };
    }

    /**
     * OPT-OUT. Everybody with an address, unless they said not to.
     *
     * @param array<string, mixed> $person
     */
    private static function email(array $person): bool
    {
        $address = trim((string) ($person['email'] ?? ''));

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return empty($person['email_opt_out']);
    }

    /**
     * OPT-IN, AND a number that can be dialled. Both, always.
     *
     * Either half alone is a mistake with a bill attached: an opt-in with an
     * unreadable number is a message the gateway charges for and nobody
     * receives, and a readable number without an opt-in is a text somebody
     * never agreed to.
     *
     * @param array<string, mixed> $person
     */
    private static function sms(array $person, string $defaultCountry): bool
    {
        if (empty($person['sms_opt_in'])) {
            return false;
        }

        return PhoneNumber::isSendable((string) ($person['phone'] ?? ''), $defaultCountry);
    }

    /**
     * A REGISTERED DEVICE. Not a preference — there is nowhere to send to.
     *
     * @param array<string, mixed> $person
     */
    private static function push(array $person): bool
    {
        return (int) ($person['push_devices'] ?? 0) > 0;
    }

    /**
     * Why somebody is not being reached, in words for a preview screen.
     *
     * Named rather than counted as one lump, because the three have different
     * answers: an opt-out is somebody's decision and nothing to do about it, a
     * missing opt-in is worth asking for, and an unreadable number is a typo
     * somebody can fix.
     *
     * @param array<string, mixed> $person
     */
    public static function why(string $channel, array $person, string $defaultCountry = ''): string
    {
        if (self::allows($channel, $person, $defaultCountry)) {
            return '';
        }

        return match ($channel) {
            self::EMAIL => trim((string) ($person['email'] ?? '')) === ''
                ? 'no email address'
                : 'turned announcements off',

            self::SMS => empty($person['sms_opt_in'])
                ? 'has not opted in to texts'
                : 'phone number cannot be read',

            self::PUSH => 'no device registered',

            default => 'unknown channel',
        };
    }
}
