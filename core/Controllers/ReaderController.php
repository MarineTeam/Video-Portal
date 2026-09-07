<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Auth\Capability;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Reader\BookRepository;
use Portal\Reader\Contents;
use Portal\Reader\Locator;
use Portal\Support\FileStream;

/**
 * Reading a book.
 *
 * # A CACHED BOOK STILL REVALIDATES BEFORE IT OPENS
 *
 * This is a deliberate difference from a downloaded video, which plays with the
 * network off, and it is NOT a bug to be fixed. A video download is a thing
 * somebody was given; a book in the cache is a copy of something they are
 * currently allowed to read, and "currently" is the whole point — removing
 * somebody's access has to take effect on their next attempt, not whenever
 * their cache happens to expire.
 *
 * So the reader asks this endpoint before it opens anything, every time. The
 * cost is real and is stated on the screen: a book will not open with no signal
 * at all. The alternative is a members-only hymnal that keeps opening for
 * somebody who left the church a year ago.
 */
final class ReaderController extends Controller
{
    public function index(Request $request): Response
    {
        $books = $this->books();

        return $this->view(['books'], [
            'title' => 'Books',
            'books' => $books->shelf($this->isMember()),
            'flash' => $this->flash(),
        ]);
    }

    /** @param array<string, string> $params */
    public function read(Request $request, array $params): Response
    {
        $book = $this->visibleBook((string) ($params['slug'] ?? ''));
        $books = $this->books();
        $contents = $books->contents((int) $book['id']);

        /*
         * Where to open. A link's reference wins, then where this person had
         * got to, then the beginning — so a link somebody was sent lands where
         * the sender meant even if the reader has their own position saved.
         */
        $reference = trim((string) ($request->query('at') ?? ''));
        $user = $this->user();
        $position = $user === null ? null : $books->positionFor((int) $book['id'], $user->id);

        if ($reference !== '') {
            $page = Contents::resolve($contents, $reference, (int) $book['page_offset']);
            $this->countIfHymn($book, $reference);
        } else {
            $page = $position === null ? 1 : (int) $position['pdf_page'];
        }

        return $this->view(['book', 'book-' . $book['slug']], [
            'title'    => (string) $book['title'],
            'book'     => $book,
            'contents' => $contents,
            'page'     => max(1, min($page, max(1, (int) $book['page_count']))),
            'position' => $position,
            'marks'    => $user === null ? [] : $books->marks((int) $book['id'], $user->id),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
        ]);
    }

    /**
     * The check the reader makes before it opens anything.
     *
     * Cheap on purpose — one indexed lookup and no file work — because it runs
     * on every open. It answers three things at once: may this person read it,
     * where is the file, and is the copy they are holding still current.
     *
     * @param array<string, string> $params
     */
    public function open(Request $request, array $params): Response
    {
        $book = $this->visibleBook((string) ($params['slug'] ?? ''));

        return Response::json([
            'slug'     => (string) $book['slug'],
            'kind'     => (string) $book['kind'],
            /*
             * The revision a saved copy compares against. A device holding an
             * older one knows to fetch the file again rather than serving pages
             * that no longer match the numbering.
             */
            'revision' => (int) $book['file_revision'],
            'pages'    => (int) $book['page_count'],
            'offset'   => (int) $book['page_offset'],
            'file'     => '/books/' . $book['slug'] . '/file',
        ])->private();
    }

