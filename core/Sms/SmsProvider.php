<?php

declare(strict_types=1);

namespace Portal\Sms;

use Portal\Providers\Provider;

/**
 * Contract for sending a text.
 *
 * Shaped like MailProvider on purpose, including the part that matters: send()
 * NEVER THROWS. It returns a result carrying the gateway's own words.
 *
 * A broadcast is a loop over hundreds of rows, and an exception on row forty
 * ends the run at whoever happens to be fortieth. One bad number has to fail
 * one row, be recorded with a reason somebody can act on, and leave the rest of
 * the send to carry on — which is only possible if failure is a return value.
 *
 * Two providers ship. That is not padding: an interface with one implementation
 * is a guess about what varies, and the second one is what proves the shape is
 * right rather than a description of the first.
 */
interface SmsProvider extends Provider
{
    /**
     * @param string $to E.164, already parsed — see Portal\Broadcast\PhoneNumber.
     *        Providers do not guess at national numbers; that decision belongs
     *        in one place and it is not here.
     */
    public function send(string $to, string $message): SmsResult;

    /** True when this provider has enough configuration to attempt a send. */
    public function isConfigured(): bool;

    /** The number or sender name texts will appear to come from. */
    public function from(): string;
}
