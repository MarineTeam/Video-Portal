<?php

declare(strict_types=1);

namespace Portal\Mail;

use Portal\Providers\Provider;

/**
 * Contract for sending transactional email.
 *
 * Every message this app sends is transactional and consequential — a share
 * link, a magic-link sign-in. So `send()` never throws: it returns a SendResult
 * carrying the provider's own error text. A failed send must not lose the
 * share it was announcing; the admin sees why it failed, fixes the config, and
 * presses Resend.
 */
interface MailProvider extends Provider
{
    /**
     * @param array<string, mixed> $options replyTo, fromName, headers
     */
    public function send(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $options = []
    ): SendResult;

    /** True when this provider has enough configuration to attempt a send. */
    public function isConfigured(): bool;

    /** The From address messages will actually carry. */
    public function fromAddress(): string;
}
