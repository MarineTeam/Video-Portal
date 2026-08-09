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
        /*
         * One page, not the whole thread. A video with two thousand comments
         * used to load every row and filter in PHP, which is fine for the first
         * year and a memory limit afterwards.
         *
         * The page comes from the query string so it is linkable and the back
         * button works — see CommentView::pager for why that matters more than
         * a smoother pager would.
         */
        $page = max(1, (int) ($_GET['comments'] ?? 1));
        $result = $repository()->page($videoId, $page);
    } catch (Throwable $e) {
        // A broken thread must not take the video page with it.
        error_log('Comments: could not load the thread: ' . $e->getMessage());
        return;
    }

    $user = $plugin->user();

    echo CommentView::thread(
        $result['comments'],
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
        $plugin->csrfField(),
        // Who is looking, so the view can offer Edit and Delete on their own
        // comments. Both routes check again for themselves; a missing button is
        // not a permission.
        $user?->email ?? '',
        ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']],
        (string) ($video['slug'] ?? '')
    );
});

/*
 * A comment count on every card in a listing.
 *
 * Through the `video_list` filter, so it reaches every listing at once —
 * homepage, category, search — rather than each of them learning about
 * comments separately.
 *
 * Batched into one query for the whole page. A count per card is a query per
 * card, which on a homepage of twenty-four is the mistake the thumbnail modes
 * exist to avoid.
 *
 * @param list<array<string, mixed>> $cards
 */
