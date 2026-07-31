<?php

declare(strict_types=1);

namespace Portal\Providers;

/**
 * One credential/config field a provider needs.
 *
 * Providers describe their settings rather than shipping a form. The installer
 * wizard and the admin Providers screen both render from this description, so
 * adding a new provider never means writing HTML in two places — and a
 * third-party provider dropped in by a plugin gets a proper UI for free.
 */
final class SettingField
{
    public const TYPE_TEXT     = 'text';
    public const TYPE_SECRET   = 'secret';
    public const TYPE_URL      = 'url';
    public const TYPE_EMAIL    = 'email';
    public const TYPE_NUMBER   = 'number';
    public const TYPE_SELECT   = 'select';
    public const TYPE_BOOL     = 'bool';
    public const TYPE_TEXTAREA = 'textarea';

    /** @param array<string, string> $choices */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TYPE_TEXT,
        public readonly bool $required = true,
        public readonly string $help = '',
        public readonly string $placeholder = '',
        public readonly array $choices = [],
        public readonly ?string $default = null,
    ) {
    }

    /**
     * Secret fields are write-only in the UI: the stored value is never sent
     * back to the browser, only a "•••• set" indicator. Submitting the form
     * with the field left blank keeps the existing value rather than wiping it,
     * which is what stops someone from accidentally clearing an API key by
     * editing an unrelated field on the same screen.
     */
    public function isSecret(): bool
    {
        return $this->type === self::TYPE_SECRET;
    }

    public static function text(string $key, string $label, string $help = '', bool $required = true): self
    {
        return new self($key, $label, self::TYPE_TEXT, $required, $help);
    }

    public static function secret(string $key, string $label, string $help = '', bool $required = true): self
    {
        return new self($key, $label, self::TYPE_SECRET, $required, $help);
    }

    public static function url(string $key, string $label, string $help = '', bool $required = true): self
    {
        return new self($key, $label, self::TYPE_URL, $required, $help);
    }

    public static function email(string $key, string $label, string $help = '', bool $required = true): self
    {
        return new self($key, $label, self::TYPE_EMAIL, $required, $help);
    }

    public static function number(string $key, string $label, string $help = '', bool $required = true, ?string $default = null): self
    {
        return new self($key, $label, self::TYPE_NUMBER, $required, $help, default: $default);
    }

    public static function bool(string $key, string $label, string $help = '', bool $default = false): self
    {
        return new self($key, $label, self::TYPE_BOOL, false, $help, default: $default ? '1' : '0');
    }

    /** @param array<string, string> $choices */
    public static function select(string $key, string $label, array $choices, string $help = '', ?string $default = null): self
    {
        return new self($key, $label, self::TYPE_SELECT, true, $help, choices: $choices, default: $default);
    }
}
