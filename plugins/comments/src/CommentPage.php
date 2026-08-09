<?php

declare(strict_types=1);

namespace Portal\Plugins\Comments;

use Portal\Auth\Capability;
use Portal\Db;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginContext;
use Portal\Plugins\PluginPage;
use Portal\Support\Audit;

/**
 * The moderation queue.
 *
 * Ordered by report count first and age second, so the thing several people
 * objected to is what a moderator sees when they only have five minutes.
 */
final class CommentPage extends PluginPage
{
    public function __construct(private readonly PluginContext $plugin)
    {
        parent::__construct();
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $this->require(Capability::MODERATE_COMMENTS);

        if ($request->method === 'POST') {
            return $this->act($request);
        }

        $status = (string) ($request->query('status') ?? CommentPolicy::STATUS_PENDING);
        if (!in_array($status, [
            CommentPolicy::STATUS_PENDING,
            CommentPolicy::STATUS_APPROVED,
            CommentPolicy::STATUS_SPAM,
            CommentPolicy::STATUS_REMOVED,
        ], true)) {
            $status = CommentPolicy::STATUS_PENDING;
        }

        return $this->page('Comments', $this->body($status), 'comments');
    }

    private function act(Request $request): Response
    {
        $this->verifyCsrf($request);

        $repository = new CommentRepository($this->db());
        $action = (string) ($request->input('action') ?? '');
        $id = (int) ($request->input('id') ?? 0);

        // Read before acting: after a delete there is nothing left to name in
        // the audit log or the confirmation.
        $comment = $this->db()->first('SELECT * FROM {comments} WHERE id = ?', [$id]);

        if ($comment === null && $action !== 'settings') {
            return $this->back($request, 'That comment is already gone.', 'error');
        }

        switch ($action) {
            case 'settings':
                $mode = (string) ($request->input('moderation') ?? CommentPolicy::MODERATE_NEWCOMERS);
                if (!in_array($mode, [
                    CommentPolicy::MODERATE_NEWCOMERS,
                    CommentPolicy::MODERATE_ALL,
                    CommentPolicy::MODERATE_NONE,
                ], true)) {
                    $mode = CommentPolicy::MODERATE_NEWCOMERS;
                }

                $this->plugin->setSetting('moderation', $mode);
                Audit::log($this->db(), $this->user()?->email, 'comments.settings', null, null, $mode);

                return $this->back($request, 'Settings saved.');

            case 'approve-author':
                $email = (string) $comment['author_email'];
                $count = $repository->approveAuthor($email);
                Audit::log($this->db(), $this->user()?->email, 'comments.approve_author', null, $email);

                return $this->back($request, $count === 1
                    ? 'Approved, and this is their only comment waiting.'
                    : sprintf('Approved all %d comments from %s.', $count, $email));

            case 'delete':
                $repository->delete($id);
                Audit::log($this->db(), $this->user()?->email, 'comments.delete', 'comment', (string) $id);

                return $this->back($request, 'Deleted for good.');

            default:
                if (!in_array($action, CommentPolicy::moderatorStatuses(), true)) {
                    return $this->back($request, 'Unknown action.', 'error');
                }

                $repository->setStatus($id, $action);
                Audit::log($this->db(), $this->user()?->email, 'comments.' . $action, 'comment', (string) $id);

                return $this->back($request, 'Comment marked as ' . $action . '.');
        }
    }

    private function body(string $status): string
    {
        $repository = new CommentRepository($this->db());
        $counts = $repository->counts();
        $token = $this->csrfField();

        $tabs = '';
        foreach ([
            CommentPolicy::STATUS_PENDING  => 'Waiting',
            CommentPolicy::STATUS_APPROVED => 'Published',
            CommentPolicy::STATUS_SPAM     => 'Spam',
            CommentPolicy::STATUS_REMOVED  => 'Removed',
        ] as $key => $label) {
            $tabs .= sprintf(
                '<a class="pill%s" href="/admin/comments?status=%s">%s (%d)</a> ',
                $key === $status ? ' ok' : '',
                e($key),
                e($label),
                $counts[$key] ?? 0
            );
        }

        $rows = '';
        foreach ($repository->forModeration($status) as $row) {
            $rows .= $this->row($row, $token, $status);
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">Nothing here.</td></tr>';
        }

        $mode = (string) $this->plugin->setting('moderation', CommentPolicy::MODERATE_NEWCOMERS);

        $options = '';
        foreach ([
            CommentPolicy::MODERATE_NEWCOMERS => 'Hold the first comment from anybody new',
            CommentPolicy::MODERATE_ALL       => 'Hold every comment for review',
            CommentPolicy::MODERATE_NONE      => 'Publish immediately',
        ] as $value => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                $value === $mode ? ' selected' : '',
                e($label)
            );
        }

        return <<<HTML
        <h1>Comments</h1>
        <p class="toolbar">{$tabs}</p>

        <table>
          <thead><tr><th>Comment</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>

        <form method="post">
          {$token}
          <fieldset>
            <legend>When to publish</legend>
            <label>New comments <select name="moderation">{$options}</select></label>
            <p class="muted small">Holding the first comment from anybody new is the setting that makes
               this survivable without a full-time moderator: it stops a spam run reaching the site while
               letting people you already know through. Holding everything produces a queue nobody
               empties, which looks to a visitor exactly like comments being broken.</p>
            <p class="muted small">Comments that look like advertising are held whatever this says.
               Turning moderation off means trusting your audience, not publishing link farms unread.</p>
            <button class="btn" name="action" value="settings">Save</button>
          </fieldset>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $row */
    private function row(array $row, string $token, string $status): string
    {
        $id = (int) $row['id'];

        $reports = (int) $row['report_count'] > 0
            ? sprintf('<span class="pill bad">%d report(s)</span>', (int) $row['report_count'])
            : '';

        // Escaped and truncated. A moderation screen renders text written by
        // the exact people it exists to police, so it is the last place to get
        // clever about display.
        $body = e(mb_substr((string) $row['body'], 0, 600));
        if (mb_strlen((string) $row['body']) > 600) {
            $body .= '…';
        }

        $buttons = '';
        foreach (CommentPolicy::moderatorStatuses() as $target) {
            if ($target === $status) {
                continue; // Already there.
            }
            $buttons .= sprintf(
                '<button name="action" value="%s" class="btn tiny%s">%s</button> ',
                e($target),
                $target === CommentPolicy::STATUS_APPROVED ? '' : ' secondary',
                e(ucfirst($target))
            );
        }

        $approveAuthor = $status === CommentPolicy::STATUS_PENDING
            ? '<button name="action" value="approve-author" class="btn tiny secondary">Approve everything from them</button> '
            : '';

        return sprintf(
            '<tr>
               <td>
                 <p class="muted small"><strong>%s</strong> &lt;%s&gt; on <a href="/watch/%s">%s</a> %s</p>
                 <p>%s</p>
               </td>
               <td class="right">
                 <form method="post" class="inline">
                   %s
                   <input type="hidden" name="id" value="%d">
                   %s%s
                   <button name="action" value="delete" class="btn tiny danger"
                           onclick="return confirm(\'Delete this comment and any replies for good?\')">Delete</button>
                 </form>
               </td>
             </tr>',
            e((string) $row['author_name']),
            e((string) $row['author_email']),
            e((string) $row['video_slug']),
            e((string) $row['video_title']),
            $reports,
            nl2br($body),
            $token,
            $id,
            $approveAuthor,
            $buttons
        );
    }
}
