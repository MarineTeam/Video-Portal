<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminBookView;
use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Reader\BookRepository;
use Portal\Reader\Locator;
use Portal\Reader\SongRepository;
use Portal\Support\Audit;
use Portal\Support\Csv;

/**
 * Keeping the books.
 *
 * The screen that matters is the OFFSET, and it is why the page is what gets
 * stored: getting it wrong sends a congregation to the wrong hymn, and putting
 * it right has to be one number rather than a migration.
 *
 * Indexing runs in the ADMIN'S BROWSER and posts its results here. OCR on
 * shared hosting is not available at any price, and the browser doing it is
 * idle anyway — so this end only stores what it is given.
 */
final class AdminBookController extends Controller
{
    public function index(Request $request): Response
    {
        $this->require(Capability::MANAGE_BOOKS);

        return $this->render('books', [
            'books' => $this->books()->shelf(true, null, true),
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_BOOKS);

        $books = $this->books();
        $book = $books->find((int) ($params['id'] ?? 0));

        if ($book === null) {
            throw HttpException::notFound('There is no book with that id.');
        }

        $contents = $books->contents((int) $book['id']);

        return $this->render('book', [
            'book'         => $book,
            'contents'     => $contents,
            'indexedPages' => $books->indexedPages((int) $book['id']),
            /*
             * A worked example of the current offset, so the effect of changing
             * it is visible before somebody saves. The number on the screen is
             * the whole point of the setting and it is otherwise invisible.
             */
            'example'      => [
                'pdf'     => 1 + (int) $book['page_offset'],
                'printed' => Locator::printedNumber(1 + (int) $book['page_offset'], (int) $book['page_offset']),
            ],
            'categories'   => $this->db()->all('SELECT id, name FROM {categories} ORDER BY name LIMIT 200'),
        ]);
    }

    /**
     * What we sang, for a licence return.
     *
     * The dates are the whole interface — a return covers a period, and the
     * period is the thing somebody gets wrong.
     */
    public function songs(Request $request): Response
    {
        $this->require(Capability::MANAGE_BOOKS);

        $to = $this->day((string) ($request->query('to') ?? ''), date('Y-m-d'));
        $from = $this->day((string) ($request->query('from') ?? ''), date('Y-m-d', strtotime('-1 year')));

        return $this->render('songs', [
            'report' => (new SongRepository($this->db()))->report($from, $to),
        ]);
    }

    /**
     * The same report as a spreadsheet.
     *
     * Through Portal\Support\Csv, which defuses formula injection — every cell
     * here is text somebody typed into an admin form, and the file is opened in
     * Excel by whoever files the return.
     */
    public function songsCsv(Request $request): Response
    {
        $this->require(Capability::MANAGE_BOOKS);

        $to = $this->day((string) ($request->query('to') ?? ''), date('Y-m-d'));
        $from = $this->day((string) ($request->query('from') ?? ''), date('Y-m-d', strtotime('-1 year')));

        $report = (new SongRepository($this->db()))->report($from, $to);

        $csv = Csv::document(
            ['Book', 'Number', 'Title', 'Author', 'Copyright', 'CCLI', 'Key', 'Tempo',
             'Times sung', 'First', 'Last'],
            array_map(
                static fn (array $song): array => [
                    (string) $song['book_title'],
                    (string) $song['number'],
                    (string) ($song['song_title'] ?? ''),
                    (string) ($song['author'] ?? ''),
                    (string) ($song['copyright'] ?? ''),
                    (string) ($song['ccli_number'] ?? ''),
                    (string) ($song['song_key'] ?? ''),
                    (string) ($song['tempo'] ?? ''),
                    (string) $song['times'],
                    (string) $song['first_used'],
                    (string) $song['last_used'],
                ],
                $report['songs']
            )
        );

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'songs.export',
            'songs',
            $from . '..' . $to,
            sprintf('%d song(s), %d without a CCLI number', $report['total'], $report['withoutCcli'])
        );

        return Response::text($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="' . Csv::filename('what-we-sang-' . $from . '-to-' . $to) . '"'
            )
            ->header('X-Content-Type-Options', 'nosniff')
            ->private();
    }

