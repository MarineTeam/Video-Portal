<?php

declare(strict_types=1);

namespace Portal\Plugins\Comments;

/**
 * The comment thread as it appears under a video.
 *
 * Everything a person typed goes through e() and nl2br, never through a
 * markdown or BBCode pass. A comment field is the one place on this site where
 * an untrusted stranger controls the input, so the renderer's only job is to
 * make sure nothing they write is ever interpreted as anything.
 *
 * Styling leans on the theme's own classes where they exist and adds a small
 * scoped block for the rest, so a custom theme inherits something reasonable
 * without having to know this plugin exists.
 */
final class CommentView
{
    /**
     * @param list<array<string, mixed>> $comments
     */
    public static function thread(
        array $comments,
        string $formHtml,
        string $notice = '',
        string $reportAction = '',
        string $csrfField = '',
        string $viewerEmail = '',
        array $pagination = [],
        string $videoSlug = ''
    ): string {
        $count = (int) ($pagination['total'] ?? self::countAll($comments));
        $heading = $count === 1 ? '1 comment' : $count . ' comments';

        $items = '';
        foreach ($comments as $comment) {
            $items .= self::one($comment, false, $reportAction, $csrfField, $viewerEmail);
        }

        if ($items === '') {
            $items = '<p class="muted">No comments yet.</p>';
        }

        $noticeHtml = $notice === ''
            ? ''
            : '<p class="comment-notice">' . e($notice) . '</p>';

        $css = self::css();
        $pager = self::pager($pagination, $videoSlug);

        return <<<HTML
        <section class="comments" aria-labelledby="comments-heading" id="comments">
          <style>{$css}</style>
          <h2 class="section-title" id="comments-heading">{$heading}</h2>
          {$noticeHtml}
          {$formHtml}
          <div class="comment-list">{$items}</div>
          {$pager}
        </section>
        HTML;
    }

    /** @param array<string, mixed> $comment */
    private static function one(
        array $comment,
        bool $isReply,
        string $reportAction = '',
        string $csrfField = '',
        string $viewerEmail = ''
    ): string {
        $id = (int) $comment['id'];

        // A removed comment survives only as a tombstone, and only when it has
        // replies. Its text is never rendered — a moderator took it down, and
        // "removed but you can still read it" would defeat the moderation.
        if (!empty($comment['removed'])) {
            $body = '<p class="comment-removed">This comment was removed.</p>';
            $author = 'Removed';
        } else {
            $body = '<p>' . nl2br(e((string) $comment['body'])) . '</p>';
            $author = (string) $comment['author'];
        }

        $replies = '';
        foreach ((array) ($comment['replies'] ?? []) as $reply) {
            $replies .= self::one($reply, true, $reportAction, $csrfField, $viewerEmail);
        }

        $replyBlock = $replies === '' ? '' : '<div class="comment-replies">' . $replies . '</div>';

        $actions = '';

        if (empty($comment['removed'])) {
            // Replies get no reply button: the thread is one level deep, and an
            // affordance that quietly reparents the answer would be a lie.
            if (!$isReply) {
                $actions .= sprintf(
                    '<button type="button" class="comment-reply-btn" data-reply-to="%d">Reply</button>',
                    $id
                );
            }

            // Reporting is offered only to someone who could also post — the
            // same bar, because an anonymous report button is a button anybody
            // can hold down, and the count is what a moderator sorts by.
            if ($reportAction !== '') {
                $actions .= sprintf(
                    '<form method="post" action="%s" class="comment-report">%s
                       <input type="hidden" name="comment_id" value="%d">
                       <button type="submit" class="comment-reply-btn">Report</button>
                     </form>',
                    e($reportAction),
                    $csrfField,
                    $id
                );
            }
        }

        /*
         * The author's own controls.
         *
         * The decision is made HERE only about what to show. Both routes check
         * again for themselves, because a button that is merely absent is not
         * a permission — anybody can post the form without ever seeing it.
         */
        if (empty($comment['removed']) && $viewerEmail !== '' && isset($comment['authorEmail'])) {
            $mine = strcasecmp((string) $comment['authorEmail'], $viewerEmail) === 0;

            $canEdit = $mine && CommentPolicy::canEdit(
                (string) $comment['authorEmail'],
                $viewerEmail,
                (string) $comment['status'],
                (string) $comment['createdAt']
            );

            if ($mine && CommentPolicy::canDelete(
                (string) $comment['authorEmail'],
                $viewerEmail,
                (string) $comment['status']
            )) {
                $actions .= sprintf(
                    '<form method="post" action="/comments/delete" class="comment-report">%s
                       <input type="hidden" name="comment_id" value="%d">
                       <button type="submit" class="comment-reply-btn"
                               onclick="return confirm(\'Delete your comment?\')">Delete</button>
                     </form>',
                    $csrfField,
                    $id
                );
            }
        }

        $when = self::when((string) $comment['createdAt']);

        /*
         * Said out loud when the words have changed. A comment rewritten under
         * three replies is a different thing from the one they answered, and a
         * reader has no other way to tell.
         */
        $edited = empty($comment['edited']) ? '' : ' <span class="muted">(edited)</span>';

        /*
         * The edit box, inside a <details>.
         *
         * No JavaScript at all. A button plus a hidden form needs a script to
         * connect them, and when the script does not run the button silently
         * does nothing — which is worse than no button. <details> is the same
         * interaction, built in, and it works in a browser with scripting
         * switched off and in a reader that never had any.
         */
        $editForm = '';
        if (!empty($canEdit)) {
            $editForm = sprintf(
                '<details class="comment-edit">
                   <summary>Edit</summary>
                   <form method="post" action="/comments/edit">%s
                     <input type="hidden" name="comment_id" value="%d">
                     <label class="visually-hidden" for="comment-edit-body-%d">Edit your comment</label>
                     <textarea id="comment-edit-body-%d" name="body" rows="4">%s</textarea>
                     <button type="submit" class="btn tiny">Save</button>
                   </form>
                 </details>',
                $csrfField,
                $id,
                $id,
                $id,
                e((string) $comment['body'])
            );
        }

        return <<<HTML
        <article class="comment" id="comment-{$id}">
          <p class="comment-meta"><strong>{$author}</strong> <span class="muted">{$when}</span>{$edited}</p>
          {$body}
          {$editForm}
          <p class="comment-actions">{$actions}</p>
          {$replyBlock}
        </article>
        HTML;
    }

