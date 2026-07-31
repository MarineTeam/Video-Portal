<?php

declare(strict_types=1);

namespace Portal\Providers;

/**
 * The outcome of a provider's self-test.
 *
 * Every provider must be able to prove it works before it is made active. The
 * installer blocks Next on a failure and the admin screen refuses to switch,
 * because the alternative — discovering that email is misconfigured when the
 * first share link silently fails to send — is exactly the failure mode this
 * whole abstraction exists to prevent.
 *
 * The message is shown verbatim to an admin, so it must say what to fix.
 */
final class TestResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $detail = null,
    ) {
    }

    public static function pass(string $message = 'Connection successful.', ?string $detail = null): self
    {
        return new self(true, $message, $detail);
    }

    public static function fail(string $message, ?string $detail = null): self
    {
        return new self(false, $message, $detail);
    }

    /**
     * A test that could not run at all — usually a missing PHP extension.
     * Distinct from a failure so the UI can say "your host is missing curl"
     * rather than "your API key is wrong".
     */
    public static function unavailable(string $message): self
    {
        return new self(false, $message);
    }
}
