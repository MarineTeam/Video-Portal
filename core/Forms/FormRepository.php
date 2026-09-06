<?php

declare(strict_types=1);

namespace Portal\Forms;

use Portal\Db;
use Portal\Http\HttpException;
use Portal\Support\Str;

/**
 * Forms, the questions on them, and what people send back.
 *
 * # AN ANSWER BELONGS TO THE QUESTION, NOT TO ITS WORDS
 *
 * Answers point at a question ROW. Renaming a question does not reach back and
 * rewrite what March's responses were answering, and a question is RETIRED
 * rather than deleted, so its answers keep saying what they said. The schema
 * enforces the second half with ON DELETE RESTRICT, because a rule that lives
 * only in a code path is a rule the next code path forgets.
 *
 * # A MEMBERS-ONLY FORM IS INVISIBLE, NOT REFUSED
 *
 * The same shape events keep. A stranger asking for it gets the answer they
 * would get for a form that does not exist — the title is a leak too, and a
 * refusal announces that there is something there to be refused.
 */
final class FormRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------- forms

    /**
     * @return list<array<string, mixed>>
     */
    public function forms(bool $includeMemberOnly, bool $includeClosed = false): array
    {
        $where = [];

        if (!$includeClosed) {
            $where[] = 'is_open = 1';
        }

        if (!$includeMemberOnly) {
            $where[] = 'member_only = 0';
        }

        return $this->db->all(
            'SELECT * FROM {forms}'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY title'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {forms} WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->first('SELECT * FROM {forms} WHERE slug = ?', [$slug]);
    }

    public function create(string $title): int
    {
        $title = trim($title);

        if ($title === '') {
            throw HttpException::badRequest('A form needs a title.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('forms', [
            'slug'       => $this->uniqueSlug($title),
            'title'      => mb_substr($title, 0, 190),
            'is_open'    => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(int $id, array $attributes): void
    {
        /*
         * Absent means LEAVE ALONE, not "clear it". A handler that reads
         * absence as a value is a handler that will eventually destroy
         * something it was never asked about — which has happened twice in
         * this codebase, once in shipped code.
         */
        $sets = [];
        $params = [];

        foreach ([
            'title'         => 190,
            'description'   => 5000,
            'notify_emails' => 500,
            'thanks'        => 500,
        ] as $field => $limit) {
            if (array_key_exists($field, $attributes)) {
                $sets[] = "{$field} = ?";
                $value = mb_substr(trim((string) $attributes[$field]), 0, $limit);
                $params[] = $field === 'title' ? $value : ($value === '' ? null : $value);
            }
        }

        foreach (['is_open', 'member_only'] as $flag) {
            if (array_key_exists($flag, $attributes)) {
                $sets[] = "{$flag} = ?";
                $params[] = !empty($attributes[$flag]) ? 1 : 0;
            }
        }

        if ($sets === []) {
            return;
        }

        $params[] = $id;

        $this->db->execute(
            'UPDATE {forms} SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?',
            $params
        );
    }

    /**
     * Who hears about a response.
     *
     * Named by the form, so a prayer-ministry card and a car-park rota do not
     * both land in one inbox that somebody then forwards by hand. Anything that
     * is not an address is dropped rather than attempted: a typo here fails at
     * send time, in a cron job, where nobody sees it.
     *
     * @return list<string>
     */
    public function notifyAddresses(array $form): array
    {
        $out = [];

        foreach (preg_split('/[,;\s]+/', (string) ($form['notify_emails'] ?? '')) ?: [] as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $out[] = Str::normalizeEmail($candidate);
            }
        }

        return array_values(array_unique($out));
    }

    // --------------------------------------------------------- questions

    /**
     * The questions being asked NOW.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(int $formId): array
    {
        return $this->db->all(
            'SELECT * FROM {form_questions} WHERE form_id = ? AND retired_at IS NULL
              ORDER BY position, id',
            [$formId]
        );
    }

    /**
     * Every question the form has EVER asked, live ones first.
     *
     * This is what a spreadsheet needs: a column for a retired question still
     * has answers under it, and putting those columns after the live ones keeps
     * the live part of the sheet looking like the form does today.
     *
     * @return list<array<string, mixed>>
     */
    public function allQuestions(int $formId): array
    {
        return $this->db->all(
            'SELECT * FROM {form_questions} WHERE form_id = ?
              ORDER BY retired_at IS NOT NULL, position, id',
            [$formId]
        );
    }

    /** @param list<string> $options */
    public function addQuestion(
        int $formId,
        string $label,
        string $type,
        array $options = [],
        bool $required = false,
        string $help = ''
    ): int {
        $label = trim($label);

        if ($label === '') {
            throw HttpException::badRequest('A question needs to say something.');
        }

        if (!FieldType::exists($type)) {
            throw HttpException::badRequest('That is not a kind of question this can ask.');
        }

        if (FieldType::hasOptions($type) && $options === []) {
            // Refused here rather than stored, because a choice question with
            // no options is one nobody can answer and the form would simply
            // refuse every response with no explanation on the page.
            throw HttpException::badRequest('A question like that needs some answers to choose from.');
        }

        $now = date('Y-m-d H:i:s');

        return (int) $this->db->insert('form_questions', [
            'form_id'     => $formId,
            'label'       => mb_substr($label, 0, 300),
            'help'        => mb_substr(trim($help), 0, 500) ?: null,
            'type'        => $type,
            'options'     => $options === [] ? null : (string) json_encode(array_values($options)),
            'is_required' => $required ? 1 : 0,
            'position'    => (int) $this->db->value(
                'SELECT COALESCE(MAX(position), 0) + 10 FROM {form_questions} WHERE form_id = ?',
                [$formId]
            ),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }

    /**
     * Change the words, keep the answers.
     *
     * The type is NOT editable and that is deliberate. Turning a three-way
     * choice into a paragraph would leave the old answers valid and the new
     * ones not, under one heading, and no export could say which was which.
     * Retire it and ask a new one.
     */
    public function renameQuestion(int $id, string $label, string $help = ''): void
    {
        $label = trim($label);

        if ($label === '') {
            throw HttpException::badRequest('A question needs to say something.');
        }

        $this->db->execute(
            'UPDATE {form_questions} SET label = ?, help = ?, updated_at = NOW() WHERE id = ?',
            [mb_substr($label, 0, 300), mb_substr(trim($help), 0, 500) ?: null, $id]
        );
    }

    /**
     * Stop asking a question, without losing what it was asked.
     *
     * There is no delete. See the migration: an answered question cannot be
     * deleted at all, because deleting it would rewrite what a past response
     * said.
     */
    public function retireQuestion(int $id, bool $retired = true): void
    {
        $this->db->execute(
            'UPDATE {form_questions} SET retired_at = ?, updated_at = NOW() WHERE id = ?',
            [$retired ? date('Y-m-d H:i:s') : null, $id]
        );
    }

    public function moveQuestion(int $id, int $direction): void
    {
        $question = $this->db->first('SELECT * FROM {form_questions} WHERE id = ?', [$id]);

        if ($question === null) {
            return;
        }

        $neighbour = $this->db->first(
            'SELECT * FROM {form_questions}
              WHERE form_id = ? AND retired_at IS NULL AND position ' . ($direction < 0 ? '<' : '>') . ' ?
              ORDER BY position ' . ($direction < 0 ? 'DESC' : 'ASC') . ' LIMIT 1',
            [(int) $question['form_id'], (int) $question['position']]
        );

        if ($neighbour === null) {
            return;
        }

        $this->db->transaction(function () use ($question, $neighbour): void {
            $this->db->execute(
                'UPDATE {form_questions} SET position = ? WHERE id = ?',
                [(int) $neighbour['position'], (int) $question['id']]
            );
            $this->db->execute(
                'UPDATE {form_questions} SET position = ? WHERE id = ?',
                [(int) $question['position'], (int) $neighbour['id']]
            );
        });
    }

    /** @return list<string> */
    public static function optionsOf(array $question): array
    {
        $decoded = json_decode((string) ($question['options'] ?? ''), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    // --------------------------------------------------------- responses

    /**
     * Store one response, and everything that came with it.
     *
     * In a transaction, because a response with half its answers is worse than
     * none: somebody rings back about a card that says nothing.
     *
     * @param array<int, string|null> $answers question id => the value already checked
     */
    public function store(
        int $formId,
        array $answers,
        ?int $userId = null,
        string $name = '',
        string $email = ''
    ): int {
        return $this->db->transaction(function () use ($formId, $answers, $userId, $name, $email): int {
            $responseId = (int) $this->db->insert('form_responses', [
                'form_id'    => $formId,
                'user_id'    => $userId,
                'from_name'  => mb_substr(trim($name), 0, 190) ?: null,
                'from_email' => mb_substr(trim($email), 0, 190) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ($answers as $questionId => $value) {
                if ($value === null) {
                    // An unanswered optional question stores no row at all, so
                    // "not asked" and "left blank" do not become the same
                    // thing in a spreadsheet.
                    continue;
                }

                $this->db->insert('form_answers', [
                    'response_id' => $responseId,
                    'question_id' => (int) $questionId,
                    'value'       => $value,
                ]);
            }

            return $responseId;
        });
    }

    /** @return list<array<string, mixed>> */
    public function responses(int $formId, bool $outstandingOnly = false, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT * FROM {form_responses}
              WHERE form_id = ?' . ($outstandingOnly ? ' AND handled_at IS NULL' : '')
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$formId]
        );
    }

    public function outstandingCount(int $formId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {form_responses} WHERE form_id = ? AND handled_at IS NULL',
            [$formId]
        );
    }

    /** @return array<string, mixed>|null */
    public function response(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {form_responses} WHERE id = ?', [$id]);
    }

    /**
     * The answers on one response, keyed by question.
     *
     * @return array<int, string>
     */
    public function answersFor(int $responseId): array
    {
        $out = [];

        foreach (
            $this->db->all(
                'SELECT question_id, value FROM {form_answers} WHERE response_id = ?',
                [$responseId]
            ) as $row
        ) {
            $out[(int) $row['question_id']] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * Every answer for a set of responses, in one query.
     *
     * @param list<int> $responseIds
     * @return array<int, array<int, string>>
     */
    public function answersForMany(array $responseIds): array
    {
        if ($responseIds === []) {
            return [];
        }

        $ids = array_map('intval', $responseIds);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $out = [];

        foreach (
            $this->db->all(
                "SELECT response_id, question_id, value FROM {form_answers}
                  WHERE response_id IN ({$marks})",
                $ids
            ) as $row
        ) {
            $out[(int) $row['response_id']][(int) $row['question_id']] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * Mark a response dealt with, BY NAME.
     *
     * The name is the point. The way follow-up fails is not that nobody rings —
     * it is that two people each assume the other did, and a tick with no name
     * beside it produces exactly that, because it answers "has this been done"
     * and not "by whom".
     */
    public function markHandled(int $id, string $who, string $note = ''): void
    {
        $who = trim($who);

        if ($who === '') {
            throw HttpException::badRequest('Say who dealt with it.');
        }

        $this->db->execute(
            'UPDATE {form_responses} SET handled_by = ?, handled_at = NOW(), handled_note = ? WHERE id = ?',
            [mb_substr($who, 0, 190), mb_substr(trim($note), 0, 500) ?: null, $id]
        );
    }

    public function markUnhandled(int $id): void
    {
        $this->db->execute(
            'UPDATE {form_responses} SET handled_by = NULL, handled_at = NULL, handled_note = NULL
              WHERE id = ?',
            [$id]
        );
    }

    // --------------------------------------------------------- internals

    private function uniqueSlug(string $desired): string
    {
        $base = Str::slug($desired) ?: 'form';
        $slug = $base;
        $suffix = 1;

        while ($this->db->value('SELECT id FROM {forms} WHERE slug = ?', [$slug]) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
