<?php

declare(strict_types=1);

namespace Portal\Mail;

/**
 * The outcome of one send attempt.
 *
 * `error` is stored verbatim on the share record and shown to the admin on
 * hover. Paraphrasing the provider's message would strip exactly the detail
 * that makes it actionable ("domain not verified", "invalid from address").
 */
final class SendResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function success(?string $messageId = null): self
    {
        return new self(true, $messageId);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
