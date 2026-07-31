<?php

declare(strict_types=1);

namespace Portal\Install;

/**
 * Checks whether this server can actually run the application.
 *
 * Runs before anything else in the installer, because the alternative is
 * someone completing five wizard steps and then hitting a fatal error on the
 * first real page load, with no idea which of their answers was wrong.
 *
 * The distinction between required and recommended matters: a missing gd
 * extension degrades thumbnails, while a missing pdo_mysql makes the app
 * impossible. Blocking on the first would turn away hosts that would work fine.
 */
final class RequirementChecker
{
    /** @return list<Requirement> */
    public function all(): array
    {
        return [
            $this->php(),
            ...$this->extensions(),
            $this->configWritable(),
            $this->storageWritable(),
            $this->uploadsWritable(),
            $this->rewrite(),
            $this->vendor(),
            $this->themes(),
        ];
    }

    /** @return list<Requirement> the ones that stop the install */
    public function blocking(): array
    {
        return array_values(array_filter($this->all(), static fn (Requirement $r): bool => $r->isBlocking()));
    }

    public function canProceed(): bool
    {
        return $this->blocking() === [];
    }

    private function php(): Requirement
    {
        $ok = version_compare(PHP_VERSION, PORTAL_MIN_PHP, '>=');

        return new Requirement(
            label: 'PHP ' . PORTAL_MIN_PHP . ' or newer',
            satisfied: $ok,
            detail: 'Running PHP ' . PHP_VERSION,
            fix: $ok ? '' : 'Most shared hosts let you change the PHP version from the control panel — '
                . 'look for "PHP Selector", "MultiPHP Manager", or "PHP Version".'
        );
    }

    /** @return list<Requirement> */
    private function extensions(): array
    {
        $required = [
            'pdo'       => 'Database access.',
            'pdo_mysql' => 'Talking to MySQL or MariaDB.',
            'openssl'   => 'Encrypting stored credentials and signing links.',
            'mbstring'  => 'Handling non-English text correctly.',
            'json'      => 'Everything.',
        ];

        $recommended = [
            'curl'     => 'Reaching bunny.net and your email provider. Without it, video and email will not work.',
            'zip'      => 'Installing plugins and themes from a ZIP file.',
            'gd'       => 'Resizing uploaded images such as your logo.',
            'fileinfo' => 'Checking that uploaded files are what they claim to be.',
        ];

        $checks = [];

        foreach ($required as $extension => $why) {
            $loaded = extension_loaded($extension);
            $checks[] = new Requirement(
                label: "PHP extension: {$extension}",
                satisfied: $loaded,
                level: Requirement::LEVEL_REQUIRED,
                detail: $why,
                fix: $loaded ? '' : "Enable {$extension} in your host's PHP settings "
                    . '(cPanel: "Select PHP Version" → Extensions).'
            );
        }

        foreach ($recommended as $extension => $why) {
            $loaded = extension_loaded($extension);
            $checks[] = new Requirement(
                label: "PHP extension: {$extension}",
                satisfied: $loaded,
                level: Requirement::LEVEL_RECOMMENDED,
                detail: $why,
                fix: $loaded ? '' : "Enable {$extension} in your host's PHP settings if you need it."
            );
        }

        return $checks;
    }

    /**
     * Can we actually write here?
     *
     * By creating a file, not by asking is_writable(). That function consults
     * the POSIX permission bits, which are meaningless wherever real access is
     * decided by something else — Windows ACLs, NFS, and several shared hosts
     * with unusual ownership setups. It returns false on directories that are
     * demonstrably writable, and blocking the install on that answer turns
     * away hosts that would have worked perfectly.
     *
     * Verified on this project's own working directory, where is_writable()
     * reports false and file_put_contents() succeeds.
     */
    private function canWriteTo(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $probe = rtrim($directory, '/\\') . '/.portal-write-test-' . bin2hex(random_bytes(4));

        $written = @file_put_contents($probe, 'test');
        if ($written === false) {
            return false;
        }

        @unlink($probe);
        return true;
    }

