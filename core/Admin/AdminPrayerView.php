<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Prayer\PrayerRepository;
use Portal\Prayer\PrayerRequest;

/**
 * The prayer queue.
 *
 * Everything rendered here is a PrayerRequest, which carries no user id and no
 * raw name — so this screen cannot show who asked even by mistake. See
 * Portal\Prayer\PrayerName.
 */
final class AdminPrayerView
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $token = e((string) $data['token']);

        $body = <<<HTML
        <h1>Prayer</h1>

        <p class="muted">Nothing goes on the wall until somebody here has read it, and there is no
           setting to change that — an unmoderated prayer wall on a church website is a liability
           with a "post" button on it.</p>

        <div class="notice">
          <strong>Anonymous means anonymous, including here.</strong>
          <p class="muted small">A request marked anonymous shows as "Anonymous" on this screen too,
             and the name was never stored. So an anonymous request cannot be followed up and its
             author cannot be blocked. That is the trade, made deliberately: anonymity that quietly
             stops at the people who run the church is a promise the site would be breaking.</p>
        </div>

        {$this->queue($data, $token)}
        {$this->wall($data, $token)}
        {$this->removed($data, $token)}
        HTML;

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function queue(array $data, string $token): string
    {
        $waiting = (array) ($data['waiting'] ?? []);

        if ($waiting === []) {
            return '<h2>Waiting to be read</h2><p class="muted">Nothing waiting.</p>';
        }

        $rows = '';
        foreach ($waiting as $request) {
            $rows .= $this->row($request, $token, true);
        }

        return sprintf(
            '<h2>Waiting to be read <span class="pill warn">%d</span></h2>
             <table><tbody>%s</tbody></table>',
            count($waiting),
            $rows
        );
    }

    /** @param array<string, mixed> $data */
    private function wall(array $data, string $token): string
    {
        $rows = '';
        foreach ((array) ($data['wall'] ?? []) as $request) {
            $rows .= $this->row($request, $token, false);
        }

        if ($rows === '') {
            $rows = '<tr><td class="muted">Nothing on the wall yet.</td></tr>';
        }

        return '<h2>On the wall</h2><table><tbody>' . $rows . '</tbody></table>';
    }

    /** @param array<string, mixed> $data */
    private function removed(array $data, string $token): string
    {
        $removed = (array) ($data['removed'] ?? []);

        if ($removed === []) {
            return '';
        }

        $rows = '';
        foreach ($removed as $request) {
            $rows .= sprintf(
                '<tr><td class="muted">%s<div class="muted small">%s</div></td>
                     <td class="right">%s</td></tr>',
                e($this->shorten($request->body)),
                e($request->createdAt),
                $this->button($token, $request->id, 'restore', 'Put it back in the queue')
            );
        }

        return '<h2>Taken down</h2>'
            . '<p class="muted small">Kept rather than deleted, so you can see what was removed — '
            . 'and so a second moderator does not find it waiting again with no sign it was dealt '
            . 'with.</p>'
            . '<table><tbody>' . $rows . '</tbody></table>';
    }

    private function row(PrayerRequest $request, string $token, bool $waiting): string
    {
        $answered = $request->isAnswered()
            ? sprintf(
                '<div class="notice ok small"><strong>Answered.</strong> %s</div>',
                e((string) $request->answerNote)
            )
            : '';

        return sprintf(
            '<tr>
               <td>
                 <strong>%s</strong>%s
                 <div class="muted small">%s · %s%s</div>
                 <p>%s</p>
                 %s
               </td>
               <td class="right">%s</td>
             </tr>',
            e($request->name),
            $request->isAnonymous ? ' <span class="pill">anonymous</span>' : '',
            e($request->createdAt),
            e($this->audience($request->visibility)),
            $request->prayedCount > 0
                ? sprintf(' · %d prayed', $request->prayedCount)
                : '',
            nl2br(e($request->body)),
            $answered,
            $waiting ? $this->queueButtons($token, $request) : $this->wallButtons($token, $request)
        );
    }

    private function queueButtons(string $token, PrayerRequest $request): string
    {
        return $this->button($token, $request->id, 'approve', 'Put it on the wall')
            . $this->button($token, $request->id, 'remove', 'No')
            . $this->visibilityForm($token, $request);
    }

    private function wallButtons(string $token, PrayerRequest $request): string
    {
        $answered = $request->isAnswered()
            ? $this->button($token, $request->id, 'unanswered', 'Not answered after all')
            : sprintf(
                '<form method="post" action="/admin/prayer" class="inline">
                   <input type="hidden" name="_token" value="%s">
                   <input type="hidden" name="id" value="%d">
                   <input type="text" name="note" placeholder="What happened">
                   <button name="action" value="answered" class="btn tiny">Answered</button>
                 </form>',
                $token,
                $request->id
            );

        return $answered
            . $this->button($token, $request->id, 'remove', 'Take it down')
            . $this->visibilityForm($token, $request);
    }

    private function visibilityForm(string $token, PrayerRequest $request): string
    {
        $options = '';

        foreach ([
            PrayerRepository::EVERYONE => 'Anybody',
            PrayerRepository::MEMBERS  => 'Members',
            PrayerRepository::LEADERS  => 'Leaders only',
        ] as $value => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                $request->visibility === $value ? ' selected' : '',
                e($label)
            );
        }

        return sprintf(
            '<form method="post" action="/admin/prayer" class="inline">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="id" value="%d">
               <select name="visibility">%s</select>
               <button name="action" value="visibility" class="btn tiny secondary">Set</button>
             </form>',
            $token,
            $request->id,
            $options
        );
    }

    private function button(string $token, int $id, string $action, string $label): string
    {
        return sprintf(
            '<form method="post" action="/admin/prayer" class="inline">
               <input type="hidden" name="_token" value="%s">
               <input type="hidden" name="id" value="%d">
               <button name="action" value="%s" class="btn tiny secondary">%s</button>
             </form>',
            $token,
            $id,
            e($action),
            e($label)
        );
    }

    private function audience(string $visibility): string
    {
        return match ($visibility) {
            PrayerRepository::EVERYONE => 'anybody can read it',
            PrayerRepository::LEADERS  => 'leaders only',
            default                    => 'members',
        };
    }

    private function shorten(string $body): string
    {
        return mb_strlen($body) > 160 ? mb_substr($body, 0, 160) . '…' : $body;
    }
}