    /**
     * Page links, when there is more than one page.
     *
     * Plain links carrying `?comments=N#comments`, so the browser's back button
     * works and a page of a thread can be linked to. A JavaScript pager would
     * be smoother and would make page two unreachable to anybody the script
     * failed for — including a search engine, which is the only way the older
     * half of a long thread ever gets found.
     *
     * @param array{page?: int, pages?: int, total?: int} $pagination
     */
    private static function pager(array $pagination, string $videoSlug): string
    {
        $page = (int) ($pagination['page'] ?? 1);
        $pages = (int) ($pagination['pages'] ?? 1);

        if ($pages <= 1) {
            return '';
        }

        $base = $videoSlug === '' ? '' : '/watch/' . rawurlencode($videoSlug);

        $link = static fn (int $to, string $label): string => sprintf(
            '<a class="comment-page" href="%s?comments=%d#comments">%s</a>',
            e($base),
            $to,
            e($label)
        );

        $out = '';

        if ($page > 1) {
            $out .= $link($page - 1, 'Newer');
        }

        $out .= sprintf('<span class="muted small">Page %d of %d</span>', $page, $pages);

        if ($page < $pages) {
            $out .= $link($page + 1, 'Older');
        }

        return '<nav class="comment-pager" aria-label="Comment pages">' . $out . '</nav>';
    }

    /**
     * The posting form, or an explanation of why there isn't one.
     */
    public static function form(string $action, string $csrfField, bool $canPost, bool $signedIn): string
    {
        if (!$canPost) {
            return $signedIn
                ? '<p class="muted">Your account is not approved to comment yet.</p>'
                : '<p class="muted"><a href="/auth/login">Sign in</a> to join the conversation.</p>';
        }

        return <<<HTML
        <form method="post" action="{$action}" class="comment-form">
          {$csrfField}
          <input type="hidden" name="parent_id" value="" id="comment-parent">
          <p class="comment-replying" hidden>
            Replying to a comment.
            <button type="button" id="comment-cancel-reply">Cancel</button>
          </p>
          <label for="comment-body" class="sr-only">Your comment</label>
          <textarea id="comment-body" name="body" rows="3"
                    maxlength="3000" placeholder="Add a comment…" required></textarea>
          <button class="btn" type="submit">Post</button>
        </form>
        <script src="/plugin-asset/comments/comments.js" defer></script>
        HTML;
    }

    /** @param list<array<string, mixed>> $comments */
    private static function countAll(array $comments): int
    {
        $total = 0;
        foreach ($comments as $comment) {
            $total++;
            $total += count((array) ($comment['replies'] ?? []));
        }

        return $total;
    }

    /**
     * A date, formatted in the site's timezone.
     *
     * Deliberately absolute rather than "3 hours ago": a relative time is
     * wrong the moment a page is cached or a screenshot is taken, and on a
     * sermon archive people genuinely want to know when something was said.
     */
    private static function when(string $timestamp): string
    {
        try {
            return (new \DateTimeImmutable($timestamp))->format('j M Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function css(): string
    {
        return <<<'CSS'
        .comments { margin-top: 3rem; max-width: 48rem; }
        .comment-form { margin-bottom: 2rem; }
        .comment-form textarea { width: 100%; padding: .625rem .75rem; border-radius: 10px;
            border: 1px solid rgb(148 163 184 / .28); background: rgb(15 23 42 / .5);
            color: inherit; font: inherit; resize: vertical; }
        .comment-form .btn { margin-top: .625rem; }
        .comment-replying { font-size: .875rem; margin: .5rem 0 0; }
        .comment { padding: 1rem 0; border-bottom: 1px solid rgb(148 163 184 / .14); }
        .comment-meta { margin: 0 0 .375rem; font-size: .875rem; }
        .comment p { margin: 0 0 .5rem; overflow-wrap: anywhere; }
        .comment-removed { font-style: italic; opacity: .6; }
        .comment-replies { margin: .75rem 0 0 1.5rem; padding-left: 1rem;
            border-left: 2px solid rgb(148 163 184 / .2); }
        .comment-actions { display: flex; gap: 1rem; margin: 0; }
        .comment-actions:empty { display: none; }
        .comment-report { display: inline; margin: 0; }
        .comment-reply-btn { background: none; border: 0; padding: 0; font: inherit;
            font-size: .8125rem; color: #38bdf8; cursor: pointer; }
        .comment-notice { padding: .625rem .875rem; border-radius: 8px; font-size: .9375rem;
            border: 1px solid rgb(56 189 248 / .4); background: rgb(56 189 248 / .08); }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
        CSS;
    }
}
