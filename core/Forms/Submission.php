<?php

declare(strict_types=1);

namespace Portal\Forms;

/**
 * Turning what arrived into answers, or into things to tell somebody.
 *
 * Pure: the questions and the raw input in, answers and errors out. No
 * database, no request object — so this can be tested against a crafted
 * submission directly, which is the only way to check the rule it exists for.
 *
 * # WHAT IS ASKED IS WHAT THE FORM ASKS
 *
 * The loop is over the QUESTIONS, never over the input. A submission carrying a
 * field for a question that belongs to another form, or to no form, or that was
 * retired last term, contributes nothing — there is no path here that stores a
 * value this form did not ask for, because nothing here ever looks at a key the
 * form did not name.
 */
final class Submission
{
    /**
     * @param list<array<string, mixed>> $questions the live questions, in order
     * @param array<string, mixed> $input whatever arrived
     * @return array{
     *     ok: bool,
     *     answers: array<int, string|null>,
     *     errors: array<int, string>,
     *     name: string,
     *     email: string
     * }
     */
    public static function read(array $questions, array $input): array
    {
        $answers = [];
        $errors = [];
        $name = '';
        $email = '';

        foreach ($questions as $question) {
            $id = (int) $question['id'];
            $type = (string) $question['type'];

            $verdict = FieldType::check(
                $type,
                FormRepository::optionsOf($question),
                (bool) $question['is_required'],
                $input['q' . $id] ?? null
            );

            if (!$verdict['ok']) {
                $errors[$id] = (string) $verdict['error'];

                continue;
            }

            $answers[$id] = $verdict['value'];

            /*
             * The first email and the first name-ish answer are lifted onto the
             * response itself, so a list of responses can say who sent what
             * without reading every answer row for every line.
             *
             * A guess, and it is allowed to be: it decides a heading in a
             * table, and getting it wrong costs a blank column rather than a
             * wrong answer. The answers themselves are untouched.
             */
            if ($verdict['value'] !== null) {
                if ($email === '' && $type === FieldType::EMAIL) {
                    $email = $verdict['value'];
                } elseif ($name === '' && $type === FieldType::TEXT && self::looksLikeAName($question)) {
                    $name = $verdict['value'];
                }
            }
        }

        return [
            'ok'      => $errors === [],
            'answers' => $answers,
            'errors'  => $errors,
            'name'    => $name,
            'email'   => $email,
        ];
    }

    /**
     * Whether a question is probably asking who somebody is.
     *
     * Matched on the label because there is no "name" field type and adding one
     * would mean every form builder had to know to use it. Deliberately narrow:
     * this only ever decides a column heading.
     *
     * @param array<string, mixed> $question
     */
    private static function looksLikeAName(array $question): bool
    {
        $label = mb_strtolower((string) $question['label']);

        foreach (['name', 'who are you', 'your details'] as $hint) {
            if (str_contains($label, $hint)) {
                return true;
            }
        }

        return false;
    }
}
