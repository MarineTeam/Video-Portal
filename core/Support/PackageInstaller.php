<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Http\HttpException;
use Throwable;
use ZipArchive;

/**
 * Installing a plugin or theme from a ZIP file.
 *
 * This is the most dangerous code in the application, and it is worth being
 * blunt about why: a plugin is PHP that this site executes on every request, so
 * anyone who can complete an upload here can run any code they like as the web
 * user. There is no sandbox that would change that — the feature IS arbitrary
 * code execution, by design, exactly as it is in WordPress.
 *
 * What can be defended is everything around it:
 *
 *   - The upload is gated by a capability, and can be switched off entirely in
 *     config.php on sites that would rather install over FTP.
 *   - Nothing is written to plugins/ or themes/ until the whole archive has
 *     been extracted to a scratch directory and inspected. A package that
 *     turns out to be malformed never half-installs over a working copy.
 *   - Every entry name is checked before extraction, not after. Checking
 *     afterwards means the traversal already happened.
 *   - Size, entry count, and compression ratio are all bounded, so a 40KB
 *     upload cannot expand to fill the disk of a shared host.
 *
 * The one thing deliberately NOT attempted is scanning the PHP for anything
 * malicious. It cannot be done reliably, and a check that catches the naive
 * cases while missing the deliberate ones mostly produces false confidence.
 */
final class PackageInstaller
{
    public const KIND_PLUGIN = 'plugin';
    public const KIND_THEME  = 'theme';

    /**
     * Bounds on what an archive may expand to.
     *
     * A shared host's disk quota is small and shared with the customer's own
     * files, so filling it is a real denial of service rather than a
     * theoretical one. These are generous for a real plugin and nowhere near
     * enough for a zip bomb.
     */
    private const MAX_ENTRIES = 2000;
    private const MAX_TOTAL_BYTES = 64 * 1024 * 1024;
    private const MAX_RATIO = 200;

    public function __construct(private readonly string $kind)
    {
    }

