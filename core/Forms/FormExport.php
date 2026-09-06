<?php

declare(strict_types=1);

namespace Portal\Forms;

use Portal\Support\Csv;

/**
 * A form's responses as a spreadsheet.
 *
 * # RETIRED QUESTIONS COME AFTER THE LIVE ONES
 *
 * Not left out, and not mixed in. Left out, a column of real answers people
 * gave in March disappears from the only export they will ever be in; mixed in,
 * the sheet stops looking like the form does today and whoever opens it has to
 * work out which columns are still being asked.
 *
 * So: today's questions in today's order, then everything the form has stopped
 * asking, each headed with a note saying so.
 *
 * Through Portal\Support\Csv, which defuses formula injection. That matters
 * more here than anywhere else in this product: every cell is text a stranger
 * typed into a public form, and the file is opened in Excel by whoever does the
 * follow-up.
 */
final class FormExport
{
    /**
     * @param list<array<string, mixed>> $questions every question, live first
     * @param list<array<string, mixed>> $responses
     * @param array<int, array<int, string>> $answers response id => question id => value
     */
    public static function csv(array $questions, array $responses, array $answers): string
    {
        $headings = ['Sent', 'Name', 'Email', 'Dealt with by', 'Dealt with', 'Note'];

        foreach ($questions as $question) {
            $headings[] = $question['retired_at'] === null
                ? (string) $question['label']
                // Said in the heading, because a column of answers to a
                // question nobody is asking any more is confusing without it —
                // and somebody would otherwise "fix" the form to match.
                : (string) $question['label'] . ' (no longer asked)';
        }

        $rows = [];

        foreach ($responses as $response) {
            $id = (int) $response['id'];
            $given = $answers[$id] ?? [];

            $row = [
                (string) $response['created_at'],
                (string) ($response['from_name'] ?? ''),
                (string) ($response['from_email'] ?? ''),
                (string) ($response['handled_by'] ?? ''),
                (string) ($response['handled_at'] ?? ''),
                (string) ($response['handled_note'] ?? ''),
            ];

            foreach ($questions as $question) {
                $questionId = (int) $question['id'];

                $row[] = FieldType::display(
                    (string) $question['type'],
                    $given[$questionId] ?? null
                );
            }

            $rows[] = $row;
        }

        return Csv::document($headings, $rows);
    }
}