$plugin->addFilter('video_list', static function (array $cards) use ($repository): array {
    if ($cards === []) {
        return $cards;
    }

    try {
        $counts = $repository()->countsFor(array_column($cards, 'id'));
    } catch (Throwable $e) {
        error_log('Comments: could not count comments for a listing: ' . $e->getMessage());

        return $cards;
    }

    foreach ($cards as $index => $card) {
        // Absent rather than zero for a video with none. A theme printing
        // "0 comments" under every card on a quiet site is worse than one
        // printing nothing.
        $count = $counts[(int) ($card['id'] ?? 0)] ?? 0;

        if ($count > 0) {
            $cards[$index]['commentCount'] = $count;
        }
    }

    return $cards;
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

/*
 * Editing and deleting your own comment.
 *
 * Two routes rather than one with a mode, because they are different acts with
 * different rules — an edit has a window and re-runs moderation, a deletion has
 * neither — and folding them together would put both behind one branch nobody
 * wants to get wrong.
 *
 * Both resolve the comment and check ownership FOR THEMSELVES. The buttons are
 * only rendered for the author, but a button that is merely absent is not a
 * permission: anybody can post the form without ever having seen it.
 */
$plugin->addRoute(
    'POST',
    '/comments/edit',
    static function (Request $request) use ($plugin, $repository, $canComment): Response {
        $plugin->verifyCsrf($request);

        $user = $plugin->user();
        $comment = $repository()->find((int) ($request->input('comment_id') ?? 0));

        $back = static function (string $message) use ($plugin, $comment): Response {
            $slug = $comment === null ? '' : (string) (Container::instance()->get(Db::class)
                ->value('SELECT slug FROM {videos} WHERE id = ?', [(int) $comment['video_id']]) ?? '');

            Container::instance()->get(\Portal\Auth\Session::class)->put('comment_notice', $message);

            return Response::redirect($plugin->config()->url('/watch/' . $slug . '#comments'));
        };

        if ($comment === null || $user === null || !$canComment()) {
            return $back('That comment could not be edited.');
        }

        if (!CommentPolicy::canEdit(
            (string) $comment['author_email'],
            $user->email,
            (string) $comment['status'],
            (string) $comment['created_at']
        )) {
            /*
             * One message for "not yours" and for "too late". A different
             * answer for each would tell somebody probing which comments exist
             * and who wrote them.
             */
            return $back('That comment can no longer be edited.');
        }

        $normalized = CommentPolicy::normalize((string) ($request->input('body') ?? ''));

        if (!$normalized['ok']) {
            return $back($normalized['error'] ?? 'That comment could not be saved.');
        }

        // The same decision a new comment gets. Keeping the old status would
        // let somebody post something harmless, wait for approval, and edit it
        // into whatever they actually wanted to say.
        $status = CommentPolicy::statusAfterEdit(
            (string) $plugin->setting('moderation', CommentPolicy::MODERATE_NEWCOMERS),
            $repository()->approvedCountFor($user->email),
            $normalized['body'] ?? ''
        );

        $repository()->edit((int) $comment['id'], $normalized['body'] ?? '', $status);

        return $back($status === CommentPolicy::STATUS_APPROVED
            ? 'Saved.'
            : 'Saved — your comment is waiting to be reviewed again.');
    },
    ['auth.authorized']
);

$plugin->addRoute(
    'POST',
    '/comments/delete',
    static function (Request $request) use ($plugin, $repository, $canComment): Response {
        $plugin->verifyCsrf($request);

        $user = $plugin->user();
        $comment = $repository()->find((int) ($request->input('comment_id') ?? 0));

        $slug = $comment === null ? '' : (string) (Container::instance()->get(Db::class)
            ->value('SELECT slug FROM {videos} WHERE id = ?', [(int) $comment['video_id']]) ?? '');

        $session = Container::instance()->get(\Portal\Auth\Session::class);

        if ($comment === null || $user === null || !$canComment()
            || !CommentPolicy::canDelete((string) $comment['author_email'], $user->email, (string) $comment['status'])) {
            $session->put('comment_notice', 'That comment could not be removed.');

            return Response::redirect($plugin->config()->url('/watch/' . $slug . '#comments'));
        }

        /*
         * Marked removed rather than deleted, which is what the tombstone rule
         * already handles: a comment with replies stays as "this comment was
         * removed" so the answers still make sense, and one without simply
         * disappears. Reusing that is why this is a status change and not a
         * DELETE.
         */
        $repository()->setStatus((int) $comment['id'], CommentPolicy::STATUS_REMOVED);

        $session->put('comment_notice', 'Your comment was removed.');

        return Response::redirect($plugin->config()->url('/watch/' . $slug . '#comments'));
    },
    ['auth.authorized']
);

/*
 * Tell somebody there is a queue.
 *
 * The plugin's own design note says holding everything "builds a queue nobody
 * empties, which a visitor cannot distinguish from the feature being broken".
 * That was written as a reason to default to holding only newcomers; it is
 * also a reason to say something when a queue does form.
 *
 * Once a day at most, and only when the oldest waiting comment has been there
 * long enough to look forgotten. A message per comment would be the thing that
 * gets filtered, after which the queue is invisible again.
 */
$plugin->addCronJob('moderation-digest', 21600, static function () use ($plugin, $repository): string {
    $queue = $repository()->queueAge();

    if ($queue['count'] === 0) {
        return 'Nothing waiting.';
    }

    $oldest = $queue['oldest'] === null ? time() : (strtotime((string) $queue['oldest']) ?: time());
    $waitingHours = (int) floor((time() - $oldest) / 3600);

    if ($waitingHours < 12) {
        return sprintf('%d waiting, none for long enough to mention.', $queue['count']);
    }

    $lastSent = (int) $plugin->setting('digest_sent_at', 0);

    if (time() - $lastSent < 86400) {
        return 'Already mentioned today.';
    }

    $db = Container::instance()->get(Db::class);

    /*
     * Everybody who could actually act on it, found through the capability
     * rather than through a role name — a site that made a "moderator" role
     * would otherwise be told nothing.
     */
    $moderators = $db->all(
        'SELECT DISTINCT u.email FROM {users} u
           JOIN {roles} r ON r.id = u.role_id
      LEFT JOIN {role_capabilities} rc ON rc.role_id = r.id
      LEFT JOIN {capabilities} c ON c.id = rc.capability_id
          WHERE u.authorized = 1
            AND (r.slug = ? OR c.slug = ?)',
        [Capability::ROLE_ADMIN, Capability::MODERATE_COMMENTS]
    );

    if ($moderators === []) {
        return 'Nobody can moderate, so nobody was told.';
    }

    $mailer = Container::instance()->get(\Portal\Mail\MailProvider::class);
    $url = $plugin->config()->url('/admin/comments');

    $sent = 0;
    foreach ($moderators as $moderator) {
        $result = $mailer->send(
            (string) $moderator['email'],
            sprintf('%d comment(s) waiting to be reviewed', $queue['count']),
            sprintf(
                '<p>%d comment(s) are waiting on %s. The oldest has been there %d hour(s).</p>'
                . '<p><a href="%s">Review them</a></p>',
                $queue['count'],
                e((string) ($plugin->config()->setting('site_name', 'your site') ?? 'your site')),
                $waitingHours,
                e($url)
            ),
            null,
            []
        );

        if ($result->sent) {
            $sent++;
        }
    }

    // Recorded whatever happened. A mail provider that is down would otherwise
    // make this retry every cron tick, which is a message storm the moment it
    // comes back.
    $plugin->setSetting('digest_sent_at', time());

    return sprintf('%d waiting; told %d moderator(s).', $queue['count'], $sent);
});

$plugin->addAdminPage(
    'Comments',
    'comments',
    Capability::MODERATE_COMMENTS,
    static fn ($request, $params) => (new CommentPage($plugin))->show($request, $params),
    position: 30
);
