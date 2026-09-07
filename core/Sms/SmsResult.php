<?php

declare(strict_types=1);

namespace Portal\Sms;

/**
 * What happened to one text.
 *
 * A value rather than an exception, for the reason on SmsProvider: a broadcast
 * is a loop, and one bad number must fail one row rather than ending the run.
 */
final class SmsResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function success(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    /**
     * The gateway's own words, not a category.
     *
     * "Connection lost" for everything is how an afternoon gets spent looking
     * in the wrong place — this project has already paid for that once, on the
     * upload path.
     */
    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }
}
