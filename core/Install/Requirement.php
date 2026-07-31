<?php

declare(strict_types=1);

namespace Portal\Install;

/**
 * One environment check, with the fix attached.
 *
 * The `fix` text is the whole point. "ext-pdo_mysql: missing" tells someone
 * nothing they can act on; "Enable pdo_mysql in your host's PHP Selector" tells
 * them where to click. Shared hosting users generally cannot install
 * extensions, but they can almost always toggle them in cPanel.
 */
final class Requirement
{
    public const LEVEL_REQUIRED    = 'required';
    public const LEVEL_RECOMMENDED = 'recommended';

    public function __construct(
        public readonly string $label,
        public readonly bool $satisfied,
        public readonly string $level = self::LEVEL_REQUIRED,
        public readonly string $detail = '',
        public readonly string $fix = '',
    ) {
    }

    /** Blocks the install entirely. */
    public function isBlocking(): bool
    {
        return !$this->satisfied && $this->level === self::LEVEL_REQUIRED;
    }

    /** Works, but something will be degraded. */
    public function isWarning(): bool
    {
        return !$this->satisfied && $this->level === self::LEVEL_RECOMMENDED;
    }
}
