<?php
/**
 * Package a staged directory into a ZIP with correct Unix permissions.
 *
 * Why this exists rather than PowerShell's Compress-Archive: that cmdlet
 * records only DOS attributes and no Unix mode at all. Extracting such an
 * archive on Linux leaves directories without the write bit, and the person who
 * just uploaded their site cannot delete or modify what they extracted:
 *
 *     rm: cannot remove 'vendor/firebase/php-jwt': Permission denied
 *
 * Every entry here gets an explicit mode, stored the way Info-ZIP and every
 * other Unix extractor expects: opsys UNIX, with the mode in the high 16 bits
 * of the external attributes.
 *
 *   php tools/package.php <stage-dir> <output.zip>
 */

declare(strict_types=1);

if (!extension_loaded('zip')) {
    fwrite(STDERR, "The zip extension is required to build a release.\n");
    exit(1);
}

$stage = $argv[1] ?? '';
$output = $argv[2] ?? '';

if ($stage === '' || $output === '' || !is_dir($stage)) {
    fwrite(STDERR, "Usage: php tools/package.php <stage-dir> <output.zip>\n");
    exit(1);
}

$stage = rtrim(str_replace('\\', '/', realpath($stage) ?: $stage), '/');

/*
 * 0755 for directories and 0644 for files.
 *
 * Not 0775/0664: a group-writable web root is a real risk on shared hosting,
 * where the web server may run as a group the account shares with others. The
 * installer's requirement check writes a probe file to confirm the directories
 * it needs are genuinely writable by the owner, so nothing here has to be
 * loosened preemptively.
 */
const DIR_MODE  = 0755;
const FILE_MODE = 0644;

if (is_file($output)) {
    unlink($output);
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create {$output}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$files = 0;
$dirs = 0;

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $absolute = str_replace('\\', '/', $item->getPathname());
    $relative = ltrim(substr($absolute, strlen($stage)), '/');

    if ($relative === '') {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
        $zip->setExternalAttributesName(
            $relative . '/',
            ZipArchive::OPSYS_UNIX,
            (DIR_MODE | 040000) << 16
        );
        $dirs++;
        continue;
    }

    $zip->addFile($absolute, $relative);
    $zip->setExternalAttributesName(
        $relative,
        ZipArchive::OPSYS_UNIX,
        (FILE_MODE | 0100000) << 16
    );
    $files++;
}

if (!$zip->close()) {
    fwrite(STDERR, "Could not finalise the archive.\n");
    exit(1);
}

/*
 * Read the modes back out. Writing them is not the same as storing them
 * correctly, and the failure this guards against is invisible until someone
 * extracts the archive on a machine we do not control.
 */
$verify = new ZipArchive();
if ($verify->open($output) !== true) {
    fwrite(STDERR, "Could not reopen the archive to verify it.\n");
    exit(1);
}

$problems = [];
for ($i = 0; $i < $verify->numFiles; $i++) {
    $name = (string) $verify->getNameIndex($i);

    if (!$verify->getExternalAttributesIndex($i, $opsys, $attributes)) {
        $problems[] = "{$name}: no external attributes stored";
        continue;
    }

    if ($opsys !== ZipArchive::OPSYS_UNIX) {
        $problems[] = "{$name}: opsys is {$opsys}, not UNIX";
        continue;
    }

    $mode = ($attributes >> 16) & 0o7777;
    $expected = str_ends_with($name, '/') ? DIR_MODE : FILE_MODE;

    if ($mode !== $expected) {
        $problems[] = sprintf('%s: mode %04o, expected %04o', $name, $mode, $expected);
    }
}

$verify->close();

if ($problems !== []) {
    fwrite(STDERR, "Permission verification failed:\n");
    foreach (array_slice($problems, 0, 20) as $problem) {
        fwrite(STDERR, "  {$problem}\n");
    }
    if (count($problems) > 20) {
        fwrite(STDERR, '  ... and ' . (count($problems) - 20) . " more\n");
    }
    exit(1);
}

printf(
    "  %d file(s) at %04o, %d director(y|ies) at %04o, all verified.\n",
    $files,
    FILE_MODE,
    $dirs,
    DIR_MODE
);
