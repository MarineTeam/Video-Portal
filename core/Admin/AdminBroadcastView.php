<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Broadcast\BroadcastRepository;
use Portal\Broadcast\Consent;

/**
 * Writing and sending a broadcast.
 *
 * The screen's job is to make the counts honest and the order obvious: write
 * it, look at who it will actually reach and why the rest will not, send
 * yourself one, then send it.
 */
final class AdminBroadcastView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'broadcasts' => $this->overview($data),
            'broadcast'  => $this->one($data),
            default      => '<p>Unknown screen.</p>',
        };

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function overview(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['broadcasts'] ?? []) as $broadcast) {
            $tally = (array) $broadcast['tally'];

            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/broadcasts/%d"><strong>%s</strong></a>
                       <div class="muted small">%s</div></td>
                   <td>%s</td>
                   <td class="muted small">%s</td>
                 </tr>',
                (int) $broadcast['id'],
                e((string) $broadcast['subject']),
                e((string) $broadcast['created_at']),
                $this->stateLabel((string) $broadcast['state']),
                sprintf(
                    '%d sent · %d failed · %d to go',
                    (int) ($tally['sent'] ?? 0),
                    (int) ($tally['failed'] ?? 0),
                    (int) ($tally['pending'] ?? 0)
                )
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">Nothing sent yet.</td></tr>';
        }

        return <<<HTML
        <h1>Broadcasts</h1>

        <p class="muted">Email, text and push. The three are governed separately, on purpose —
           email goes unless somebody turned it off, a text needs an explicit opt-in and a number
           this can read, and push needs a device. A text costs money and arrives on a lock screen,
           and in most places sending one without consent is against the law.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Message</th><th></th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>
            <h2>Write one</h2>
            <form method="post" action="/admin/broadcasts">
              <input type="hidden" name="_token" value="{$token}">
              <label>Subject <input type="text" name="subject" required></label>
              <button class="btn" name="action" value="create">Start</button>
            </form>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function one(array $data): string
    {
        $token = e((string) $data['token']);
        $broadcast = (array) $data['broadcast'];
        $id = (int) $broadcast['id'];
        $isDraft = (string) $broadcast['state'] === BroadcastRepository::DRAFT;

        return <<<HTML
        <p class="muted small"><a href="/admin/broadcasts">&larr; Broadcasts</a></p>
        <h1>{$this->text((string) $broadcast['subject'])}</h1>
        <p>{$this->stateLabel((string) $broadcast['state'])}</p>

        <div class="cols">
          <div>
            {$this->composer($data, $token, $id, $isDraft)}
          </div>
          <div>
            {$this->reach($data, $token, $id, $isDraft)}
            {$this->progress($data, $token, $id, $isDraft)}
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function composer(array $data, string $token, int $id, bool $isDraft): string
    {
        $broadcast = (array) $data['broadcast'];

        if (!$isDraft) {
            return sprintf(
                '<h2>The message</h2><p><strong>%s</strong></p><p>%s</p>
                 <p class="muted small">This one has started going out, so it cannot be changed —
                    half the list would have the old words and half the new, which is worse than
                    either.</p>',
                e((string) $broadcast['subject']),
                nl2br(e((string) $broadcast['body']))
            );
        }

        $audience = '';
        foreach ([
            BroadcastRepository::EVERYONE => 'Everybody with an account',
            BroadcastRepository::GROUP    => 'A permission group',
            BroadcastRepository::EVENT    => 'People signed up to an event',
        ] as $value => $label) {
            $audience .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                (string) $broadcast['audience_type'] === $value ? ' selected' : '',
                e($label)
            );
        }

        $targets = '<option value="0">— none —</option>';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $targets .= sprintf(
                '<option value="%d"%s>Group: %s</option>',
                (int) $group['id'],
                (int) ($broadcast['audience_id'] ?? 0) === (int) $group['id'] ? ' selected' : '',
                e((string) $group['name'])
            );
        }
        foreach ((array) ($data['events'] ?? []) as $event) {
            $targets .= sprintf(
                '<option value="%d"%s>Event: %s</option>',
                (int) $event['id'],
                (int) ($broadcast['audience_id'] ?? 0) === (int) $event['id'] ? ' selected' : '',
                e((string) $event['title'])
            );
        }

        return <<<HTML
        <h2>The message</h2>
        <form method="post" action="/admin/broadcasts">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$id}">
          <input type="hidden" name="_whole_form" value="1">

          <label>Subject <input type="text" name="subject" value="{$this->text((string) $broadcast['subject'])}"></label>
          <label>Message <textarea name="body" rows="10">{$this->text((string) $broadcast['body'])}</textarea></label>
          <p class="muted small">Texts and push notifications get the first 300 characters, because
             a gateway charges per 160 and splits a long one into several without asking.</p>

          <label>Who it goes to <select name="audience_type">{$audience}</select></label>
          <label>Which one <select name="audience_id">{$targets}</select></label>
          <p class="muted small">An event's sign-ups include the people with no account, which is
             usually who you most want to write to afterwards.</p>

          <label class="check">
            <input type="checkbox" name="by_email" value="1" {$this->checked((bool) $broadcast['by_email'])}>
            Email
          </label>
          <label class="check">
            <input type="checkbox" name="by_sms" value="1" {$this->checked((bool) $broadcast['by_sms'])}>
            Text {$this->readiness((bool) ($data['smsReady'] ?? false), 'no text provider is set up')}
          </label>
          <label class="check">
            <input type="checkbox" name="by_push" value="1" {$this->checked((bool) $broadcast['by_push'])}>
            Push {$this->readiness((bool) ($data['pushReady'] ?? false), 'the push plugin is not active')}
          </label>

          <button class="btn" name="action" value="save">Save</button>
          <button class="btn secondary" name="action" value="test">Send it to me first</button>
        </form>
        HTML;
    }

    /**
     * HONEST COUNTS, with the reasons named.
     *
     * "300 recipients" is a number that gets believed and then quietly is not
     * true. The three refusals are listed separately because they need
     * different answers: an opt-out is somebody's decision, a missing opt-in is
     * worth asking for, and an unreadable number is a typo somebody can fix.
     *
     * @param array<string, mixed> $data
     */
    private function reach(array $data, string $token, int $id, bool $isDraft): string
    {
        $preview = (array) ($data['preview'] ?? []);

        if ($preview === []) {
            return '<h2>Who it reaches</h2><p class="muted">Pick at least one way to send it.</p>';
        }

        $blocks = '';
        $total = 0;

        foreach ($preview as $channel => $numbers) {
            $total += (int) $numbers['reach'];

            $why = '';
            foreach ((array) $numbers['skipped'] as $reason => $count) {
                $why .= sprintf('<li>%d %s</li>', (int) $count, e((string) $reason));
            }

            $blocks .= sprintf(
                '<div class="notice"><strong>%s: %d</strong>%s</div>',
                e($this->channelLabel((string) $channel)),
                (int) $numbers['reach'],
                $why === '' ? '' : '<ul class="muted small">' . $why . '</ul>'
            );
        }

        $send = $isDraft
            ? sprintf(
                '<form method="post" action="/admin/broadcasts">
                   <input type="hidden" name="_token" value="%s">
                   <input type="hidden" name="id" value="%d">
                   <button class="btn" name="action" value="send"
                           onclick="return confirm(\'Send this now? It cannot be taken back.\')">
                     Send it
                   </button>
                 </form>
                 <p class="muted small">Nothing is sent until you press this, and everybody it
                    will go to is written down first — so a send that is interrupted carries on
                    rather than starting again.</p>',
                $token,
                $id
            )
            : '';

        return <<<HTML
        <h2>Who it reaches</h2>
        {$blocks}
        <p class="muted small">{$total} message(s) in total. Counted now, not when you started
           writing — people join and opt out in between.</p>
        {$send}
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function progress(array $data, string $token, int $id, bool $isDraft): string
    {
        if ($isDraft) {
            return '';
        }

        $tally = (array) ($data['tally'] ?? []);

        $failures = '';
        foreach ((array) ($data['recipients'] ?? []) as $recipient) {
            if ((string) $recipient['state'] !== BroadcastRepository::FAILED) {
                continue;
            }

            $failures .= sprintf(
                '<tr><td>%s</td><td class="muted small">%s</td></tr>',
                e((string) $recipient['address']),
                e((string) ($recipient['error'] ?? ''))
            );
        }

        $failed = $failures === ''
            ? ''
            : '<h3>Did not go</h3><p class="muted small">One bad address does not stop the rest —'
                . ' these are the ones that failed, in the provider\'s own words.</p>'
                . '<table><tbody>' . $failures . '</tbody></table>';

        $stop = (string) ((array) $data['broadcast'])['state'] === BroadcastRepository::SENDING
            ? sprintf(
                '<form method="post" action="/admin/broadcasts">
                   <input type="hidden" name="_token" value="%s">
                   <input type="hidden" name="id" value="%d">
                   <button class="btn tiny secondary" name="action" value="stop">Stop the rest</button>
                 </form>',
                $token,
                $id
            )
            : '';

        return sprintf(
            '<h2>How it is going</h2>
             <p>%d sent · %d failed · %d to go</p>
             <p class="muted small">The rest go out a few at a time, bounded by a count and a
                clock — a send of four hundred cannot happen in one request on this kind of
                hosting.</p>
             %s%s',
            (int) ($tally['sent'] ?? 0),
            (int) ($tally['failed'] ?? 0),
            (int) ($tally['pending'] ?? 0),
            $stop,
            $failed
        );
    }

    private function readiness(bool $ready, string $why): string
    {
        return $ready ? '' : '<span class="muted small">(' . e($why) . ')</span>';
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            Consent::EMAIL => 'By email',
            Consent::SMS   => 'By text',
            Consent::PUSH  => 'By push',
            default        => $channel,
        };
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            BroadcastRepository::DRAFT   => '<span class="pill">draft</span>',
            BroadcastRepository::SENDING => '<span class="pill warn">going out</span>',
            BroadcastRepository::SENT    => '<span class="pill">sent</span>',
            BroadcastRepository::STOPPED => '<span class="pill warn">stopped</span>',
            default                      => e($state),
        };
    }

    private function checked(bool $on): string
    {
        return $on ? 'checked' : '';
    }

    private function text(string $value): string
    {
        return e($value);
    }
}
