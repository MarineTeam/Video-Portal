<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;

/**
 * Stores note sheets and the answers people write on them.
 *
 * Visibility is NOT decided here. The controller resolves the video through
 * the ordinary listing query first; a repository that took a video id and
 * answered would be a way to read a members-only sermon's outline by counting.
 */
final class NoteSheetRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The sheet for a video, or null when it has none.
     *
     * @return array{outline: string, version: int}|null
     */
    public function find(int $videoId): ?array
    {
        $row = $this->db->first(
            'SELECT outline, version FROM {note_sheets} WHERE video_id = ?',
            [$videoId]
        );

        if ($row === null || (string) $row['outline'] === '') {
            return null;
        }

        return ['outline' => (string) $row['outline'], 'version' => (int) $row['version']];
    }

    /** The outline as stored, empty when there is none — for the edit form. */
    public function outline(int $videoId): string
    {
        return (string) ($this->db->value('SELECT outline FROM {note_sheets} WHERE video_id = ?', [$videoId]) ?? '');
    }

    /**
     * Save an outline, bumping the version only when its wording changed.
     *
     * One statement, so two editors saving at once cannot both read version 3
     * and both write 4. `version` is assigned BEFORE `fingerprint` on purpose:
     * MySQL applies these assignments left to right, so the comparison sees the
     * OLD fingerprint. The other order compares the new value with itself and
     * never bumps anything.
     *
     * An empty outline keeps the row and its version — see the migration.
     */
    public function saveOutline(int $videoId, string $outline): void
    {
        $outline = NoteSheet::cleanOutline($outline);

        $this->db->execute(
            'INSERT INTO {note_sheets} (video_id, outline, version, fingerprint, updated_at)
             VALUES (?, ?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE
               version     = IF(fingerprint = VALUES(fingerprint), version, version + 1),
               fingerprint = VALUES(fingerprint),
               outline     = VALUES(outline),
               updated_at  = NOW()',
            [$videoId, $outline, NoteSheet::fingerprint($outline)]
        );
    }

    /**
     * This person's answers, and the version they were written under.
     *
     * @return array{version: int, answers: list<string>}|null
     */
    public function answers(int $userId, int $videoId): ?array
    {
        $row = $this->db->first(
            'SELECT sheet_version, answers FROM {note_sheet_answers} WHERE user_id = ? AND video_id = ?',
            [$userId, $videoId]
        );

        if ($row === null) {
            return null;
        }

        $answers = json_decode((string) $row['answers'], true);

        return [
            'version' => (int) $row['sheet_version'],
            'answers' => is_array($answers) ? array_values(array_map('strval', $answers)) : [],
        ];
    }

    /**
     * Save answers as they are typed. An upsert, keyed on the person and sheet.
     *
     * @param list<string> $answers already shaped by NoteSheet::cleanAnswers()
     */
    public function saveAnswers(int $userId, int $videoId, int $version, array $answers): void
    {
        $this->db->execute(
            'INSERT INTO {note_sheet_answers} (user_id, video_id, sheet_version, answers, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               sheet_version = VALUES(sheet_version),
               answers       = VALUES(answers),
               updated_at    = NOW()',
            [$userId, $videoId, $version, json_encode($answers, JSON_UNESCAPED_UNICODE)]
        );
    }
}
