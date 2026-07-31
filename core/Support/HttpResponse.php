<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * An outbound HTTP response.
 *
 * `status === 0` means the request never completed (DNS, TLS, timeout) and
 * `transportError` explains why. That is a genuinely different situation from
 * a 4xx, and provider self-tests report it differently: "your host cannot
 * reach bunny.net" is a hosting problem, "401" is a credentials problem.
 */
final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly ?string $transportError = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    public function transportFailed(): bool
    {
        return $this->status === 0;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * A short, human-readable reason suitable for showing an admin.
     *
     * APIs disagree about where the message lives, so we look in the three
     * common places before falling back to a truncated body.
     */
    public function errorMessage(): string
    {
        if ($this->transportError !== null && $this->transportError !== '') {
            return $this->transportError;
        }

        $json = $this->json();
        foreach (['message', 'Message', 'error', 'error_description', 'title'] as $key) {
            if (isset($json[$key])) {
                if (is_string($json[$key])) {
                    return $json[$key];
                }
                // Resend nests: {"error": {"message": "..."}}
                if (is_array($json[$key]) && isset($json[$key]['message']) && is_string($json[$key]['message'])) {
                    return $json[$key]['message'];
                }
            }
        }

        $body = trim($this->body);
        if ($body === '') {
            return "HTTP {$this->status}";
        }

        return "HTTP {$this->status}: " . Str::truncate($body, 200);
    }
}
