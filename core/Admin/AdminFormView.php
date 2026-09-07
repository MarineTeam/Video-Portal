<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Forms\FieldType;
use Portal\Forms\FormRepository;

/**
 * Building forms, and reading what people send back.
 *
 * Separate from AdminView for the reason the share, rota, event and schedule
 * views are. The shell comes from AdminView; these are the bodies.
 */
final class AdminFormView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'forms' => $this->overview($data),
            'form'  => $this->form($data),
            default => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['forms'] ?? []) as $form) {
            $outstanding = (int) $form['outstanding'];

            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/forms/%d"><strong>%s</strong></a>
                       <div class="muted small">/forms/%s</div></td>
                   <td>%s %s</td>
                   <td class="right">%s</td>
                 </tr>',
                (int) $form['id'],
                e((string) $form['title']),
                e((string) $form['slug']),
                $form['is_open']
                    ? '<span class="pill">open</span>'
                    : '<span class="pill warn">closed</span>',
                $form['member_only'] ? '<span class="pill">members only</span>' : '',
                // Hidden at zero rather than shown greyed: a permanent
                // "0 waiting" trains people to skip the one spot where the
                // number has to be noticed on the day it changes.
                $outstanding > 0
                    ? sprintf('<span class="pill warn">%d to deal with</span>', $outstanding)
                    : '<span class="muted small">nothing waiting</span>'
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No forms yet.</td></tr>';
        }

        return <<<HTML
        <h1>Forms &amp; connect cards</h1>

        <p class="muted">Built here rather than in code, because the questions change every term.
           Anybody can fill one in — no account needed, which is the point of a connect card.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Form</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>

          <div>
            <h2>Add a form</h2>
            <form method="post" action="/admin/forms">
              <input type="hidden" name="_token" value="{$token}">
              <label>Title <input type="text" name="title" required placeholder="Connect card"></label>
              <button class="btn" name="action" value="create">Create</button>
            </form>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function form(array $data): string
    {
        $token = e((string) $data['token']);
        $form = (array) $data['form'];
        $id = (int) $form['id'];

        return <<<HTML
        <p class="muted small"><a href="/admin/forms">&larr; Forms</a></p>
        <h1>{$this->text((string) $form['title'])}</h1>
        <p class="muted small">Live at <a href="/forms/{$this->text((string) $form['slug'])}">/forms/{$this->text((string) $form['slug'])}</a></p>

        <div class="cols">
          <div>
            {$this->questions($data, $token, $id)}
            {$this->responses($data, $token, $id)}
          </div>
          <div>
            {$this->settings($form, $token, $id)}
            {$this->newQuestion($data, $token, $id)}
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $form */
    private function settings(array $form, string $token, int $id): string
    {
        return <<<HTML
        <h2>Settings</h2>
        <form method="post" action="/admin/forms">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">
          <input type="hidden" name="_whole_form" value="1">

          <label>Title <input type="text" name="title" value="{$this->text((string) $form['title'])}"></label>
          <label>Introduction
            <textarea name="description" rows="3">{$this->text((string) ($form['description'] ?? ''))}</textarea>
          </label>

          <label class="check">
            <input type="checkbox" name="is_open" value="1" {$this->checked((bool) $form['is_open'])}>
            Taking answers
          </label>
          <p class="muted small">Closing keeps everything. Deleting a form to stop it is how a
             term's answers disappear.</p>

          <label class="check">
            <input type="checkbox" name="member_only" value="1" {$this->checked((bool) $form['member_only'])}>
            Members only
          </label>
          <p class="muted small">A members-only form is <strong>invisible</strong> to everybody
             else rather than refused — the title is a leak too, and a refusal tells a stranger
             there is something here to be refused.</p>

          <label>Tell these people about a response
            <input type="text" name="notify_emails"
                   value="{$this->text((string) ($form['notify_emails'] ?? ''))}"
                   placeholder="office@example.org, pastoral@example.org">
          </label>
          <p class="muted small">Per form, so a prayer card and a car-park rota do not land in one
             inbox. The email says a response arrived and links here; <strong>the answers are not
             in it</strong>, because what people write on these is not for an inbox.</p>

          <label>What they see afterwards
            <input type="text" name="thanks" value="{$this->text((string) ($form['thanks'] ?? ''))}"
                   placeholder="Thank you — somebody will be in touch this week.">
          </label>

          <button class="btn" name="action" value="save">Save</button>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function questions(array $data, string $token, int $id): string
    {
        $rows = '';

        foreach ((array) ($data['questions'] ?? []) as $question) {
            $retired = $question['retired_at'] !== null;
            $options = FormRepository::optionsOf($question);
            $types = FieldType::all();

            $rows .= sprintf(
                '<tr%s>
                   <td>
                     <form method="post" action="/admin/forms" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="question" value="%d">
                       <input type="text" name="label" value="%s" style="min-width:16rem">
                       <button name="action" value="rename" class="btn tiny secondary">Rename</button>
                     </form>
                     %s
                   </td>
                   <td class="muted small">%s%s</td>
                   <td class="right">%s</td>
                 </tr>',
                $retired ? ' class="muted"' : '',
                $token,
                (int) $question['id'],
                e((string) $question['label']),
                $question['is_required'] ? '<span class="pill">needed</span>' : '',
                e($types[(string) $question['type']] ?? (string) $question['type']),
                $options === [] ? '' : '<br>' . e(implode(' · ', $options)),
                $this->questionButtons($question, $token, $retired)
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No questions yet.</td></tr>';
        }

        return <<<HTML
        <h2>The questions</h2>
        <table>
          <thead><tr><th>Question</th><th>Kind</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        <p class="muted small">Renaming one does <strong>not</strong> change what people already
           answered — an answer belongs to the question, not to the words it was asked in. A
           question you stop asking keeps its answers and appears in the spreadsheet after the
           live ones.</p>
        HTML;
    }

    /** @param array<string, mixed> $question */
    private function questionButtons(array $question, string $token, bool $retired): string
    {
        $id = (int) $question['id'];

        $button = static fn (string $action, string $label): string => sprintf(
            '<form method="post" action="/admin/forms" class="inline">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="question" value="%d">
               <button name="action" value="%s" class="btn tiny secondary">%s</button>
             </form>',
            $token,
            $id,
            $action,
            $label
        );

        if ($retired) {
            return $button('restore', 'Ask it again');
        }

        return $button('move-up', '&uarr;')
            . $button('move-down', '&darr;')
            // Never "Delete". The database refuses to delete an answered
            // question, and offering a button that fails is worse than not
            // offering one.
            . $button('retire', 'Stop asking');
    }

    /** @param array<string, mixed> $data */
    private function newQuestion(array $data, string $token, int $id): string
    {
        $options = '';
        foreach ((array) ($data['types'] ?? []) as $type => $label) {
            $options .= sprintf('<option value="%s">%s</option>', e((string) $type), e((string) $label));
        }

        return <<<HTML
        <h2>Ask something new</h2>
        <form method="post" action="/admin/forms">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">

          <label>Question <input type="text" name="label" required placeholder="How did you hear about us?"></label>
          <label>Kind <select name="type">{$options}</select></label>
          <label>Answers to choose from
            <textarea name="options" rows="4" placeholder="One per line"></textarea>
          </label>
          <p class="muted small">One per line, so an answer can contain a comma — "Sunday, 9am" is
             a normal thing to want. Only used by the three choice kinds, and the server checks
             answers against this list rather than against whatever the page said.</p>

          <label>A note under the question <input type="text" name="help"></label>
          <label class="check"><input type="checkbox" name="is_required" value="1"> Needed</label>

          <button class="btn" name="action" value="add-question">Add</button>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function responses(array $data, string $token, int $id): string
    {
        $questions = array_values(array_filter(
            (array) ($data['questions'] ?? []),
            static fn (array $q): bool => $q['retired_at'] === null
        ));
        $answers = (array) ($data['answers'] ?? []);

        $rows = '';

        foreach ((array) ($data['responses'] ?? []) as $response) {
            $responseId = (int) $response['id'];
            $given = (array) ($answers[$responseId] ?? []);

            $cells = '';
            foreach ($questions as $question) {
                $cells .= '<div><span class="muted small">' . e((string) $question['label'])
                    . '</span><br>'
                    . e(FieldType::display(
                        (string) $question['type'],
                        $given[(int) $question['id']] ?? null
                    ))
                    . '</div>';
            }

            $rows .= sprintf(
                '<tr>
                   <td class="muted small">%s</td>
                   <td>%s</td>
                   <td>%s</td>
                 </tr>',
                e((string) $response['created_at']),
                $cells,
                $this->handledCell($response, $token)
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Nobody has filled this in yet.</td></tr>';
        }

        return <<<HTML
        <h2>What people sent</h2>
        <p class="muted small">
          <a href="/admin/forms/{$id}/export.csv" class="btn tiny secondary">Download as a spreadsheet</a>
          Questions you have stopped asking come after the live ones, with their answers.
        </p>
        <table>
          <thead><tr><th>When</th><th>Answers</th><th>Dealt with</th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    /** @param array<string, mixed> $response */
    private function handledCell(array $response, string $token): string
    {
        $id = (int) $response['id'];

        if ($response['handled_at'] !== null) {
            return sprintf(
                '<strong>%s</strong><div class="muted small">%s</div>%s
                 <form method="post" action="/admin/forms" class="inline">
                   <input type="hidden" name="_token" value="%s">
                   <input type="hidden" name="response" value="%d">
                   <button name="action" value="unhandled" class="btn tiny secondary">Not yet</button>
                 </form>',
                e((string) $response['handled_by']),
                e((string) $response['handled_at']),
                empty($response['handled_note'])
                    ? ''
                    : '<div class="muted small">' . e((string) $response['handled_note']) . '</div>',
                $token,
                $id
            );
        }

        /*
         * The name comes from the session rather than a text box. The whole
         * value of this field is that it says WHO — the way follow-up fails is
         * two people each assuming the other rang — and a name somebody types
         * is a name somebody types "done" into.
         */
        return sprintf(
            '<form method="post" action="/admin/forms">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="response" value="%d">
               <input type="text" name="note" placeholder="Rang Tuesday">
               <button name="action" value="handled" class="btn tiny">I dealt with this</button>
             </form>',
            $token,
            $id
        );
    }

    private function checked(bool $on): string
    {
        return $on ? 'checked' : '';
    }

    private function text(string $value): string
    {
        return e($value);
    }
}