    /**
     * Install from an uploaded file.
     *
     * @param array<string, mixed> $file one entry from $_FILES
     * @return array{ok: bool, message: string, slug?: string, replaced?: bool}
     */
    public function installUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => $this->uploadError($error)];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        // The genuine article: PHP guarantees this path came from a real upload
        // on this request, which is what stops a crafted request naming a file
        // already on disk.
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'That upload did not arrive properly. Try again.'];
        }

        return $this->installArchive($tmp, (string) ($file['name'] ?? 'package.zip'));
    }

    /**
     * @return array{ok: bool, message: string, slug?: string, replaced?: bool}
     */
    public function installArchive(string $archivePath, string $originalName = 'package.zip'): array
    {
        if (!class_exists(ZipArchive::class)) {
            return [
                'ok' => false,
                'message' => 'This server has no ZIP support, so packages cannot be installed here. '
                    . 'Upload the folder over FTP instead, or ask your host to enable the zip extension.',
            ];
        }

        if (!str_ends_with(strtolower($originalName), '.zip')) {
            return ['ok' => false, 'message' => 'That is not a .zip file.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return ['ok' => false, 'message' => 'That file could not be opened as a ZIP archive.'];
        }

        try {
            $inspection = $this->inspect($zip);
        } catch (HttpException $e) {
            $zip->close();
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $slug = $inspection['slug'];
        $staging = $this->scratchDirectory();

        try {
            if (!$zip->extractTo($staging)) {
                return ['ok' => false, 'message' => 'The archive could not be unpacked.'];
            }
        } finally {
            $zip->close();
        }

        try {
            // Sweep any backup a previous install could not remove — a rename
            // succeeding while the delete afterwards failed is a normal outcome
            // on Windows and on hosts with aggressive file locking, and left
            // alone they accumulate forever.
            $this->sweepStaleBackups($slug);

            $source = $staging . '/' . $slug;

            $reason = $this->validate($source, $slug);
            if ($reason !== null) {
                return ['ok' => false, 'message' => $reason];
            }

            $destination = $this->root() . '/' . $slug;
            $replaced = is_dir($destination);

            // Only now does anything touch the live directory. Up to this point
            // a bad package has cost a scratch directory and nothing else.
            if ($replaced) {
                // Named with a leading dot, and deliberately so: glob() does
                // not match dotfiles, so discovery cannot see this even for the
                // moment it exists — nor if it survives, which on Windows it
                // sometimes does when a file handle is still open. A visible
                // stray folder here would be listed as a broken package.
                $backup = $this->root() . '/.' . $slug . '.replacing-' . bin2hex(random_bytes(4));

                if (!@rename($destination, $backup)) {
                    return [
                        'ok' => false,
                        'message' => 'Could not replace the existing files. Check the permissions on the '
                            . $this->kind . 's folder.',
                    ];
                }

                if (!@rename($source, $destination)) {
                    // Put the working copy back rather than leaving the site
                    // with neither version.
                    @rename($backup, $destination);
                    return ['ok' => false, 'message' => 'Could not write the new files. The previous version is intact.'];
                }

                $this->deleteDirectory($backup);
            } elseif (!@rename($source, $destination)) {
                return [
                    'ok' => false,
                    'message' => 'Could not write to the ' . $this->kind . 's folder. Check its permissions.',
                ];
            }

            return [
                'ok'       => true,
                'slug'     => $slug,
                'replaced' => $replaced,
                'message'  => $replaced
                    ? sprintf('Updated %s. It keeps its previous settings and stays as it was — active or not.', $slug)
                    : sprintf('Installed %s. It is switched off until you activate it.', $slug),
            ];
        } finally {
            $this->deleteDirectory($staging);
        }
    }

    // ------------------------------------------------------------ inspection

    /**
     * Read the archive's table of contents and refuse anything alarming.
     *
     * Every check here happens BEFORE a single byte is written. Validating an
     * extracted tree would mean the traversal, the zip bomb, and the overwrite
     * had all already happened.
     *
     * @return array{slug: string}
     */
    private function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles === 0) {
            throw HttpException::badRequest('That archive is empty.');
        }

        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw HttpException::badRequest(sprintf(
                'That archive has %d files in it, which is far more than a %s should need.',
                $zip->numFiles,
                $this->kind
            ));
        }

        $roots = [];
        $totalBytes = 0;
        $compressedBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw HttpException::badRequest('That archive could not be read.');
            }

            $name = (string) $stat['name'];

            $this->rejectDangerousName($name);

            $totalBytes += (int) $stat['size'];
            $compressedBytes += (int) $stat['comp_size'];

            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw HttpException::badRequest(
                    'That archive unpacks to more than 64MB, which is far larger than a '
                    . $this->kind . ' should be.'
                );
            }

            $root = explode('/', trim(str_replace('\\', '/', $name), '/'))[0] ?? '';
            if ($root !== '') {
                $roots[$root] = true;
            }
        }

        // A ratio this high is a compression bomb, not a plugin. Checked after
        // the loop because a single tiny entry can look extreme on its own.
        if ($compressedBytes > 0 && $totalBytes / $compressedBytes > self::MAX_RATIO) {
            throw HttpException::badRequest('That archive is compressed suspiciously heavily and has been refused.');
        }

        if (count($roots) !== 1) {
            throw HttpException::badRequest(sprintf(
                'A %s archive must contain exactly one folder. This one has %d, so it is not clear what to install.',
                $this->kind,
                count($roots)
            ));
        }

        $slug = $this->sanitizeSlug((string) array_key_first($roots));

        if ($slug === '') {
            throw HttpException::badRequest('The folder inside that archive does not have a usable name.');
        }

        return ['slug' => $slug];
    }

    /**
     * Refuse an entry name that could escape the target directory.
     *
     * The classic attack is "../../public/index.php". Less obvious ones: an
     * absolute path, a Windows drive letter, a backslash separator that PHP
     * treats as a directory on some platforms and as a filename character on
     * others, and a null byte truncating the path inside a C library.
     */
    private function rejectDangerousName(string $name): void
    {
        $refuse = static function (string $why): void {
            throw HttpException::badRequest('That archive was refused: ' . $why);
        };

        if ($name === '' || str_contains($name, "\0")) {
            $refuse('it contains an entry with an invalid name.');
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/')) {
            $refuse('it contains an absolute path.');
        }

        if (preg_match('#^[a-zA-Z]:#', $normalized) === 1) {
            $refuse('it contains an absolute Windows path.');
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                $refuse('it tries to write outside its own folder.');
            }
        }
    }

    /**
     * Is the extracted tree actually the thing it claims to be?
     *
     * A plugin without a plugin.php, or a theme without a theme.json, would
     * install as a directory nothing ever loads — a silent no-op that looks
     * like success and leaves the admin wondering why nothing happened.
     */
    private function validate(string $source, string $slug): ?string
    {
        if (!is_dir($source)) {
            return 'The folder inside that archive did not unpack as expected.';
        }

        if ($this->kind === self::KIND_PLUGIN) {
            if (!is_file($source . '/plugin.php')) {
                return 'That archive has no plugin.php in it, so it is not a plugin.';
            }

            $header = \Portal\Plugins\PluginHeader::fromFile($source . '/plugin.php', $slug);
            if ($header === null) {
                return 'That plugin has no "Plugin Name" header, so this site cannot identify it.';
            }

            $reason = $header->incompatibilityReason();

            return $reason === null ? null : 'That plugin cannot run here: ' . $reason;
        }

        if (!is_file($source . '/theme.json')) {
            return 'That archive has no theme.json in it, so it is not a theme.';
        }

        $manifest = \Portal\Themes\ThemeManifest::fromDirectory($source, $slug);

        if ($manifest === null) {
            return 'That theme.json could not be read. It may not be valid JSON.';
        }

        // A child theme whose parent is absent resolves half its templates to
        // nothing, which looks like a broken site rather than a missing
        // dependency. Caught here, while the package is still in a scratch
        // directory and nothing has been disturbed.
        if ($manifest->parent !== null && !is_dir(PORTAL_THEMES . '/' . $manifest->parent)) {
            return sprintf(
                'That theme builds on "%s", which is not installed. Install that one first.',
                $manifest->parent
            );
        }

        return null;
    }

    // --------------------------------------------------------------- helpers

    /** Is installing from an upload permitted on this site at all? */
    public static function uploadsAllowed(\Portal\Config $config): bool
    {
        // Defaults to allowed. The alternative — off until someone edits
        // config.php — means every plugin install on a host with no shell
        // needs an FTP session first, which defeats the point of the feature.
        // Sites that would rather keep code changes on the FTP side turn it off
        // with one line, and the screen says which.
        return $config->bool('allow_package_uploads', true);
    }

    private function root(): string
    {
        return $this->kind === self::KIND_PLUGIN ? PORTAL_PLUGINS : PORTAL_THEMES;
    }

    /** Remove leftover backups from an earlier install of the same package. */
    private function sweepStaleBackups(string $slug): void
    {
        foreach ((array) glob($this->root() . '/.' . $slug . '.replacing-*', GLOB_ONLYDIR) as $stale) {
            if (is_string($stale)) {
                $this->deleteDirectory($stale);
            }
        }
    }

    private function scratchDirectory(): string
    {
        $base = PORTAL_STORAGE . '/tmp';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        $path = $base . '/package-' . bin2hex(random_bytes(6));
        @mkdir($path, 0775, true);

        return $path;
    }

    private function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('/[^a-z0-9_-]/', '', $slug);

        return substr($slug, 0, 64);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        try {
            foreach ((array) scandir($path) as $entry) {
                if (!is_string($entry) || $entry === '.' || $entry === '..') {
                    continue;
                }

                $full = $path . '/' . $entry;

                // Not followed: a symlink pointing at something real would
                // otherwise have its TARGET deleted rather than the link.
                if (is_link($full)) {
                    @unlink($full);
                    continue;
                }

                is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
            }

            @rmdir($path);
        } catch (Throwable $e) {
            error_log('Portal: could not clean up ' . $path . ': ' . $e->getMessage());
        }
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'That file is larger than this server accepts. The limit is upload_max_filesize in php.ini.',
            UPLOAD_ERR_PARTIAL   => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was chosen.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'This server could not store the upload. Its temporary folder may be full or unwritable.',
            default              => 'The upload failed.',
        };
    }
}
