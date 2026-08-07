<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * What may be attached to a video, and what it is called on disk.
 *
 * Pure. Every decision here is a security decision, which is why they are in
 * one testable place rather than scattered through an upload handler.
 *
 * The shape of the threat: an attachment is a file a person uploads and other
 * people download. The two ways that goes wrong are executing it on the server
 * and executing it in somebody's browser. Storing outside the document root
 * handles the first — nothing under {@see PORTAL_STORAGE} is reachable by URL,
 * so a .php that got through is inert. This class handles the second, and the
 * naming rules that stop an upload escaping its directory.
 */
final class AssetPolicy
{
    /** 25MB. A sermon handout is kilobytes; a slide deck is a few megabytes. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    public const MAX_NAME_LENGTH = 190;

    /**
     * The extensions allowed, and what each is served as.
     *
     * An ALLOWLIST, and the content type comes from this table rather than from
     * the upload. A browser's declared type is attacker-controlled: a .php
     * uploaded as "application/pdf" would be stored happily by any check that
     * believed it, and the extension is the only thing that decides how a
     * browser treats what it receives.
     *
     * SVG is deliberately absent. It is an image everywhere else and a script
     * container here — one that runs in the origin of whoever opens it.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt'  => 'text/plain',
            'md'   => 'text/plain',
            'rtf'  => 'application/rtf',
            'csv'  => 'text/csv',
            'zip'  => 'application/zip',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
        ];
    }

    /**
     * The extension of a submitted filename, lowercased, or null.
     *
     * Taken as the LAST dot-segment, so "notes.pdf.php" reads as php and is
     * refused. Reading the first would accept it, which is the oldest upload
     * bypass there is.
     */
    public static function extension(string $filename): ?string
    {
        // basename() first: a name like "../../evil.pdf" must not have its
        // path taken seriously even for the purpose of finding a dot.
        $name = basename(str_replace('\\', '/', trim($filename)));

        $position = strrpos($name, '.');

        if ($position === false || $position === strlen($name) - 1) {
            return null;
        }

        $extension = strtolower(substr($name, $position + 1));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : null;
    }

    /** Is this something we will accept at all? */
    public static function isAllowed(string $filename): bool
    {
        $extension = self::extension($filename);

        return $extension !== null && array_key_exists($extension, self::types());
    }

    /** What to send it back as. */
    public static function contentType(string $filename): string
    {
        $extension = self::extension($filename);

        // A type nobody claimed is a download and nothing else. Falling back to
        // text/plain would let an unexpected file render in the browser.
        return self::types()[$extension] ?? 'application/octet-stream';
    }

    /**
     * A name safe to show and to send in a Content-Disposition header.
     *
     * The original is kept only for display and for the download filename; it
     * never touches the filesystem. Control characters, quotes, and newlines
     * are stripped because a filename reaching a header can otherwise inject
     * one, and directory separators because a name shown as "uploads/x" is
     * misleading even when it is stored elsewhere.
     */
    public static function displayName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', trim($filename)));

        // Everything a header or a filesystem could misread.
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]/u', '', $name);
        $name = trim($name, " .");

        if ($name === '') {
            $name = 'attachment';
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * The name it is stored under.
     *
     * Random, and never derived from what was uploaded. Two reasons: a name
     * nobody can guess is one nobody can request directly if the storage
     * directory is ever misconfigured into the docroot, and generating it
     * removes every collision, unicode, and traversal question at once rather
     * than answering each of them.
     *
     * The extension is carried over because that is what tells this code, later,
     * how to serve the file back — but it comes from the allowlist, so it is one
     * of a known set rather than whatever was typed.
     */
    public static function storedName(string $filename): ?string
    {
        $extension = self::extension($filename);

        if ($extension === null || !array_key_exists($extension, self::types())) {
            return null;
        }

        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    /**
     * Where it lives, relative to the storage root.
     *
     * Split by year and month so a directory never grows to a size that makes
     * a listing painful on a shared host, and so a year can be archived by
     * moving one folder.
     */
    public static function relativePath(string $storedName, ?\DateTimeImmutable $when = null): string
    {
        $when ??= new \DateTimeImmutable();

        return 'assets/' . $when->format('Y/m') . '/' . $storedName;
    }

    /**
     * Human-readable size.
     *
     * On the admin screen so somebody can see at a glance that they attached
     * the 40MB export rather than the one-page handout.
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
