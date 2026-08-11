<?php

declare(strict_types=1);

namespace Portal\Auth;

use Portal\Config;
use Portal\Db;
use Portal\Mail\MailProvider;
use Throwable;

/**
 * Telling the administrators that somebody is waiting.
 *
 * The dashboard has counted pending accounts since Phase 1, and a count is
 * only seen by whoever happens to open the dashboard. On a site where
 * approvals are occasional — which is every site this targets — that is
 * indistinguishable from nobody ever asking.
 *
 * Everything here is best-effort and says so. The request is already stored
 * before this runs, so a mail provider that is missing, misconfigured, or
 * simply down costs a notification and never costs the request.
 */
final class AccessRequestMailer
{
    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
        private readonly MailProvider $mail,
    ) {
    }

    /**
     * @return bool whether at least one administrator was actually emailed
     */
    public function notify(User $requester, string $note): bool
    {
        if (!$this->mail->isConfigured()) {
            // Normal on a fresh install. Not an error, and not worth a log line
            // on every request — the row stays, and the users screen shows it.
            return false;
        }

        $recipients = $this->administrators();

        if ($recipients === []) {
            return false;
        }

        $siteName = (string) $this->config->setting('site_name', 'Video Portal');
        // BASE_URL, never the Host header. A link built from a request an
        // untrusted person made is a link an untrusted person chose.
        $link = $this->config->url('/admin/users');

        $subject = sprintf('%s: %s is asking for access', $siteName, $requester->email);
        $html = self::html($requester->displayName(), $requester->email, $note, $siteName, $link);
        $text = self::plain($requester->displayName(), $requester->email, $note, $siteName, $link);

        $sent = false;
        foreach ($recipients as $to) {
            try {
                $result = $this->mail->send($to, $subject, $html, $text);
                $sent = $sent || $result->sent;
            } catch (Throwable $e) {
                // One administrator with a dead address must not stop the rest
                // being told.
                error_log('Access request: could not notify ' . $to . '. ' . $e->getMessage());
            }
        }

        return $sent;
    }

    /**
     * Who to tell.
     *
     * Everybody holding the admin role, rather than everybody who could
     * approve. `manage_viewers` can be granted narrowly and to people who are
     * not expecting mail from the site; the admin role is the one whose holders
     * have signed up to run the place.
     *
     * @return list<string>
     */
    private function administrators(): array
    {
        $rows = $this->db->column(
            'SELECT u.email
               FROM {users} u
               JOIN {roles} r ON r.id = u.role_id
              WHERE r.slug = ? AND u.email <> \'\'
              ORDER BY u.id
              LIMIT 20',
            [Capability::ROLE_ADMIN]
        );

        return array_values(array_filter(array_map(
            static fn (mixed $email): string => trim((string) $email),
            $rows
        )));
    }

    /**
     * The message, as HTML.
     *
     * Public and static because it is a pure function of its arguments and
     * because it is the one part of this class worth testing directly: every
     * value in it except the site name was chosen by somebody the site has not
     * approved. A missed escape here is a stranger writing markup into an
     * administrator's inbox — and the send path cannot be exercised without a
     * live mail provider, so a test that went through notify() would prove
     * nothing about the words.
     */
    public static function html(
        string $name,
        string $email,
        string $note,
        string $siteName,
        string $link
    ): string {
        $name = e($name);
        $email = e($email);
        $site = e($siteName);
        $url = e($link);

        // Their words, escaped and clearly attributed. An administrator reading
        // this must never be in doubt about which part a stranger wrote.
        $said = $note === ''
            ? '<p style="color:#64748b">They did not leave a message.</p>'
            : '<blockquote style="margin:0;padding:.75rem 1rem;border-left:3px solid #cbd5e1;color:#334155">'
              . nl2br(e($note)) . '</blockquote>';

        return <<<HTML
        <div style="font:15px/1.6 system-ui,-apple-system,'Segoe UI',sans-serif;color:#0f172a">
          <p><strong>{$name}</strong> ({$email}) has asked for access to {$site}.</p>
          {$said}
          <p><a href="{$url}">Review it on the People screen</a>, where you can approve or ignore it.</p>
          <p style="color:#64748b;font-size:13px">You are getting this because you hold the
             administrator role on {$site}. Each person can ask once, so this will not repeat.</p>
        </div>
        HTML;
    }

    /**
     * The same message as plain text.
     *
     * Nothing is escaped here and nothing should be: escaping HTML into a
     * text/plain part is how a reader ends up with `&amp;` in the middle of a
     * sentence. The protection that matters for this part happened before the
     * note was stored, where control characters were stripped.
     */
    public static function plain(
        string $name,
        string $email,
        string $note,
        string $siteName,
        string $link
    ): string {
        return implode("\n\n", [
            sprintf('%s (%s) has asked for access to %s.', $name, $email, $siteName),
            $note === '' ? 'They did not leave a message.' : $note,
            'Review it here: ' . $link,
            'You are getting this because you hold the administrator role. Each person can ask once.',
        ]);
    }
}