    /**
     * The file itself, through an access-checked route.
     *
     * NEVER a direct link to storage. Every file this product serves goes
     * through a route that re-asks the question, which is what makes removing
     * somebody's access take effect on their next request rather than on a
     * cache's schedule.
     *
     * @param array<string, string> $params
     */
    public function file(Request $request, array $params): Response
    {
        $book = $this->visibleBook((string) ($params['slug'] ?? ''));

        if (empty($book['asset_id'])) {
            throw HttpException::notFound('That book has no file yet.');
        }

        $asset = $this->db()->first('SELECT * FROM {file_assets} WHERE id = ?', [(int) $book['asset_id']]);

        if ($asset === null) {
            throw HttpException::notFound('That book has no file yet.');
        }

        /** @var \Portal\Content\AssetRepository $assets */
        $assets = $this->container->get(\Portal\Content\AssetRepository::class);
        $path = $assets->absolutePath((string) $asset['path']);

        if ($path === null || !is_file($path)) {
            /*
             * The row exists and the file does not. A 404 rather than a 500 —
             * from outside those are the same thing — with the log line where
             * an administrator finds out it was the second.
             */
            error_log('Portal: book ' . $book['id'] . ' has no file at ' . $asset['path']);

            throw HttpException::notFound('That book has no file yet.');
        }

        // inline, because the point is a reader rendering it in the page. A
        // download prompt instead of a hymnal on a Sunday morning is the
        // feature not working.
        return FileStream::send($path, $asset, true);
    }

    /**
     * Where somebody had got to.
     *
     * Keyed to the signed-in person in the WHERE clause rather than taken from
     * the request, so nobody can move a stranger's bookmark by guessing an id.
     *
     * @param array<string, string> $params
     */
    public function savePosition(Request $request, array $params): Response
    {
        $this->verifyCsrf($request);

        $user = $this->user();
        $book = $this->visibleBook((string) ($params['slug'] ?? ''));

        if ($user === null) {
            // Not an error. Somebody reading without an account is the ordinary
            // case for a public book, and there is simply nowhere to keep it.
            return Response::json(['saved' => false]);
        }

        $page = max(1, (int) ($request->input('page') ?? 1));

        $this->books()->savePosition(
            (int) $book['id'],
            $user->id,
            $page,
            Locator::percent($page, (int) $book['page_count']),
            (string) ($request->input('cfi') ?? '')
        );

        return Response::json(['saved' => true]);
    }

    /**
     * Search inside one book, or across everything on the same shelf.
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $books = $this->books();
        $query = trim((string) ($request->query('q') ?? ''));
        $slug = trim((string) ($params['slug'] ?? ''));

        if ($slug !== '') {
            $book = $this->visibleBook($slug);
            $ids = [(int) $book['id']];
        } else {
            /*
             * Only books this person may read. Searching the text of a
             * members-only book would report its contents to a stranger a
             * snippet at a time, which is the same leak as listing its title.
             */
            $ids = array_map(
                static fn (array $row): int => (int) $row['id'],
                $books->shelf($this->isMember())
            );
        }

        return Response::json([
            'query'   => $query,
            'results' => $books->search($ids, $query),
        ])->private();
    }

    // ---------------------------------------------------------- internals

    /**
     * Somebody looked up a hymn.
     *
     * Counted only for a NUMBER, never for a page: the question is "what do we
     * sing", and a page reference is somebody sharing a spot in a book.
     *
     * @param array<string, mixed> $book
     */
    private function countIfHymn(array $book, string $reference): void
    {
        $parsed = Locator::parse($reference);

        if ($parsed['kind'] === Locator::NUMBER && $book['is_hymnal']) {
            $this->books()->countLookup((int) $book['id'], $parsed['value']);
        }
    }

    /**
     * The book, or a 404 — including when it exists and is not for you.
     *
     * One function, so no screen can decide this differently, and the 404 for
     * a members-only book is the SAME 404 as for one that does not exist.
     *
     * @return array<string, mixed>
     */
    private function visibleBook(string $slug): array
    {
        $book = $this->books()->findBySlug($slug);

        if ($book === null) {
            throw HttpException::notFound('There is no book here.');
        }

        if (!$book['is_published'] && !$this->canManage()) {
            throw HttpException::notFound('There is no book here.');
        }

        if ($book['member_only'] && !$this->isMember()) {
            throw HttpException::notFound('There is no book here.');
        }

        return $book;
    }

    private function isMember(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->isAdmin() || $user->authorized);
    }

    private function canManage(): bool
    {
        return $this->guard()->can(Capability::MANAGE_BOOKS);
    }

    private function books(): BookRepository
    {
        return new BookRepository($this->db());
    }
}
