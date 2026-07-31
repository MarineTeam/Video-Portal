<?php

declare(strict_types=1);

namespace Portal\Install;

/**
 * The outcome of the final install step.
 *
 * Carries the cron secret out because the finish screen is the only place it
 * is ever shown — it lives in config.php and is never rendered again, so an
 * admin who wants a real cron job has to copy the URL now or read the file
 * later.
 */
final class InstallResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly ?string $detail = null,
        public readonly string $baseUrl = '',
        public readonly string $adminEmail = '',
        public readonly string $cronSecret = '',
    ) {
    }

    public static function success(string $baseUrl, string $adminEmail, string $cronSecret): self
    {
        return new self(
            ok: true,
            message: 'Installation complete.',
            baseUrl: $baseUrl,
            adminEmail: $adminEmail,
            cronSecret: $cronSecret,
        );
    }

    public static function failure(string $message, ?string $detail = null): self
    {
        return new self(ok: false, message: $message, detail: $detail);
    }

    public function cronUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/cron?key=' . $this->cronSecret;
    }
}
