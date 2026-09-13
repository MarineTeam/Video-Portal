<?php

declare(strict_types=1);

namespace Portal\Content;

/**
 * What sort of file something is, for filtering a list of them.
 *
 * Read from the stored content type, which was sniffed from the file's bytes
 * at upload rather than taken from its extension — so "PDF" here means the
 * file is a PDF, not that somebody named it `.pdf`.
 */
final class FileKind
{
    public const PDF = 'pdf';
    public const AUDIO = 'audio';
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const DOCUMENT = 'document';
    public const OTHER = 'other';

    /** @return array<string, string> kind => label, in the order a filter lists them */
    public static function labels(): array
    {
        return [
            self::PDF      => 'PDFs',
            self::AUDIO    => 'Audio',
            self::IMAGE    => 'Images',
            self::VIDEO    => 'Video files',
            self::DOCUMENT => 'Documents',
            self::OTHER    => 'Everything else',
        ];
    }

    /** The kind of one content type. Unknown is OTHER, never an error. */
    public static function of(string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return match (true) {
            $type === 'application/pdf'           => self::PDF,
            str_starts_with($type, 'audio/')      => self::AUDIO,
            str_starts_with($type, 'image/')      => self::IMAGE,
            str_starts_with($type, 'video/')      => self::VIDEO,
            in_array($type, self::DOCUMENT_TYPES, true) => self::DOCUMENT,
            default                               => self::OTHER,
        };
    }

    /**
     * The SQL condition for one kind, as a fragment and its parameters.
     *
     * Kept beside of() so the filter and the label a row shows cannot disagree —
     * a file listed under "Audio" that the filter for Audio does not return is
     * the kind of small inconsistency that makes a screen untrustworthy. OTHER is
     * written as the negation of every named kind for the same reason, rather
     * than as its own list that would drift.
     *
     * @return array{0: string, 1: list<string>}|null null for "no filter"
     */
    public static function condition(string $kind, string $column = 'a.content_type'): ?array
    {
        $documents = implode(',', array_fill(0, count(self::DOCUMENT_TYPES), '?'));

        $named = [
            self::PDF      => ["{$column} = ?", ['application/pdf']],
            self::AUDIO    => ["{$column} LIKE ?", ['audio/%']],
            self::IMAGE    => ["{$column} LIKE ?", ['image/%']],
            self::VIDEO    => ["{$column} LIKE ?", ['video/%']],
            self::DOCUMENT => ["{$column} IN ({$documents})", self::DOCUMENT_TYPES],
        ];

        if (isset($named[$kind])) {
            return $named[$kind];
        }

        if ($kind !== self::OTHER) {
            return null;
        }

        $sql = [];
        $params = [];

        foreach ($named as [$fragment, $values]) {
            $sql[] = $fragment;
            $params = array_merge($params, $values);
        }

        return ['NOT (' . implode(' OR ', $sql) . ')', $params];
    }

    /** Office and text documents people attach to sermons. */
    private const DOCUMENT_TYPES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/rtf',
        'text/plain',
        'application/epub+zip',
    ];
}