    private function day(string $raw, string $fallback): string
    {
        $stamp = trim($raw) === '' ? false : strtotime($raw);

        return $stamp === false ? $fallback : date('Y-m-d', $stamp);
    }

    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_BOOKS);

        $books = $this->books();
        $action = (string) ($request->input('action') ?? '');

        try {
            return match ($action) {
                'save-song'    => $this->saveSong($request),
                'record-sung'  => $this->recordSung($request),
                'create'       => $this->create($request, $books),
                'save'         => $this->save($request, $books),
                'add-entry'    => $this->addEntry($request, $books),
                'remove-entry' => $this->removeEntry($request, $books),
                default        => $this->back($request, 'That is not something this screen can do.', 'error'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function create(Request $request, BookRepository $books): Response
    {
        $id = $books->create(
            (string) ($request->input('title') ?? ''),
            (string) ($request->input('kind') ?? BookRepository::PDF)
        );

        Audit::log($this->db(), $this->user()?->email, 'book.create', 'book', (string) $id);

        return $this->redirect('/admin/books/' . $id);
    }

    private function save(Request $request, BookRepository $books): Response
    {
        $id = (int) ($request->input('id') ?? 0);
        $before = (array) $books->find($id);

        $attributes = ['_whole_form' => $request->input('_whole_form') !== null];

        foreach (['title', 'subtitle', 'author'] as $field) {
            if ($request->input($field) !== null) {
                $attributes[$field] = (string) $request->input($field);
            }
        }

        foreach (['page_count', 'page_offset', 'category_id', 'position'] as $number) {
            if ($request->input($number) !== null) {
                $attributes[$number] = (int) $request->input($number);
            }
        }

        foreach (['is_published', 'member_only', 'is_hymnal'] as $flag) {
            $attributes[$flag] = $request->input($flag) !== null;
        }

        $books->update($id, $attributes);

        $offsetChanged = isset($attributes['page_offset'])
            && (int) $attributes['page_offset'] !== (int) ($before['page_offset'] ?? 0);

        return $this->back(
            $request,
            $offsetChanged
                /*
                 * Said explicitly, because the surprising half is that nothing
                 * was rewritten. Somebody who expects a correction like this to
                 * be destructive will not make it.
                 */
                ? 'Saved. Every page number in the book now reads differently — no bookmark, '
                    . 'highlight or contents entry was changed, because what is stored is the '
                    . 'page in the file rather than the number printed on it.'
                : 'Saved.'
        );
    }

    private function addEntry(Request $request, BookRepository $books): Response
    {
        $books->addEntry(
            (int) ($request->input('id') ?? 0),
            (string) ($request->input('title') ?? ''),
            (int) ($request->input('pdf_page') ?? 1),
            (int) ($request->input('number') ?? 0)
        );

        return $this->back($request, 'Added.');
    }

    private function saveSong(Request $request): Response
    {
        $bookId = (int) ($request->input('id') ?? 0);
        $number = (int) ($request->input('number') ?? 0);

        (new SongRepository($this->db()))->saveSong($bookId, $number, [
            'author'      => (string) ($request->input('author') ?? ''),
            'copyright'   => (string) ($request->input('copyright') ?? ''),
            'ccli_number' => (string) ($request->input('ccli_number') ?? ''),
            'song_key'    => (string) ($request->input('song_key') ?? ''),
            'tempo'       => (string) ($request->input('tempo') ?? ''),
        ]);

        return $this->back($request, 'Saved. This survives re-indexing the book.');
    }

    private function recordSung(Request $request): Response
    {
        $songs = new SongRepository($this->db());

        $recorded = $songs->recordUse(
            (int) ($request->input('id') ?? 0),
            (int) ($request->input('number') ?? 0),
            (string) ($request->input('on_date') ?? date('Y-m-d')),
            null,
            SongRepository::MANUAL
        );

        return $this->back(
            $request,
            $recorded
                ? 'Recorded. It will appear on the licence return for that period.'
                : 'That was already recorded for that day.'
        );
    }

    private function removeEntry(Request $request, BookRepository $books): Response
    {
        $books->removeEntry((int) ($request->input('entry') ?? 0));

        return $this->back($request, 'Removed.');
    }

    /**
     * What an admin's browser worked out about a book.
     *
     * The contents, the page text, or both. Posted rather than computed here
     * because neither PDF parsing nor OCR is something a shared host can do —
     * and the browser that is about to sit idle for four minutes can.
     *
     * @param array<string, string> $params
     */
    public function receiveIndex(Request $request, array $params): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_BOOKS);

        $books = $this->books();
        $book = $books->find((int) ($params['id'] ?? 0));

        if ($book === null) {
            throw HttpException::notFound('There is no book with that id.');
        }

        $payload = $request->json();

        if ($payload === []) {
            throw HttpException::badRequest('That is not something this can read.');
        }

        $summary = [];

        if (isset($payload['contents']) && is_array($payload['contents'])) {
            $summary['entries'] = $books->replaceContents((int) $book['id'], $payload['contents']);
        }

        if (isset($payload['pages']) && is_array($payload['pages'])) {
            $summary['pages'] = $books->storePages((int) $book['id'], $payload['pages']);
        }

        if (!empty($payload['done'])) {
            $books->markIndexed((int) $book['id']);
        }

        if (isset($payload['page_count'])) {
            $books->update((int) $book['id'], ['page_count' => (int) $payload['page_count']]);
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'book.index',
            'book',
            (string) $book['id'],
            json_encode($summary) ?: ''
        );

        return Response::json(['stored' => $summary])->private();
    }

    // ---------------------------------------------------------------- wiring

    private function books(): BookRepository
    {
        return new BookRepository($this->db());
    }

    /** @param array<string, mixed> $data */
    private function render(string $screen, array $data): Response
    {
        $view = new AdminBookView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }
}
