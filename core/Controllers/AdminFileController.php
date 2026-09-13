<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminFileView;
use Portal\Auth\Capability;
use Portal\Content\AssetRepository;
use Portal\Content\FileKind;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Support\Audit;

/**
 * Every stored file in one place.
 *
 * Until this screen the only way to find an attachment was to open the video it
 * belonged to — no way to answer "what is filling the disk", or "which video was
 * that handout on". Uploading stays where it was, on the video and on the book:
 * a file is always added TO something, and a file manager that uploaded into
 * nothing would make orphans on purpose.
 *
 * # PERMISSIONS ARE PER ROW
 *
 * manage_files is scopable — it can be granted on one category — so the screen
 * asks canAnywhere (via requireAnywhere) to let a scoped editor in, and every
 * DELETE asks again against the video that file belongs to. The listing is not
 * filtered by scope, for the reason Controller::requireAnywhere() already
 * records: filtering means a resolver walk per row, and a second implementation
 * of the resolver in SQL would be one that eventually shows more than it should.
 * A scoped editor sees every file and can delete their own.
 */
final class AdminFileController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->requireAnywhere(Capability::MANAGE_FILES);

        $search = trim((string) ($request->query('q') ?? ''));
        $kind = (string) ($request->query('kind') ?? '');
        $kind = array_key_exists($kind, FileKind::labels()) ? $kind : '';
        $before = max(0, (int) ($request->query('before') ?? 0));

        $assets = $this->assets();
        $rows = $assets->library($search, $kind, $before, self::PER_PAGE + 1);

        // One more than a page, so "there is a next page" is a fact rather than a
        // guess from a full page that turns out to be the last.
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);

        return Response::html((new AdminFileView())->render([
            'files'    => $rows,
            'totals'   => $assets->totals(),
            'search'   => $search,
            'kind'     => $kind,
            'kinds'    => FileKind::labels(),
            'nextBefore' => $more && $rows !== [] ? (int) $rows[array_key_last($rows)]['id'] : 0,
            'screen'   => 'files',
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }

    /**
     * Delete the files that were ticked.
     *
     * One at a time, and a refusal does not stop the rest — the same rule the
     * bulk video actions keep. A page is not filtered by scope, so a selection
     * legitimately includes files somebody cannot touch; throwing on the first
     * would mean the button never works for them at all. Refusals are counted
     * AND named, because "3 were skipped" sends somebody hunting for which.
     */
    public function update(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->requireAnywhere(Capability::MANAGE_FILES);

        $ids = array_values(array_unique(array_filter(array_map('intval', $request->inputArray('files')))));

        if ($ids === []) {
            return $this->back($request, 'Nothing was ticked.', 'error');
        }

        $assets = $this->assets();
        $deleted = 0;
        $refused = [];

        foreach ($ids as $id) {
            $row = $assets->find($id);

            if ($row === null) {
                continue;
            }

            $name = (string) $row['original_name'];

            /*
             * Asked against the file's own video, per file. A file with no video
             * — a book's, or one whose video is gone — is a site-wide question.
             */
            $allowed = $row['video_id'] !== null
                ? $this->guard()->can(Capability::MANAGE_FILES, 'video', (int) $row['video_id'])
                : $this->guard()->can(Capability::MANAGE_FILES);

            if (!$allowed) {
                $refused[] = $name . ' is not yours to delete';
                continue;
            }

            $why = $assets->deleteUnlessInUse($id);

            if ($why !== null) {
                $refused[] = $name . ' ' . $why;
                continue;
            }

            $deleted++;

            Audit::log(
                $this->db(),
                $this->user()?->email,
                'file.delete',
                'file_asset',
                (string) $id,
                $name
            );
        }

        $message = $deleted === 1 ? 'Deleted 1 file.' : sprintf('Deleted %d files.', $deleted);

        if ($refused !== []) {
            $message .= ' Not deleted: ' . implode('; ', $refused) . '.';
        }

        return $this->back($request, $message, $refused === [] ? 'success' : 'error');
    }

    private function assets(): AssetRepository
    {
        return $this->container->get(AssetRepository::class);
    }
}
