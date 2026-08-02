<?php
/**
 * Plugin Name: Comments
 * Slug: comments
 * Version: 1.0.0
 * Requires: 1.0.0
 * Requires PHP: 8.2.0
 * Description: Lets approved viewers discuss a video, with a moderation queue and reports.
 * Author: Video Portal
 */

declare(strict_types=1);

use Portal\Auth\Capability;
use Portal\Container;
use Portal\Db;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\Comments\CommentPage;
use Portal\Plugins\Comments\CommentPolicy;
use Portal\Plugins\Comments\CommentRepository;
use Portal\Plugins\Comments\CommentView;
use Portal\Support\RateLimit;

/** @var \Portal\Plugins\PluginContext $plugin */

require_once __DIR__ . '/src/CommentPolicy.php';
require_once __DIR__ . '/src/CommentRepository.php';
require_once __DIR__ . '/src/CommentView.php';
require_once __DIR__ . '/src/CommentPage.php';

/**
 * Who may comment.
 *
 * The same test /watch applies. Somebody who cannot play the video has nothing
 * to say about it yet, and letting unapproved accounts post would make the
 * approval queue and the moderation queue two names for the same backlog.
 */
$canComment = static function () use ($plugin): bool {
    $user = $plugin->user();

    return $user !== null && ($user->isAdmin() || $user->authorized);
};

$repository = static fn (): CommentRepository => new CommentRepository(Container::instance()->get(Db::class));

/*
 * The thread, under the video.
 *
 * @param array<string, mixed> $video the template's video data
 */
$plugin->addAction('after_video', static function (array $video) use ($plugin, $canComment, $repository): void {
    $videoId = isset($video['id']) ? (int) $video['id'] : 0;
    if ($videoId <= 0) {
        return;
    }

    // Per-category override: comments can be switched off for one section of
    // the site without deactivating the plugin everywhere.
    try {
        $videos = Container::instance()->get(\Portal\Content\VideoRepository::class);
        $model = $videos->find($videoId);

        if ($model !== null && !$plugin->isEnabledFor($videos->effectiveCategoryId($model))) {
            return;
        }
    } catch (Throwable $e) {
        error_log('Comments: could not resolve the category; showing the thread. ' . $e->getMessage());
    }

    try {
        $comments = $repository()->thread($videoId);
    } catch (Throwable $e) {
        // A broken thread must not take the video page with it.
        error_log('Comments: could not load the thread: ' . $e->getMessage());
        return;
    }

    $user = $plugin->user();

    echo CommentView::thread(
        $comments,
        CommentView::form(
            '/comments/' . $videoId,
            $plugin->csrfField(),
            $canComment(),
            $user !== null
        ),
        (string) (Container::instance()->get(\Portal\Auth\Session::class)->pull('comment_notice') ?? ''),
        // Offered only to someone who could also post. An anonymous report
        // button is one anybody can hold down, and the count is what the
        // moderation queue sorts by.
        $canComment() ? '/comments/report' : '',
        $plugin->csrfField()
    );
});

/*
 * Posting.
 *
 * A plain form post rather than JSON, so it works with JavaScript switched off
 * and degrades to a page reload rather than to silence.
 */
/*
 * Note the constraint. Without it {video} matches ANY single segment, so
 * /comments/report is swallowed by this route with video = "report", which
 * casts to 0 and silently does nothing — the POST still answers 302 and the
 * report is simply lost. Registering the literal route first would also work
 * and would depend on nobody ever reordering these; a pattern that cannot
 * match "report" does not.
 */
