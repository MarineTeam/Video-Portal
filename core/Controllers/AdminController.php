<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminView;
use Portal\Auth\Capability;
use Portal\Auth\UserRepository;
use Portal\Content\BulkAction;
use Portal\Content\CategoryRepository;
use Portal\Content\DownloadPolicy;
use Portal\Content\RevisionRepository;
use Portal\Content\ThumbnailPolicy;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginManager;
use Portal\Providers\ProviderRegistry;
use Portal\Support\Audit;
use Portal\Support\PackageInstaller;
use Throwable;

/**
 * The admin area.
 *
 * Every handler re-checks its own capability. The route-level `admin.area`
 * guard only decides who gets through the front door — it deliberately admits
 * anyone with any admin capability, so a category editor is not met with a 403
 * on /admin itself. Relying on it for authorisation would give that editor the
 * run of the place.
 */
final class AdminController extends Controller
{
    /**
     * What stops working when a service is not configured, in the words of the
     * thing somebody came here to do.
     *
     * "Mail is unconfigured" is a fact about the software. "No share links,
     * approval requests or new-video notices will be delivered" is a fact about
     * their site, and it is the one that gets the box filled in.
     */
    private const PROVIDER_COST = [
        ProviderRegistry::KIND_AUTH  => 'nobody can sign in',
        ProviderRegistry::KIND_VIDEO => 'uploads and playback will not work',
        ProviderRegistry::KIND_MAIL  =>
            'share links, approval requests and new-video notices will not be delivered',
    ];

    public function dashboard(Request $request): Response
    {
        $stats = [];

        try {
            $stats = [
                'videos'     => (int) $this->db()->value('SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL'),
                'published'  => (int) $this->db()->value(
                    'SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL AND is_published = 1 AND status = "ready"'
                ),
                'processing' => (int) $this->db()->value(
                    'SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL AND status = "processing"'
                ),
                'categories' => (int) $this->db()->value('SELECT COUNT(*) FROM {categories}'),
                'users'      => (int) $this->db()->value('SELECT COUNT(*) FROM {users}'),
                'pending'    => (int) $this->db()->value('SELECT COUNT(*) FROM {users} WHERE authorized = 0'),
            ];
        } catch (Throwable $e) {
            error_log('Portal: dashboard counts failed: ' . $e->getMessage());
        }

        return $this->admin('dashboard', [
            'stats'    => $stats,
            'activity' => $this->guard()->can(Capability::VIEW_AUDIT_LOG)
                ? Audit::recent($this->db(), 15)
                : [],
            'providers' => $this->providerSummary(),
            'schema'    => $this->schemaHealth(),
        ]);
    }

    /**
     * Whether the database is actually the shape this code expects.
     *
     * The upgrade path here is "git pull, and the next request migrates" —
     * which works until it does not, and until now a migration that failed was
     * caught, written to the error log, and invisible. On a shared host with no
     * shell the error log is the one place nobody can read, so the site went on
     * serving a half-applied schema. That does not look broken; it looks like
     * features that mysteriously do not work.
     *
     * @return array{ok: bool, pending: list<string>, applied: int, expected: int, error: string}
     */
    private function schemaHealth(): array
    {
        try {
            $status = (new \Portal\Migrator($this->db()))->coreStatus();

            $error = (string) ($this->db()->value(
                'SELECT `value` FROM {settings} WHERE `key` = ?',
                ['last_migration_error']
            ) ?? '');

            return [
                'ok'       => $status['pending'] === [] && $error === '',
                'pending'  => $status['pending'],
                'applied'  => count($status['applied']),
                'expected' => count($status['expected']),
                'error'    => $error,
            ];
        } catch (Throwable $e) {
            error_log('Portal: could not read the schema state: ' . $e->getMessage());

            // Reported as a problem rather than as fine. Not being able to tell
            // is itself worth showing on the screen somebody checks after a
            // deployment.
            return [
                'ok'       => false,
                'pending'  => [],
                'applied'  => 0,
                'expected' => 0,
                'error'    => 'The schema state could not be read: ' . $e->getMessage(),
            ];
        }
    }

    // --------------------------------------------------------------- videos

