<?php

declare(strict_types=1);

namespace Portal\Sharing;

use Portal\Config;
use Portal\Mail\MailProvider;
use Portal\Mail\SendResult;
use Portal\Support\Str;
use Throwable;

/**
 * Emails about share links.
 *
 * Two rules.
 *
 * A FAILED SEND MUST NEVER LOSE THE LINK. The share exists whether or not the
 * message got out. The provider's own error is stored verbatim on the record,
 * the link stays copyable in the admin, and Resend is one click away. Treating
 * a send failure as a share failure would mean a misconfigured SMTP password
 * silently discarding work an admin had already done.
 *
 * EVERY URL COMES FROM CONFIG. Never from the request. `Host` is attacker-
 * controlled on most shared hosts, and an emailed link built from it is a
 * phishing vector wearing the site's own domain. This was a real, fixed bug in
 * one of the predecessor apps.
 */
final class ShareMailer
{
    public function __construct(
        private readonly Config $config,
        private readonly MailProvider $mail,
        private readonly ShareRepository $shares,
        private readonly BundleRepository $bundles,
    ) {
    }

    public function isConfigured(): bool
    {
        try {
            return $this->mail->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    private function siteName(): string
    {
        return $this->config->setting('site_name', 'Video Portal') ?? 'Video Portal';
    }

    // ------------------------------------------------------------- one link

    /**
     * Tell someone about a single share.
     *
     * Records the outcome on the share either way, so the admin table can show
     * "emailed" or the exact reason it did not go.
     */
    public function sendShare(Share $share): SendResult
    {
        if (!$this->isConfigured()) {
            $result = SendResult::failure('No email service is configured.');
            $this->shares->markEmailed($share->id, $result->error);
            return $result;
        }

        $site = $this->siteName();
        $url = $this->config->url($share->url());

        $subject = sprintf('%s — a video has been shared with you: %s', $site, $share->videoTitle);

        $result = $this->send(
            $share->recipientEmail,
            $subject,
            $this->shareHtml($share, $url, $site),
            $this->shareText($share, $url, $site)
        );

        $this->shares->markEmailed($share->id, $result->sent ? null : $result->error);

        return $result;
    }

    /**
     * Tell someone about everything they have.
     *
     * Once a recipient has a bundle, one consolidated message replaces the
     * per-video ones. Someone receiving eight separate emails because eight
     * videos were shared at once would reasonably conclude the site was broken.
     */
    public function sendBundle(Bundle $bundle): SendResult
    {
        if (!$this->isConfigured()) {
            return SendResult::failure('No email service is configured.');
        }

        $items = $this->bundles->liveItems($bundle->id);

        if ($items === []) {
            return SendResult::failure('There is nothing live in that bundle to tell them about.');
        }

        $site = $this->siteName();
        $url = $this->config->url($bundle->url());

        $subject = count($items) === 1
            ? sprintf('%s — a video has been shared with you: %s', $site, $items[0]->videoTitle)
            : sprintf('%s — %d videos have been shared with you', $site, count($items));

        $result = $this->send(
            $bundle->recipientEmail,
            $subject,
            $this->bundleHtml($bundle, $items, $url, $site),
            $this->bundleText($bundle, $items, $url, $site)
        );

        // Stamp every member, so the admin table shows them all as notified
        // rather than only the one that triggered the send.
        foreach ($items as $item) {
            $this->shares->markEmailed($item->id, $result->sent ? null : $result->error);
        }

        return $result;
    }

    /**
     * Notify a recipient the right way.
     *
     * One live share means a direct link. Two or more means the bundle — being
     * sent to an index page holding a single row is worse than being sent the
     * row itself.
     */
    public function notify(string $email): SendResult
    {
        $email = Str::normalizeEmail($email);
        $bundle = $this->bundles->forRecipient($email);

        if ($bundle !== null && count($this->bundles->liveItems($bundle->id)) > 1) {
            return $this->sendBundle($bundle);
        }

        $live = $this->shares->liveForRecipient($email);

        if ($live === []) {
            return SendResult::failure('That person has nothing live to be told about.');
        }

        return $this->sendShare($live[0]);
    }

    /**
     * The account-free gate's sign-in link.
     *
     * Deliberately says as little as possible. It is sent in response to an
     * unauthenticated request, so it must not confirm anything to someone who
     * merely guessed an address — including what was shared.
     */
    public function sendGateLink(string $email, string $url, string $title): SendResult
    {
        $site = $this->siteName();

        return $this->send(
            $email,
            sprintf('%s — your sign-in link', $site),
            $this->gateHtml($url, $title, $site),
            $this->gateText($url, $title, $site)
        );
    }

    // ---------------------------------------------------------------- sending

    private function send(string $to, string $subject, string $html, string $text): SendResult
    {
        try {
            /** @var string $html */
            $html = apply_filters('share_email_html', $html, $to, $subject);

            return $this->mail->send($to, $subject, $html, $text);
        } catch (Throwable $e) {
            // A provider that throws rather than returning a result must not
            // take the request down with it.
            return SendResult::failure($e->getMessage());
        }
    }

    // --------------------------------------------------------------- templates

    private function shareHtml(Share $share, string $url, string $site): string
    {
        $expiry = $this->formatExpiry($share);
        $access = $this->accessNote($share->accessMode, $share->recipientEmail);

        return $this->wrap($site, sprintf(
            '<p style="margin:0 0 8px;">A video has been shared with you.</p>
             <p style="margin:0 0 24px;font-size:18px;font-weight:600;">%s</p>
             %s
             <p style="%s">This link stops working %s.</p>
             <p style="%s">%s</p>
             %s',
            e($share->videoTitle),
            $this->button($url, 'Watch the video'),
            self::META,
            e($expiry),
            self::META,
            $access,
            $this->fallback($url)
        ));
    }

    private function shareText(Share $share, string $url, string $site): string
    {
        return implode("\n", [
            $site,
            '',
            'A video has been shared with you: ' . $share->videoTitle,
            '',
            $url,
            '',
            'This link stops working ' . $this->formatExpiry($share) . '.',
            strip_tags($this->accessNote($share->accessMode, $share->recipientEmail)),
            '',
            'If you were not expecting this, you can ignore it.',
        ]);
    }

    /** @param list<Share> $items */
    private function bundleHtml(Bundle $bundle, array $items, string $url, string $site): string
    {
        $rows = '';

        foreach ($items as $item) {
            $rows .= sprintf(
                '<tr><td style="padding:10px 0;border-bottom:1px solid #e2e8f0;">
                   <span style="font-weight:600;">%s</span><br>
                   <span style="%s">Available %s</span>
                 </td></tr>',
                e($item->videoTitle),
                self::META,
                e(Str::relativeTo($item->expiresAt))
            );
        }

        return $this->wrap($site, sprintf(
            '<p style="margin:0 0 20px;">%d videos have been shared with you.</p>
             %s
             <table style="width:100%%;border-collapse:collapse;margin:8px 0 20px;">%s</table>
             <p style="%s">%s</p>
             %s',
            count($items),
            $this->button($url, 'View your videos'),
            $rows,
            self::META,
            $this->accessNote($bundle->accessMode, $bundle->recipientEmail),
            $this->fallback($url)
        ));
    }

    /** @param list<Share> $items */
    private function bundleText(Bundle $bundle, array $items, string $url, string $site): string
    {
        $lines = [$site, '', sprintf('%d videos have been shared with you:', count($items)), ''];

        foreach ($items as $item) {
            $lines[] = '  - ' . $item->videoTitle . ' (available ' . Str::relativeTo($item->expiresAt) . ')';
        }

        $lines[] = '';
        $lines[] = $url;
        $lines[] = '';
        $lines[] = strip_tags($this->accessNote($bundle->accessMode, $bundle->recipientEmail));

        return implode("\n", $lines);
    }

    private function gateHtml(string $url, string $title, string $site): string
    {
        return $this->wrap($site, sprintf(
            '<p style="margin:0 0 20px;">Here is your sign-in link.</p>
             %s
             <p style="%s">It works once, and expires in an hour.</p>
             %s
             <p style="%s">If you did not ask for this, you can ignore it. Nothing has changed.</p>',
            $this->button($url, 'Open ' . e($title)),
            self::META,
            $this->fallback($url),
            self::META
        ));
    }

    private function gateText(string $url, string $title, string $site): string
    {
        return implode("\n", [
            $site,
            '',
            'Here is your sign-in link:',
            '',
            $url,
            '',
            'It works once, and expires in an hour.',
            'If you did not ask for this, you can ignore it. Nothing has changed.',
        ]);
    }

    // --------------------------------------------------------------- helpers

    /**
     * How the recipient will be asked to prove who they are.
     *
     * Worth saying plainly. In account mode a link opened while signed in as a
     * different address shows a refusal, and "sign in as the address this was
     * sent to" is the one instruction that resolves it.
     */
    private function accessNote(string $accessMode, string $email): string
    {
        if ($accessMode === Share::MODE_GATE) {
            return 'You will be asked to confirm your email address before it opens.';
        }

        return sprintf('This only works when you are signed in as <strong>%s</strong>.', e($email));
    }

    private function formatExpiry(Share $share): string
    {
        return Str::relativeTo($share->expiresAt)
            . ' (' . $share->expiresAt->format('j M Y, H:i') . ' UTC)';
    }

    /** Shared inline style for secondary text. */
    private const META = 'margin:0 0 8px;font-size:13px;color:#64748b;';

    private function button(string $url, string $label): string
    {
        // Inline, and a real anchor rather than anything clever: Outlook in
        // particular renders very little else reliably.
        return sprintf(
            '<p style="margin:20px 0;"><a href="%s" style="display:inline-block;padding:12px 24px;'
            . 'background:#0ea5e9;color:#ffffff;text-decoration:none;border-radius:8px;'
            . 'font-weight:600;font-size:15px;">%s</a></p>',
            e($url),
            $label
        );
    }

    /**
     * The copyable URL.
     *
     * Always present. Corporate mail gateways rewrite or strip link hrefs
     * often enough that a message offering only a button is a message some
     * recipients simply cannot act on.
     */
    private function fallback(string $url): string
    {
        return sprintf(
            '<p style="margin:20px 0 0;font-size:12px;color:#94a3b8;">'
            . 'If the button does not work, copy this address into your browser:<br>'
            . '<span style="word-break:break-all;color:#475569;">%s</span></p>',
            e($url)
        );
    }

    /**
     * The email shell.
     *
     * Inline styles and a table-free layout where possible: email clients strip
     * <style> blocks unpredictably, and anything relying on one is a gamble.
     */
    private function wrap(string $site, string $body): string
    {
        $name = e($site);

        return <<<HTML
        <!doctype html>
        <html>
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
        <body style="margin:0;padding:24px;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
          <div style="max-width:34rem;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
            <p style="margin:0 0 24px;font-size:14px;font-weight:600;color:#64748b;letter-spacing:.02em;">{$name}</p>
            <div style="font-size:15px;line-height:1.6;">{$body}</div>
          </div>
          <p style="max-width:34rem;margin:16px auto 0;font-size:12px;color:#94a3b8;text-align:center;">
            If you were not expecting this, you can safely ignore it.
          </p>
        </body>
        </html>
        HTML;
    }
}
