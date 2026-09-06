<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminFormView;
use Portal\Auth\Capability;
use Portal\Forms\FieldType;
use Portal\Forms\FormExport;
use Portal\Forms\FormRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;
use Portal\Support\Csv;

/**
 * Building forms, and reading what people send back.
 *
 * MANAGE_FORMS throughout, including for the questions. It looks like two
 * different jobs — arranging fields, and reading personal details a stranger
 * typed into a public page — but whoever can add a question can add "what is
 * your address", so the two cannot be separated in any way that means anything.
 */
final class AdminFormController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_FORMS);

        $forms = $this->forms();
        $rows = [];

        foreach ($forms->forms(true, true) as $form) {
            $form['outstanding'] = $forms->outstandingCount((int) $form['id']);
            $rows[] = $form;
        }

        return $this->render('forms', ['forms' => $rows]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_FORMS);

        $forms = $this->forms();
        $form = $forms->find((int) ($params['id'] ?? 0));

        if ($form === null) {
            throw HttpException::notFound('There is no form with that id.');
        }

        $responses = $forms->responses((int) $form['id']);

        return $this->render('form', [
            'form'      => $form,
            'questions' => $forms->allQuestions((int) $form['id']),
            'responses' => $responses,
            'answers'   => $forms->answersForMany(array_map(
                static fn (array $row): int => (int) $row['id'],
                $responses
            )),
            'types'     => FieldType::all(),
        ]);
    }

    /**
     * A form's responses as a spreadsheet.
     *
     * Retired questions come after the live ones — see FormExport for why
     * neither leaving them out nor mixing them in is right.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_FORMS);

        $forms = $this->forms();
        $form = $forms->find((int) ($params['id'] ?? 0));

        if ($form === null) {
            throw HttpException::notFound('There is no form with that id.');
        }

        $responses = $forms->responses((int) $form['id'], false, 1000);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'form.export',
            'form',
            (string) $form['id'],
            sprintf('%d response(s)', count($responses))
        );

        $csv = FormExport::csv(
            $forms->allQuestions((int) $form['id']),
            $responses,
            $forms->answersForMany(array_map(
                static fn (array $row): int => (int) $row['id'],
                $responses
            ))
        );

        return Response::text($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="' . Csv::filename((string) $form['slug']) . '"'
            )
            /*
             * The browser must not decide this is HTML. Every cell here is text
             * a stranger typed into a public form, so a CSV that sniffs as HTML
             * is a page built out of exactly that.
             */
            ->header('X-Content-Type-Options', 'nosniff')
            ->private();
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_FORMS);

        $forms = $this->forms();
        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'create'      => $this->create($request, $forms),
                'save'        => $this->save($request, $forms),
                'add-question' => $this->addQuestion($request, $forms),
                'rename'      => $this->rename($request, $forms),
                'retire'      => $this->retire($request, $forms, true),
                'restore'     => $this->retire($request, $forms, false),
                'move-up'     => $this->move($request, $forms, -1),
                'move-down'   => $this->move($request, $forms, 1),
                'handled'     => $this->handled($request, $forms),
                'unhandled'   => $this->unhandled($request, $forms),
                default       => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ----------------------------------------------------------- the form

    private function create(Request $request, FormRepository $forms): Response
    {
        $id = $forms->create((string) ($request->input('title') ?? ''));

        Audit::log($this->db(), $this->user()?->email, 'form.create', 'form', (string) $id);

        return $this->redirect('/admin/forms/' . $id);
    }

    private function save(Request $request, FormRepository $forms): Response
    {
        $id = (int) ($request->input('id') ?? 0);

        $attributes = [];

        foreach (['title', 'description', 'notify_emails', 'thanks'] as $field) {
            if ($request->input($field) !== null) {
                $attributes[$field] = (string) $request->input($field);
            }
        }

        /*
         * The marker the video form has used since Phase 4. An unticked box and
         * an omitted one are both nothing, so a form that means "these are all
         * my checkboxes" has to say so — otherwise a partial save reads absence
         * as "switch it off" and quietly closes a form nobody asked to close.
         */
        if ($request->input('_whole_form') !== null) {
            $attributes['is_open'] = $request->input('is_open') !== null;
            $attributes['member_only'] = $request->input('member_only') !== null;
        }

        $forms->update($id, $attributes);

        return $this->back($request, 'Saved.');
    }

    // ------------------------------------------------------ the questions

    private function addQuestion(Request $request, FormRepository $forms): Response
    {
        $type = (string) ($request->input('type') ?? FieldType::TEXT);

        $forms->addQuestion(
            (int) ($request->input('id') ?? 0),
            (string) ($request->input('label') ?? ''),
            $type,
            FieldType::hasOptions($type)
                ? FieldType::parseOptions((string) ($request->input('options') ?? ''))
                : [],
            $request->input('is_required') !== null,
            (string) ($request->input('help') ?? '')
        );

        return $this->back($request, 'Added.');
    }

    private function rename(Request $request, FormRepository $forms): Response
    {
        $forms->renameQuestion(
            (int) ($request->input('question') ?? 0),
            (string) ($request->input('label') ?? ''),
            (string) ($request->input('help') ?? '')
        );

        // Said explicitly, because the surprising half is what does NOT change.
        return $this->back(
            $request,
            'Renamed. Answers already given still say what they were answering — a response from '
            . 'March did not change meaning.'
        );
    }

    private function retire(Request $request, FormRepository $forms, bool $retired): Response
    {
        $forms->retireQuestion((int) ($request->input('question') ?? 0), $retired);

        return $this->back(
            $request,
            $retired
                ? 'No longer asked. The answers people already gave are kept, and they appear in the '
                    . 'spreadsheet after the questions you are still asking.'
                : 'Being asked again.'
        );
    }

    private function move(Request $request, FormRepository $forms, int $direction): Response
    {
        $forms->moveQuestion((int) ($request->input('question') ?? 0), $direction);

        return $this->back($request);
    }

    // ------------------------------------------------------ the responses

    private function handled(Request $request, FormRepository $forms): Response
    {
        $id = (int) ($request->input('response') ?? 0);

        /*
         * The signed-in person's name, not a free-text box. A name somebody
         * types is a name somebody can leave blank or fill in with "done", and
         * the whole value of this field is that it says WHO — the way follow-up
         * fails is two people each assuming the other rang.
         */
        $user = $this->user();
        $who = trim((string) ($user?->name ?? '')) ?: (string) ($user?->email ?? '');

        $forms->markHandled($id, $who, (string) ($request->input('note') ?? ''));

        return $this->back($request, 'Marked as dealt with by ' . $who . '.');
    }

    private function unhandled(Request $request, FormRepository $forms): Response
    {
        $forms->markUnhandled((int) ($request->input('response') ?? 0));

        return $this->back($request, 'Back on the list.');
    }

    // ---------------------------------------------------------------- wiring

    private function forms(): FormRepository
    {
        return new FormRepository($this->db());
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminFormView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