    public function videos(Request $request): Response
    {
        $this->requireAnywhere(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $page = max(1, (int) ($request->query('page') ?? 1));

        /*
         * Whitelisted here as well as in the repository. Not belt and braces —
         * this one decides which tab renders as selected, and an unrecognised
         * value must fall back to "All" rather than highlighting nothing while
         * the list quietly shows everything.
         */
        $status = (string) ($request->query('status') ?? '');
        if (!in_array($status, \Portal\Content\Video::STATUSES, true)) {
            $status = '';
        }

        $result = $videos->query([
            'includeUnpublished' => true,
            'includeProcessing'  => true,
            'includeHidden'      => true,
            'includeMemberOnly'  => true,
            'search'             => $request->query('q') ?? '',
            'status'             => $status,
        ], $page, 25);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        return $this->admin('videos', [
            'videos'     => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'search'     => $request->query('q') ?? '',
            'status'     => $status,
            'categories' => $categories->all(true),
            'canUpload'  => $this->canUpload(),
            'trashed'    => $videos->trashedCount(),
            /*
             * Tags for the whole page in ONE query, not one per row. The
             * per-item call inside a foreach is the cost the batched version
             * exists to avoid, and a listing is exactly where somebody reaches
             * for it.
             */
            'tagsByVideo' => $this->tagRepo()->forItems(
                'video',
                array_map(static fn ($v): int => $v->id, $result['items'])
            ),
            // Suggestions for the bulk tag box. Without them, bulk tagging is
            // the fastest way to spread "prayers" beside "prayer" across two
            // hundred videos at once.
            'tagChoices'  => $this->tagRepo()->all(),
            /*
             * Counted, not inferred from the page on screen. A filter tab
             * reading "Failed" with no number beside it gives an admin no
             * reason to press it, and the whole point is that they may have
             * failed videos they do not know about.
             */
            'failed'     => $videos->countByStatus(\Portal\Content\Video::STATUS_FAILED),
        ]);
    }

    /**
     * Edit one video.
     *
     * The listing deliberately stays a listing. Everything worth changing about
     * a video — description, categories, watermark, artwork — needs room to
     * explain itself, and inline forms in a table row give none.
     *
     * @param array<string, string> $params
     */
    public function editVideo(Request $request, array $params): Response
    {
        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $video = $videos->find((int) ($params['id'] ?? 0));
        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        /*
         * Asked ABOUT this video, not in general.
         *
         * Scoped grants have been storable since Phase 1 — the permissions
         * screen has a "Limited to" dropdown offering a category or a series —
         * and no check anywhere passed a scope, so `can()` was always asked the
         * site-wide question. resolve() answers that with false for a scoped
         * grant, which meant a category-scoped editor could enter the admin
         * area (canSeeAdmin matches any grant regardless of scope) and then got
         * 403 on every screen inside it.
         *
         * The video has to be loaded first, because the question is about the
         * object. Ordering a permission check after a lookup is normally the
         * wrong way round; here the lookup is what makes the check answerable,
         * and find() reveals nothing — a 404 for a missing video is the same
         * answer everybody gets.
         */
        $this->require(Capability::MANAGE_VIDEOS, 'video', $video->id);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        return $this->admin('video-edit', [
            'video'          => $video,
            'categories'     => $categories->all(true),
            'assigned'       => $videos->categoryIds($video->id),
            'series'         => $this->seriesRepo()->all(true),
            'speakers'       => $this->speakerRepo()->all(),
            'inheritedLabel' => $this->inheritedThumbnailLabel($videos, $video),
            'inheritedDownloadLabel' => $this->inheritedDownloadLabel($videos, $video),
            'transcript'     => $this->transcriptSummary($video->id),
            'chapters'       => $this->chapterText($video->id),
            'assets'         => $this->attachments($video->id),
            'captions'       => $this->captions($video),
            'captionsSupported' => $this->captionProvider() !== null,
            'scripture'      => $this->scriptureForEdit($video->id),
            'tags'           => $this->tagText($video->id),
            /*
             * Every tag already in use, for the field's autocomplete.
             *
             * This is the main defence against the vocabulary problem a
             * create-on-write design has: without seeing what exists, people
             * invent "prayers" beside "prayer" and the two never meet again
             * without somebody noticing and merging them by hand.
             */
            'tagChoices'     => $this->tagRepo()->all(),
        ] + $this->revisionPanel(RevisionRepository::VIDEO, $video->id));
    }

    /**
     * The history panel's data for one subject.
     *
     * Wrapped because on the request that applies migration 0008 the table does
     * not exist yet, and an edit screen that 500s because a history panel could
     * not load is a worse outcome than one without the panel.
     *
     * @return array{revisions: list<array<string, mixed>>, revisionDifferences: array<int, mixed>}
     */
    /**
     * The transcript summary for the edit screen, or null.
     *
     * Wrapped for the same reason as the revision panel: on the request that
     * applies migration 0009 the table does not exist yet, and an edit screen
     * that 500s because of a summary line is worse than one without it.
     *
     * @return array<string, mixed>|null
     */
    private function transcriptSummary(int $videoId): ?array
    {
        try {
            return $this->transcripts()->find($videoId);
        } catch (Throwable $e) {
            error_log('Could not read the transcript: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The chapter list as text, in the shape it was typed.
     *
     * So changing one title does not mean rebuilding the list. Wrapped for the
     * same reason as the other two panels: on the request that applies
     * migration 0010 the table does not exist yet.
     */
    /**
     * A video's attachments.
     *
     * Wrapped like the other panels: on the request that applies migration
     * 0012 the table does not exist yet.
     *
     * @return list<array<string, mixed>>
     */
    private function attachments(int $videoId): array
    {
        try {
            return $this->container->get(\Portal\Content\AssetRepository::class)->forVideo($videoId);
        } catch (Throwable $e) {
            error_log('Could not read the attachments: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * This video's tags, as the comma-separated line the form shows.
     *
     * Failing quiet and returning empty would be wrong here: an unreadable tag
     * list rendered as a blank field, then saved, DELETES every tag on the
     * video — the partial-save defect again, in a place where the form looks
     * complete. So the exception propagates and the screen fails to render,
     * which is recoverable, rather than rendering a lie that is not.
     */
    private function tagText(int $videoId): string
    {
        $tags = $this->container
            ->get(\Portal\Content\TagRepository::class)
            ->forItem('video', $videoId);

        return implode(', ', array_map(static fn ($tag): string => $tag->name, $tags));
    }

    private function chapterText(int $videoId): string
    {
        try {
            return \Portal\Content\ChapterParser::toText(
                $this->container->get(\Portal\Content\ChapterRepository::class)->forVideo($videoId)
            );
        } catch (Throwable $e) {
            error_log('Could not read the chapters: ' . $e->getMessage());

            return '';
        }
    }

    private function revisionPanel(string $subjectType, int $subjectId): array
    {
        try {
            $repo = $this->revisions();
            $revisions = $repo->forSubject($subjectType, $subjectId);

            $differences = [];
            foreach ($revisions as $revision) {
                $differences[(int) $revision['id']] = $repo->differences(
                    $subjectType,
                    $subjectId,
                    (array) $revision['data']
                );
            }

            return ['revisions' => $revisions, 'revisionDifferences' => $differences];
        } catch (Throwable $e) {
            error_log('Could not load the revision history: ' . $e->getMessage());

            return ['revisions' => [], 'revisionDifferences' => []];
        }
    }

    public function updateVideo(Request $request): Response
    {
        $this->verifyCsrf($request);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        /*
         * A bulk submission before the single-video path, because it carries no
         * `id` at all — falling through would 404 on a missing video rather
         * than doing what the button says.
         *
         * Bulk checks each video separately, inside bulkVideos(). Asking the
         * site-wide question here first would refuse a scoped editor the whole
         * button rather than the rows they may not touch.
         */
        if ($request->input('bulk') !== null) {
            return $this->bulkVideos($request, $videos);
        }

        $id = (int) ($request->input('id') ?? 0);
        $video = $videos->find($id);

        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        $this->require(Capability::MANAGE_VIDEOS, 'video', $video->id);

        $action = $request->input('action') ?? 'save';

        /*
         * A rejected value comes back as a message on the form, not as a 400.
         *
         * Every other admin screen here already did this; the video save did
         * not, so a mistyped date or a backwards schedule threw the editor onto
         * an error page with their other changes lost. The repository is still
         * the thing that refuses — this only decides how the refusal is shown.
         */
        try {
            return $this->saveVideo($request, $videos, $video, $id, $action);
        } catch (HttpException $e) {
            /*
             * Only a bad value. A 403 has to stay a 403 — turning "you may not
             * publish" into a flash message would make a refused action look
             * like a failed one, and the capability checks in this switch are
             * the point of them being there.
             */
            if ($e->status !== 400) {
                throw $e;
            }

            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    /**
     * The same actions as the single-row buttons, over a selection.
     *
     * The sharing screens have had bulk actions since Phase 2 and the video
     * library never has, so a site with four hundred videos publishes them one
     * at a time.
     *
     * Each item is done on its own and counted. A transaction around the whole
     * selection would be tidier and wrong: on shared hosting the request can be
     * cut off by the execution limit, and rolling back two hundred videos
     * because the two hundred and first timed out throws away work that
     * succeeded. Per-item, with a report, means a person can see where it got
     * to and run it again.
     */
    private function bulkVideos(Request $request, VideoRepository $videos): Response
    {
        $action = (string) ($request->input('bulk') ?? '');

        if (!BulkAction::isKnown($action)) {
            return $this->back($request, 'That is not something this screen can do.', 'error');
        }

        /*
         * The same capability the single-row button checks. A bulk endpoint is
         * exactly where somebody eventually forgets, and the consequence of
         * forgetting here is worse — it is the whole library rather than one
         * row.
         *
         * Anywhere, not site-wide: the real check is per video, below, and
         * a scoped editor must be able to press the button for the rows they do
         * hold. This only refuses somebody who holds the capability nowhere.
         */
        $capability = (string) BulkAction::capability($action);
        $this->requireAnywhere($capability);

        $raw = $request->inputArray('selected');
        $ids = BulkAction::ids($raw);

        if ($ids === []) {
            return $this->back($request, 'Nothing was selected.', 'error');
        }

        $categoryId = (int) ($request->input('bulk_category') ?? 0);
        if ($action === 'categorise') {
            if ($categoryId <= 0) {
                return $this->back($request, 'Choose a category to add them to.', 'error');
            }

            // Filing something somewhere needs permission on WHERE, as well as
            // on the thing being filed. Checked once — it is the same category
            // for every row.
            $this->require(Capability::MANAGE_CATEGORIES, 'category', $categoryId);
        }

        /*
         * Parsed ONCE, before the loop.
         *
         * Running the parser per video would do the same work two hundred
         * times, and — worse — a name that parses to nothing would be
         * discovered on video one and again on video two hundred, with the
         * refusal message arriving after half the library had been touched.
         */
        $tagNames = [];
        if ($action === 'tag') {
            $tagNames = \Portal\Content\TagRepository::parse((string) ($request->input('bulk_tags') ?? ''));

            if ($tagNames === []) {
                return $this->back(
                    $request,
                    'Type at least one tag. Nothing here can be used as one — a tag needs a letter or a number.',
                    'error'
                );
            }
        }

        $changed = 0;
        $failures = [];

        foreach ($ids as $id) {
            $video = $videos->find($id);

            if ($video === null) {
                // Deleted between the page rendering and the button being
                // pressed. Not an error worth stopping for, but worth counting.
                $failures[] = '#' . $id . ' no longer exists';
                continue;
            }

            /*
             * Asked about each video, so a bulk press does exactly what pressing
             * every single-row button would have done — no more.
             *
             * Refused rows are counted and named rather than aborting the run.
             * The list on screen is not filtered by scope (see
             * Controller::requireAnywhere), so a scoped editor selecting a page
             * of videos will legitimately include some they cannot touch, and
             * throwing 403 would mean the button never works for them at all.
             */
            if (!$this->guard()->can($capability, 'video', $id)) {
                $failures[] = $video->title . ': not yours to change';
                continue;
            }

            try {
                switch ($action) {
                    case 'publish':
                        $videos->update($id, ['is_published' => true, 'published_at' => date('Y-m-d H:i:s')]);
                        break;

                    case 'unpublish':
                        $videos->update($id, ['is_published' => false]);
                        break;

                    case 'categorise':
                        // Added to what it already has, not replacing it. A
                        // bulk button that silently cleared every other
                        // category would be the partial-save defect again, in
                        // a place where it destroys taxonomy rather than a flag.
                        $existing = $videos->categoryIds($id);
                        if (!in_array($categoryId, $existing, true)) {
                            $existing[] = $categoryId;
                            $videos->setCategories($id, $existing);
                        }
                        break;

                    case 'tag':
                        /*
                         * ADDS, the same as categorise above and the opposite
                         * of the tag field on the edit screen.
                         *
                         * The difference is what the person can see. A form
                         * shows the complete current list, so an empty box
                         * means "remove them all" and replacing is right. A
                         * bulk bar shows the names being ADDED and nothing
                         * about what each of two hundred videos already
                         * carries — so replacing there would silently wipe
                         * tagging nobody was looking at.
                         */
                        $tags = $this->tagRepo();
                        $current = array_map(
                            static fn ($tag): string => $tag->name,
                            $tags->forItem('video', $id)
                        );

                        $tags->setFor('video', $id, array_merge($current, $tagNames));
                        break;

                    case 'trash':
                        $videos->softDelete($id);
                        do_action('video_deleted', $id, $video->title);
                        break;
                }

                $changed++;
            } catch (Throwable $e) {
                $failures[] = $video->title . ': ' . $e->getMessage();
            }
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'video.bulk.' . $action,
            null,
            null,
            $changed . ' of ' . count($ids)
        );

        $message = BulkAction::report($action, $changed, $failures);

        if (BulkAction::wasTruncated($raw)) {
            $message .= sprintf(
                ' Only the first %d were handled — select fewer and run it again.',
                BulkAction::MAX_PER_REQUEST
            );
        }

        return $this->back($request, $message, $failures === [] ? 'success' : 'error');
    }

    private function saveVideo(
        Request $request,
        VideoRepository $videos,
        \Portal\Content\Video $video,
        int $id,
        string $action
    ): Response {
        switch ($action) {
            case 'delete':
                $videos->softDelete($id);
                Audit::log($this->db(), $this->user()?->email, 'video.delete', 'video', (string) $id, $video->title);
                do_action('video_deleted', $id, $video->title);
                return $this->back($request, 'Video moved to trash.');

            case 'publish':
                $this->require(Capability::PUBLISH_CONTENT, 'video', $id);
                $videos->update($id, ['is_published' => true, 'published_at' => date('Y-m-d H:i:s')]);
                Audit::log($this->db(), $this->user()?->email, 'video.publish', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video published.');

            case 'unpublish':
                $this->require(Capability::PUBLISH_CONTENT, 'video', $id);
                $videos->update($id, ['is_published' => false]);
                Audit::log($this->db(), $this->user()?->email, 'video.unpublish', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video unpublished.');

            case 'recheck':
                return $this->recheckVideo($request, $videos, $video, $id);

            case 'test-download':
                return $this->testDownload($request, $videos, $video);

            case 'restore-revision':
                return $this->restoreRevision($request, RevisionRepository::VIDEO, $id);

            /*
             * MANAGE_FILES, which until now was declared, described on the
             * permissions screen, handed to two roles, and enforced by nothing.
             * Attachments were governed by MANAGE_VIDEOS alone, so withholding
             * it stopped nobody and granting it let nobody do anything — a
             * switch on a screen that was wired to no lamp.
             *
             * In ADDITION to the video check updateVideo() has already made,
             * not instead of it: uploading a file to this host is a different
             * risk from renaming a video, which is why the capability exists
             * separately.
             */
            case 'attach':
                $this->require(Capability::MANAGE_FILES, 'video', $id);
                return $this->attachFile($request, $id);

            case 'detach':
                $this->require(Capability::MANAGE_FILES, 'video', $id);
                $this->container
                    ->get(\Portal\Content\AssetRepository::class)
                    ->delete((int) ($request->input('asset') ?? 0));

                Audit::log($this->db(), $this->user()?->email, 'asset.delete', 'video', (string) $id);

                return $this->back($request, 'Attachment removed.');

            case 'transcript':
                return $this->saveTranscript($request, $id);

            case 'caption':
                return $this->saveCaption($request, $video);

            case 'caption-delete':
                return $this->deleteCaption($request, $video);

            case 'chapters':
                $submitted = trim((string) ($request->input('chapters') ?? ''));
                $chapters = \Portal\Content\ChapterParser::parse($submitted);

                /*
                 * Checked BEFORE the write, not after.
                 *
                 * An empty box is a legitimate answer — that is how somebody
                 * removes chapters. Text that produced nothing is a format
                 * mistake, and the first version of this refused it with a
                 * message after having already replaced the list with the
                 * empty one. The message was right and the damage was done:
                 * one mistyped save silently wiped a real list.
                 */
                if ($chapters === [] && $submitted !== '') {
                    return $this->back(
                        $request,
                        'No chapters could be read from that, so nothing was changed. Each line needs a timestamp first, like "2:15 The reading".',
                        'error'
                    );
                }

                $stored = $this->container
                    ->get(\Portal\Content\ChapterRepository::class)
                    ->replace($id, $chapters);

                Audit::log($this->db(), $this->user()?->email, 'chapters.save', 'video', (string) $id, (string) $stored);

                return $this->back($request, $stored === 0
                    ? 'Chapters cleared.'
                    : sprintf('Saved %d chapter(s).', $stored));

            case 'transcript-delete':
                $this->transcripts()->delete($id);
                Audit::log($this->db(), $this->user()?->email, 'transcript.delete', 'video', (string) $id);
                return $this->back($request, 'Transcript removed.');

            default:
                /*
                 * Snapshot before the write, so the newest revision is the
                 * state you can go back TO rather than the one you are about
                 * to be in. Recorded here rather than in the repository
                 * because this is where a HUMAN edit happens — the provider
                 * sync also calls update(), and burying one editorial change
                 * under a hundred machine writes would make the history
                 * useless for the thing it exists to do.
                 */
                $this->revisions()->record(RevisionRepository::VIDEO, $id, $this->user()?->email ?? '');

                /*
                 * Absent and empty are different answers.
                 *
                 * A field the form did not send means "leave this alone"; a
                 * field sent empty means "clear it". Collapsing the two —
                 * which this did — makes any POST carrying a subset of the
                 * form silently destroy everything it left out. A smoke check
                 * that saved a thumbnail setting detached the video from its
                 * series and its speaker, and nothing said so.
                 *
                 * The real edit form always submits every select, so this was
                 * invisible from the browser. It is still wrong: a plugin
                 * screen, a future partial form, or a bulk action would each
                 * hit it, and the loss looks like the data was never there.
                 */
                $whole = $request->input('_whole_form') !== null;

                $seriesRaw = $request->input('series_id');
                $speakerRaw = $request->input('speaker_id');

                $videos->update($id, [
                    'title'          => $request->input('title') ?? $video->title,
                    'description'    => $request->input('description') === null
                        ? $video->description
                        : $request->input('description'),
                    'watermark_mode' => $request->input('watermark_mode') ?? $video->watermarkMode,
                    'thumbnail_mode' => $request->input('thumbnail_mode') ?? $video->thumbnailMode,
                    'download_mode'  => $request->input('download_mode') ?? $video->downloadMode,
                    // Zero means "none", which has to be expressible — so an
                    // empty selection becomes null rather than 0, which no
                    // series or speaker will ever have as an id.
                    'published_at'   => $request->input('published_at') === null
                        ? $video->publishedAt
                        : $request->input('published_at'),
                    'unpublish_at'   => $request->input('unpublish_at') === null
                        ? $video->unpublishAt
                        : $request->input('unpublish_at'),
                    'series_id'      => $seriesRaw === null
                        ? $video->seriesId
                        : (($s = (int) $seriesRaw) > 0 ? $s : null),
                    'speaker_id'     => $speakerRaw === null
                        ? $video->speakerId
                        : (($p = (int) $speakerRaw) > 0 ? $p : null),
                    /*
                     * Checkboxes and multi-selects are the cases where absent
                     * and empty genuinely cannot be told apart: a browser sends
                     * nothing for an unchecked box and nothing for a category
                     * list with none ticked.
                     *
                     * So the form declares itself complete with a hidden field.
                     * Present, presence is the value and unticking really does
                     * clear. Missing, these are left alone — which is what a
                     * partial POST means everywhere else in this handler, and
                     * the only reading under which "save the thumbnail mode"
                     * cannot also mean "make this public and uncategorised".
                     */
                    'member_only'    => $whole ? $request->input('member_only') !== null : $video->memberOnly,
                    'hidden'         => $whole ? $request->input('hidden') !== null : $video->hidden,
                    'premiere'       => $whole ? $request->input('premiere') !== null : $video->premiere,
                    'featured'       => $whole ? $request->input('featured') !== null : $video->featured,
                    'pinned'         => $whole ? $request->input('pinned') !== null : $video->pinned,
                ]);

                if ($whole) {
                    $categoryIds = array_map('intval', $request->inputArray('categories'));

                    /*
                     * Filing a video somewhere needs permission on WHERE, and
                     * not only on the video — the same rule the category
                     * reparent and the bulk categorise button follow.
                     *
                     * Without it, holding one category is enough to move any
                     * video you hold into somebody else's section, which is the
                     * one way a scope can be used to reach outside itself.
                     *
                     * Only categories being ADDED are checked. Asking about the
                     * ones already there would refuse a scoped editor their own
                     * save whenever a video also sits somewhere they do not
                     * hold — a normal state, since a video can be in several
                     * categories and only one of them need be theirs.
                     *
                     * MANAGE_VIDEOS on the destination, not MANAGE_CATEGORIES:
                     * the question is whether you may put VIDEOS there, not
                     * whether you may rename the category. Requiring the latter
                     * would refuse every contributor, whose role carries
                     * manage_videos and deliberately not manage_categories —
                     * turning a scope fix into a broken form for a role that
                     * has nothing to do with scopes.
                     */
                    foreach (array_diff($categoryIds, $videos->categoryIds($id)) as $added) {
                        $this->require(Capability::MANAGE_VIDEOS, 'category', $added);
                    }

                    $videos->setCategories($id, $categoryIds);

                    /*
                     * Tags are inside the `_whole_form` guard with the
                     * categories, and for the same reason: the field shows the
                     * complete current list, so an empty box means "remove them
                     * all" — which is right when the form was rendered with the
                     * tags in it, and destructive when a partial POST simply
                     * never mentioned them.
                     */
                    $tags = $this->container->get(\Portal\Content\TagRepository::class);
                    $tags->setFor('video', $id, \Portal\Content\TagRepository::parse(
                        (string) ($request->input('tags') ?? '')
                    ));

                    // A tag whose last use has just gone stops existing, so the
                    // admin list never fills with labels linking to empty pages.
                    $tags->pruneUnused();
                }

                Audit::log($this->db(), $this->user()?->email, 'video.update', 'video', (string) $id, $video->title);

                /*
                 * Fired here rather than in the repository, for the same reason
                 * the revision snapshot is taken here: this is where a HUMAN
                 * edit happens. The provider sync calls update() too, and an
                 * integration woken a hundred times an hour by a routine
                 * encoding-status refresh would be turned off within a day.
                 */
                do_action('video_updated', $id, $video->title);

                $this->saveScripture($request, $id);

                return $this->back($request, 'Video saved.');
        }
    }

    // --------------------------------------------------------- distribution

    public function installPlugin(Request $request): Response
    {
        return $this->installPackage($request, PackageInstaller::KIND_PLUGIN, Capability::MANAGE_PLUGINS);
    }

    public function installTheme(Request $request): Response
    {
        return $this->installPackage($request, PackageInstaller::KIND_THEME, Capability::MANAGE_THEMES);
    }

    /**
     * Install a plugin or theme from an uploaded ZIP.
     *
     * Both kinds share everything but a capability and a directory, and the
     * dangerous part — extraction — is the same code either way. Two copies of
     * this would eventually be two different sets of safety checks.
     */
    private function installPackage(Request $request, string $kind, string $capability): Response
    {
        $this->verifyCsrf($request);
        $this->require($capability);

        if (!PackageInstaller::uploadsAllowed($this->config())) {
            return $this->back(
                $request,
                'Installing from a file is switched off on this site. Remove allow_package_uploads '
                . 'from config.php to turn it back on, or upload the folder over FTP.',
                'error'
            );
        }

        /** @var array<string, mixed> $file */
        $file = (array) ($request->files['package'] ?? []);

        $result = (new PackageInstaller($kind))->installUpload($file);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            $kind . '.install',
            $kind,
            $result['slug'] ?? null,
            $result['ok'] ? 'installed' : $result['message']
        );

        // Discovery is memoised, and the listing it holds was taken before this
        // upload existed. Without forgetting it first, the screen reports
        // "Installed" and then does not list the thing it just installed.
        if ($result['ok'] && $kind === PackageInstaller::KIND_PLUGIN) {
            $plugins = $this->container->get(PluginManager::class);
            $plugins->forgetDiscovered();
            $plugins->sync();
        }

        if ($result['ok'] && $kind === PackageInstaller::KIND_THEME) {
            $this->themeManager()->forgetDiscovered();
            $this->themeManager()->sync();
        }

        return $this->back($request, $result['message'], $result['ok'] ? 'success' : 'error');
    }

    /**
     * Download this site's configuration as JSON.
     *
     * Deliberately excludes every credential. Provider secrets are encrypted
     * with this install's APP_KEY and would not decrypt anywhere else, so
     * exporting them would produce a file that is both a liability and useless.
     */
    public function exportSettings(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        $themes = $this->themeManager();

        $payload = [
            'exportedAt' => date('c'),
            'version'    => PORTAL_VERSION,
            'settings'   => $this->exportableSettings(),
            'theme'      => [
                'active'   => $themes->activeSlug(),
                'settings' => $themes->settings($themes->activeSlug()),
            ],
            'plugins'    => $this->db()->column('SELECT slug FROM {plugins} WHERE is_active = 1'),
        ];

        Audit::log($this->db(), $this->user()?->email, 'settings.export');

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

        return Response::html($json)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="video-portal-settings-' . date('Y-m-d') . '.json"'
            )
            ->private();
    }

    /**
     * Read a library export back in.
     *
     * The export has said since it shipped that it is a record and not a
     * restore, because writing this needed answers to real questions. The
     * answer to the biggest one — what happens to a slug that already exists —
     * is that nothing is ever overwritten. See ContentImport.
     */
    public function importContent(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        /** @var array<string, mixed> $file */
        $file = (array) ($request->files['library'] ?? []);
        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        /*
         * The size limit gets its own message, because it is the failure this
         * will actually hit. A library export is the biggest file this product
         * ever produces and shared hosts cap uploads at 2MB by default — and
         * PHP reports that as an empty $_FILES entry, which reads as "no file
         * was chosen" and sends somebody looking at their file picker.
         */
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return $this->back($request, sprintf(
                'That file is larger than this server accepts (%s). Ask your host to raise '
                . 'upload_max_filesize and post_max_size, or import the library in pieces.',
                ini_get('upload_max_filesize') ?: 'the configured limit'
            ), 'error');
        }

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->back($request, 'No file was chosen.', 'error');
        }

        $handle = @fopen($tmp, 'rb');
        if ($handle === false) {
            return $this->back($request, 'That file could not be read.', 'error');
        }

        try {
            $result = (new \Portal\Content\ContentImport(
                $this->db(),
                $this->container->get(CategoryRepository::class)
            ))->read($handle);
        } finally {
            fclose($handle);
        }

        $counts = $result['counts'];
        $added = $counts['categories'] + $counts['series'] + $counts['speakers'] + $counts['videos'];

        /*
         * A file that produced nothing is reported as a probable wrong file,
         * not as a successful import of zero things. "Imported 0 videos" reads
         * as the feature being broken; naming the likely cause does not.
         */
        if ($added === 0 && $counts['skipped'] === 0) {
            return $this->back(
                $request,
                'Nothing in that file could be read. It should be the .ndjson file from '
                . '“Download the library” — a settings export will not work here.',
                'error'
            );
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'content.import',
            null,
            null,
            sprintf('%d added, %d already here', $added, $counts['skipped'])
        );

        $message = sprintf(
            'Imported %d categor%s, %d series, %d speaker%s and %d video%s. '
            . '%d record%s already here and %s left alone.',
            $counts['categories'],
            $counts['categories'] === 1 ? 'y' : 'ies',
            $counts['series'],
            $counts['speakers'],
            $counts['speakers'] === 1 ? '' : 's',
            $counts['videos'],
            $counts['videos'] === 1 ? '' : 's',
            $counts['skipped'],
            $counts['skipped'] === 1 ? '' : 's',
            $counts['skipped'] === 1 ? 'was' : 'were'
        );

        if ($counts['transcripts'] > 0) {
            $message .= sprintf(' %d transcript(s) came with them.', $counts['transcripts']);
        }

        if ($counts['failed'] > 0) {
            $message .= sprintf(
                ' %d line(s) could not be read%s',
                $counts['failed'],
                $result['problems'] === [] ? '.' : ': ' . implode('; ', $result['problems'])
            );
        }

        return $this->back($request, $message, $counts['failed'] > 0 ? 'error' : 'success');
    }

    public function importSettings(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        /** @var array<string, mixed> $file */
        $file = (array) ($request->files['settings'] ?? []);
        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->back($request, 'No file was chosen.', 'error');
        }

        $decoded = json_decode((string) file_get_contents($tmp), true);

        if (!is_array($decoded)) {
            return $this->back($request, 'That file is not a settings export.', 'error');
        }

        $applied = $this->applyImport($decoded);

        Audit::log($this->db(), $this->user()?->email, 'settings.import', null, null, implode(', ', $applied));

        return $this->back($request, $applied === []
            ? 'Nothing in that file could be applied.'
            : 'Imported: ' . implode(', ', $applied) . '.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string> what was actually changed
     */
    private function applyImport(array $payload): array
    {
        $applied = [];

        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $pairs = [];
            foreach ($payload['settings'] as $key => $value) {
                // Only keys this version knows about. An export from a newer
                // version would otherwise write settings nothing reads, which
                // then reappear if the site is upgraded and behave unexpectedly.
                if (is_string($key) && is_scalar($value) && in_array($key, self::EXPORTABLE_SETTINGS, true)) {
                    $pairs[$key] = (string) $value;
                }
            }

            if ($pairs !== []) {
                $this->config()->setSettings($pairs);
                $applied[] = count($pairs) . ' site setting(s)';
            }
        }

        if (isset($payload['theme']['settings']) && is_array($payload['theme']['settings'])) {
            $themes = $this->themeManager();
            $values = [];
            foreach ($payload['theme']['settings'] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $values[$key] = (string) $value;
                }
            }

            if ($values !== []) {
                // Applied to the CURRENTLY active theme, not the one named in
                // the file. Importing settings must not silently switch the
                // site's appearance to a theme that may not be installed.
                $themes->saveSettings($themes->activeSlug(), $values);
                $applied[] = 'theme customisations';
            }
        }

        return $applied;
    }

    /** @return array<string, string|null> */
    /**
     * The whole library, streamed as NDJSON.
     *
     * Settings have been exportable since Phase 3 and the content never has,
     * which on a host with no shell and no database console means the only copy
     * of what somebody spent a year cataloguing lives somewhere they cannot
     * reach except through this application.
     *
     * Streamed rather than built. A real library assembled into a string is a
     * string the memory limit refuses, and the refusal arrives as a blank page
     * — the least diagnosable failure this product could produce.
     *
     * Behind MANAGE_SETTINGS, the same bar as the settings export. This carries
     * every video including the unpublished and the members-only, so it is a
     * site-owner action and not an editor one.
     */
    public function exportContent(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        $withTranscripts = $request->query('transcripts') === '1';

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'content.export',
            null,
            null,
            $withTranscripts ? 'with transcripts' : 'catalogue only'
        );

        $export = new \Portal\Content\ContentExport($this->db());

        /*
         * The generator is not touched until send() runs the callback, so
         * nothing is queried while the response is still being assembled — and
         * by then the headers have gone out, which is why nothing in here may
         * throw. Each record is encoded and flushed on its own; holding even
         * the encoded lines would put the whole library back in memory.
         */
        return Response::stream(
            static function () use ($export, $withTranscripts): void {
                foreach ($export->records($withTranscripts) as $record) {
                    echo \Portal\Content\ContentExport::line($record);
                    flush();
                }
            },
            'application/x-ndjson; charset=utf-8'
        )->header(
            'Content-Disposition',
            'attachment; filename="library-' . date('Y-m-d') . '.ndjson"'
        )->private();
    }

    private function exportableSettings(): array
    {
        $out = [];
        foreach (self::EXPORTABLE_SETTINGS as $key) {
            $out[$key] = $this->config()->setting($key);
        }

        return $out;
    }

    /**
     * Settings safe to move between installs.
     *
     * An allow-list rather than "everything except secrets", because the
     * dangerous direction is a setting added later that nobody remembers to
     * exclude. A new key is invisible to export until someone adds it here on
     * purpose.
     */
    private const EXPORTABLE_SETTINGS = [
        'site_name',
        'timezone',
        'watermark_default',
        'members_thumbnail_default',
        'downloads_enabled',
        'require_verified_email',
        'allow_access_requests',
        'geo_enabled',
        'admin_geo_enabled',
    ];

    // ---------------------------------------------------------- permissions

    public function permissions(Request $request): Response
    {
        $this->require(Capability::MANAGE_PERMISSIONS);

        $repo = $this->permissionRepo();

        return $this->admin('permissions', [
            'roles'        => $repo->roles(),
            'groups'       => $repo->groups(),
            'grants'       => $repo->grants(),
            'capabilities' => Capability::all(),
            'siteOnly'     => Capability::siteOnly(),
            'categories'   => $this->container->get(CategoryRepository::class)->all(true),
            'seriesList'   => $this->seriesRepo()->all(true),
        ]);
    }

    public function savePermissions(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_PERMISSIONS);

        $repo = $this->permissionRepo();
        $action = $request->input('action') ?? '';
        $actor = $this->user()?->email;

        try {
            $message = match ($action) {
                'role' => $this->saveRoleCapabilities($repo, $request, $actor),
                'group-create' => $this->createPermissionGroup($repo, $request, $actor),
                'group-delete' => $this->deletePermissionGroup($repo, $request, $actor),
                'group-capabilities' => $this->saveGroupCapabilities($repo, $request, $actor),
                'group-add-member' => $this->addPermissionGroupMember($repo, $request, $actor),
                'group-remove-member' => $this->removePermissionGroupMember($repo, $request, $actor),
                'grant' => $this->createGrant($repo, $request, $actor),
                'revoke' => $this->revokeGrant($repo, $request, $actor),
                default => throw HttpException::badRequest('Unknown action.'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        // Anything cached during this request now describes the state the admin
        // just changed away from.
        $this->container->get(\Portal\Auth\Capabilities::class)->flush();

        return $this->back($request, $message);
    }

    private function saveRoleCapabilities(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $roleId = (int) ($request->input('role_id') ?? 0);
        $repo->setRoleCapabilities($roleId, $request->inputArray('capabilities'));

        Audit::log($this->db(), $actor, 'permissions.role', 'role', (string) $roleId);

        return 'Role updated.';
    }

    private function createPermissionGroup(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = $repo->createGroup(
            $request->input('name') ?? '',
            $request->input('description')
        );

        Audit::log($this->db(), $actor, 'permissions.group.create', 'group', (string) $id);

        return 'Group created.';
    }

    private function deletePermissionGroup(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $repo->deleteGroup($id);

        Audit::log($this->db(), $actor, 'permissions.group.delete', 'group', (string) $id);

        return 'Group deleted. Everyone in it loses whatever it granted.';
    }

    private function saveGroupCapabilities(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $repo->setGroupCapabilities($id, $request->inputArray('capabilities'));

        Audit::log($this->db(), $actor, 'permissions.group.capabilities', 'group', (string) $id);

        return 'Group permissions updated.';
    }

    private function addPermissionGroupMember(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $email = $request->input('email') ?? '';
        $repo->addGroupMember($id, $email);

        Audit::log($this->db(), $actor, 'permissions.group.add', 'group', (string) $id, $email);

        return 'Added to the group.';
    }

    private function removePermissionGroupMember(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $email = $request->input('email') ?? '';
        $repo->removeGroupMember($id, $email);

        Audit::log($this->db(), $actor, 'permissions.group.remove', 'group', (string) $id, $email);

        return 'Removed from the group.';
    }

    private function createGrant(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $subjectType = $request->input('subject_type') ?? 'email';

        // The scope picker is one dropdown — "site", or "category:12" — because
        // a type select plus an id select would let someone choose a category
        // type with a series id and produce a grant that silently matches
        // nothing.
        [$scopeType, $scopeId] = $this->parseScope($request->input('scope') ?? 'site');

        $repo->grant(
            $subjectType,
            $subjectType === 'email'
                ? ($request->input('email') ?? '')
                : ($request->input('subject_id') ?? '0'),
            $request->input('capability') ?? '',
            $scopeType,
            $scopeId,
            $actor
        );

        Audit::log($this->db(), $actor, 'permissions.grant', null, null, $request->input('capability'));

        return 'Permission granted.';
    }

    private function revokeGrant(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('grant_id') ?? 0);
        $repo->revoke($id);

        Audit::log($this->db(), $actor, 'permissions.revoke', null, (string) $id);

        return 'Permission removed.';
    }

    private function permissionRepo(): \Portal\Auth\PermissionRepository
    {
        return new \Portal\Auth\PermissionRepository($this->db());
    }

    /**
     * "site" or "category:12" into a type and an id.
     *
     * Anything unrecognised becomes site-wide rather than throwing. That is the
     * safe direction here only because the repository re-validates the type and
     * refuses a scoped grant with no id — this is parsing, not authorisation.
     *
     * @return array{0: string, 1: int}
     */
    private function parseScope(string $raw): array
    {
        if (!str_contains($raw, ':')) {
            return ['site', 0];
        }

        [$type, $id] = explode(':', $raw, 2);

        return [$type, (int) $id];
    }

    // ---------------------------------------------------------------- trash

    public function trash(Request $request): Response
    {
        $this->requireAnywhere(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        return $this->admin('trash', ['videos' => $videos->trashed()]);
    }

    /**
     * Restore, or destroy for good.
     *
     * Permanent deletion removes the video at the provider FIRST, and gives up
     * if that fails. Removing only the local row would be worse than useless:
     * the file is still at the provider, so the next sync re-imports it and the
     * admin is left believing the delete silently failed at random.
     */
    public function updateTrash(Request $request): Response
    {
        $this->verifyCsrf($request);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $id = (int) ($request->input('id') ?? 0);
        $video = $videos->find($id) ?? $this->trashedVideo($id);

        if ($video === null) {
            return $this->back($request, 'That video is not in the trash.', 'error');
        }

        /*
         * Trashing does not detach a video from its series or its categories —
         * only `deleted_at` is set — so the scope a grant was attached to still
         * resolves, and the person who could delete it can restore it.
         */
        $this->require(Capability::MANAGE_VIDEOS, 'video', $video->id);

        if (($request->input('action') ?? '') === 'restore') {
            $videos->restore($id);
            Audit::log($this->db(), $this->user()?->email, 'video.restore', 'video', (string) $id, $video->title);

            return $this->back($request, 'Restored “' . $video->title . '”.');
        }

        // Permanent.
        try {
            $this->container->get(\Portal\Video\VideoProvider::class)->deleteVideo($video->providerId);
        } catch (Throwable $e) {
            return $this->back($request, sprintf(
                'Could not delete “%s” at your video service, so it has been left in the trash: %s '
                . 'Deleting it here alone would not work — the next sync would bring it straight back.',
                $video->title,
                $e->getMessage()
            ), 'error');
        }

        $videos->forceDelete($id);
        Audit::log($this->db(), $this->user()?->email, 'video.purge', 'video', (string) $id, $video->title);

        return $this->back($request, 'Deleted “' . $video->title . '” for good.');
    }

    /** find() hides trashed rows, which is exactly what this screen works on. */
    private function trashedVideo(int $id): ?\Portal\Content\Video
    {
        $row = $this->db()->first('SELECT * FROM {videos} WHERE id = ? AND deleted_at IS NOT NULL', [$id]);

        return $row === null ? null : \Portal\Content\Video::fromRow($row);
    }

    // --------------------------------------------------------------- series

    public function series(Request $request): Response
    {
        $this->requireAnywhere(Capability::MANAGE_SERIES);

        return $this->admin('series', [
            'series'     => $this->seriesRepo()->all(true),
            'categories' => $this->container->get(CategoryRepository::class)->all(true),
        ]);
    }

    /** @param array<string, string> $params */
    public function editSeries(Request $request, array $params): Response
    {
        $series = $this->seriesRepo()->find((int) ($params['id'] ?? 0));
        if ($series === null) {
            throw HttpException::notFound('That series does not exist.');
        }

        // About this series: a grant on the category it sits in covers it.
        $this->require(Capability::MANAGE_SERIES, 'series', $series->id);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        return $this->admin('series-edit', [
            'series'     => $series,
            'categories' => $this->container->get(CategoryRepository::class)->all(true),
            // In series order, so the list on screen is the running order.
            'episodes'   => $videos->forSeries($series->id, true),
            'available'  => $this->unassignedVideos($series->id),
            'inheritedDownloadLabel' => $this->inheritedSeriesDownloadLabel(),
        ]);
    }

    public function saveSeries(Request $request): Response
    {
        $this->verifyCsrf($request);

        $repo = $this->seriesRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        /*
         * These actions name a series they are changing, so each is asked about
         * that series. Anything else falls to `default:` below, which CREATES —
         * so the list is written out rather than tested for the word "create".
         * Asking `$action !== 'create'` would let a made-up action name arrive
         * with a scoped id, pass the scoped check, and then create a series
         * that nobody had site-wide permission to create.
         *
         * Creating names no scope yet: create() takes a title and the category
         * is chosen on the edit screen afterwards. So the site-wide question is
         * the honest one, and a category-scoped editor can change the series in
         * their category without being able to invent new ones — the correct
         * reading of a grant that names a place.
         */
        $scoped = ['delete', 'restore-revision', 'update', 'episodes', 'up', 'down'];

        if (in_array($action, $scoped, true) && $id > 0) {
            $this->require(Capability::MANAGE_SERIES, 'series', $id);
        } else {
            $this->require(Capability::MANAGE_SERIES);
        }

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'series.delete', 'series', (string) $id);
                    return $this->back($request, 'Series deleted. Its videos were kept.');

                case 'restore-revision':
                    return $this->restoreRevision($request, RevisionRepository::SERIES, $id);

                case 'update':
                    $this->revisions()->record(RevisionRepository::SERIES, $id, $this->user()?->email ?? '');
                    $repo->update($id, [
                        'title'        => $request->input('title'),
                        'slug'         => $request->input('slug'),
                        'description'  => $request->input('description'),
                        'category_id'  => $request->input('category_id'),
                        // Absent means unchecked; see updateVideo().
                        'is_published' => $request->input('is_published') !== null,
                        'member_only'  => $request->input('member_only') !== null,
                        'hidden'       => $request->input('hidden') !== null,
                        'featured'     => $request->input('featured') !== null,
                        'sequential'   => $request->input('sequential') !== null,
                        'download_mode' => $request->input('download_mode'),
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'series.update', 'series', (string) $id);
                    return $this->back($request, 'Series saved.');

                case 'episodes':
                    $repo->setVideos($id, array_map('intval', $request->inputArray('videos')));
                    Audit::log($this->db(), $this->user()?->email, 'series.episodes', 'series', (string) $id);
                    return $this->back($request, 'Episodes updated.');

                case 'up':
                case 'down':
                    $repo->move((int) ($request->input('video') ?? 0), $action === 'up' ? -1 : 1);
                    return $this->back($request, '');

                default:
                    $created = $repo->create(['title' => $request->input('title')]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'series.create',
                        'series',
                        (string) $created->id,
                        $created->title
                    );
                    return $this->redirect('/admin/series/' . $created->id);
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ------------------------------------------------------------ playlists

    public function playlists(Request $request): Response
    {
        $this->require(Capability::MANAGE_SERIES);

        return $this->admin('playlists', [
            'playlists' => $this->playlistRepo()->all(true),
        ]);
    }

    /** @param array<string, string> $params */
    public function editPlaylist(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_SERIES);

        $playlist = $this->playlistRepo()->find((int) ($params['id'] ?? 0));
        if ($playlist === null) {
            throw HttpException::notFound('That playlist does not exist.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        /*
         * Unlike a series, a playlist does not own its videos, so there is no
         * "unassigned" pool to offer — every video in the library is a
         * candidate, including ones already on other playlists. The chosen ones
         * are listed separately and in order, because that order is the whole
         * point of the screen.
         */
        $chosen = $this->playlistRepo()->orderedVideoIds($playlist->id);

        return $this->admin('playlist-edit', [
            'playlist'  => $playlist,
            'items'     => $this->playlistRepo()->videos($playlist->id, true, true),
            'chosenIds' => $chosen,
            'available' => $videos->query(['includeUnpublished' => true, 'includeHidden' => true,
                                           'includeMemberOnly' => true], 1, 100)['items'],
        ]);
    }

    public function savePlaylist(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SERIES);

        $repo = $this->playlistRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'playlist.delete', 'playlist', (string) $id);
                    return $this->back($request, 'Playlist deleted. Its videos were kept.');

                case 'update':
                    $repo->update($id, [
                        'title'        => $request->input('title'),
                        'slug'         => $request->input('slug'),
                        'description'  => $request->input('description'),
                        // Absent means unchecked; see updateVideo().
                        'is_published' => $request->input('is_published') !== null,
                        'member_only'  => $request->input('member_only') !== null,
                        'hidden'       => $request->input('hidden') !== null,
                        'featured'     => $request->input('featured') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'playlist.update', 'playlist', (string) $id);
                    return $this->back($request, 'Playlist saved.');

                case 'items':
                    $repo->setVideos($id, array_map('intval', $request->inputArray('videos')));
                    Audit::log($this->db(), $this->user()?->email, 'playlist.items', 'playlist', (string) $id);
                    return $this->back($request, 'Playlist updated.');

                case 'up':
                case 'down':
                    // The playlist id travels with the move. Without it the
                    // neighbour lookup would find whichever row in any playlist
                    // held the adjacent position.
                    $repo->move($id, (int) ($request->input('video') ?? 0), $action === 'up' ? -1 : 1);
                    return $this->back($request, '');

                default:
                    $created = $repo->create(['title' => $request->input('title')]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'playlist.create',
                        'playlist',
                        (string) $created->id,
                        $created->title
                    );
                    return $this->redirect('/admin/playlists/' . $created->id);
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function playlistRepo(): \Portal\Content\PlaylistRepository
    {
        return $this->container->get(\Portal\Content\PlaylistRepository::class);
    }

    // ------------------------------------------------------------ analytics

    /**
     * What got watched.
     *
     * Governed by VIEW_ANALYTICS, which has existed since Phase 1, is granted
     * to editors, and until now decided nothing but whether somebody could see
     * the ratings plugin's leaderboard.
     */
    public function analytics(Request $request): Response
    {
        $this->require(Capability::VIEW_ANALYTICS);

        $days = \Portal\Content\ViewRepository::sanitizePeriod($request->query('days'));

        try {
            $views = $this->container->get(\Portal\Content\ViewRepository::class);

            return $this->admin('analytics', [
                'days'    => $days,
                'summary' => $views->summary($days),
                'top'     => $views->topVideos($days),
            ]);
        } catch (Throwable $e) {
            // Before migration 0011 has run. An empty screen beats a 500.
            error_log('Could not read view counts: ' . $e->getMessage());

            return $this->admin('analytics', [
                'days'    => $days,
                'summary' => ['views' => 0, 'completions' => 0],
                'top'     => [],
            ]);
        }
    }

    /**
     * The view figures as a spreadsheet.
     *
     * Daily rows rather than the totals on the screen: an export exists to let
     * somebody do what the screen cannot, and a day cannot be recovered from a
     * total.
     *
     * Behind VIEW_ANALYTICS, the same capability as the screen. Not stricter —
     * this is the same information in a different shape, and a download that
     * needed a second permission would be one nobody could explain.
     */
    public function exportAnalytics(Request $request): Response
    {
        $this->require(Capability::VIEW_ANALYTICS);

        $days = \Portal\Content\ViewRepository::sanitizePeriod($request->query('days'));

        try {
            $rows = $this->container->get(\Portal\Content\ViewRepository::class)->dailyRows($days);
        } catch (Throwable $e) {
            error_log('Could not export view counts: ' . $e->getMessage());
            $rows = [];
        }

        $csv = \Portal\Support\Csv::document(
            ['Date', 'Video', 'Address', 'Views', 'Finished'],
            array_map(
                static fn (array $row): array => [
                    $row['day'],
                    $row['title'],
                    '/watch/' . $row['slug'],
                    $row['views'],
                    $row['completions'],
                ],
                $rows
            )
        );

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'analytics.export',
            'analytics',
            (string) $days,
            (string) count($rows)
        );

        return Response::text($csv)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="' . \Portal\Support\Csv::filename('views-' . $days . '-days') . '"'
            )
            // The browser must not decide this is HTML. A CSV that sniffs as
            // HTML is a page rendered from content editors typed.
            ->header('X-Content-Type-Options', 'nosniff')
            ->private();
    }

    // ------------------------------------------------------------- homepage

    public function homeRows(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        return $this->admin('home-rows', [
            'rows'       => $this->homeRowRepo()->all(true),
            'categories' => $this->container->get(CategoryRepository::class)->all(true),
            'series'     => $this->seriesRepo()->all(true),
            'playlists'  => $this->playlistRepo()->all(true),
        ]);
    }

    public function saveHomeRow(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $repo = $this->homeRowRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'home_row.delete', 'home_row', (string) $id);
                    return $this->back($request, 'Row removed.');

                case 'update':
                    $repo->update($id, [
                        'title'       => $request->input('title'),
                        'source_type' => $request->input('source_type'),
                        // The picker for the chosen source. One field per kind
                        // rather than one shared one, because a single select
                        // holding ids from three tables cannot say which table
                        // a number came from.
                        'source_id'   => $request->input('source_' . ($request->input('source_type') ?? '')),
                        'max_items'   => $request->input('max_items'),
                        'is_active'   => $request->input('is_active') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'home_row.update', 'home_row', (string) $id);
                    return $this->back($request, 'Row saved.');

                case 'up':
                case 'down':
                    $repo->move($id, $action === 'up' ? -1 : 1);
                    return $this->back($request, '');

                default:
                    $source = (string) ($request->input('source_type') ?? '');
                    $created = $repo->create([
                        'title'       => $request->input('title'),
                        'source_type' => $source,
                        'source_id'   => $request->input('source_' . $source),
                        'max_items'   => $request->input('max_items'),
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'home_row.create',
                        'home_row',
                        (string) $created->id
                    );
                    return $this->back($request, 'Row added.');
            }
        } catch (HttpException $e) {
            if ($e->status !== 400) {
                throw $e;
            }

            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    /**
     * How many people have subscribed.
     *
     * Wrapped, because on the one request that applies migration 0007 the
     * table does not exist yet and the settings screen is more important than
     * the number on it.
     */
    private function subscriberCount(): int
    {
        try {
            return $this->container->get(\Portal\Content\SubscriptionRepository::class)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function revisions(): RevisionRepository
    {
        return $this->container->get(RevisionRepository::class);
    }

    /**
     * Attach a file to a video.
     *
     * The upload is checked before anything is read: PHP's own error code
     * first, then that it genuinely arrived as an upload, then the size, then
     * the extension against the allowlist. Reading a 500MB temp file to
     * discover it is too large is how a shared host runs out of memory.
     */
    /**
     * Ask the provider what it currently says about one video.
     *
     * The recovery path for a video marked failed. Until the sync job was
     * fixed it read one page of a hundred and marked everything else failed,
     * so on a large library there are rows saying "Failed" about videos that
     * are perfectly fine. Waiting for the corrected job to come round is not an
     * answer for somebody looking at one of them now.
     *
     * FOUR outcomes, and the fourth is the point.
     *
     * Ready, still encoding, and genuinely gone are three verdicts the provider
     * gave. An unreachable provider is NOT a verdict — and it is the one this
     * screen must never round off, because "we could not ask" and "it is gone"
     * look identical to a caught exception and lead to opposite actions. The
     * repository only writes `failed` on a real 404; a thrown error never
     * reaches it.
     */
    /**
     * Fetch the signed download URL and report what the CDN actually said.
     *
     * The one question no screen here could answer. Every other check runs
     * against what the provider's API says; this runs against the CDN, which is
     * a different host with different credentials and its own opinion — and
     * whose refusal reaches the person's browser rather than this site, so the
     * report is always "the download does not work" while every screen here
     * insists it should.
     *
     * A button rather than part of the download path: one extra round trip per
     * download, on every request, to answer a question only asked when
     * something is already wrong.
     *
     * A single byte is requested. The point is the status code, and pulling a
     * whole sermon through shared hosting to read one would be its own outage.
     */
    private function testDownload(Request $request, VideoRepository $videos, \Portal\Content\Video $video): Response
    {
        try {
            /** @var \Portal\Video\VideoProvider $provider */
            $provider = $this->container->get(\Portal\Video\VideoProvider::class);
            $source = (new \Portal\Video\Mp4Locator($provider, $videos))->locate($video, 600);
        } catch (Throwable $e) {
            return $this->back($request, 'The video service is not responding: ' . $e->getMessage(), 'error');
        }

        // No URL at all is already a specific, actionable answer — one of the
        // four this site works out for itself, so there is nothing to fetch.
        if ($source->url === null) {
            return $this->back($request, $source->explain(), 'error');
        }

        $response = \Portal\Support\Http::get($source->url, ['Range' => 'bytes=0-0']);

        $verdict = \Portal\Video\Mp4Source::diagnose($response->status, $response->transportError);
        $ok = $response->transportError === null && $response->status >= 200 && $response->status < 300;

        return $this->back(
            $request,
            sprintf('Tested the %dp download. %s', $source->height, $verdict),
            $ok ? 'success' : 'error'
        );
    }

    private function recheckVideo(Request $request, VideoRepository $videos, \Portal\Content\Video $video, int $id): Response
    {
        try {
            $result = $videos->recheckAgainstProvider(
                $video,
                $this->container->get(\Portal\Video\VideoProvider::class)
            );
        } catch (Throwable $e) {
            return $this->back($request, sprintf(
                'Could not reach your video service, so nothing was changed and “%s” still says what it said: %s',
                $video->title,
                $e->getMessage()
            ), 'error');
        }

        Audit::log($this->db(), $this->user()?->email, 'video.recheck', 'video', (string) $id, $video->title);

        if (!$result['found']) {
            return $this->back($request, sprintf(
                '“%s” is not at your video service any more, so it is marked failed. '
                . 'The record here is kept — its categories and share history are intact.',
                $video->title
            ), 'error');
        }

        return match ($result['status']) {
            \Portal\Content\Video::STATUS_READY => $this->back(
                $request,
                sprintf('“%s” is fine — your video service has it ready.', $video->title)
            ),
            \Portal\Content\Video::STATUS_PROCESSING => $this->back(
                $request,
                sprintf('“%s” is still encoding. Check again in a few minutes.', $video->title)
            ),
            default => $this->back(
                $request,
                sprintf('Your video service reports “%s” as failed at their end.', $video->title),
                'error'
            ),
        };
    }

    private function attachFile(Request $request, int $videoId): Response
    {
        $upload = $_FILES['attachment'] ?? null;

        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $this->back($request, 'Choose a file to attach.', 'error');
        }

        $error = (int) $upload['error'];

        if ($error !== UPLOAD_ERR_OK) {
            /*
             * INI_SIZE and FORM_SIZE are the common ones and they mean the same
             * thing to the person: it was too big. Naming the limit is more use
             * than naming the constant, since the host's limit may be lower
             * than ours and there is nothing here that can change it.
             */
            return $this->back($request, match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                    'That file is larger than this server accepts. The limit here is %s, and your host may set a lower one.',
                    \Portal\Content\AssetPolicy::formatSize(\Portal\Content\AssetPolicy::MAX_BYTES)
                ),
                UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
                default            => 'That file could not be uploaded.',
            }, 'error');
        }

        $temporary = (string) ($upload['tmp_name'] ?? '');

        if (!is_uploaded_file($temporary)) {
            return $this->back($request, 'That file could not be uploaded.', 'error');
        }

        $name = (string) ($upload['name'] ?? '');

        if (!\Portal\Content\AssetPolicy::isAllowed($name)) {
            return $this->back(
                $request,
                'That kind of file cannot be attached. Documents, images, and audio only.',
                'error'
            );
        }

        try {
            $stored = $this->container
                ->get(\Portal\Content\AssetRepository::class)
                ->store($videoId, $temporary, $name, $this->user()?->email ?? '');
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'asset.create',
            'video',
            (string) $videoId,
            (string) $stored['original_name']
        );

        return $this->back($request, 'Attached ' . $stored['original_name'] . '.');
    }

    private function transcripts(): \Portal\Content\TranscriptRepository
    {
        return $this->container->get(\Portal\Content\TranscriptRepository::class);
    }

    /**
     * Import a transcript, from an uploaded file or pasted text.
     *
     * Both, because both are how people have it: a .vtt from a captioning
     * service, or text copied out of a transcription tool. Offering only one
     * means the other person converts a file by hand or does not bother.
     *
     * The parse happens before anything is stored and the count is reported
     * back. A file that produced two cues out of an expected four hundred is a
     * broken import, and the number is the only way anybody finds out — the
     * panel would otherwise just look short.
     */
    private function saveTranscript(Request $request, int $videoId): Response
    {
        $raw = (string) ($request->input('transcript') ?? '');

        $upload = $_FILES['transcript_file'] ?? null;

        if (is_array($upload)
            && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) $upload['tmp_name'])) {
            /*
             * A ceiling before reading. A subtitle file is measured in
             * kilobytes; anything past a few megabytes is not one, and reading
             * it to find that out is how a shared host runs out of memory.
             */
            if ((int) ($upload['size'] ?? 0) > 8 * 1024 * 1024) {
                return $this->back($request, 'That file is too large to be a transcript.', 'error');
            }

            $contents = file_get_contents((string) $upload['tmp_name']);

            if ($contents !== false) {
                // The file wins when both are given: somebody who attached one
                // meant the file, and the textarea may still hold the previous
                // transcript the form rendered.
                $raw = $contents;
            }
        }

        if (trim($raw) === '') {
            return $this->back($request, 'There was nothing to import.', 'error');
        }

        $cues = \Portal\Content\TranscriptParser::parse($raw);

        if ($cues === []) {
            return $this->back(
                $request,
                'No timed lines could be read from that. It needs to be WebVTT or SubRip.',
                'error'
            );
        }

        $stored = $this->transcripts()->replace(
            $videoId,
            $cues,
            trim((string) ($request->input('transcript_source') ?? ''))
        );

        Audit::log($this->db(), $this->user()?->email, 'transcript.import', 'video', (string) $videoId, (string) $stored);

        return $this->back($request, sprintf('Imported %d line(s).', $stored));
    }

    private function scripture(): \Portal\Content\ScriptureRepository
    {
        return $this->container->get(\Portal\Content\ScriptureRepository::class);
    }

    /**
     * What the edit screen shows: the manual field's contents, and what the
     * description contributed, separately.
     *
     * Separately because they behave differently, and a single merged list
     * would make an editor think they could delete a parsed reference by
     * clearing the box.
     *
     * @return array{manual: string, parsed: list<string>}
     */
    private function scriptureForEdit(int $videoId): array
    {
        try {
            $manual = [];
            $parsed = [];

            foreach ($this->scripture()->forVideo($videoId) as $row) {
                $formatted = \Portal\Content\ScriptureParser::format([
                    'book'       => (string) $row['book'],
                    'chapter'    => (int) $row['chapter'],
                    'verse'      => $row['verse'] === null ? null : (int) $row['verse'],
                    'endChapter' => (int) $row['end_chapter'],
                    'endVerse'   => $row['end_verse'] === null ? null : (int) $row['end_verse'],
                ]);

                if ((string) $row['source'] === 'manual') {
                    $manual[] = $formatted;
                } else {
                    $parsed[] = $formatted;
                }
            }

            return ['manual' => implode('; ', $manual), 'parsed' => $parsed];
        } catch (Throwable $e) {
            // On the request that applies migration 0014 the table does not
            // exist yet, and an edit screen that 500s over a panel is worse
            // than one without it.
            error_log('Portal: could not read scripture references: ' . $e->getMessage());

            return ['manual' => '', 'parsed' => []];
        }
    }

    /**
     * Keep a video's scripture references in step with the edit.
     *
     * Two sources with one rule each, so neither can quietly undo the other:
     *
     *   manual  whatever is in the scripture field, replaced wholesale. Empty
     *           the box and the manual references go, which is how somebody
     *           removes one.
     *   parsed  re-read from the description on every save, because the
     *           description is the thing that just changed.
     *
     * A re-scan never touches manual references and an editor's list is never
     * extended by the description, which is the only arrangement where an
     * editor's correction survives the next edit somebody else makes.
     *
     * Absent means leave alone, as everywhere else in this handler — a POST
     * that does not mention scripture must not clear it.
     */
    private function saveScripture(Request $request, int $videoId): void
    {
        try {
            $scripture = $this->scripture();

            $typed = $request->input('scripture');

            if ($typed !== null) {
                $scripture->replace(
                    $videoId,
                    \Portal\Content\ScriptureParser::parse((string) $typed),
                    'manual'
                );
            }

            $description = $request->input('description');

            if ($description !== null) {
                $scripture->replace(
                    $videoId,
                    \Portal\Content\ScriptureParser::parse((string) $description),
                    'parsed'
                );
                $scripture->markScanned($videoId);
            }
        } catch (Throwable $e) {
            /*
             * Never fatal. On the request that applies this migration the table
             * does not exist yet, and losing an index entry is a smaller
             * failure than an editor's save appearing to have been refused when
             * the video was in fact written.
             */
            error_log('Portal: could not update scripture references: ' . $e->getMessage());
        }
    }

    /**
     * The provider, if it can carry captions.
     *
     * Null is a normal answer and every caller handles it: a provider without
     * caption support is not a misconfiguration, it is a provider whose player
     * has no caption menu. The panel disappears rather than offering an upload
     * that could not work.
     */
    private function captionProvider(): ?\Portal\Video\SupportsCaptions
    {
        try {
            $provider = $this->container->get(\Portal\Video\VideoProvider::class);
        } catch (Throwable $e) {
            // An unconfigured provider is the normal state of a fresh install.
            error_log('Could not resolve the video provider: ' . $e->getMessage());

            return null;
        }

        return $provider instanceof \Portal\Video\SupportsCaptions ? $provider : null;
    }

    /**
     * Caption tracks for the edit screen.
     *
     * @return list<array{language: string, label: string}>
     */
    private function captions(\Portal\Content\Video $video): array
    {
        $provider = $this->captionProvider();

        return $provider === null ? [] : $provider->listCaptions($video->providerId);
    }

    /**
     * Send a caption track to the provider.
     *
     * Two sources, for the same reason the transcript importer has two: a
     * captioning service hands you a .vtt or an .srt, and somebody who already
     * imported a transcript here should not have to go and find the file
     * again. The transcript path costs sub-second timing — the cues were
     * stored at second precision because a transcript panel seeks to the
     * second — and the form says so rather than letting somebody discover it
     * as captions that feel half a beat early.
     */
    private function saveCaption(Request $request, \Portal\Content\Video $video): Response
    {
        $provider = $this->captionProvider();

        if ($provider === null) {
            return $this->back($request, 'This video provider cannot store captions.', 'error');
        }

        $language = \Portal\Content\CaptionFile::language(
            (string) ($request->input('caption_language') ?? '')
        );

        if ($language === null) {
            return $this->back(
                $request,
                'That is not a language code. Use something like "en", "es", or "pt-br".',
                'error'
            );
        }

        $vtt = $this->captionSource($request, $video);

        if ($vtt === null) {
            return $this->back(
                $request,
                'No timed lines could be read from that. A caption file has to be WebVTT or SubRip.',
                'error'
            );
        }

        $label = \Portal\Content\CaptionFile::label(
            (string) ($request->input('caption_label') ?? ''),
            $language
        );

        try {
            $provider->uploadCaption($video->providerId, $language, $label, $vtt);
        } catch (HttpException $e) {
            /*
             * A flash rather than an error page.
             *
             * Everything on this screen reports failure in the same place, and
             * a provider having a bad afternoon is the most ordinary failure
             * here — the one thing an editor must be able to do about it is try
             * again, which a 502 with the form gone does not help with.
             */
            return $this->back($request, $e->getMessage(), 'error');
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'caption.upload',
            'video',
            (string) $video->id,
            $language
        );

        /*
         * The cue count is the only feedback there is. The captions now live at
         * the provider and nothing here can look inside them again, so a file
         * that yielded four cues out of four hundred has to be visible at the
         * moment it is uploaded or not at all.
         */
        return $this->back($request, sprintf(
            'Uploaded %d caption line(s) in %s.',
            \Portal\Content\CaptionFile::cueCount($vtt),
            $label
        ));
    }

    /**
     * The WebVTT to upload, from whichever source was given.
     *
     * The file wins over the transcript when both are offered: somebody who
     * attached one meant the file, and it is the source with real timings.
     */
    private function captionSource(Request $request, \Portal\Content\Video $video): ?string
    {
        $upload = $_FILES['caption_file'] ?? null;

        if (is_array($upload)
            && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string) $upload['tmp_name'])) {
            // Checked before reading. Finding out a 900MB upload was not a
            // caption file by loading it is how a shared host runs out of
            // memory.
            if ((int) ($upload['size'] ?? 0) > \Portal\Content\CaptionFile::MAX_BYTES) {
                return null;
            }

            $contents = file_get_contents((string) $upload['tmp_name']);

            return $contents === false ? null : \Portal\Content\CaptionFile::toVtt($contents);
        }

        if ((string) ($request->input('caption_from_transcript') ?? '') === '') {
            return null;
        }

        return \Portal\Content\CaptionFile::fromTranscriptCues(
            $this->transcripts()->cues($video->id)
        );
    }

    private function deleteCaption(Request $request, \Portal\Content\Video $video): Response
    {
        $provider = $this->captionProvider();

        if ($provider === null) {
            return $this->back($request, 'This video provider cannot store captions.', 'error');
        }

        $language = \Portal\Content\CaptionFile::language(
            (string) ($request->input('caption_language') ?? '')
        );

        if ($language === null) {
            return $this->back($request, 'That is not a language code.', 'error');
        }

        try {
            $provider->deleteCaption($video->providerId, $language);
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        Audit::log(
            $this->db(),
            $this->user()?->email,
            'caption.delete',
            'video',
            (string) $video->id,
            $language
        );

        return $this->back($request, 'Captions removed.');
    }

    /**
     * Put a previous version back.
     *
     * Applied through the ordinary update path, so every validation rule still
     * runs — a revision from before a slug became unavailable is corrected
     * rather than written blindly. And the restore is itself snapshotted
     * first, so undoing an undo is possible.
     */
    private function restoreRevision(Request $request, string $subjectType, int $subjectId): Response
    {
        $revision = $this->revisions()->find((int) ($request->input('revision') ?? 0));

        if ($revision === null
            || $revision['subjectType'] !== $subjectType
            || $revision['subjectId'] !== $subjectId) {
            // A revision id that belongs to something else is a tampered form.
            // Nothing useful to say to it.
            return $this->back($request, 'That version is not available.', 'error');
        }

        $this->revisions()->record($subjectType, $subjectId, $this->user()?->email ?? '');

        $repository = match ($subjectType) {
            RevisionRepository::VIDEO    => $this->container->get(VideoRepository::class),
            RevisionRepository::CATEGORY => $this->container->get(CategoryRepository::class),
            RevisionRepository::SERIES   => $this->seriesRepo(),
            RevisionRepository::PLAYLIST => $this->playlistRepo(),
            default                      => null,
        };

        if ($repository === null) {
            return $this->back($request, 'That version is not available.', 'error');
        }

        $repository->update($subjectId, $revision['data']);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            $subjectType . '.restore',
            $subjectType,
            (string) $subjectId,
            'revision ' . $revision['id']
        );

        return $this->back($request, 'Restored that version.');
    }

    private function homeRowRepo(): \Portal\Content\HomeRowRepository
    {
        return $this->container->get(\Portal\Content\HomeRowRepository::class);
    }

    // --------------------------------------------------------- announcements

    public function announcementsScreen(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        return $this->admin('announcements', [
            'announcements' => $this->announcementRepo()->all(),
        ]);
    }

    // ------------------------------------------------------------------- live

    private function liveRepo(): \Portal\Content\LiveStreamRepository
    {
        return $this->container->get(\Portal\Content\LiveStreamRepository::class);
    }

    public function liveScreen(Request $request): Response
    {
        $this->require(Capability::MANAGE_VIDEOS);

        return $this->admin('live', [
            'streams' => $this->liveRepo()->all(),
            // Only ready videos, so a recording cannot be attached to something
            // that is still encoding and would 404 for everybody who followed
            // the redirect.
            'videos'  => $this->container->get(VideoRepository::class)->query([], 1, 100)['items'],
        ]);
    }

    public function saveLive(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIDEOS);

        $repo = $this->liveRepo();
        $action = (string) ($request->input('action') ?? 'create');
        $id = (int) ($request->input('id') ?? 0);

        switch ($action) {
            case 'delete':
                $repo->delete($id);
                Audit::log($this->db(), $this->user()?->email, 'live.delete', 'live', (string) $id);
                return $this->back($request, 'Stream removed.');

            case 'end':
                $repo->end($id);
                Audit::log($this->db(), $this->user()?->email, 'live.end', 'live', (string) $id);
                return $this->back($request, 'Marked as ended.');

            case 'resume':
                $repo->resume($id);
                Audit::log($this->db(), $this->user()?->email, 'live.resume', 'live', (string) $id);
                return $this->back($request, 'Back on. Its schedule decides again.');

            case 'update':
                $reason = \Portal\Content\LiveStreamPolicy::rejectionReason(
                    (string) ($request->input('embed_url') ?? '')
                );

                if ($reason !== null) {
                    return $this->back($request, $reason, 'error');
                }

                $repo->update($id, [
                    'title'        => $request->input('title'),
                    'description'  => $request->input('description'),
                    'embed_url'    => $request->input('embed_url'),
                    'starts_at'    => $request->input('starts_at'),
                    'ends_at'      => $request->input('ends_at'),
                    'video_id'     => $request->input('video_id'),
                    'is_published' => $request->input('is_published') !== null,
                    'member_only'  => $request->input('member_only') !== null,
                ]);

                Audit::log($this->db(), $this->user()?->email, 'live.update', 'live', (string) $id);

                return $this->back($request, $this->liveAdvice($request));

            default:
                $reason = \Portal\Content\LiveStreamPolicy::rejectionReason(
                    (string) ($request->input('embed_url') ?? '')
                );

                if ($reason !== null) {
                    return $this->back($request, $reason, 'error');
                }

                $new = $repo->create([
                    'title'        => $request->input('title'),
                    'description'  => $request->input('description'),
                    'embed_url'    => $request->input('embed_url'),
                    'starts_at'    => $request->input('starts_at'),
                    'ends_at'      => $request->input('ends_at'),
                    'is_published' => true,
                    'member_only'  => $request->input('member_only') !== null,
                ]);

                Audit::log($this->db(), $this->user()?->email, 'live.create', 'live', (string) $new);

                return $this->back($request, $this->liveAdvice($request));
        }
    }

    /**
     * A saved message that mentions the commonest mistake, if it applies.
     *
     * Pasting the page you are watching rather than the embed produces a frame
     * the other site refuses to render — visible only as an empty rectangle,
     * with the explanation in a console nobody has open. Saying so at the
     * moment of saving is the only point where it is cheap to fix.
     */
    private function liveAdvice(Request $request): string
    {
        $warning = \Portal\Content\LiveStreamPolicy::embedWarning(
            (string) ($request->input('embed_url') ?? '')
        );

        return $warning === null ? 'Stream saved.' : 'Stream saved. ' . $warning;
    }

    // ---------------------------------------------------------------- webhooks

    private function webhookRepo(): \Portal\Content\WebhookRepository
    {
        return $this->container->get(\Portal\Content\WebhookRepository::class);
    }

    public function webhooksScreen(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        $repo = $this->webhookRepo();
        $endpoints = $repo->all();

        // The delivery history is loaded per endpoint rather than as one join,
        // because a site with two endpoints is the normal case and the join
        // would be written for a site with fifty.
        $deliveries = [];
        foreach ($endpoints as $endpoint) {
            $deliveries[(int) $endpoint['id']] = $repo->recentDeliveries((int) $endpoint['id'], 10);
        }

        return $this->admin('webhooks', [
            'webhooks'   => $endpoints,
            'deliveries' => $deliveries,
            'pending'    => $repo->pendingCount(),
            'events'     => \Portal\Content\WebhookPolicy::events(),
            // The secret is shown once, on the request that created it, and
            // never again — it is in the database, so this is convenience
            // rather than secrecy, but a page that reprints every secret on
            // every visit is one more place for them to be seen.
            'newSecret'  => $request->query('secret') ?? '',
        ]);
    }

    public function saveWebhook(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $repo = $this->webhookRepo();
        $action = (string) ($request->input('action') ?? 'create');
        $id = (int) ($request->input('id') ?? 0);

        switch ($action) {
            case 'delete':
                $repo->delete($id);
                Audit::log($this->db(), $this->user()?->email, 'webhook.delete', 'webhook', (string) $id);
                return $this->back($request, 'Endpoint removed.');

            case 'disable':
                $repo->setActive($id, false);
                Audit::log($this->db(), $this->user()?->email, 'webhook.disable', 'webhook', (string) $id);
                return $this->back($request, 'Endpoint switched off.');

            case 'enable':
                $repo->setActive($id, true);
                Audit::log($this->db(), $this->user()?->email, 'webhook.enable', 'webhook', (string) $id);
                return $this->back($request, 'Endpoint switched on. Its failure count has been reset.');

            case 'rotate':
                $secret = $repo->rotateSecret($id);
                Audit::log($this->db(), $this->user()?->email, 'webhook.rotate', 'webhook', (string) $id);
                // Through the URL so it survives the redirect. It is a fresh
                // secret for an endpoint nothing has been signed with yet.
                return $this->redirect('/admin/webhooks?secret=' . rawurlencode($secret));

            default:
                $url = trim((string) ($request->input('url') ?? ''));

                $reason = \Portal\Content\WebhookPolicy::rejectionReason(
                    $url,
                    $this->config()->bool('webhook_allow_private_addresses', false)
                );

                if ($reason !== null) {
                    return $this->back($request, $reason, 'error');
                }

                $new = $repo->create(
                    $url,
                    \Portal\Content\WebhookPolicy::normalizeEvents($request->inputArray('events')),
                    (string) ($request->input('description') ?? '')
                );

                $row = $repo->find($new);

                Audit::log($this->db(), $this->user()?->email, 'webhook.create', 'webhook', (string) $new, $url);

                return $this->redirect(
                    '/admin/webhooks?secret=' . rawurlencode((string) ($row['secret'] ?? ''))
                );
        }
    }

    public function saveAnnouncement(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $repo = $this->announcementRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'announcement.delete', 'announcement', (string) $id);
                    return $this->back($request, 'Announcement removed.');

                case 'update':
                    $repo->update($id, [
                        'title'       => $request->input('title'),
                        'body'        => $request->input('body'),
                        'level'       => $request->input('level'),
                        'audience'    => $request->input('audience'),
                        'starts_at'   => $request->input('starts_at'),
                        'ends_at'     => $request->input('ends_at'),
                        // Absent means unchecked; the form is always complete.
                        'dismissible' => $request->input('dismissible') !== null,
                        'is_active'   => $request->input('is_active') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'announcement.update', 'announcement', (string) $id);
                    return $this->back($request, 'Announcement saved.');

                default:
                    $created = $repo->create([
                        'title'       => $request->input('title'),
                        'body'        => $request->input('body'),
                        'level'       => $request->input('level'),
                        'audience'    => $request->input('audience'),
                        'starts_at'   => $request->input('starts_at'),
                        'ends_at'     => $request->input('ends_at'),
                        'dismissible' => $request->input('dismissible') !== null,
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'announcement.create',
                        'announcement',
                        (string) $created->id
                    );
                    return $this->back($request, 'Announcement added.');
            }
        } catch (HttpException $e) {
            if ($e->status !== 400) {
                throw $e;
            }

            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function announcementRepo(): \Portal\Content\AnnouncementRepository
    {
        return $this->container->get(\Portal\Content\AnnouncementRepository::class);
    }

    // ------------------------------------------------------------- speakers

    /**
     * The tag vocabulary, with how much each label carries.
     *
     * Tags are created by being typed on a video, so this screen is not where
     * they come from — it is where they get FIXED. Without it a typo spread
     * across thirty videos means opening thirty videos, and near-duplicates
     * ("prayer", "prayers", "Prayer") accumulate with nothing able to merge
     * them. That is the whole cost of a vocabulary nobody curates first.
     *
     * MANAGE_CATEGORIES rather than MANAGE_VIDEOS, deliberately. Tagging one
     * video is an edit to that video and stays with manage_videos; renaming a
     * tag rewrites every video carrying it, which is vocabulary management —
     * the same act as editing a category, and the same capability.
     */
    public function tags(Request $request): Response
    {
        $this->require(Capability::MANAGE_CATEGORIES);

        return $this->admin('tags', [
            'tags' => $this->tagRepo()->withCounts(),
        ]);
    }

    public function saveTag(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_CATEGORIES);

        $repo = $this->tagRepo();
        $id = (int) ($request->input('id') ?? 0);
        $action = $request->input('action') ?? 'rename';

        if ($id <= 0) {
            return $this->back($request, 'That tag does not exist.', 'error');
        }

        if ($action === 'delete') {
            $repo->delete($id);
            Audit::log($this->db(), $this->user()?->email, 'tag.delete', 'tag', (string) $id);

            return $this->back($request, 'Tag removed. The content it was on is untouched.');
        }

        $name = trim((string) ($request->input('name') ?? ''));
        if ($name === '') {
            return $this->back($request, 'A tag needs a name.', 'error');
        }

        /*
         * Renaming onto a tag that already exists MERGES the two, which is the
         * usual reason to rename one at all. Said on the screen beforehand,
         * because a merge cannot be undone by renaming back — the two sets of
         * videos are one set afterwards and nothing recorded which was which.
         */
        $existing = $repo->findBySlug(\Portal\Support\Str::slug($name));
        $merging = $existing !== null && $existing->id !== $id;

        if (!$repo->rename($id, $name)) {
            return $this->back($request, 'That name cannot be used as a tag.', 'error');
        }

        Audit::log($this->db(), $this->user()?->email, 'tag.rename', 'tag', (string) $id, $name);

        return $this->back(
            $request,
            $merging
                ? 'Merged into “' . $name . '”. Everything that carried either tag now carries this one.'
                : 'Tag renamed to “' . $name . '”.'
        );
    }

    private function tagRepo(): \Portal\Content\TagRepository
    {
        return $this->container->get(\Portal\Content\TagRepository::class);
    }

    public function speakers(Request $request): Response
    {
        $this->require(Capability::MANAGE_SPEAKERS);

        return $this->admin('speakers', [
            'speakers' => $this->speakerRepo()->all(),
            'editing'  => $this->speakerRepo()->find((int) ($request->query('edit') ?? 0)),
        ]);
    }

    public function saveSpeaker(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SPEAKERS);

        $repo = $this->speakerRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $count = $repo->videoCount($id);
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'speaker.delete', 'speaker', (string) $id);

                    return $this->back($request, $count === 0
                        ? 'Speaker removed.'
                        : sprintf(
                            'Speaker removed. %d video%s kept, now with no speaker.',
                            $count,
                            $count === 1 ? '' : 's'
                        ));

                case 'update':
                    $repo->update($id, [
                        'name'      => $request->input('name'),
                        'slug'      => $request->input('slug'),
                        'bio'       => $request->input('bio'),
                        'image_url' => $request->input('image_url'),
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'speaker.update', 'speaker', (string) $id);
                    return $this->back($request, 'Speaker saved.');

                default:
                    $created = $repo->create([
                        'name' => $request->input('name'),
                        'bio'  => $request->input('bio'),
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'speaker.create',
                        'speaker',
                        (string) $created->id,
                        $created->name
                    );
                    return $this->back($request, 'Speaker added.');
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    /**
     * Videos that could be added to this series.
     *
     * Anything already in ANOTHER series is excluded rather than offered and
     * then silently stolen: a video belongs to at most one series, so adding it
     * here would remove it from there without anyone being told.
     *
     * @return list<\Portal\Content\Video>
     */
    private function unassignedVideos(int $seriesId): array
    {
        $rows = $this->db()->all(
            'SELECT * FROM {videos}
              WHERE deleted_at IS NULL AND (series_id IS NULL OR series_id = ?)
              ORDER BY COALESCE(published_at, created_at) DESC
              LIMIT 200',
            [$seriesId]
        );

        return array_map(
            static fn (array $row): \Portal\Content\Video => \Portal\Content\Video::fromRow($row),
            $rows
        );
    }

    private function seriesRepo(): \Portal\Content\SeriesRepository
    {
        return $this->container->get(\Portal\Content\SeriesRepository::class);
    }

    private function speakerRepo(): \Portal\Content\SpeakerRepository
    {
        return $this->container->get(\Portal\Content\SpeakerRepository::class);
    }

    // ----------------------------------------------------------- categories

    public function categories(Request $request): Response
    {
        $this->requireAnywhere(Capability::MANAGE_CATEGORIES);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        return $this->admin('categories', [
            'tree' => $categories->tree(true),
            'flat' => $categories->all(true),
        ]);
    }

    /** @param array<string, string> $params */
    public function editCategory(Request $request, array $params): Response
    {
        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        $category = $categories->find((int) ($params['id'] ?? 0));
        if ($category === null) {
            throw HttpException::notFound('That category does not exist.');
        }

        // A grant on an ancestor covers this one; that inheritance is the whole
        // reason category scopes exist rather than per-video ones.
        $this->require(Capability::MANAGE_CATEGORIES, 'category', $category->id);

        return $this->admin('category-edit', [
            'category'       => $category,
            'flat'           => $categories->all(true),
            'ancestors'      => $categories->ancestors($category->id),
            'inheritedLabel' => $this->inheritedCategoryThumbnailLabel($categories, $category),
            'inheritedDownloadLabel' => $this->inheritedCategoryDownloadLabel($categories, $category),
        ]);
    }

    public function saveCategory(Request $request): Response
    {
        $this->verifyCsrf($request);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        /*
         * As in saveSeries(): the actions that name a category are listed, and
         * everything else falls to `default:`, which creates.
         *
         * Creating is asked about the PARENT, because a new child of a category
         * you hold is inside your grant — which is what makes a category grant
         * usable at all, since otherwise a scoped editor could never add a
         * sub-category to their own section. A new top-level category has no
         * parent to ask about and stays site-wide, and so does `import`, which
         * makes top-level categories in bulk.
         */
        $named = ['delete', 'restore-revision', 'up', 'down', 'update'];

        if (in_array($action, $named, true) && $id > 0) {
            $this->require(Capability::MANAGE_CATEGORIES, 'category', $id);
        } elseif ($action !== 'import' && ($parent = (int) ($request->input('parent_id') ?? 0)) > 0) {
            $this->require(Capability::MANAGE_CATEGORIES, 'category', $parent);
        } else {
            $this->require(Capability::MANAGE_CATEGORIES);
        }

        try {
            switch ($action) {
                case 'delete':
                    $categories->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'category.delete', 'category', (string) $id);
                    return $this->back($request, 'Category deleted. Its videos were not removed.');

                case 'restore-revision':
                    return $this->restoreRevision($request, RevisionRepository::CATEGORY, $id);

                case 'up':
                case 'down':
                    /*
                     * Silent on success, like the other ordering buttons — the
                     * list itself is the feedback, and a flash after every
                     * nudge would bury the change under a message about it.
                     * Only the no-op is worth a word, because a button that
                     * appears to do nothing otherwise looks broken.
                     */
                    $moved = $categories->move($id, $action === 'up' ? -1 : 1);

                    return $this->back(
                        $request,
                        $moved ? '' : 'That one is already at the end of its level.'
                    );

                case 'update':
                    /*
                     * Moving a category needs permission on where it is GOING,
                     * not only on the thing being moved — otherwise somebody
                     * granted one section could graft it under any other, or
                     * out to the top level, and take it with them.
                     *
                     * Only when the parent actually changes. Asking every save
                     * about the parent would refuse a scoped editor their own
                     * top category, whose parent is by definition outside the
                     * grant they were given.
                     */
                    $newParent = ($p = (int) ($request->input('parent_id') ?? 0)) > 0 ? $p : null;
                    if ($newParent !== $categories->find($id)?->parentId) {
                        $newParent === null
                            ? $this->require(Capability::MANAGE_CATEGORIES)
                            : $this->require(Capability::MANAGE_CATEGORIES, 'category', $newParent);
                    }

                    $this->revisions()->record(RevisionRepository::CATEGORY, $id, $this->user()?->email ?? '');
                    $categories->update($id, [
                        'name'           => $request->input('name'),
                        'slug'           => $request->input('slug'),
                        'description'    => $request->input('description'),
                        'parent_id'      => ($p = (int) ($request->input('parent_id') ?? 0)) > 0 ? $p : null,
                        'thumbnail_mode' => $request->input('thumbnail_mode'),
                        'download_mode'  => $request->input('download_mode'),
                        // Absent means unchecked; see updateVideo().
                        'is_published'   => $request->input('is_published') !== null,
                        'member_only'    => $request->input('member_only') !== null,
                        'hidden'         => $request->input('hidden') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'category.update', 'category', (string) $id);
                    return $this->back($request, 'Category saved.');

                case 'import':
                    return $this->importCollections($request);

                default:
                    $created = $categories->create([
                        'name'      => $request->input('name'),
                        'parent_id' => ($p = (int) ($request->input('parent_id') ?? 0)) > 0 ? $p : null,
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'category.create',
                        'category',
                        (string) $created->id,
                        $created->name
                    );
                    return $this->back($request, 'Category created.');
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    /**
     * What "Inherit" resolves to for this video, said in words.
     *
     * A three-way setting whose default depends on a category chain and a site
     * setting is exactly the kind of thing an admin cannot verify by looking at
     * it. Rather than make them reason about the hierarchy, the form states the
     * answer the resolver actually gives.
     */
    private function inheritedThumbnailLabel(VideoRepository $videos, \Portal\Content\Video $video): string
    {
        $siteDefault = $this->config()->settingBool('members_thumbnail_default', false);

        // Asked with the video's OWN setting removed, which is the only way to
        // learn what it would fall back to.
        $withoutOwn = new \Portal\Content\Video(
            id: $video->id,
            providerId: $video->providerId,
            slug: $video->slug,
            title: $video->title,
            thumbnailMode: ThumbnailPolicy::INHERIT,
        );

        $resolved = $videos->thumbnailModes([$withoutOwn], $siteDefault)[$video->id]
            ?? ThumbnailPolicy::PUBLIC_ART;

        return $resolved === ThumbnailPolicy::MEMBERS
            ? 'Inherit — currently members only'
            : 'Inherit — currently shows the real thumbnail';
    }

    /**
     * What "Inherit" resolves to for this video's download setting.
     *
     * Same reasoning as the thumbnail label above, and more necessary: this
     * chain has four levels rather than three, and getting it wrong hands out a
     * file that cannot be taken back.
     */
    private function inheritedDownloadLabel(VideoRepository $videos, \Portal\Content\Video $video): string
    {
        // Asked with the video's OWN setting removed, which is the only way to
        // learn what it would fall back to. The series and the id are kept,
        // because those are what the fallback is read from.
        $withoutOwn = new \Portal\Content\Video(
            id: $video->id,
            providerId: $video->providerId,
            slug: $video->slug,
            title: $video->title,
            seriesId: $video->seriesId,
            downloadMode: DownloadPolicy::INHERIT,
        );

        return DownloadPolicy::allows($videos->downloadModeFor($withoutOwn, $this->downloadsEnabled()))
            ? 'Inherit — currently allowed'
            : 'Inherit — currently blocked';
    }

    /** The same question for a category: what do its ancestors say? */
    private function inheritedCategoryDownloadLabel(
        CategoryRepository $categories,
        \Portal\Content\Category $category
    ): string {
        foreach (array_reverse($categories->ancestors($category->id)) as $ancestor) {
            if ($ancestor->downloadMode === DownloadPolicy::ALLOW) {
                return 'Inherit — allowed, from ' . $ancestor->name;
            }
            if ($ancestor->downloadMode === DownloadPolicy::BLOCK) {
                return 'Inherit — blocked, from ' . $ancestor->name;
            }
        }

        return $this->downloadsEnabled()
            ? 'Inherit — allowed, from the site setting'
            : 'Inherit — blocked, from the site setting';
    }

    /**
     * And for a series, where the honest answer is "it depends on the episode".
     *
     * A series sits above the categories in the resolution order but does not
     * own them — each episode falls back to ITS OWN categories, which may
     * differ from one another. So this names the fallback rather than
     * pretending there is a single answer, and states the site setting, which
     * is the one part that is the same for all of them.
     */
    private function inheritedSeriesDownloadLabel(): string
    {
        return $this->downloadsEnabled()
            ? 'Inherit — each episode falls back to its categories, then the site setting (allowed)'
            : 'Inherit — each episode falls back to its categories, then the site setting (blocked)';
    }

    /** The site-wide download default. Off unless somebody turned it on. */
    private function downloadsEnabled(): bool
    {
        return $this->config()->settingBool('downloads_enabled', false);
    }

    /**
     * The same question for a category: what would it inherit from its parents?
     *
     * @param \Portal\Content\Category $category
     */
    private function inheritedCategoryThumbnailLabel(
        CategoryRepository $categories,
        \Portal\Content\Category $category
    ): string {
        foreach (array_reverse($categories->ancestors($category->id)) as $ancestor) {
            if ($ancestor->thumbnailMode === ThumbnailPolicy::MEMBERS) {
                return 'Inherit — members only, from ' . $ancestor->name;
            }
            if ($ancestor->thumbnailMode === ThumbnailPolicy::PUBLIC_ART) {
                return 'Inherit — real thumbnails, from ' . $ancestor->name;
            }
        }

        return $this->config()->settingBool('members_thumbnail_default', false)
            ? 'Inherit — members only, from the site setting'
            : 'Inherit — real thumbnails, from the site setting';
    }

    /**
     * Is there a video service that could accept an upload?
     *
     * Asked so the Videos screen can offer an upload box only when one would
     * work. Showing it regardless makes it a trap: it looks like the way in,
     * and every attempt fails with an error from a service nobody configured.
     */
    private function canUpload(): bool
    {
        try {
            $provider = $this->container->get(\Portal\Video\VideoProvider::class);
        } catch (Throwable) {
            return false;
        }

        // Deliberately not a test() call. That reaches the network, and this
        // runs on every visit to the Videos screen — a slow or unreachable
        // provider would make the page hang rather than the upload fail.
        return $provider instanceof \Portal\Video\BunnyStreamProvider
            ? $provider->uploadsConfigured()
            : true;
    }

    private function importCollections(Request $request): Response
    {
        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        try {
            $provider = $this->container->get(\Portal\Video\VideoProvider::class);
            $result = $categories->importCollections($provider->listCollections());
        } catch (Throwable $e) {
            return $this->back($request, 'Could not read collections: ' . $e->getMessage(), 'error');
        }

        Audit::log($this->db(), $this->user()?->email, 'category.import', null, null, sprintf(
            '%d created, %d already present',
            $result['created'],
            $result['skipped']
        ));

        return $this->back($request, sprintf(
            'Imported %d new categor%s. %d already existed and were left untouched.',
            $result['created'],
            $result['created'] === 1 ? 'y' : 'ies',
            $result['skipped']
        ));
    }

    // ---------------------------------------------------------------- users

    public function users(Request $request): Response
    {
        $this->require(Capability::MANAGE_USERS);

        $users = $this->db()->all(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
               FROM {users} u LEFT JOIN {roles} r ON r.id = u.role_id
              ORDER BY u.authorized ASC, u.created_at DESC
              LIMIT 200'
        );

        // One query for the whole listing rather than one per row. Two hundred
        // accounts times a note lookup is exactly the shape the query monitor
        // exists to catch.
        $notes = [];
        try {
            $notes = $this->container->get(\Portal\Auth\AccessRequests::class)
                ->notesFor(array_map(static fn (array $u): int => (int) $u['id'], $users));
        } catch (Throwable $e) {
            // Before migration 0018 has run. The screen is still the screen.
            error_log('Could not read access requests: ' . $e->getMessage());
        }

        return $this->admin('users', [
            'users'        => $users,
            'roles'        => $this->db()->all('SELECT * FROM {roles} ORDER BY position'),
            'requestNotes' => $notes,
        ]);
    }

    /**
     * The activity log, with the filters that make it answerable.
     *
     * Sixteen files write to this table and, until now, one screen read fifteen
     * rows of it. `view_audit_log` has been grantable since Phase 1 describing
     * itself as "Read the activity log" — a capability that promised a screen
     * nobody had built, which is this project's signature defect wearing a
     * permission for a hat.
     */
    public function auditLog(Request $request): Response
    {
        $this->require(Capability::VIEW_AUDIT_LOG);

        $filters = [
            'actor'  => trim((string) ($request->query['actor'] ?? '')),
            'action' => trim((string) ($request->query['action'] ?? '')),
            'target' => trim((string) ($request->query['target'] ?? '')),
            'from'   => $this->dateOnly((string) ($request->query['from'] ?? '')),
            'to'     => $this->dateOnly((string) ($request->query['to'] ?? '')),
        ];

        $result = Audit::page($this->db(), $filters, max(1, (int) ($request->query['page'] ?? 1)));

        return $this->admin('audit', [
            'log'     => $result,
            'filters' => $filters,
            'page'    => max(1, (int) ($request->query['page'] ?? 1)),
        ]);
    }

    /**
     * The same query, as a file.
     *
     * An activity log is read when something has gone wrong, and what happens
     * next usually happens somewhere else — a spreadsheet, an email to whoever
     * needs to know, a record kept beyond the pruning window. A screen that can
     * only be scrolled makes somebody retype it.
     *
     * Streamed, and bounded at 5000 rows: this runs on shared hosting where
     * building a year of history in memory is how a page becomes a 500.
     */
    public function auditLogCsv(Request $request): Response
    {
        $this->require(Capability::VIEW_AUDIT_LOG);

        $result = Audit::page($this->db(), [
            'actor'  => trim((string) ($request->query['actor'] ?? '')),
            'action' => trim((string) ($request->query['action'] ?? '')),
            'target' => trim((string) ($request->query['target'] ?? '')),
            'from'   => $this->dateOnly((string) ($request->query['from'] ?? '')),
            'to'     => $this->dateOnly((string) ($request->query['to'] ?? '')),
        ], 1, 5000);

        $rows = [];
        foreach ($result['items'] as $row) {
            $rows[] = [
                (string) $row['created_at'],
                (string) ($row['actor_email'] ?? ''),
                (string) $row['action'],
                (string) ($row['target_type'] ?? ''),
                (string) ($row['target_id'] ?? ''),
                (string) ($row['detail'] ?? ''),
                (string) ($row['ip'] ?? ''),
            ];
        }

        /*
         * Through Csv::document, which already knows the two things that make
         * a spreadsheet export wrong: the byte order mark Excel needs to read
         * UTF-8, and the leading characters it reads as a formula. An audit log
         * carries free text somebody else wrote, so the formula guard is not
         * theoretical here.
         */
        $body = \Portal\Support\Csv::document(
            ['when', 'who', 'action', 'target type', 'target', 'detail', 'ip'],
            $rows
        );

        /*
         * Exporting the log is itself logged. Somebody taking a copy of who did
         * what is exactly the kind of act the log exists to record, and leaving
         * it out would make the export the one action invisible to it.
         */
        Audit::log(
            $this->db(),
            $this->user()?->email,
            'audit.export',
            null,
            null,
            sprintf('%d row(s)', count($rows))
        );

        return Response::text($body)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="' . \Portal\Support\Csv::filename('activity-log') . '"'
            )
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * A date, or nothing.
     *
     * The value goes straight into a comparison against a DATETIME column, so
     * anything that is not a plain date is dropped rather than passed along to
     * become either a confusing result or a bound parameter doing nothing
     * useful.
     */
    private function dateOnly(string $value): string
    {
        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Who can sign in — the address list, and everyone the door was shut on.
     *
     * Named for the question it answers, not for its table. The screen next to
     * it is "Accounts", which answers "who has an account and what may they
     * do"; the two look alike enough that the application this is ported from
     * shipped them as "Access" and "Authorized emails" and had to rename both
     * a commit later because nobody could tell which was which.
     *
     * The refusals sit on the same page rather than a screen of their own. An
     * administrator arrives here because somebody cannot get in, and the answer
     * is either "they are not on the list" or "they were refused for another
     * reason" — putting those two facts on separate screens means reading one
     * and guessing the other.
     */
    public function signInAccess(Request $request): Response
    {
        $this->require(Capability::MANAGE_USERS);

        $allowlist = new \Portal\Auth\SignInAllowlist($this->db());
        $attempts = new \Portal\Auth\AccessAttempts($this->db());

        $search = trim((string) ($request->query['q'] ?? ''));
        $page = max(1, (int) ($request->query['page'] ?? 1));

        return $this->admin('signin-access', [
            'enabled'     => $this->config()->settingBool('signin_allowlist_enabled', false),
            'list'        => $allowlist->page($search, $page),
            'activeCount' => $allowlist->activeCount(),
            'attempts'    => $attempts->page(
                trim((string) ($request->query['aq'] ?? '')),
                (string) ($request->query['reason'] ?? ''),
                max(1, (int) ($request->query['apage'] ?? 1))
            ),
            'unreviewed'  => $attempts->unreviewedCount(),
            'search'      => $search,
            'claimName'   => (string) $this->config()->setting('signin_claim_name', ''),
            'claimValues' => (string) $this->config()->setting('signin_claim_values', ''),
            'gateMode'    => \Portal\Auth\ClaimGate::normalizeMode(
                (string) $this->config()->setting('signin_gate_mode', \Portal\Auth\ClaimGate::ALL)
            ),
            'authParam'   => (string) $this->config()->setting('signin_authorize_param', ''),
            'regSecret'   => (string) $this->config()->setting('signin_registration_secret', ''),
            'regUrl'      => $this->config()->url('/auth/registration-check'),
        ]);
    }

    public function saveSignInAccess(Request $request): Response
    {
        $this->require(Capability::MANAGE_USERS);
        $this->verifyCsrf($request);

        $allowlist = new \Portal\Auth\SignInAllowlist($this->db());
        $action = (string) ($request->input('action') ?? '');
        $actor = $this->user()?->email;

        switch ($action) {
            case 'add':
                $result = $allowlist->addMany(
                    (string) ($request->input('emails') ?? ''),
                    $request->input('note'),
                    $actor
                );

                Audit::log(
                    $this->db(),
                    $actor,
                    'signin.allowlist.add',
                    null,
                    null,
                    sprintf('%d added, %d already listed', $result['added'], $result['updated'])
                );

                $message = sprintf(
                    '%d added, %d already on the list.',
                    $result['added'],
                    $result['updated']
                );

                if ($result['rejected'] !== []) {
                    // Named, not counted. "3 were rejected" sends somebody
                    // hunting through two hundred lines for which three.
                    $message .= ' Not an address: ' . implode(', ', array_slice($result['rejected'], 0, 10))
                        . (count($result['rejected']) > 10 ? ' and more' : '') . '.';
                }

                return $this->back($request, $message, $result['rejected'] === [] ? 'success' : 'error');

            case 'suspend':
                $allowlist->suspend((int) ($request->input('id') ?? 0));
                Audit::log($this->db(), $actor, 'signin.allowlist.suspend', null, (string) $request->input('id'));

                return $this->back($request, 'Suspended. They are refused on their next request.');

            case 'reinstate':
                $allowlist->reinstate((int) ($request->input('id') ?? 0));
                Audit::log($this->db(), $actor, 'signin.allowlist.reinstate', null, (string) $request->input('id'));

                return $this->back($request, 'Reinstated.');

            case 'remove':
                $allowlist->remove((int) ($request->input('id') ?? 0));
                Audit::log($this->db(), $actor, 'signin.allowlist.remove', null, (string) $request->input('id'));

                return $this->back($request, 'Removed from the list.');

            case 'reviewed':
                (new \Portal\Auth\AccessAttempts($this->db()))->markReviewed(date('Y-m-d H:i:s'));

                return $this->back($request, 'Marked as dealt with.');

            case 'registration-secret':
                /*
                 * Regenerating is the only way to change it, and the old one
                 * stops working the instant it is pressed — which is the point
                 * of the button. Rotating a shared secret is what you do when
                 * you think it has leaked, and a rotation that leaves the
                 * previous value working for a grace period is not a rotation.
                 */
                $secret = \Portal\Support\Crypto::token(32);
                $this->config()->setSettings(['signin_registration_secret' => $secret]);
                Audit::log($this->db(), $actor, 'signin.registration.secret');

                return $this->back(
                    $request,
                    'A new secret was generated. Paste it into the Auth0 Action now — the previous one '
                    . 'stopped working just then, so any Action still holding it will refuse every signup.'
                );

            case 'registration-off':
                $this->config()->setSettings(['signin_registration_secret' => '']);
                Audit::log($this->db(), $actor, 'signin.registration.off');

                return $this->back(
                    $request,
                    'Turned off. The endpoint now answers 404 to everybody, so remove the Auth0 Action '
                    . 'or it will refuse every signup.',
                    'error'
                );

            case 'membership':
                $values = \Portal\Auth\ClaimGate::parseValues((string) ($request->input('claim_values') ?? ''));

                $this->config()->setSettings([
                    'signin_claim_name'   => trim((string) ($request->input('claim_name') ?? '')),
                    'signin_claim_values' => implode(', ', $values),
                    /*
                     * Through normalizeMode, so anything unrecognised becomes
                     * the strict mode rather than being stored verbatim and
                     * interpreted later. A value nobody can select from the
                     * form can still arrive by other means, and it must not be
                     * the loose one.
                     */
                    'signin_gate_mode'    => \Portal\Auth\ClaimGate::normalizeMode(
                        (string) ($request->input('gate_mode') ?? '')
                    ),
                    'signin_authorize_param' => trim((string) ($request->input('auth_param') ?? '')),
                ]);

                Audit::log($this->db(), $actor, 'signin.membership.save');

                return $this->back(
                    $request,
                    $values === []
                        ? 'Saved. With no accepted values the membership check is off.'
                        : sprintf('Saved. %d accepted value(s).', count($values))
                );

            case 'enable':
            case 'disable':
                $on = $action === 'enable';

                /*
                 * Refused while the list is empty, which is the one way this
                 * feature can take a site down. Enabling an empty allowlist
                 * refuses every non-administrator on their next request, and
                 * the person who did it finds out from other people rather
                 * than from the screen.
                 *
                 * Administrators are exempt from the gate, so they could still
                 * reach this screen to undo it — but "the site was dark until
                 * somebody phoned" is not an acceptable way to learn.
                 */
                if ($on && $allowlist->activeCount() === 0) {
                    return $this->back(
                        $request,
                        'Add at least one address first. Turning this on with an empty list '
                        . 'refuses everybody who is not an administrator.',
                        'error'
                    );
                }

                $this->config()->setSettings(['signin_allowlist_enabled' => $on ? '1' : '0']);
                Audit::log($this->db(), $actor, 'signin.allowlist.' . ($on ? 'enabled' : 'disabled'));

                return $this->back(
                    $request,
                    $on
                        ? 'On. Anyone not on the list is refused, except administrators and '
                          . 'accounts with a password here.'
                        : 'Off. The list is kept, and nobody is refused by it.'
                );
        }

        return $this->back($request, 'Unknown action.', 'error');
    }

    public function saveUser(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_USERS);

        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        $id = (int) ($request->input('id') ?? 0);
        $action = $request->input('action') ?? '';
        $target = $users->find($id);

        if ($target === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        switch ($action) {
            case 'authorize':
                $users->setAuthorized($id, true, $this->user()?->email);

                /*
                 * The question has been answered, so it stops being a question.
                 * Left in place, the row would keep the "asked for access" note
                 * beside an account that already has access — and would be the
                 * second place, after {users}, that claims to know whether
                 * somebody is waiting.
                 */
                try {
                    $this->container->get(\Portal\Auth\AccessRequests::class)->clear($id);
                } catch (Throwable $e) {
                    error_log('Could not clear the access request: ' . $e->getMessage());
                }

                Audit::log($this->db(), $this->user()?->email, 'user.authorize', 'user', (string) $id, $target->email);
                return $this->back($request, $target->email . ' can now watch videos.');

            case 'revoke':
                // Removing the last administrator is an unrecoverable lockout
                // on a host with no shell access.
                if ($users->isLastAdmin($id)) {
                    return $this->back($request, 'This is the only administrator. Promote someone else first.', 'error');
                }
                $users->setAuthorized($id, false, $this->user()?->email);
                Audit::log($this->db(), $this->user()?->email, 'user.revoke', 'user', (string) $id, $target->email);
                return $this->back($request, 'Access removed for ' . $target->email . '.');

            case 'role':
                $this->require(Capability::MANAGE_PERMISSIONS);

                $role = $request->input('role') ?? '';
                if ($target->isAdmin() && $role !== Capability::ROLE_ADMIN && $users->isLastAdmin($id)) {
                    return $this->back($request, 'This is the only administrator. Promote someone else first.', 'error');
                }

                $users->setRole($id, $role);
                Audit::log($this->db(), $this->user()?->email, 'user.role', 'user', (string) $id, $target->email . ' → ' . $role);
                return $this->back($request, 'Role updated.');

            default:
                return $this->back($request, 'Unknown action.', 'error');
        }
    }

    // -------------------------------------------------------------- plugins

    public function plugins(Request $request): Response
    {
        $this->require(Capability::MANAGE_PLUGINS);

        /** @var PluginManager $plugins */
        $plugins = $this->container->get(PluginManager::class);

        return $this->admin('plugins', [
            'plugins'        => $plugins->listForAdmin(),
            'uploadsAllowed' => PackageInstaller::uploadsAllowed($this->config()),
        ]);
    }

    public function togglePlugin(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_PLUGINS);

        /** @var PluginManager $plugins */
        $plugins = $this->container->get(PluginManager::class);

        $slug = $request->input('slug') ?? '';
        $action = $request->input('action') ?? '';

        $result = match ($action) {
            'activate'   => $plugins->activate($slug),
            'deactivate' => $plugins->deactivate($slug),
            'uninstall'  => $plugins->uninstall($slug),
            default      => ['ok' => false, 'message' => 'Unknown action.'],
        };

        Audit::log($this->db(), $this->user()?->email, 'plugin.' . $action, 'plugin', $slug, $result['message']);

        return $this->back($request, $result['message'], $result['ok'] ? 'success' : 'error');
    }

    // --------------------------------------------------------------- themes

    public function themes(Request $request): Response
    {
        $this->require(Capability::MANAGE_THEMES);

        $themes = $this->themeManager();
        $active = $themes->active();

        return $this->admin('themes', [
            'themes'         => $themes->listForAdmin(),
            'customizer'     => $active->customizer,
            'settings'       => $themes->settings($active->slug) + $active->defaults(),
            'activeSlug'     => $active->slug,
            'uploadsAllowed' => PackageInstaller::uploadsAllowed($this->config()),
        ]);
    }

    public function saveTheme(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_THEMES);

        $themes = $this->themeManager();
        $action = $request->input('action') ?? 'customize';

        if ($action === 'activate') {
            $result = $themes->activate($request->input('slug') ?? '');
            Audit::log($this->db(), $this->user()?->email, 'theme.activate', 'theme', $request->input('slug'));
            return $this->back($request, $result['message'], $result['ok'] ? 'success' : 'error');
        }

        $values = [];
        foreach ((array) ($request->post['settings'] ?? []) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        // Unchecked boxes are simply absent from a form post, so every declared
        // bool has to be defaulted off or it could never be turned off.
        foreach ($themes->active()->settingDefinitions() as $key => $definition) {
            if (($definition['type'] ?? '') === 'bool' && !isset($values[$key])) {
                $values[$key] = '0';
            }
        }

        $themes->saveSettings($themes->activeSlug(), $values);
        Audit::log($this->db(), $this->user()?->email, 'theme.customize', 'theme', $themes->activeSlug());

        return $this->back($request, 'Appearance saved.');
    }

    // ------------------------------------------------------------ providers

    public function providers(Request $request): Response
    {
        $this->require(Capability::MANAGE_PROVIDERS);

        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $kinds = [];
        foreach ([ProviderRegistry::KIND_AUTH, ProviderRegistry::KIND_VIDEO, ProviderRegistry::KIND_MAIL] as $kind) {
            $active = $registry->activeSlug($kind);
            $kinds[$kind] = [
                'options' => $registry->describe($kind),
                'active'  => $active,
                'fields'  => $active === null ? [] : $registry->fieldsFor($kind, $active),
                'values'  => $active === null ? [] : $registry->credentials($kind, $active),
            ];
        }

        return $this->admin('providers', ['kinds' => $kinds]);
    }

    public function saveProvider(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_PROVIDERS);

        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $kind = $request->input('kind') ?? '';
        $slug = $request->input('slug') ?? '';
        $action = $request->input('action') ?? 'save';

        $credentials = [];
        foreach ((array) ($request->post['credentials'] ?? []) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $credentials[$key] = trim((string) $value);
            }
        }

        try {
            $registry->saveCredentials($kind, $slug, $credentials);

            if ($action === 'test') {
                $result = $registry->safeTest($registry->build($kind, $slug));
                return $this->back(
                    $request,
                    $result->message . ($result->detail !== null ? ' — ' . $result->detail : ''),
                    $result->ok ? 'success' : 'error'
                );
            }

            // Activation runs the provider's own test first; a service that
            // fails now would otherwise fail silently for weeks.
            $result = $registry->activate($kind, $slug);

            Audit::log($this->db(), $this->user()?->email, 'provider.activate', $kind, $slug, $result->message);

            return $this->back(
                $request,
                $result->ok
                    ? ucfirst($kind) . ' service switched to ' . $slug . '. ' . $result->message
                    : 'Not switched: ' . $result->message,
                $result->ok ? 'success' : 'error'
            );
        } catch (Throwable $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ------------------------------------------------------------- settings

    public function settings(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        /** @var \Portal\Support\Cron $cron */
        $cron = $this->container->get(\Portal\Support\Cron::class);

        return $this->admin('settings', [
            'settings' => [
                'site_name' => $this->config()->setting('site_name', 'Video Portal'),
                'timezone'  => $this->config()->setting('timezone', 'UTC'),
                'members_thumbnail_default' => $this->config()->setting('members_thumbnail_default', '0'),
                'downloads_enabled'   => $this->config()->setting('downloads_enabled', '0'),
                'allow_indexing'      => $this->config()->setting('allow_indexing', '0'),
                'podcast_author'      => $this->config()->setting('podcast_author', ''),
                'podcast_owner_name'  => $this->config()->setting('podcast_owner_name', ''),
                'podcast_owner_email' => $this->config()->setting('podcast_owner_email', ''),
                'podcast_image_url'   => $this->config()->setting('podcast_image_url', ''),
                'podcast_category'    => $this->config()->setting('podcast_category', 'Religion & Spirituality'),
                'podcast_explicit'    => $this->config()->setting('podcast_explicit', '0'),
                // Default '1': the box is opt-out, because a subscribe form
                // that nobody switched on is a feature nobody knows exists.
                'subscriptions_enabled' => $this->config()->setting('subscriptions_enabled', '1'),
                // Default '0': enforcing this is a decision with real lockout
                // risk, so it belongs to whoever owns the site.
                'require_verified_email' => $this->config()->setting('require_verified_email', '0'),
                // Default '1': refusing somebody and giving them no way to ask
                // is the state this replaces, not one worth preserving.
                'allow_access_requests' => $this->config()->setting('allow_access_requests', '1'),
                // Default '0'. A switch that closes the site must never be on
                // because nobody set it.
                'maintenance_mode'    => $this->config()->setting('maintenance_mode', '0'),
                'maintenance_message' => $this->config()->setting('maintenance_message', ''),
            ],
            'subscriberCount' => $this->subscriberCount(),
            'cronJobs' => $cron->jobs(),
            'baseUrl'  => $this->config()->baseUrl(),
            // Geo lists are shown read-only: they live in config.php on
            // purpose, so a mistaken entry can be undone over FTP rather than
            // locking the author out of the screen that made it.
            'geo' => [
                'viewers' => $this->config()->csv('geo_whitelist'),
                'admin'   => $this->config()->csv('admin_geo_whitelist'),
                'bypass'  => $this->config()->csv('admin_geo_bypass_emails'),
            ],
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $siteName = trim($request->input('site_name') ?? '');
        $timezone = $request->input('timezone') ?? 'UTC';

        if ($siteName === '') {
            return $this->back($request, 'The site needs a name.', 'error');
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $this->back($request, 'That is not a recognised timezone.', 'error');
        }

        /*
         * Absent means unchecked, so a checkbox cannot be read with ?? — that
         * would make the setting impossible to turn back off.
         *
         * Which makes a POST that omits a checkbox indistinguishable from one
         * that unticked it, and this handler used to read every such POST as
         * "turn it off". Harmless while every checkbox here defaulted to OFF,
         * because writing '0' matched the default. The moment one defaulted to
         * ON, a partial save silently disabled it — which is the defect Phase 4
         * found on the video form and fixed the same way.
         *
         * So the form declares itself complete. Present, an unticked box really
         * does clear. Missing, checkboxes are left exactly as they were, and a
         * caller saving one text field cannot change a policy it never
         * mentioned.
         */
        $whole = $request->input('_whole_form') !== null;

        $checkbox = function (string $key, bool $default) use ($request, $whole): string {
            if ($whole) {
                return $request->input($key) !== null ? '1' : '0';
            }

            return $this->config()->settingBool($key, $default) ? '1' : '0';
        };

        $this->config()->setSettings([
            'site_name' => $siteName,
            'timezone'  => $timezone,

            'members_thumbnail_default' => $checkbox('members_thumbnail_default', false),
            'downloads_enabled'         => $checkbox('downloads_enabled', false),
            'allow_indexing'            => $checkbox('allow_indexing', false),
            'podcast_explicit'          => $checkbox('podcast_explicit', false),
            'subscriptions_enabled'     => $checkbox('subscriptions_enabled', true),
            'require_verified_email'    => $checkbox('require_verified_email', false),
            'allow_access_requests'     => $checkbox('allow_access_requests', true),
            'maintenance_mode'          => $checkbox('maintenance_mode', false),

            'maintenance_message' => mb_substr(trim($request->input('maintenance_message') ?? ''), 0, 300),

            'podcast_author'      => trim($request->input('podcast_author') ?? ''),
            'podcast_owner_name'  => trim($request->input('podcast_owner_name') ?? ''),
            'podcast_owner_email' => trim($request->input('podcast_owner_email') ?? ''),
            'podcast_image_url'   => trim($request->input('podcast_image_url') ?? ''),
            'podcast_category'    => trim($request->input('podcast_category') ?? ''),
        ]);
        Audit::log($this->db(), $this->user()?->email, 'settings.update');

        return $this->back($request, 'Settings saved.');
    }

    // -------------------------------------------------------------- helpers

    /** @param array<string, mixed> $data */
    private function admin(string $screen, array $data): Response
    {
        $view = new AdminView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'user'     => $this->user(),
            'guard'    => $this->guard(),
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
            // Every admin screen, because the person who needs reminding that
            // the site is shut is the one who has moved on to something else.
            'maintenanceMode' => $this->config()->settingBool('maintenance_mode', false),
        ]))->private();
    }

    /** @return array<string, array{slug: ?string, ok: bool}> */
    private function providerSummary(): array
    {
        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $summary = [];
        foreach ([ProviderRegistry::KIND_AUTH, ProviderRegistry::KIND_VIDEO, ProviderRegistry::KIND_MAIL] as $kind) {
            $slug = $registry->activeSlug($kind);

            /*
             * `ok` used to mean "something is selected", and the screen printed
             * the slug beside it — which reads as "this works". It frequently
             * was not: a site with Resend chosen and no API key dropped every
             * share link, approval request and subscription email in silence.
             *
             * Selected and configured are now separate answers, and the missing
             * fields are named, because "email is not configured" sends
             * somebody to a screen with eight boxes on it.
             */
            $missing = $slug === null ? [] : $registry->missingCredentials($kind, $slug);

            /*
             * Mail is asked twice, deliberately.
             *
             * A field check can only see what the form collects. PhpMailProvider
             * is configured when it has a From address AND `mail()` exists — and
             * plenty of shared hosts disable that function, which no list of
             * credential fields can express. So the provider's own verdict is
             * consulted as well, and a provider that says no is not overruled by
             * a form that looks complete.
             *
             * Only mail has isConfigured(); auth and video would each need a
             * network call to answer, which is not something a dashboard render
             * may do.
             */
            $selfReport = ($kind === ProviderRegistry::KIND_MAIL && $slug !== null)
                ? $registry->mailConfigured()
                : true;

            $summary[$kind] = [
                'slug'    => $slug,
                'ok'      => $slug !== null && $missing === [] && $selfReport,
                'missing' => $missing,
                /*
                 * The provider said no while every required field is filled in.
                 * Naming no field would be worse than useless here — it would
                 * send somebody to re-type an API key that is already correct.
                 */
                'refused' => $slug !== null && $missing === [] && !$selfReport,
                /*
                 * What stops working, said in the words of the thing the person
                 * came here to do. "Mail is unconfigured" is a fact about the
                 * software; "no share links will be delivered" is a fact about
                 * their site.
                 */
                'cost'    => self::PROVIDER_COST[$kind] ?? '',
            ];
        }

        return $summary;
    }
}