$plugin->addRoute(
    'POST',
    '/comments/{video:\d+}',
    static function (Request $request, array $params) use ($plugin, $canComment, $repository): Response {
        $plugin->verifyCsrf($request);

        $videoId = (int) ($params['video'] ?? 0);
        $user = $plugin->user();

        if (!$canComment() || $user === null) {
            return Response::redirect($plugin->config()->url('/auth/login'));
        }

        $say = static function (string $message) use ($plugin, $videoId): Response {
            Container::instance()->get(\Portal\Auth\Session::class)->put('comment_notice', $message);

            // Back to the video, at the comments. Which video is looked up
            // rather than taken from the form: a redirect target a visitor
            // controls is an open redirect waiting to happen.
            $slug = (string) (Container::instance()->get(Db::class)
                ->value('SELECT slug FROM {videos} WHERE id = ?', [$videoId]) ?? '');

            return Response::redirect($plugin->config()->url('/watch/' . $slug . '#comments'));
        };

        // Cheap for us, consequential for whoever moderates it.
        $limiter = new RateLimit(Container::instance()->get(Db::class));
        if (!$limiter->allow('comment:' . $user->id, 10, 600)) {
            return $say('You are posting very quickly. Give it a minute.');
        }

        $normalized = CommentPolicy::normalize((string) ($request->input('body') ?? ''));
        if (!$normalized['ok']) {
            return $say($normalized['error'] ?? 'That comment could not be posted.');
        }

        $parentId = (int) ($request->input('parent_id') ?? 0);

        $status = CommentPolicy::initialStatus(
            (string) $plugin->setting('moderation', CommentPolicy::MODERATE_NEWCOMERS),
            $repository()->approvedCountFor($user->email),
            $normalized['body'] ?? ''
        );

        $repository()->create(
            $videoId,
            $parentId > 0 ? $parentId : null,
            $user->displayName(),
            $user->email,
            $normalized['body'] ?? '',
            $status,
            $request->ip()
        );

        return $say($status === CommentPolicy::STATUS_APPROVED
            ? 'Posted.'
            : 'Thanks — your comment is waiting to be reviewed.');
    },
    ['auth.authorized']
);

/*
 * Reporting a comment.
 *
 * A separate route rather than a parameter on the posting one: they need
 * different rate limits and mean entirely different things, and folding them
 * together would put "publish this text" and "flag that text" behind one
 * branch nobody wants to get wrong.
 */
$plugin->addRoute(
    'POST',
    '/comments/report',
    static function (Request $request) use ($plugin, $canComment, $repository): Response {
        $plugin->verifyCsrf($request);

        $user = $plugin->user();
        $commentId = (int) ($request->input('comment_id') ?? 0);

        $session = Container::instance()->get(\Portal\Auth\Session::class);

        $home = static fn (): Response => Response::redirect($plugin->config()->url('/'));

        if (!$canComment() || $user === null || $commentId <= 0) {
            return $home();
        }

        $db = Container::instance()->get(Db::class);

        $slug = (string) ($db->value(
            'SELECT v.slug FROM {comments} c JOIN {videos} v ON v.id = c.video_id WHERE c.id = ?',
            [$commentId]
        ) ?? '');

        if ($slug === '') {
            return $home();
        }

        // Deliberately generous, because the limit exists to stop scripted
        // abuse rather than to ration a judgement somebody makes by hand.
        $limiter = new RateLimit($db);
        if (!$limiter->allow('comment-report:' . $user->id, 20, 3600)) {
            $session->put('comment_notice', 'You have reported a lot of comments recently.');

            return Response::redirect($plugin->config()->url('/watch/' . $slug . '#comments'));
        }

        try {
            $repository()->report($commentId, $user->email, (string) ($request->input('reason') ?? ''));
        } catch (Throwable $e) {
            error_log('Comments: could not record a report: ' . $e->getMessage());
        }

        // The same words whether or not this was a new report. Telling somebody
        // "you already reported that" reveals nothing useful and invites them
        // to find another way to register the same objection.
        $session->put('comment_notice', 'Thanks — a moderator will take a look.');

        return Response::redirect($plugin->config()->url('/watch/' . $slug . '#comments'));
    },
    ['auth.authorized']
);

$plugin->addAdminPage(
    'Comments',
    'comments',
    Capability::MODERATE_COMMENTS,
    static fn ($request, $params) => (new CommentPage($plugin))->show($request, $params),
    position: 30
);
