<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Content\NoteSheet;
use Portal\Content\NoteSheetRepository;
use Portal\Content\Video;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;

/**
 * The fill-in-the-blank note sheet for a video.
 *
 * Readable by anybody who could see the video in a listing — signed out
 * included, because the paper version is handed to visitors at the door and a
 * sheet that needed an account to READ would be worse than the paper. Keeping
 * the answers needs an account, and the page says so rather than letting
 * somebody fill in a whole sheet and lose it.
 */
final class NoteSheetController extends Controller
{
    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $video = $this->resolve((string) ($params['slug'] ?? ''));
        $sheet = $this->sheets()->find($video->id);

        if ($sheet === null) {
            throw HttpException::notFound('There is no note sheet for that video.');
        }

        $user = $this->user();
        $saved = $user !== null ? $this->sheets()->answers($user->id, $video->id) : null;
        $gaps = NoteSheet::gapCount($sheet['outline']);
        $answers = NoteSheet::cleanAnswers($saved['answers'] ?? [], $gaps);

        return $this->view(['note-sheet'], [
            'title'     => 'Notes: ' . $video->title,
            'video'     => ['title' => $video->title, 'url' => $video->url(), 'slug' => $video->slug],
            'segments'  => NoteSheet::segments($sheet['outline']),
            'answers'   => $answers,
            'version'   => $sheet['version'],
            'changed'   => NoteSheet::changedSince($saved['version'] ?? null, $sheet['version'], $answers),
            'signedIn'  => $user !== null,
            'savedPlain' => $request->input('saved') === '1',
            // Only for somebody who can save: a token field in view data starts
            // a session for every anonymous reader, which this page has many of.
            'token'     => $user !== null ? $this->csrfToken() : '',
            'loginUrl'  => '/auth/login?returnTo=' . rawurlencode('/sheets/' . $video->slug),
        ]);
    }

    /**
     * Save answers — as they are typed, from the script, or from the Save
     * button when the script is blocked.
     *
     * The version saved is the one the PAGE was rendered with, not the current
     * one. If an editor changed the outline while somebody had the sheet open,
     * their answers were written against the old gaps, and storing them under
     * the new version would hide exactly the mismatch the notice exists for.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->json(['saved' => false], 401);
        }

        $this->verifyCsrf($request);

        $video = $this->resolve((string) ($params['slug'] ?? ''));
        $sheet = $this->sheets()->find($video->id);

        if ($sheet === null) {
            throw HttpException::notFound('There is no note sheet for that video.');
        }

        // A version from the future or from nowhere is the current one: it can
        // only come from a hand-built request, and inventing a mismatch for it
        // would put a false notice on somebody's sheet.
        $version = (int) $request->data('version', 0);
        if ($version < 1 || $version > $sheet['version']) {
            $version = $sheet['version'];
        }

        $this->sheets()->saveAnswers(
            $user->id,
            $video->id,
            $version,
            NoteSheet::cleanAnswers($request->data('answers', []), NoteSheet::gapCount($sheet['outline']))
        );

        if ($request->input('_plain') !== null) {
            return $this->redirect('/sheets/' . $video->slug . '?saved=1');
        }

        return $this->json(['saved' => true]);
    }

    /**
     * The video, if THIS viewer could see it listed — through the listing query,
     * so a members-only, group-restricted or unreleased video's outline is a 404
     * like its title would be. The same 404 whether the video is missing or
     * withheld: telling them apart tells somebody it exists.
     */
    private function resolve(string $slug): Video
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);
        $video = $videos->findBySlug($slug);

        if ($video === null || $videos->visibleInOrder([$video->id], $this->visibilityFilters([])) === []) {
            throw HttpException::notFound('There is no note sheet for that video.');
        }

        return $video;
    }

    private function sheets(): NoteSheetRepository
    {
        return new NoteSheetRepository($this->db());
    }
}