    private function configWritable(): Requirement
    {
        // The file does not exist yet, so the directory is what must be
        // writable. Checking the file would always report "not writable".
        $writable = is_file(PORTAL_CONFIG_FILE)
            ? ($this->canWriteTo(PORTAL_ROOT) || is_writable(PORTAL_CONFIG_FILE))
            : $this->canWriteTo(PORTAL_ROOT);

        return new Requirement(
            label: 'config.php can be written',
            satisfied: $writable,
            detail: PORTAL_ROOT,
            fix: $writable ? '' : 'Set the application folder to 755 and make sure it is owned by your '
                . 'web user. Over FTP, right-click the folder → File Permissions.'
        );
    }

    private function storageWritable(): Requirement
    {
        $writable = $this->canWriteTo(PORTAL_STORAGE);

        return new Requirement(
            label: 'storage/ is writable',
            satisfied: $writable,
            detail: 'Logs and caches are written here.',
            fix: $writable ? '' : 'Create the storage folder and set it to 755 (or 775 if your host needs it).'
        );
    }

    private function uploadsWritable(): Requirement
    {
        $writable = $this->canWriteTo(PORTAL_PUBLIC . '/uploads');

        return new Requirement(
            label: 'public/uploads/ is writable',
            satisfied: $writable,
            level: Requirement::LEVEL_RECOMMENDED,
            detail: 'Needed for logo uploads and staging plugin or theme ZIPs.',
            fix: $writable ? '' : 'Create public/uploads and set it to 755. Videos are not stored here — '
                . 'they go straight to your video provider.'
        );
    }

    /**
     * mod_rewrite cannot be detected reliably from PHP.
     *
     * apache_get_modules() only exists under mod_php, and a host running
     * PHP-FPM or LiteSpeed will not expose it even when rewriting works
     * perfectly. Rather than guess and block a working host, report what we
     * can and let the install continue — a broken rewrite shows up
     * immediately as a 404 on the first page, which is diagnosable.
     */
    private function rewrite(): Requirement
    {
        if (function_exists('apache_get_modules')) {
            $modules = apache_get_modules();
            $enabled = in_array('mod_rewrite', $modules, true);

            return new Requirement(
                label: 'URL rewriting (mod_rewrite)',
                satisfied: $enabled,
                level: Requirement::LEVEL_RECOMMENDED,
                detail: $enabled ? 'Detected.' : 'Not detected.',
                fix: $enabled ? '' : 'Enable mod_rewrite, or ask your host to. Without it every page '
                    . 'except the homepage will return 404.'
            );
        }

        return new Requirement(
            label: 'URL rewriting (mod_rewrite)',
            satisfied: true,
            level: Requirement::LEVEL_RECOMMENDED,
            detail: 'Could not be checked on this server type — this is normal on PHP-FPM, LiteSpeed, and nginx.',
            fix: 'If pages other than the homepage return 404 after installing, URL rewriting is the cause.'
        );
    }

    private function vendor(): Requirement
    {
        $present = PORTAL_HAS_VENDOR;

        return new Requirement(
            label: 'Bundled libraries present',
            satisfied: $present,
            detail: $present ? 'vendor/ found.' : 'vendor/ is missing.',
            fix: $present ? '' : 'The vendor folder did not upload. Re-upload the release ZIP in full — '
                . 'FTP clients sometimes skip deeply nested folders.'
        );
    }

    private function themes(): Requirement
    {
        $present = is_file(PORTAL_THEMES . '/default/theme.json');

        return new Requirement(
            label: 'Default theme present',
            satisfied: $present,
            detail: $present ? 'themes/default found.' : 'themes/default is missing.',
            fix: $present ? '' : 'The default theme did not upload. Re-upload the release ZIP in full.'
        );
    }

    /**
     * Extensions a specific provider needs, so the wizard can warn before
     * someone picks something their host cannot run.
     *
     * @param list<string> $extensions
     * @return list<string> the missing ones
     */
    public function missingExtensions(array $extensions): array
    {
        return array_values(array_filter(
            $extensions,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));
    }
}
