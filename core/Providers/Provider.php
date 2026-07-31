<?php

declare(strict_types=1);

namespace Portal\Providers;

/**
 * Common contract for every swappable service (auth, video, mail).
 *
 * Providers are constructed with their decrypted credential array and are
 * otherwise stateless, so switching one is just "build a different object".
 */
interface Provider
{
    /** Stable identifier, stored in the database. Never change it for a shipped provider. */
    public static function slug(): string;

    /** Human name for the installer dropdown. */
    public static function label(): string;

    /** One line explaining when someone would pick this. */
    public static function description(): string;

    /**
     * The credential fields this provider needs.
     *
     * @return list<SettingField>
     */
    public static function fields(): array;

    /**
     * PHP extensions this provider requires, so the installer can warn before
     * someone picks something their host cannot run.
     *
     * @return list<string>
     */
    public static function requiredExtensions(): array;

    /** Prove the credentials actually work. Must not throw. */
    public function test(): TestResult;
}
