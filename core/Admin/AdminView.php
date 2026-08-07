<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Auth\Guard;
use Portal\Content\Category;
use Portal\Content\Series;
use Portal\Content\Speaker;
use Portal\Content\ThumbnailPolicy;
use Portal\Content\Video;
use Portal\Providers\SettingField;
use Portal\Support\Str;

/**
 * The admin interface.
 *
 * Deliberately not themed. The admin area has to work when the active theme is
 * broken — switching a bad theme back requires reaching the screen that just
 * stopped rendering. Plugins extend it through registered pages rather than by
 * overriding these templates.
 */
final class AdminView
{
    /** @param array<string, mixed> $data */
    public function render(string $screen, array $data): string
    {
        $body = match ($screen) {
            'dashboard'  => $this->dashboard($data),
            'videos'        => $this->videos($data),
            'video-edit'    => $this->videoEdit($data),
            'trash'         => $this->trash($data),
            'categories'    => $this->categories($data),
            'category-edit' => $this->categoryEdit($data),
            'series'        => $this->series($data),
            'series-edit'   => $this->seriesEdit($data),
            'playlists'     => $this->playlists($data),
            'playlist-edit' => $this->playlistEdit($data),
            'home-rows'     => $this->homeRows($data),
            'analytics'     => $this->analytics($data),
            'announcements' => $this->announcements($data),
            'webhooks'      => $this->webhooks($data),
            'speakers'      => $this->speakers($data),
            'users'       => $this->users($data),
            'permissions' => $this->permissions($data),
            'plugins'    => $this->plugins($data),
            'themes'     => $this->themes($data),
            'providers'  => $this->providers($data),
            'settings'   => $this->settings($data),
            default      => '<p>Unknown screen.</p>',
        };

        return $this->layout($body, $data);
    }

    /**
     * The admin chrome around a page body.
     *
     * Public so the sharing screens can render inside it. Two shells would
     * drift, and the admin area would stop looking like one application.
     *
     * @param array<string, mixed> $data
     */
    public function shell(string $body, array $data): string
    {
        return $this->layout($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function layout(string $body, array $data): string
    {
        $siteName = e((string) ($data['siteName'] ?? 'Video Portal'));
        $screen = (string) ($data['screen'] ?? '');

        $nav = '';
        foreach ((array) ($data['nav'] ?? []) as $item) {
            $nav .= sprintf(
                '<a href="%s"%s>%s</a>',
                e((string) $item['path']),
                $item['key'] === $screen ? ' class="active"' : '',
                e((string) $item['label'])
            );
        }

        $flash = '';
        if (is_array($data['flash'] ?? null)) {
            $flash = sprintf(
                '<div class="flash %s">%s</div>',
                e((string) $data['flash']['type']),
                e((string) $data['flash']['message'])
            );
        }

        $css = $this->css();

        /*
         * The admin shell's one hook point.
         *
         * Everything a plugin could previously reach was on the public side —
         * this layout had no do_action at all, so a plugin with something to
         * say about an admin request had nowhere to say it. Buffered rather
         * than echoed because this method returns a string; a hook that printed
         * directly would land above the doctype.
         *
         * Deliberately at the end of the body and given the screen name, so
         * what hooks it can decide whether it applies here. Anything a plugin
         * renders here is inside the admin area, which is already governed by
         * its own capability check — but that check governs the PAGE, not what
         * a plugin chooses to print on it, so a plugin printing anything
         * sensitive still has to check for itself.
         */
        ob_start();
        do_action('admin_footer', $screen);
        $pluginFooter = (string) ob_get_clean();

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Admin — {$siteName}</title>
        <style>{$css}</style>
        </head>
        <body>
        <header class="bar">
          <a class="brand" href="/admin">{$siteName}</a>
          <nav>{$nav}</nav>
          <div class="spacer"></div>
          <a href="/" class="muted">View site</a>
          <a href="/auth/logout" class="muted">Sign out</a>
        </header>
        <main>
          {$flash}
          {$body}
        </main>
        {$pluginFooter}
        </body>
        </html>
        HTML;
    }

    // ------------------------------------------------------------ dashboard

    /** @param array<string, mixed> $data */
    private function dashboard(array $data): string
    {
        $stats = (array) ($data['stats'] ?? []);

        $tiles = '';
        foreach ([
            'videos'     => 'Videos',
            'published'  => 'Published',
            'processing' => 'Encoding',
            'categories' => 'Categories',
            'users'      => 'People',
            'pending'    => 'Awaiting approval',
        ] as $key => $label) {
            $value = (int) ($stats[$key] ?? 0);
            $highlight = ($key === 'pending' && $value > 0) ? ' class="tile warn"' : ' class="tile"';
            $tiles .= sprintf('<div%s><span class="n">%d</span><span class="l">%s</span></div>', $highlight, $value, e($label));
        }

        $providers = '';
        foreach ((array) ($data['providers'] ?? []) as $kind => $info) {
            $providers .= sprintf(
                '<li>%s: <strong>%s</strong></li>',
                e(ucfirst((string) $kind)),
                e((string) ($info['slug'] ?? 'not configured'))
            );
        }

        $activity = '';
        foreach ((array) ($data['activity'] ?? []) as $row) {
            $activity .= sprintf(
                '<tr><td class="muted">%s</td><td>%s</td><td>%s</td><td class="muted">%s</td></tr>',
                e((string) $row['created_at']),
                e((string) ($row['actor_email'] ?? 'system')),
                e((string) $row['action']),
                e((string) ($row['detail'] ?? ''))
            );
        }

        $activityBlock = $activity === '' ? '' : <<<HTML
        <h2>Recent activity</h2>
        <table>
          <thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead>
          <tbody>{$activity}</tbody>
        </table>
        HTML;

        return <<<HTML
        <h1>Dashboard</h1>
        <div class="tiles">{$tiles}</div>
        <h2>Services</h2>
        <ul class="plain">{$providers}</ul>
        {$activityBlock}
        HTML;
    }

    // --------------------------------------------------------------- videos

    /** @param array<string, mixed> $data */
    private function videos(array $data): string
    {
        $token = e((string) $data['token']);
        $search = e((string) ($data['search'] ?? ''));

        /** @var list<Video> $videos */
        $videos = $data['videos'] ?? [];

        $rows = '';
        foreach ($videos as $video) {
            $status = match ($video->status) {
                'ready'      => '<span class="pill ok">Ready</span>',
                'processing' => '<span class="pill warn">Encoding ' . $video->encodeProgress . '%</span>',
                default      => '<span class="pill bad">Failed</span>',
            };

            $published = $video->isPublished
                ? '<span class="pill ok">Published</span>'
                : '<span class="pill">Draft</span>';

            $toggle = $video->isPublished ? 'unpublish' : 'publish';
            $toggleLabel = $video->isPublished ? 'Unpublish' : 'Publish';

            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/videos/%d"><strong>%s</strong></a><br><span class="muted">%s</span></td>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="right">
                     <a class="btn tiny secondary" href="/admin/videos/%d">Edit</a>
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="%s" class="btn tiny">%s</button>
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'Move this video to trash?\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                $video->id,
                e($video->title),
                e(Str::duration($video->duration) ?: '—'),
                $status,
                $published,
                $video->id,
                $token,
                $video->id,
                $toggle,
                $toggleLabel
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">No videos yet. Upload one at your video provider, '
                . 'or wait for the next sync to pick them up.</td></tr>';
        }

        $total = (int) ($data['total'] ?? 0);
        $upload = $this->uploader($data);

        $trashed = (int) ($data['trashed'] ?? 0);
        $trashLink = $trashed === 0
            ? ''
            : sprintf(
                '<p class="muted small"><a href="/admin/videos/trash">Trash (%d)</a></p>',
                $trashed
            );

        return <<<HTML
        <h1>Videos <span class="muted">({$total})</span></h1>
        {$trashLink}
        {$upload}
        <form method="get" class="toolbar">
          <input type="search" name="q" value="{$search}" placeholder="Search titles and descriptions…">
          <button class="btn secondary">Search</button>
        </form>
        <table>
          <thead><tr><th>Title</th><th>Status</th><th>Visibility</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    /**
     * The trash.
     *
     * Reachable from the Videos screen rather than the top navigation: it is a
     * rare destination, and a permanent-delete button does not belong one
     * mis-click away from everyday work.
     *
     * @param array<string, mixed> $data
     */
    private function trash(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['videos'] ?? []) as $video) {
            $rows .= sprintf(
                '<tr>
                   <td><strong>%s</strong><br><span class="muted">%s</span></td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="restore" class="btn tiny">Restore</button>
                       <button name="action" value="purge" class="btn tiny danger"
                               onclick="return confirm(\'%s\')">Delete for good</button>
                     </form>
                   </td>
                 </tr>',
                e($video->title),
                e(Str::duration($video->duration) ?: '—'),
                $token,
                $video->id,
                e(sprintf(
                    'Permanently delete "%s"? This also deletes the file at your video service and '
                    . 'cannot be undone.',
                    $video->title
                ))
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">The trash is empty.</td></tr>';
        }

        return <<<HTML
        <p class="muted small"><a href="/admin/videos">&larr; All videos</a></p>
        <h1>Trash</h1>

        <p class="muted">Deleted videos are kept here rather than removed, so a mistake is
           recoverable. They do not appear anywhere on the site while they are in the trash.</p>

        <p class="muted small"><strong>Deleting for good removes the file at your video service too.</strong>
           It has to: leaving it there means the next sync re-imports it, and the delete would appear
           to have failed at random. If your video service refuses, the video stays here and says why.</p>

        <table>
          <thead><tr><th>Title</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    /**
     * The upload panel.
     *
     * Rendered only when the video provider can actually accept a file. An
     * upload box on an install with no credentials is a trap: it looks like the
     * way in, and every attempt fails with an error from a service the person
     * has not configured yet.
     *
     * @param array<string, mixed> $data
     */
    private function uploader(array $data): string
    {
        $token = e((string) $data['token']);

        if (empty($data['canUpload'])) {
            return <<<HTML
            <fieldset>
              <legend>Upload</legend>
              <p class="muted small">Uploading needs a video service. Add your credentials under
                 <a href="/admin/providers">Services</a> and this becomes an upload box.</p>
            </fieldset>
            HTML;
        }

        return <<<HTML
        <fieldset id="upload-panel" data-token="{$token}">
          <legend>Upload</legend>

          <div id="upload-drop" class="dropzone">
            <p>Drop video files here, or
               <label class="linklike">choose files<input type="file" id="upload-input"
                      accept="video/*" multiple hidden></label>.</p>
            <p class="muted small">Files go straight from this browser to your video service — they do
               not pass through this site, so size is limited by your video service rather than by
               PHP. A dropped connection resumes rather than starting again.</p>
          </div>

          <ul id="upload-list" class="upload-list"></ul>
        </fieldset>

        <script src="/assets/upload.js" defer></script>
        HTML;
    }

    /**
     * One video's edit form.
     *
     * @param array<string, mixed> $data
     */
    private function videoEdit(array $data): string
    {
        /** @var Video $video */
        $video = $data['video'];
        $token = e((string) $data['token']);

        /** @var list<Category> $categories */
        $categories = (array) ($data['categories'] ?? []);
        /** @var list<int> $assigned */
        $assigned = (array) ($data['assigned'] ?? []);

        $checkboxes = '';
        foreach ($categories as $category) {
            $checkboxes .= sprintf(
                '<label class="checkbox"><input type="checkbox" name="categories[]" value="%d"%s>%s%s</label>',
                $category->id,
                in_array($category->id, $assigned, true) ? ' checked' : '',
                str_repeat('&nbsp;&nbsp;&nbsp;', $category->depth),
                e($category->name)
            );
        }

        if ($checkboxes === '') {
            $checkboxes = '<p class="muted small">No categories yet. '
                . '<a href="/admin/categories">Create one</a> and it will appear here.</p>';
        }

        $seriesOptions = '<option value="0">— none —</option>';
        foreach ((array) ($data['series'] ?? []) as $item) {
            $seriesOptions .= sprintf(
                '<option value="%d"%s>%s</option>',
                $item->id,
                $item->id === $video->seriesId ? ' selected' : '',
                e($item->title)
            );
        }

        $speakerOptions = '<option value="0">— none —</option>';
        foreach ((array) ($data['speakers'] ?? []) as $speaker) {
            $speakerOptions .= sprintf(
                '<option value="%d"%s>%s</option>',
                $speaker->id,
                $speaker->id === $video->speakerId ? ' selected' : '',
                e($speaker->name)
            );
        }

        $title = e($video->title);
        $description = e((string) ($video->description ?? ''));

        $thumbnail = $this->modeSelect(
            'thumbnail_mode',
            ThumbnailPolicy::choices((string) ($data['inheritedLabel'] ?? 'Inherit')),
            $video->thumbnailMode
        );

        $watermark = $this->modeSelect('watermark_mode', [
            'default' => 'Inherit from the site setting',
            'on'      => 'Always watermark',
            'off'     => 'Never watermark',
        ], $video->watermarkMode);

        $memberOnly = $video->memberOnly ? ' checked' : '';
        $hidden = $video->hidden ? ' checked' : '';
        $premiere = $video->premiere ? ' checked' : '';
        $featured = $video->featured ? ' checked' : '';
        $pinned = $video->pinned ? ' checked' : '';

        /*
         * datetime-local wants "Y-m-dTH:i" and rejects anything else silently,
         * rendering an empty box that looks like no date is set — so a stored
         * value is reformatted rather than printed.
         */
        $forInput = static function (?string $value): string {
            if ($value === null || $value === '') {
                return '';
            }
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i');
            } catch (\Throwable) {
                return '';
            }
        };

        $publishedAt = $this->attr($forInput($video->publishedAt));
        $unpublishAt = $this->attr($forInput($video->unpublishAt));

        $attachmentPanel = $this->attachmentPanel(
            (array) ($data['assets'] ?? []),
            $token,
            $video->id
        );

        $chapterPanel = $this->chapterPanel(
            (string) ($data['chapters'] ?? ''),
            $token,
            $video->id
        );

        /*
         * The two sources are shown separately, because they behave
         * differently: clearing the box removes what an editor typed and does
         * nothing to what the description says. Merging them into one list
         * would make the box look like it governed both.
         */
        $scripture = (array) ($data['scripture'] ?? ['manual' => '', 'parsed' => []]);
        $scriptureManual = $this->attr((string) ($scripture['manual'] ?? ''));

        $fromDescription = (array) ($scripture['parsed'] ?? []);
        $scriptureParsed = $fromDescription === []
            ? '<p class="muted small">Nothing found in the description.</p>'
            : '<p class="muted small">Also found in the description, and kept in step with it: '
              . e(implode(', ', $fromDescription)) . '.</p>';

        $captionPanel = ($data['captionsSupported'] ?? false)
            ? $this->captionPanel(
                (array) ($data['captions'] ?? []),
                isset($data['transcript']) && is_array($data['transcript']),
                $token,
                $video->id
            )
            : '';

        $transcriptPanel = $this->transcriptPanel(
            isset($data['transcript']) && is_array($data['transcript']) ? $data['transcript'] : null,
            $token,
            $video->id
        );

        $history = $this->revisionHistory(
            (array) ($data['revisions'] ?? []),
            (array) ($data['revisionDifferences'] ?? []),
            $token,
            '/admin/videos',
            $video->id
        );

        /*
         * Say what the dates currently mean, in words. A schedule is the kind
         * of setting people get wrong by one field and cannot tell from looking
         * at two boxes.
         */
        $scheduleNote = '';
        if ($video->hasExpired()) {
            $scheduleNote = '<p class="pill bad">Its end date has passed, so nobody can see it.</p>';
        } elseif ($video->isPremiering()) {
            $scheduleNote = '<p class="pill">Listed now, playable from '
                . e((string) $video->publishedAt) . '.</p>';
        } elseif ($video->isScheduled()) {
            $scheduleNote = '<p class="pill">Hidden until ' . e((string) $video->publishedAt) . '.</p>';
        }

        $published = match (true) {
            !$video->isPublished    => '<span class="pill">Draft</span>',
            $video->hasExpired()    => '<span class="pill bad">Ended</span>',
            $video->isPremiering()  => '<span class="pill">Premiering</span>',
            $video->isScheduled()   => '<span class="pill">Scheduled</span>',
            default                 => '<span class="pill ok">Published</span>',
        };

        return <<<HTML
        <p class="muted small"><a href="/admin/videos">&larr; All videos</a></p>
        <h1>{$title} {$published}</h1>

        <form method="post" action="/admin/videos">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$video->id}">
          <!--
            This form carries every field, so unticking a checkbox or clearing
            the category list really means "off" and "none". Without this
            marker the handler cannot tell an unticked box from a POST that
            simply did not include it, and would have to guess — see the
            comment on the save branch in AdminController.
          -->
          <input type="hidden" name="_whole_form" value="1">

          <div class="cols">
            <div>
              <fieldset>
                <legend>Details</legend>
                <label>Title <input type="text" name="title" value="{$title}" required></label>
                <label>Description <textarea name="description" rows="5">{$description}</textarea></label>
              </fieldset>

              <fieldset>
                <legend>Categories</legend>
                <p class="muted small">Categories you set here take precedence over the collection this
                   video came from at your video provider.</p>
                {$checkboxes}
              </fieldset>

              <fieldset>
                <legend>Scripture</legend>
                <label>Passages <input type="text" name="scripture" value="{$scriptureManual}"
                                       placeholder="John 3:16; Romans 8:28-30"></label>
                <p class="muted small">Separate them however you like — this reads the same shapes as a
                   description, so "1 Cor 13", "I Corinthians 13" and "First Corinthians 13" all work.
                   Empty the box to remove the ones you added.</p>
                {$scriptureParsed}
              </fieldset>

              <fieldset>
                <legend>Series and speaker</legend>
                <label>Series <select name="series_id">{$seriesOptions}</select></label>
                <p class="muted small">A video belongs to at most one series. Its position in the
                   running order is set on the <a href="/admin/series">series</a> screen.</p>
                <label>Speaker <select name="speaker_id">{$speakerOptions}</select></label>
              </fieldset>
            </div>

            <div>
              <fieldset>
                <legend>Who can see it</legend>

                <label class="checkbox">
                  <input type="checkbox" name="member_only" value="1"{$memberOnly}>
                  Members only
                </label>
                <p class="muted small">Removes it from the library entirely for anyone signed out or
                   not yet approved — they will not know it exists.</p>

                <label class="checkbox">
                  <input type="checkbox" name="hidden" value="1"{$hidden}>
                  Hidden
                </label>
                <p class="muted small">Not listed anywhere, but still reachable by direct link. Useful
                   for something you want to share without publishing.</p>
              </fieldset>

              <fieldset>
                <legend>Where it appears</legend>

                <label class="checkbox">
                  <input type="checkbox" name="featured" value="1"{$featured}>
                  Featured
                </label>
                <p class="muted small">Puts it in a "Featured" homepage row, if you have one. Both this
                   flag and the row are optional — the flag on its own does nothing visible.</p>

                <label class="checkbox">
                  <input type="checkbox" name="pinned" value="1"{$pinned}>
                  Pin to the top of listings
                </label>
                <p class="muted small">Sorts above everything else while browsing. Search deliberately
                   ignores it: somebody who typed an exact title is not asking what you would rather
                   they watched.</p>
              </fieldset>

              <fieldset>
                <legend>When it appears</legend>

                <label>Publish at
                  <input type="datetime-local" name="published_at" value="{$publishedAt}">
                </label>
                <p class="muted small">Leave blank to publish as soon as it is saved. A future date
                   keeps it out of every listing until then — no scheduled job has to run for that to
                   happen, so it is on time even on a site nobody visited that day.</p>

                <label>Stop showing it at
                  <input type="datetime-local" name="unpublish_at" value="{$unpublishAt}">
                </label>
                <p class="muted small">Leave blank to keep it up. After this it disappears from
                   listings and its page, for everybody.</p>

                <label class="checkbox">
                  <input type="checkbox" name="premiere" value="1"{$premiere}>
                  Announce it before it plays
                </label>
                <p class="muted small">Lists it early, showing the date, with the player refusing to
                   start until then. Without this a scheduled video is simply invisible until its
                   date — which is the right default, but not what you want for something you are
                   building up to.</p>

                {$scheduleNote}
              </fieldset>

              <fieldset>
                <legend>Thumbnail</legend>
                <label>Who sees the real artwork {$thumbnail}</label>
                <p class="muted small">Listing and playing are separate here: the library is public and
                   /watch is not, so anyone can browse titles while only an approved account can play.
                   Choose "members only" to withhold the artwork too — the image URL is never sent to a
                   visitor who cannot watch, so it is not merely hidden.</p>
              </fieldset>

              <fieldset>
                <legend>Watermark</legend>
                <label>Overlay the viewer's email {$watermark}</label>
                <p class="muted small">Only applies while the Watermark plugin is active.</p>
              </fieldset>
            </div>
          </div>

          <div class="actions">
            <button class="btn" name="action" value="save">Save</button>
            <a class="btn secondary" href="/admin/videos">Cancel</a>
          </div>
        </form>

        {$attachmentPanel}

        {$chapterPanel}

        {$captionPanel}

        {$transcriptPanel}

        {$history}
        HTML;
    }

    /**
     * Chapters, edited as text.
     *
     * A textarea rather than a row-at-a-time form because the input format is
     * the one people already have — a list pasted from anywhere else works
     * unchanged, and somebody starting from nothing types it faster than they
     * could operate an interface.
     */
    /**
     * Files attached to a video.
     *
     * @param list<array<string, mixed>> $assets
     */
    private function attachmentPanel(array $assets, string $token, int $videoId): string
    {
        $rows = '';

        foreach ($assets as $asset) {
            $name = (string) $asset['original_name'];

            $rows .= sprintf(
                '<tr>
                   <td><a href="/asset/%d/%s">%s</a><br>
                       <span class="muted small">%s%s</span></td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="asset" value="%d">
                       <button name="action" value="detach" class="btn tiny danger"
                               onclick="return confirm(\'Remove this attachment? The file is deleted.\')">Remove</button>
                     </form>
                   </td>
                 </tr>',
                (int) $asset['id'],
                rawurlencode($name),
                e($name),
                e(\Portal\Content\AssetPolicy::formatSize((int) $asset['size_bytes'])),
                ((string) $asset['uploaded_by']) === '' ? '' : ' · ' . e((string) $asset['uploaded_by']),
                $token,
                $videoId,
                (int) $asset['id']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">Nothing attached.</td></tr>';
        }

        $limit = \Portal\Content\AssetPolicy::formatSize(\Portal\Content\AssetPolicy::MAX_BYTES);
        $kinds = implode(', ', array_keys(\Portal\Content\AssetPolicy::types()));

        return <<<HTML
        <h2>Attachments</h2>

        <table>
          <tbody>{$rows}</tbody>
        </table>

        <form method="post" action="/admin/videos" enctype="multipart/form-data">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$videoId}">

          <fieldset>
            <legend>Attach a file</legend>
            <p class="muted small">Notes, slides, a handout. Up to {$limit}.</p>

            <label>File <input type="file" name="attachment" required></label>

            <p class="muted small">Allowed: {$kinds}. Anything a browser could execute is refused,
               including HTML and SVG — an SVG is an image everywhere else and a script container
               here.</p>
            <p class="muted small">Attachments follow the video: one on a members-only video is only
               downloadable by somebody who could watch it, and unpublishing takes it away too.</p>

            <button class="btn" name="action" value="attach">Attach</button>
          </fieldset>
        </form>
        HTML;
    }

    /**
     * Caption tracks, which live at the video provider rather than here.
     *
     * The whole panel is absent for a provider that cannot carry them — see
     * SupportsCaptions. Offering an upload that cannot reach a player is worse
     * than offering nothing, because the person doing it has no way to tell it
     * did not work.
     *
     * @param list<array{language: string, label: string}> $captions
     */
    private function captionPanel(
        array $captions,
        bool $hasTranscript,
        string $token,
        int $videoId
    ): string {
        $rows = '';

        foreach ($captions as $caption) {
            $rows .= sprintf(
                '<tr>
                   <td>%s <span class="muted small">%s</span></td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="caption_language" value="%s">
                       <button name="action" value="caption-delete" class="btn tiny danger"
                               onclick="return confirm(\'Remove these captions?\')">Remove</button>
                     </form>
                   </td>
                 </tr>',
                e($caption['label']),
                e($caption['language']),
                $token,
                $videoId,
                $this->attr($caption['language'])
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">No captions on this video.</td></tr>';
        }

        $options = '';
        foreach (\Portal\Content\CaptionFile::languages() as $tag => $name) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                $this->attr($tag),
                $tag === 'en' ? ' selected' : '',
                e($name)
            );
        }

        /*
         * Offered only when there is a transcript to convert, and with its cost
         * stated. Cues are stored at second precision because a transcript
         * panel seeks to the second, so captions made this way can sit up to a
         * second early — which reads as the captions being slightly out rather
         * than as a consequence of a choice somebody made.
         */
        $fromTranscript = $hasTranscript
            ? '<label class="checkbox">
                 <input type="checkbox" name="caption_from_transcript" value="1">
                 Use this video\'s transcript instead of a file
               </label>
               <p class="muted small">Handy when you have no caption file, with one cost: transcript
                  lines are stored to the nearest second, so captions built from one can appear up to
                  a second early. A real caption file keeps its exact timings.</p>'
            : '';

        $limit = \Portal\Content\AssetPolicy::formatSize(\Portal\Content\CaptionFile::MAX_BYTES);

        return <<<HTML
        <h2>Captions</h2>

        <table>
          <tbody>{$rows}</tbody>
        </table>

        <form method="post" action="/admin/videos" enctype="multipart/form-data">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$videoId}">

          <fieldset>
            <legend>Add captions</legend>
            <p class="muted small">Captions are stored at your video provider, because the player is
               theirs — nothing kept here could put text on the screen. That also means this list is
               read from them, so it is what viewers will really see.</p>

            <label>Language <select name="caption_language">{$options}</select></label>
            <label>Label <input type="text" name="caption_label"
                                placeholder="Shown in the player's caption menu"></label>

            <label>File <input type="file" name="caption_file" accept=".vtt,.srt,text/vtt"></label>
            <p class="muted small">WebVTT or SubRip, up to {$limit}. Timings, positioning and styling
               are passed through untouched.</p>

            {$fromTranscript}

            <p class="muted small">Uploading a language that is already here replaces it.</p>

            <button class="btn" name="action" value="caption">Upload captions</button>
          </fieldset>
        </form>
        HTML;
    }

    private function chapterPanel(string $chapters, string $token, int $videoId): string
    {
        $value = e($chapters);

        return <<<HTML
        <h2>Chapters</h2>

        <form method="post" action="/admin/videos">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$videoId}">

          <fieldset>
            <legend>Moments worth jumping to</legend>
            <p class="muted small">One per line, timestamp first — the same shape you would paste into
               a video description elsewhere. Empty the box to remove them.</p>

            <label class="visually-hidden" for="chapters">Chapters</label>
            <textarea id="chapters" name="chapters" rows="6"
                      placeholder="0:00 Welcome&#10;2:15 The reading&#10;14:30 Questions">{$value}</textarea>

            <p class="muted small">A line without a timestamp at the front is skipped, so a heading
               above the list is harmless. A title containing a time — "Psalm 1:1" — stays a title.</p>

            <button class="btn" name="action" value="chapters">Save chapters</button>
          </fieldset>
        </form>
        HTML;
    }

    /**
     * Importing a transcript.
     *
     * A separate form from the video's own, and enctype multipart, because a
     * subtitle file is what people have — a captioning service hands you a
     * .vtt and a transcription tool hands you text. Offering only one of the
     * two means the other person converts a file by hand or does not bother.
     *
     * @param array<string, mixed>|null $transcript
     */
    private function transcriptPanel(?array $transcript, string $token, int $videoId): string
    {
        $current = '<p class="muted">No transcript yet.</p>';

        if ($transcript !== null) {
            $source = (string) ($transcript['source'] ?? '');
            $current = sprintf(
                '<p class="muted">%d line(s)%s, updated %s.</p>
                 <form method="post" class="inline">
                   <input type="hidden" name="_token" value="%s">
                   <input type="hidden" name="id" value="%d">
                   <button name="action" value="transcript-delete" class="btn tiny danger"
                           onclick="return confirm(\'Remove this transcript?\')">Remove</button>
                 </form>',
                (int) ($transcript['cue_count'] ?? 0),
                $source === '' ? '' : ' from ' . e($source),
                e((string) ($transcript['updated_at'] ?? '')),
                $token,
                $videoId
            );
        }

        return <<<HTML
        <h2>Transcript</h2>
        {$current}

        <form method="post" action="/admin/videos" enctype="multipart/form-data">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$videoId}">

          <fieldset>
            <legend>Import</legend>
            <p class="muted small">WebVTT or SubRip. Upload the file, or paste it below — whichever
               you have. Importing replaces whatever is there now; two transcripts of one recording
               are one mistake rather than something to merge.</p>

            <label>File <input type="file" name="transcript_file" accept=".vtt,.srt,text/vtt,text/plain"></label>
            <label>or paste it <textarea name="transcript" rows="6"
                     placeholder="WEBVTT&#10;&#10;00:00:01.000 --> 00:00:04.000&#10;The first thing said."></textarea></label>
            <label>Where it came from
              <input type="text" name="transcript_source" placeholder="Whisper, the captioner, typed by hand">
            </label>

            <button class="btn" name="action" value="transcript">Import</button>
          </fieldset>
        </form>

        <p class="muted small">A transcript is searchable, and a search that matches one shows the
           moment the words were said. It is weighted well below a title on purpose: a transcript is
           tens of thousands of words, so almost every common word is in almost every one, and
           ranking it higher would return the whole library in arbitrary order.</p>
        HTML;
    }

    /**
     * The revision list, with what each one would put back.
     *
     * The differences are against the CURRENT state, not against the revision
     * before it: the question an editor is asking is "what do I get back", and
     * answering a different one would be worse than answering none.
     *
     * @param list<array<string, mixed>>      $revisions
     * @param array<int, array<string, array{from: string, to: string}>> $differences
     */
    private function revisionHistory(
        array $revisions,
        array $differences,
        string $token,
        string $action,
        int $subjectId
    ): string {
        if ($revisions === []) {
            return '<h2>History</h2>
                    <p class="muted">Nothing yet. A version is kept every time you save a change,
                       so you can put one back.</p>';
        }

        $rows = '';

        foreach ($revisions as $revision) {
            $id = (int) $revision['id'];
            $changes = $differences[$id] ?? [];

            $summary = '';
            foreach ($changes as $field => $change) {
                $summary .= sprintf(
                    '<li><strong>%s</strong>: %s &rarr; %s</li>',
                    e(str_replace('_', ' ', (string) $field)),
                    e(\Portal\Support\Str::truncate($change['from'], 80)),
                    e(\Portal\Support\Str::truncate($change['to'], 80))
                );
            }

            $summary = $summary === ''
                ? '<p class="muted small">Identical to the current version.</p>'
                : '<ul class="revision-changes">' . $summary . '</ul>';

            $who = (string) $revision['changedBy'];
            $who = $who === '' ? 'somebody' : $who;

            $rows .= sprintf(
                '<tr>
                   <td>
                     <p class="muted small">Saved by %s on %s</p>
                     %s
                   </td>
                   <td class="right">
                     <form method="post" action="%s" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="revision" value="%d">
                       <button name="action" value="restore-revision" class="btn tiny secondary"
                               onclick="return confirm(\'Put this version back? The current one is kept in the history.\')"%s>Restore</button>
                     </form>
                   </td>
                 </tr>',
                e($who),
                e((string) $revision['createdAt']),
                $summary,
                e($action),
                $token,
                $subjectId,
                $id,
                $changes === [] ? ' disabled' : ''
            );
        }

        return <<<HTML
        <h2>History</h2>
        <p class="muted small">The last {$this->keepLimit()} versions are kept. Restoring one records
           the current version first, so an undo can itself be undone.</p>
        <table>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    private function keepLimit(): int
    {
        return \Portal\Content\RevisionRepository::KEEP;
    }

    // ----------------------------------------------------------- categories

    /** @param array<string, mixed> $data */
    private function categories(array $data): string
    {
        $token = e((string) $data['token']);

        /** @var list<Category> $flat */
        $flat = $data['flat'] ?? [];

        $options = '<option value="0">— top level —</option>';
        $rows = '';

        foreach ($flat as $category) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $category->depth);

            $options .= sprintf(
                '<option value="%d">%s%s</option>',
                $category->id,
                $indent,
                e($category->name)
            );

            $imported = $category->isImported()
                ? '<span class="pill">imported</span>'
                : '';

            $locked = $category->thumbnailMode === ThumbnailPolicy::MEMBERS
                ? '<span class="pill warn">members-only art</span>'
                : '';

            $rows .= sprintf(
                '<tr>
                   <td>%s<a href="/admin/categories/%d"><strong>%s</strong></a> %s %s<br>
                       <span class="muted">/category/%s</span></td>
                   <td class="right">
                     <a class="btn tiny secondary" href="/admin/categories/%d">Edit</a>
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'Delete this category? Videos in it are kept.\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                $indent,
                $category->id,
                e($category->name),
                $imported,
                $locked,
                e($category->slug),
                $category->id,
                $token,
                $category->id
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">No categories yet.</td></tr>';
        }

        return <<<HTML
        <h1>Categories</h1>

        <p class="muted">Categories you create here take precedence over collections at your video
           provider. Importing brings collections in as a starting point; renaming one afterwards
           will not be undone by a later import.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Name</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>
            <h2>Add a category</h2>
            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <label>Name <input type="text" name="name" required></label>
              <label>Parent <select name="parent_id">{$options}</select></label>
              <button class="btn" name="action" value="create">Create</button>
            </form>

            <h2>Import from provider</h2>
            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <button class="btn secondary" name="action" value="import">Import collections</button>
            </form>
          </div>
        </div>
        HTML;
    }

    /**
     * One category's edit form.
     *
     * @param array<string, mixed> $data
     */
    private function categoryEdit(array $data): string
    {
        /** @var Category $category */
        $category = $data['category'];
        $token = e((string) $data['token']);

        /** @var list<Category> $flat */
        $flat = (array) ($data['flat'] ?? []);

        // A category cannot be moved inside itself or its own descendants, so
        // its subtree is left out of the parent picker rather than offered and
        // then rejected on save.
        $subtree = $category->path;
        $options = '<option value="0">— top level —</option>';
        foreach ($flat as $candidate) {
            if (str_starts_with($candidate->path, $subtree)) {
                continue;
            }
            $options .= sprintf(
                '<option value="%d"%s>%s%s</option>',
                $candidate->id,
                $candidate->id === $category->parentId ? ' selected' : '',
                str_repeat('&nbsp;&nbsp;&nbsp;', $candidate->depth),
                e($candidate->name)
            );
        }

        $trail = '';
        foreach ((array) ($data['ancestors'] ?? []) as $ancestor) {
            $trail .= e($ancestor->name) . ' / ';
        }

        $name = e($category->name);
        $slug = e($category->slug);
        $description = e((string) ($category->description ?? ''));

        $thumbnail = $this->modeSelect(
            'thumbnail_mode',
            ThumbnailPolicy::choices((string) ($data['inheritedLabel'] ?? 'Inherit')),
            $category->thumbnailMode
        );

        $publishedAttr = $category->isPublished ? ' checked' : '';
        $memberOnlyAttr = $category->memberOnly ? ' checked' : '';
        $hiddenAttr = $category->hidden ? ' checked' : '';

        return <<<HTML
        <p class="muted small"><a href="/admin/categories">&larr; All categories</a></p>
        <h1><span class="muted">{$trail}</span>{$name}</h1>

        <form method="post" action="/admin/categories">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$category->id}">

          <div class="cols">
            <div>
              <fieldset>
                <legend>Details</legend>
                <label>Name <input type="text" name="name" value="{$name}" required></label>
                <label>Address <input type="text" name="slug" value="{$slug}"></label>
                <p class="muted small">Changing this keeps the old address working — a link printed in
                   a bulletin will not break because somebody fixed a typo.</p>
                <label>Parent <select name="parent_id">{$options}</select></label>
                <label>Description <textarea name="description" rows="4">{$description}</textarea></label>
              </fieldset>
            </div>

            <div>
              <fieldset>
                <legend>Who can see it</legend>

                <label class="checkbox">
                  <input type="checkbox" name="is_published" value="1"{$publishedAttr}>
                  Published
                </label>

                <label class="checkbox">
                  <input type="checkbox" name="member_only" value="1"{$memberOnlyAttr}>
                  Members only
                </label>

                <label class="checkbox">
                  <input type="checkbox" name="hidden" value="1"{$hiddenAttr}>
                  Hidden
                </label>
              </fieldset>

              <fieldset>
                <legend>Thumbnails</legend>
                <label>Who sees the real artwork {$thumbnail}</label>
                <p class="muted small">Applies to every video in this category and, unless they say
                   otherwise, everything nested beneath it. A video's own setting always wins, and where
                   a video sits in two categories that disagree, "members only" is what applies.</p>
              </fieldset>
            </div>
          </div>

          <div class="actions">
            <button class="btn" name="action" value="update">Save</button>
            <a class="btn secondary" href="/admin/categories">Cancel</a>
          </div>
        </form>
        HTML;
    }

    /**
     * A select for one of the three-way inherit/on/off settings.
     *
     * @param array<string, string> $choices
     */
    private function modeSelect(string $name, array $choices, string $current): string
    {
        $options = '';
        foreach ($choices as $value => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                $value === $current ? ' selected' : '',
                e($label)
            );
        }

        return sprintf('<select name="%s">%s</select>', e($name), $options);
    }

    // --------------------------------------------------------------- series

    /** @param array<string, mixed> $data */
    private function series(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['series'] ?? []) as $item) {
            $state = $item->isPublished
                ? '<span class="pill ok">Published</span>'
                : '<span class="pill">Draft</span>';

            $count = $item->videoCount === 1 ? '1 video' : $item->videoCount . ' videos';

            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/series/%d"><strong>%s</strong></a> %s<br>
                       <span class="muted">/series/%s — %s</span></td>
                   <td class="right">
                     <a class="btn tiny secondary" href="/admin/series/%d">Edit</a>
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'Delete this series? Its videos are kept.\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                $item->id,
                e($item->title),
                $state,
                e($item->slug),
                e($count),
                $item->id,
                $token,
                $item->id
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">No series yet.</td></tr>';
        }

        return <<<HTML
        <h1>Series</h1>

        <p class="muted">A series is an <strong>order</strong> — episode 1, 2, 3. A category is a
           <strong>place</strong>. A video can sit in several categories but belongs to at most one
           series, because "episode 3" cannot mean two things at once.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Title</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>
            <h2>Add a series</h2>
            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <label>Title <input type="text" name="title" required></label>
              <button class="btn" name="action" value="create">Create</button>
            </form>
            <p class="muted small">You will land on its edit screen, where you can add episodes.</p>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function seriesEdit(array $data): string
    {
        /** @var Series $series */
        $series = $data['series'];
        $token = e((string) $data['token']);

        $categoryOptions = '<option value="0">— none —</option>';
        foreach ((array) ($data['categories'] ?? []) as $category) {
            $categoryOptions .= sprintf(
                '<option value="%d"%s>%s%s</option>',
                $category->id,
                $category->id === $series->categoryId ? ' selected' : '',
                str_repeat('&nbsp;&nbsp;&nbsp;', $category->depth),
                e($category->name)
            );
        }

        /** @var list<Video> $episodes */
        $episodes = (array) ($data['episodes'] ?? []);

        $running = '';
        $position = 1;
        foreach ($episodes as $video) {
            $running .= sprintf(
                '<tr>
                   <td class="muted">%d</td>
                   <td><a href="/admin/videos/%d">%s</a></td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="video" value="%d">
                       <button name="action" value="up" class="btn tiny secondary"
                               aria-label="Move up"%s>&uarr;</button>
                       <button name="action" value="down" class="btn tiny secondary"
                               aria-label="Move down"%s>&darr;</button>
                     </form>
                   </td>
                 </tr>',
                $position,
                $video->id,
                e($video->title),
                $token,
                $series->id,
                $video->id,
                $position === 1 ? ' disabled' : '',
                $position === count($episodes) ? ' disabled' : ''
            );
            $position++;
        }

        if ($running === '') {
            $running = '<tr><td colspan="3" class="muted">No episodes yet. Add some below.</td></tr>';
        }

        $assigned = array_map(static fn (Video $v): int => $v->id, $episodes);

        $picker = '';
        foreach ((array) ($data['available'] ?? []) as $video) {
            $picker .= sprintf(
                '<label class="checkbox"><input type="checkbox" name="videos[]" value="%d"%s>%s</label>',
                $video->id,
                in_array($video->id, $assigned, true) ? ' checked' : '',
                e($video->title)
            );
        }

        if ($picker === '') {
            $picker = '<p class="muted small">No videos available to add.</p>';
        }

        $title = e($series->title);
        $slug = e($series->slug);
        $description = e((string) ($series->description ?? ''));

        $publishedAttr = $series->isPublished ? ' checked' : '';
        $memberAttr = $series->memberOnly ? ' checked' : '';
        $hiddenAttr = $series->hidden ? ' checked' : '';
        $featuredAttr = $series->featured ? ' checked' : '';

        return <<<HTML
        <p class="muted small"><a href="/admin/series">&larr; All series</a></p>
        <h1>{$title}</h1>

        <div class="cols">
          <div>
            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$series->id}">
              <fieldset>
                <legend>Details</legend>
                <label>Title <input type="text" name="title" value="{$title}" required></label>
                <label>Address <input type="text" name="slug" value="{$slug}"></label>
                <p class="muted small">Changing this keeps the old address working.</p>
                <label>Category <select name="category_id">{$categoryOptions}</select></label>
                <label>Description <textarea name="description" rows="4">{$description}</textarea></label>
              </fieldset>

              <fieldset>
                <legend>Visibility</legend>
                <label class="checkbox"><input type="checkbox" name="is_published" value="1"{$publishedAttr}> Published</label>
                <label class="checkbox"><input type="checkbox" name="member_only" value="1"{$memberAttr}> Members only</label>
                <label class="checkbox"><input type="checkbox" name="hidden" value="1"{$hiddenAttr}> Hidden</label>
                <label class="checkbox"><input type="checkbox" name="featured" value="1"{$featuredAttr}> Featured</label>
              </fieldset>

              <button class="btn" name="action" value="update">Save</button>
            </form>
          </div>

          <div>
            <fieldset>
              <legend>Running order</legend>
              <table>
                <tbody>{$running}</tbody>
              </table>
            </fieldset>

            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$series->id}">
              <fieldset>
                <legend>Episodes</legend>
                <p class="muted small">Ticking adds a video; unticking removes it. Videos already in
                   another series are not listed — a video belongs to one series, so adding it here
                   would quietly take it out of that one.</p>
                {$picker}
                <button class="btn" name="action" value="episodes">Update episodes</button>
              </fieldset>
            </form>
          </div>
        </div>
        HTML;
    }

    // ------------------------------------------------------------ playlists

    /** @param array<string, mixed> $data */
    private function playlists(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['playlists'] ?? []) as $item) {
            $state = $item->isPublished
                ? '<span class="pill ok">Published</span>'
                : '<span class="pill">Draft</span>';

            $count = $item->videoCount === 1 ? '1 video' : $item->videoCount . ' videos';

            $rows .= sprintf(
                '<tr>
                   <td><a href="/admin/playlists/%d"><strong>%s</strong></a> %s<br>
                       <span class="muted">/playlist/%s — %s</span></td>
                   <td class="right">
                     <a class="btn tiny secondary" href="/admin/playlists/%d">Edit</a>
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'Delete this playlist? Its videos are kept.\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                $item->id,
                e($item->title),
                $state,
                e($item->slug),
                e($count),
                $item->id,
                $token,
                $item->id
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">No playlists yet.</td></tr>';
        }

        return <<<HTML
        <h1>Playlists</h1>

        <p class="muted">A playlist is a <strong>selection</strong> — whatever you want to put
           together, in whatever order. Unlike a series, a video can be on any number of playlists
           at once, so adding one here never takes it off anything else.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Title</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>
            <h2>Add a playlist</h2>
            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <label>Title <input type="text" name="title" required></label>
              <button class="btn" name="action" value="create">Create</button>
            </form>
            <p class="muted small">You will land on its edit screen, where you can add videos.</p>
          </div>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function playlistEdit(array $data): string
    {
        /** @var \Portal\Content\Playlist $playlist */
        $playlist = $data['playlist'];
        $token = e((string) $data['token']);

        /** @var list<Video> $items */
        $items = (array) ($data['items'] ?? []);

        $running = '';
        $position = 1;
        foreach ($items as $video) {
            $running .= sprintf(
                '<tr>
                   <td class="muted">%d</td>
                   <td><a href="/admin/videos/%d">%s</a></td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <input type="hidden" name="video" value="%d">
                       <button name="action" value="up" class="btn tiny secondary"
                               aria-label="Move up"%s>&uarr;</button>
                       <button name="action" value="down" class="btn tiny secondary"
                               aria-label="Move down"%s>&darr;</button>
                     </form>
                   </td>
                 </tr>',
                $position,
                $video->id,
                e($video->title),
                $token,
                $playlist->id,
                $video->id,
                $position === 1 ? ' disabled' : '',
                $position === count($items) ? ' disabled' : ''
            );
            $position++;
        }

        if ($running === '') {
            $running = '<tr><td colspan="3" class="muted">Nothing on this playlist yet.</td></tr>';
        }

        /*
         * The picker is seeded from the stored order rather than from what the
         * running-order table shows. Those differ: the table lists what a
         * viewer would see, and an unpublished video the editor deliberately
         * queued is not in it. Building the checkboxes from the table would
         * drop that video the next time anybody pressed Save.
         */
        $chosen = array_map('intval', (array) ($data['chosenIds'] ?? []));

        $picker = '';
        foreach ((array) ($data['available'] ?? []) as $video) {
            $picker .= sprintf(
                '<label class="checkbox"><input type="checkbox" name="videos[]" value="%d"%s>%s</label>',
                $video->id,
                in_array($video->id, $chosen, true) ? ' checked' : '',
                e($video->title)
            );
        }

        if ($picker === '') {
            $picker = '<p class="muted small">No videos available to add.</p>';
        }

        $title = e($playlist->title);
        $slug = e($playlist->slug);
        $description = e((string) ($playlist->description ?? ''));

        $publishedAttr = $playlist->isPublished ? ' checked' : '';
        $memberAttr = $playlist->memberOnly ? ' checked' : '';
        $hiddenAttr = $playlist->hidden ? ' checked' : '';
        $featuredAttr = $playlist->featured ? ' checked' : '';

        return <<<HTML
        <p class="muted small"><a href="/admin/playlists">&larr; All playlists</a></p>
        <h1>{$title}</h1>

        <div class="cols">
          <div>
            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$playlist->id}">
              <fieldset>
                <legend>Details</legend>
                <label>Title <input type="text" name="title" value="{$title}" required></label>
                <label>Address <input type="text" name="slug" value="{$slug}"></label>
                <p class="muted small">Changing this keeps the old address working.</p>
                <label>Description <textarea name="description" rows="4">{$description}</textarea></label>
              </fieldset>

              <fieldset>
                <legend>Visibility</legend>
                <label class="checkbox"><input type="checkbox" name="is_published" value="1"{$publishedAttr}> Published</label>
                <label class="checkbox"><input type="checkbox" name="member_only" value="1"{$memberAttr}> Members only</label>
                <label class="checkbox"><input type="checkbox" name="hidden" value="1"{$hiddenAttr}> Hidden</label>
                <label class="checkbox"><input type="checkbox" name="featured" value="1"{$featuredAttr}> Featured</label>
              </fieldset>

              <button class="btn" name="action" value="update">Save</button>
            </form>
          </div>

          <div>
            <fieldset>
              <legend>Order</legend>
              <p class="muted small">This lists what a viewer would see. Anything unpublished stays on
                 the playlist and simply does not appear here.</p>
              <table>
                <tbody>{$running}</tbody>
              </table>
            </fieldset>

            <form method="post">
              <input type="hidden" name="_token" value="{$token}">
              <input type="hidden" name="id" value="{$playlist->id}">
              <fieldset>
                <legend>Videos</legend>
                <p class="muted small">Ticking adds a video; unticking removes it. A video can be on
                   several playlists at once, so nothing here affects any other list.</p>
                {$picker}
                <button class="btn" name="action" value="items">Update playlist</button>
              </fieldset>
            </form>
          </div>
        </div>
        HTML;
    }

    // ------------------------------------------------------------- analytics

    /** @param array<string, mixed> $data */
    private function analytics(array $data): string
    {
        $days = (int) ($data['days'] ?? 30);
        $summary = (array) ($data['summary'] ?? []);

        $views = (int) ($summary['views'] ?? 0);
        $completions = (int) ($summary['completions'] ?? 0);

        /*
         * The ratio, and only when there is enough behind it to mean anything.
         * "100% finished" from two views is a number people quote at each
         * other, and it is noise.
         */
        $rate = $views >= 10
            ? sprintf('%d%% watched to the end', (int) round(($completions / $views) * 100))
            : 'not enough views yet to say how many finish';

        $tabs = '';
        foreach (\Portal\Content\ViewRepository::periods() as $value => $label) {
            $tabs .= sprintf(
                '<a class="pill%s" href="/admin/analytics?days=%d">%s</a> ',
                $value === $days ? ' ok' : '',
                $value,
                e($label)
            );
        }

        $rows = '';
        foreach ((array) ($data['top'] ?? []) as $row) {
            $rowViews = (int) $row['views'];
            $rowDone = (int) $row['completions'];

            $rows .= sprintf(
                '<tr>
                   <td><a href="/watch/%s">%s</a></td>
                   <td class="right">%d</td>
                   <td class="right">%d</td>
                   <td class="right muted">%s</td>
                 </tr>',
                e((string) $row['slug']),
                e((string) $row['title']),
                $rowViews,
                $rowDone,
                $rowViews >= 10 ? (int) round(($rowDone / $rowViews) * 100) . '%' : '—'
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="muted">Nothing has been watched in this period.</td></tr>';
        }

        return <<<HTML
        <h1>Analytics</h1>
        <p class="toolbar">{$tabs}</p>

        <p class="muted"><strong>{$views}</strong> view(s), <strong>{$completions}</strong> finished —
           {$rate}.</p>

        <table>
          <thead>
            <tr>
              <th>Video</th>
              <th class="right">Views</th>
              <th class="right">Finished</th>
              <th class="right">Rate</th>
            </tr>
          </thead>
          <tbody>{$rows}</tbody>
        </table>

        <p class="muted small">A view is one signed-in viewer getting more than ten seconds into a
           video, counted once per session however long they stay. Finished means the same 95% mark
           the resume feature uses.</p>

        <p class="muted small">Only daily totals are kept — there is no record here of who watched
           what. That is deliberate: a library owner needs to know what is worth making more of, and
           a per-person viewing history is a liability nobody asked for. Share links are tracked
           separately on the <a href="/admin/shares">sharing</a> screen, where the question genuinely
           is whether one recipient opened one link.</p>
        HTML;
    }

    // -------------------------------------------------------------- homepage

    /** @param array<string, mixed> $data */
    private function homeRows(array $data): string
    {
        $token = e((string) $data['token']);

        /** @var list<\Portal\Content\HomeRow> $rows */
        $rows = (array) ($data['rows'] ?? []);

        // Held for the duration of this render so each row form can rebuild its
        // own pickers with its own selection. Sharing one rendered block would
        // show every row the first one's choice.
        $this->targetItems = [
            \Portal\Content\HomeRow::CATEGORY => (array) ($data['categories'] ?? []),
            \Portal\Content\HomeRow::SERIES   => (array) ($data['series'] ?? []),
            \Portal\Content\HomeRow::PLAYLIST => (array) ($data['playlists'] ?? []),
        ];

        $targets = [
            \Portal\Content\HomeRow::CATEGORY => $this->targetOptions($this->targetItems['category'], 'name'),
            \Portal\Content\HomeRow::SERIES   => $this->targetOptions($this->targetItems['series'], 'title'),
            \Portal\Content\HomeRow::PLAYLIST => $this->targetOptions($this->targetItems['playlist'], 'title'),
        ];

        $body = '';
        $position = 1;

        foreach ($rows as $row) {
            $body .= $this->homeRowForm($row, $token, $targets, $position === 1, $position === count($rows));
            $position++;
        }

        if ($body === '') {
            $body = '<p class="muted">No rows yet. The homepage is showing its default: continue
                     watching, then everything, newest first.</p>';
        }

        $sourceOptions = '';
        foreach (\Portal\Content\HomeRow::sources() as $value => $label) {
            $sourceOptions .= sprintf('<option value="%s">%s</option>', e($value), e($label));
        }

        $newTargets = '';
        foreach ($targets as $kind => $options) {
            $newTargets .= sprintf(
                '<label class="home-target" data-for="%s">%s <select name="source_%s">%s</select></label>',
                e($kind),
                e(ucfirst($kind)),
                e($kind),
                $options
            );
        }

        $script = $this->homeRowScript();

        return <<<HTML
        <h1>Homepage</h1>

        <p class="muted">Rows are shown top to bottom. Each one points at content rather than holding
           its own, so curating a playlist curates the homepage — there is one place to edit, not two
           that drift apart. A row whose target has been deleted, or that has nothing to show, is left
           out rather than rendered as an empty heading.</p>

        <p class="muted small">With no rows at all the homepage keeps its default arrangement, so this
           screen is safe to leave empty.</p>

        {$body}

        <h2>Add a row</h2>
        <form method="post" class="home-row-form">
          <input type="hidden" name="_token" value="{$token}">
          <label>Heading <input type="text" name="title" placeholder="Leave blank to use the source's own name"></label>
          <label>Show <select name="source_type" class="home-source">{$sourceOptions}</select></label>
          {$newTargets}
          <label>How many <input type="number" name="max_items" value="12" min="1" max="50"></label>
          <button class="btn" name="action" value="create">Add row</button>
        </form>

        {$script}
        HTML;
    }

    /**
     * @param list<object>                $items
     * @return string
     */
    private function targetOptions(array $items, string $labelProperty, ?int $selected = null): string
    {
        $options = '';
        foreach ($items as $item) {
            $options .= sprintf(
                '<option value="%d"%s>%s</option>',
                (int) $item->id,
                (int) $item->id === $selected ? ' selected' : '',
                e((string) $item->{$labelProperty})
            );
        }

        return $options === '' ? '<option value="">— none available —</option>' : $options;
    }

    /** @param array<string, string> $targets */
    private function homeRowForm(
        \Portal\Content\HomeRow $row,
        string $token,
        array $targets,
        bool $isFirst,
        bool $isLast
    ): string {
        $sourceOptions = '';
        foreach (\Portal\Content\HomeRow::sources() as $value => $label) {
            $sourceOptions .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                $value === $row->sourceType ? ' selected' : '',
                e($label)
            );
        }

        $targetFields = '';
        foreach ($targets as $kind => $_) {
            // Rebuilt per row so the stored target is selected. Sharing one
            // rendered block between rows would show every row the first one's
            // selection.
            $options = $this->targetOptions(
                $this->targetItemsFor($kind),
                $kind === \Portal\Content\HomeRow::CATEGORY ? 'name' : 'title',
                $row->sourceType === $kind ? $row->sourceId : null
            );

            $targetFields .= sprintf(
                '<label class="home-target" data-for="%s">%s <select name="source_%s">%s</select></label>',
                e($kind),
                e(ucfirst($kind)),
                e($kind),
                $options
            );
        }

        $title = $this->attr($row->title);
        $active = $row->isActive ? ' checked' : '';

        return <<<HTML
        <form method="post" class="home-row-form">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$row->id}">

          <label>Heading <input type="text" name="title" value="{$title}"
                                placeholder="Leave blank to use the source's own name"></label>
          <label>Show <select name="source_type" class="home-source">{$sourceOptions}</select></label>
          {$targetFields}
          <label>How many <input type="number" name="max_items" value="{$row->maxItems}" min="1" max="50"></label>
          <label class="checkbox"><input type="checkbox" name="is_active" value="1"{$active}> Shown</label>

          <button class="btn tiny" name="action" value="update">Save</button>
          <button class="btn tiny secondary" name="action" value="up" aria-label="Move up"
                  {$this->disabledIf($isFirst)}>&uarr;</button>
          <button class="btn tiny secondary" name="action" value="down" aria-label="Move down"
                  {$this->disabledIf($isLast)}>&darr;</button>
          <button class="btn tiny danger" name="action" value="delete"
                  onclick="return confirm('Remove this row? The content stays where it is.')">Remove</button>
        </form>
        HTML;
    }

    private function disabledIf(bool $condition): string
    {
        return $condition ? 'disabled' : '';
    }

    /**
     * The pickers a row form needs, held for the duration of one render.
     *
     * @var array<string, list<object>>
     */
    private array $targetItems = [];

    /** @return list<object> */
    private function targetItemsFor(string $kind): array
    {
        return $this->targetItems[$kind] ?? [];
    }

    /**
     * Show only the picker that matches the chosen source.
     *
     * Progressive enhancement: with scripting off every picker is visible and
     * the form still works, because the handler reads only the one named by
     * source_type. The script hides the irrelevant ones rather than being what
     * makes the form correct.
     */
    private function homeRowScript(): string
    {
        return <<<'HTML'
        <script>
        (function () {
          'use strict';
          function sync(form) {
            var chosen = form.querySelector('.home-source').value;
            form.querySelectorAll('.home-target').forEach(function (field) {
              field.hidden = field.getAttribute('data-for') !== chosen;
            });
          }
          document.querySelectorAll('.home-row-form').forEach(function (form) {
            sync(form);
            form.querySelector('.home-source').addEventListener('change', function () { sync(form); });
          });
        })();
        </script>
        HTML;
    }

    // --------------------------------------------------------- announcements

    /**
     * Webhook endpoints, and how their deliveries have been going.
     *
     * The delivery history is on the same screen as the endpoints rather than
     * behind a link, because the question somebody arrives with is almost
     * always "is this working" and the answer is entirely in the history. An
     * endpoint row on its own can only say what was configured.
     *
     * @param array<string, mixed> $data
     */
    private function webhooks(array $data): string
    {
        $token = e((string) $data['token']);
        $deliveries = (array) ($data['deliveries'] ?? []);
        $pending = (int) ($data['pending'] ?? 0);

        $rows = '';
        foreach ((array) ($data['webhooks'] ?? []) as $endpoint) {
            $rows .= $this->webhookCard($endpoint, $token, (array) ($deliveries[(int) $endpoint['id']] ?? []));
        }

        if ($rows === '') {
            $rows = '<p class="muted">No endpoints. Nothing is being sent anywhere.</p>';
        }

        $checkboxes = '';
        foreach ((array) ($data['events'] ?? []) as $event => $description) {
            $checkboxes .= sprintf(
                '<label class="checkbox"><input type="checkbox" name="events[]" value="%s" checked> '
                . '<code>%s</code> — %s</label>',
                $this->attr((string) $event),
                e((string) $event),
                e((string) $description)
            );
        }

        /*
         * Shown once, on the redirect that created or rotated it. The value is
         * in the database and an admin could read it there, so this is
         * convenience rather than secrecy — but a screen that reprinted every
         * secret on every visit would be one more place for one to be seen
         * over somebody's shoulder.
         */
        $secret = (string) ($data['newSecret'] ?? '');
        $secretBox = $secret === '' ? '' : sprintf(
            '<div class="flash ok"><p><strong>Signing secret:</strong> <code>%s</code></p>'
            . '<p class="small">Copy it now — it is not shown again. The receiving system needs it to '
            . 'verify that a delivery came from here.</p></div>',
            e($secret)
        );

        $pendingNote = $pending === 0
            ? '<p class="muted small">Nothing waiting to be sent.</p>'
            : sprintf(
                '<p class="muted small">%d delivery(s) waiting. They go out on the next scheduled run.</p>',
                $pending
            );

        return <<<HTML
        <h1>Webhooks</h1>

        <p class="muted">A POST to another system when something happens here. Every delivery is
           signed, so the receiver can tell it came from this site and not from somebody who guessed
           the URL.</p>

        <p class="muted small"><strong>Deliveries are queued, not immediate.</strong> Sending happens
           on the scheduled run, because doing it while somebody waits would put another server's
           slowness in front of your own pages. If your host offers cron, point it at the URL on the
           <a href="/admin/cron">scheduled jobs</a> screen — without it, deliveries only go out when
           somebody visits the site, which on a quiet day means late rather than never.</p>

        {$secretBox}
        {$pendingNote}

        {$rows}

        <h2>Add an endpoint</h2>
        <form method="post">
          <input type="hidden" name="_token" value="{$token}">

          <fieldset>
            <legend>Where to send it</legend>
            <label>URL <input type="url" name="url" placeholder="https://example.com/hooks/portal" required></label>
            <p class="muted small">Must be https, and must be reachable from the public internet.
               An address on a private network is refused: deliveries go out from this server, so an
               internal address would let this reach things that are not meant to be reachable from
               outside.</p>

            <label>Description <input type="text" name="description" placeholder="What is at the other end"></label>
          </fieldset>

          <fieldset>
            <legend>What to send</legend>
            {$checkboxes}
            <p class="muted small">Tick nothing and it receives everything, including events added in
               later versions.</p>
          </fieldset>

          <button class="btn" name="action" value="create">Add endpoint</button>
        </form>

        <h2>Verifying a delivery</h2>
        <p class="muted small">Each request carries <code>X-Portal-Signature: t=&lt;unix time&gt;,v1=&lt;hex&gt;</code>,
           where the hex is <code>HMAC-SHA256</code> of <code>"&lt;t&gt;.&lt;raw body&gt;"</code> using the
           endpoint's secret. Compare it in constant time, and reject anything whose timestamp is more
           than a few minutes old — that is what stops a captured delivery being replayed later.</p>
        HTML;
    }

    /**
     * @param array<string, mixed>              $endpoint
     * @param list<array<string, mixed>>        $deliveries
     */
    private function webhookCard(array $endpoint, string $token, array $deliveries): string
    {
        $id = (int) $endpoint['id'];
        $active = (int) $endpoint['is_active'] === 1;

        $status = $active
            ? '<span class="pill ok">Active</span>'
            : '<span class="pill bad">Off</span>';

        $reason = (string) $endpoint['disabled_reason'];
        $reasonNote = $reason === '' ? '' : '<p class="pill bad">' . e($reason) . '</p>';

        $history = '';
        foreach ($deliveries as $delivery) {
            $result = match ((string) $delivery['status']) {
                'delivered' => '<span class="pill ok">' . (int) $delivery['response_status'] . '</span>',
                'failed'    => '<span class="pill bad">gave up</span>',
                default     => '<span class="pill">waiting</span>',
            };

            $history .= sprintf(
                '<tr><td><code>%s</code></td><td>%s</td><td class="muted small">%s</td>'
                . '<td class="muted small">%s</td></tr>',
                e((string) $delivery['event']),
                $result,
                e((string) $delivery['created_at']),
                e((string) $delivery['error'])
            );
        }

        $history = $history === ''
            ? '<p class="muted small">Nothing sent yet.</p>'
            : '<table><tbody>' . $history . '</tbody></table>';

        $toggle = $active
            ? '<button name="action" value="disable" class="btn tiny secondary">Switch off</button>'
            : '<button name="action" value="enable" class="btn tiny">Switch on</button>';

        $events = (string) $endpoint['events'];
        $eventLabel = $events === \Portal\Content\WebhookPolicy::ALL_EVENTS
            ? 'every event'
            : e($events);

        $url = e((string) $endpoint['url']);
        $description = e((string) $endpoint['description']);

        return <<<HTML
        <div class="panel">
          <h3><code>{$url}</code> {$status}</h3>
          <p class="muted small">{$description}</p>
          <p class="muted small">Sends: {$eventLabel}</p>
          {$reasonNote}

          {$history}

          <form method="post" class="inline">
            <input type="hidden" name="_token" value="{$token}">
            <input type="hidden" name="id" value="{$id}">
            {$toggle}
            <button name="action" value="rotate" class="btn tiny secondary"
                    onclick="return confirm('Replace the signing secret? Deliveries will fail until the receiving system is updated.')">New secret</button>
            <button name="action" value="delete" class="btn tiny danger"
                    onclick="return confirm('Remove this endpoint and its delivery history?')">Remove</button>
          </form>
        </div>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function announcements(array $data): string
    {
        $token = e((string) $data['token']);

        $forInput = static function (?string $value): string {
            if ($value === null || $value === '') {
                return '';
            }
            try {
                return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i');
            } catch (\Throwable) {
                return '';
            }
        };

        $body = '';
        foreach ((array) ($data['announcements'] ?? []) as $announcement) {
            $body .= $this->announcementForm($announcement, $token, $forInput);
        }

        if ($body === '') {
            $body = '<p class="muted">Nothing is showing.</p>';
        }

        $levels = '';
        foreach (\Portal\Content\Announcement::levels() as $value => $label) {
            $levels .= sprintf('<option value="%s">%s</option>', e($value), e($label));
        }

        $audiences = '';
        foreach (\Portal\Content\Announcement::audiences() as $value => $label) {
            $audiences .= sprintf('<option value="%s">%s</option>', e($value), e($label));
        }

        return <<<HTML
        <h1>Notices</h1>

        <p class="muted">A banner across the top of the site. Give it an end date and it takes itself
           down — nothing has to run for that to happen, so it is on time even on a day nobody
           visited.</p>

        <p class="muted small"><strong>Not a private channel.</strong> "Approved accounts only" decides
           who is bothered by a message, not who is able to read one. Do not put anything here that
           would be damaging to see.</p>

        {$body}

        <h2>Add a notice</h2>
        <form method="post">
          <input type="hidden" name="_token" value="{$token}">
          <label>Heading <input type="text" name="title"></label>
          <label>Message <textarea name="body" rows="3" required></textarea></label>
          <label>Tone <select name="level">{$levels}</select></label>
          <label>Show it to <select name="audience">{$audiences}</select></label>
          <label>From <input type="datetime-local" name="starts_at"></label>
          <label>Until <input type="datetime-local" name="ends_at"></label>
          <label class="checkbox"><input type="checkbox" name="dismissible" value="1" checked>
            Let people dismiss it</label>
          <button class="btn" name="action" value="create">Add</button>
        </form>
        HTML;
    }

    private function announcementForm(
        \Portal\Content\Announcement $announcement,
        string $token,
        callable $forInput
    ): string {
        $levels = '';
        foreach (\Portal\Content\Announcement::levels() as $value => $label) {
            $levels .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                $value === $announcement->level ? ' selected' : '',
                e($label)
            );
        }

        $audiences = '';
        foreach (\Portal\Content\Announcement::audiences() as $value => $label) {
            $audiences .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($value),
                $value === $announcement->audience ? ' selected' : '',
                e($label)
            );
        }

        $title = $this->attr($announcement->title);
        $message = e($announcement->body);
        $starts = $this->attr($forInput($announcement->startsAt));
        $ends = $this->attr($forInput($announcement->endsAt));
        $dismissible = $announcement->dismissible ? ' checked' : '';
        $active = $announcement->isActive ? ' checked' : '';

        return <<<HTML
        <form method="post" class="home-row-form">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$announcement->id}">
          <label>Heading <input type="text" name="title" value="{$title}"></label>
          <label>Message <textarea name="body" rows="2" required>{$message}</textarea></label>
          <label>Tone <select name="level">{$levels}</select></label>
          <label>Show it to <select name="audience">{$audiences}</select></label>
          <label>From <input type="datetime-local" name="starts_at" value="{$starts}"></label>
          <label>Until <input type="datetime-local" name="ends_at" value="{$ends}"></label>
          <label class="checkbox"><input type="checkbox" name="dismissible" value="1"{$dismissible}>
            Dismissible</label>
          <label class="checkbox"><input type="checkbox" name="is_active" value="1"{$active}>
            Shown</label>
          <button class="btn tiny" name="action" value="update">Save</button>
          <button class="btn tiny danger" name="action" value="delete"
                  onclick="return confirm('Remove this notice?')">Remove</button>
        </form>
        HTML;
    }

    // ------------------------------------------------------------- speakers

    /** @param array<string, mixed> $data */
    private function speakers(array $data): string
    {
        $token = e((string) $data['token']);

        /** @var Speaker|null $editing */
        $editing = $data['editing'] ?? null;

        $rows = '';
        foreach ((array) ($data['speakers'] ?? []) as $speaker) {
            $count = $speaker->videoCount === 1 ? '1 video' : $speaker->videoCount . ' videos';

            $warning = $speaker->videoCount === 0
                ? 'Remove this speaker?'
                : sprintf(
                    'Remove this speaker? %d video%s will be kept but lose their speaker.',
                    $speaker->videoCount,
                    $speaker->videoCount === 1 ? '' : 's'
                );

            $rows .= sprintf(
                '<tr>
                   <td><strong>%s</strong><br><span class="muted">/speaker/%s — %s</span></td>
                   <td class="right">
                     <a class="btn tiny secondary" href="/admin/speakers?edit=%d">Edit</a>
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'%s\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                e($speaker->name),
                e($speaker->slug),
                e($count),
                $speaker->id,
                $token,
                $speaker->id,
                e($warning)
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">Nobody yet.</td></tr>';
        }

        $form = $editing === null
            ? <<<HTML
              <h2>Add a speaker</h2>
              <form method="post">
                <input type="hidden" name="_token" value="{$token}">
                <label>Name <input type="text" name="name" required></label>
                <label>Bio <textarea name="bio" rows="4"></textarea></label>
                <button class="btn" name="action" value="create">Add</button>
              </form>
              HTML
            : sprintf(
                '<h2>Edit %s</h2>
                 <form method="post">
                   <input type="hidden" name="_token" value="%s">
                   <input type="hidden" name="id" value="%d">
                   <label>Name <input type="text" name="name" value="%s" required></label>
                   <label>Address <input type="text" name="slug" value="%s"></label>
                   <label>Bio <textarea name="bio" rows="4">%s</textarea></label>
                   <label>Photo URL <input type="url" name="image_url" value="%s"></label>
                   <div class="actions">
                     <button class="btn" name="action" value="update">Save</button>
                     <a class="btn secondary" href="/admin/speakers">Cancel</a>
                   </div>
                 </form>',
                e($editing->name),
                $token,
                $editing->id,
                e($editing->name),
                e($editing->slug),
                e((string) ($editing->bio ?? '')),
                e((string) ($editing->imageUrl ?? ''))
            );

        return <<<HTML
        <h1>Speakers</h1>

        <p class="muted">Whoever is talking. Deliberately separate from user accounts: a guest from
           four years ago still needs a name under their video, and deleting a login should never
           erase attribution.</p>

        <div class="cols">
          <div>
            <table>
              <thead><tr><th>Name</th><th></th></tr></thead>
              <tbody>{$rows}</tbody>
            </table>
          </div>
          <div>{$form}</div>
        </div>
        HTML;
    }

    // ---------------------------------------------------------- permissions

    /**
     * Roles, groups, and scoped grants.
     *
     * One screen rather than three, because the question an admin actually
     * arrives with is "why can this person do that", and the answer can live in
     * any of the three. Splitting them would mean checking three pages to
     * answer one question.
     *
     * @param array<string, mixed> $data
     */
    private function permissions(array $data): string
    {
        $token = e((string) $data['token']);

        /** @var array<string, string> $capabilities */
        $capabilities = (array) ($data['capabilities'] ?? []);
        /** @var list<string> $siteOnly */
        $siteOnly = (array) ($data['siteOnly'] ?? []);

        $roles = '';
        foreach ((array) ($data['roles'] ?? []) as $role) {
            $roles .= $this->roleCard($role, $capabilities, $token);
        }

        $groups = '';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $groups .= $this->groupCard($group, $capabilities, $token);
        }

        $grantRows = '';
        foreach ((array) ($data['grants'] ?? []) as $grant) {
            $grantRows .= sprintf(
                '<tr>
                   <td>%s</td><td><code>%s</code></td><td>%s</td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="grant_id" value="%d">
                       <button name="action" value="revoke" class="btn tiny danger">Remove</button>
                     </form>
                   </td>
                 </tr>',
                e((string) $grant['subject']),
                e((string) $grant['capability']),
                e((string) $grant['scope']),
                $token,
                (int) $grant['id']
            );
        }

        if ($grantRows === '') {
            $grantRows = '<tr><td colspan="4" class="muted">No individual permissions granted.</td></tr>';
        }

        $capabilityOptions = '';
        foreach ($capabilities as $slug => $description) {
            $capabilityOptions .= sprintf(
                '<option value="%s"%s>%s — %s</option>',
                e($slug),
                in_array($slug, $siteOnly, true) ? ' data-site-only="1"' : '',
                e($slug),
                e($description)
            );
        }

        $scopeOptions = '<option value="site">The whole site</option>';
        foreach ((array) ($data['categories'] ?? []) as $category) {
            $scopeOptions .= sprintf(
                '<option value="category:%d">%sCategory: %s</option>',
                $category->id,
                str_repeat('&nbsp;&nbsp;', $category->depth),
                e($category->name)
            );
        }
        foreach ((array) ($data['seriesList'] ?? []) as $item) {
            $scopeOptions .= sprintf('<option value="series:%d">Series: %s</option>', $item->id, e($item->title));
        }

        $groupOptions = '';
        foreach ((array) ($data['groups'] ?? []) as $group) {
            $groupOptions .= sprintf('<option value="%d">%s</option>', (int) $group['id'], e((string) $group['name']));
        }

        return <<<HTML
        <h1>Permissions</h1>

        <p class="muted">Three ways somebody can hold a permission, checked in this order: their
           <strong>role</strong>, any <strong>group</strong> they are in, and finally an individual
           <strong>grant</strong>. A grant can be limited to one category or series, and is inherited
           by everything inside it.</p>

        <p class="muted small"><strong>Administrator is not on this screen by design.</strong> It is a
           role, never a permission, so nobody editing this page can make themselves one — including
           you. Change who is an administrator under <a href="/admin/users">People</a>.</p>

        <h2>Roles</h2>
        <div class="cards">{$roles}</div>

        <h2>Groups</h2>
        <p class="muted small">A named bundle of permissions with a list of email addresses.
           Membership is by address, so somebody can be given permissions before they have ever
           signed in.</p>

        <form method="post" class="toolbar">
          <input type="hidden" name="_token" value="{$token}">
          <input type="text" name="name" placeholder="New group name" required>
          <button class="btn secondary" name="action" value="group-create">Create group</button>
        </form>

        <div class="cards">{$groups}</div>

        <h2>Individual permissions</h2>

        <form method="post">
          <input type="hidden" name="_token" value="{$token}">
          <fieldset>
            <legend>Grant a permission</legend>

            <div class="cols">
              <div>
                <label>To
                  <select name="subject_type">
                    <option value="email">One person, by email</option>
                    <option value="group">Everyone in a group</option>
                    <option value="role">Everyone with a role</option>
                  </select>
                </label>
                <label>Email address <input type="email" name="email" placeholder="someone@example.com"></label>
                <label>Or pick a group <select name="subject_id"><option value="0">—</option>{$groupOptions}</select></label>
              </div>
              <div>
                <label>Permission <select name="capability">{$capabilityOptions}</select></label>
                <label>Limited to <select name="scope">{$scopeOptions}</select></label>
                <p class="muted small">Some permissions — managing plugins, themes, services, users,
                   or settings — only make sense site-wide, and are stored that way whatever is
                   chosen here.</p>
              </div>
            </div>

            <button class="btn" name="action" value="grant">Grant</button>
          </fieldset>
        </form>

        <table>
          <thead><tr><th>Who</th><th>May</th><th>Where</th><th></th></tr></thead>
          <tbody>{$grantRows}</tbody>
        </table>
        HTML;
    }

    /**
     * @param array<string, mixed>  $role
     * @param array<string, string> $capabilities
     */
    private function roleCard(array $role, array $capabilities, string $token): string
    {
        $name = e((string) $role['name']);
        $slug = (string) $role['slug'];
        $users = (int) $role['users'];
        $people = $users === 1 ? '1 person' : $users . ' people';

        // The administrator role holds everything implicitly and short-circuits
        // every check, so there is genuinely nothing here to edit — and a form
        // that appeared to work while doing nothing would be worse than none.
        if ($slug === 'admin') {
            return <<<HTML
            <div class="card">
              <h3>{$name} <span class="pill ok">system</span></h3>
              <p class="muted small">{$people}. Holds everything, always. Not editable — an
                 administrator cannot be partially disarmed, and pretending otherwise would be
                 misleading.</p>
            </div>
            HTML;
        }

        /** @var list<string> $held */
        $held = (array) $role['capabilities'];

        $boxes = '';
        foreach ($capabilities as $capability => $description) {
            $boxes .= sprintf(
                '<label class="checkbox" title="%s"><input type="checkbox" name="capabilities[]" value="%s"%s>%s</label>',
                e($description),
                e($capability),
                in_array($capability, $held, true) ? ' checked' : '',
                e($capability)
            );
        }

        return sprintf(
            '<div class="card">
               <h3>%s</h3>
               <p class="muted small">%s</p>
               <form method="post">
                 <input type="hidden" name="_token" value="%s">
                 <input type="hidden" name="role_id" value="%d">
                 %s
                 <button class="btn tiny" name="action" value="role">Save</button>
               </form>
             </div>',
            $name,
            e($people),
            $token,
            (int) $role['id'],
            $boxes
        );
    }

    /**
     * @param array<string, mixed>  $group
     * @param array<string, string> $capabilities
     */
    private function groupCard(array $group, array $capabilities, string $token): string
    {
        $id = (int) $group['id'];
        $name = e((string) $group['name']);

        /** @var list<string> $held */
        $held = (array) $group['capabilities'];

        $boxes = '';
        foreach ($capabilities as $capability => $description) {
            $boxes .= sprintf(
                '<label class="checkbox" title="%s"><input type="checkbox" name="capabilities[]" value="%s"%s>%s</label>',
                e($description),
                e($capability),
                in_array($capability, $held, true) ? ' checked' : '',
                e($capability)
            );
        }

        $members = '';
        foreach ((array) $group['members'] as $email) {
            $members .= sprintf(
                '<li>%s
                   <form method="post" class="inline">
                     <input type="hidden" name="_token" value="%s">
                     <input type="hidden" name="group_id" value="%d">
                     <input type="hidden" name="email" value="%s">
                     <button name="action" value="group-remove-member" class="btn tiny danger">Remove</button>
                   </form>
                 </li>',
                e((string) $email),
                $token,
                $id,
                e((string) $email)
            );
        }

        if ($members === '') {
            $members = '<li class="muted">Nobody yet.</li>';
        }

        return <<<HTML
        <div class="card">
          <h3>{$name}</h3>

          <form method="post">
            <input type="hidden" name="_token" value="{$token}">
            <input type="hidden" name="group_id" value="{$id}">
            {$boxes}
            <button class="btn tiny" name="action" value="group-capabilities">Save permissions</button>
          </form>

          <ul class="plain">{$members}</ul>

          <form method="post" class="toolbar">
            <input type="hidden" name="_token" value="{$token}">
            <input type="hidden" name="group_id" value="{$id}">
            <input type="email" name="email" placeholder="add someone@example.com" required>
            <button class="btn tiny secondary" name="action" value="group-add-member">Add</button>
          </form>

          <form method="post">
            <input type="hidden" name="_token" value="{$token}">
            <input type="hidden" name="group_id" value="{$id}">
            <button name="action" value="group-delete" class="btn tiny danger"
                    onclick="return confirm('Delete this group? Everyone in it loses whatever it granted.')">
              Delete group
            </button>
          </form>
        </div>
        HTML;
    }

    // ---------------------------------------------------------------- users

    /** @param array<string, mixed> $data */
    private function users(array $data): string
    {
        $token = e((string) $data['token']);

        $roleOptions = '';
        foreach ((array) ($data['roles'] ?? []) as $role) {
            $roleOptions .= sprintf(
                '<option value="%s">%s</option>',
                e((string) $role['slug']),
                e((string) $role['name'])
            );
        }

        $rows = '';
        foreach ((array) ($data['users'] ?? []) as $user) {
            $authorized = (int) $user['authorized'] === 1;

            $selected = str_replace(
                'value="' . e((string) ($user['role_slug'] ?? '')) . '"',
                'value="' . e((string) ($user['role_slug'] ?? '')) . '" selected',
                $roleOptions
            );

            $rows .= sprintf(
                '<tr>
                   <td><strong>%s</strong><br><span class="muted">%s</span></td>
                   <td>%s</td>
                   <td>
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <select name="role">%s</select>
                       <button name="action" value="role" class="btn tiny">Set</button>
                     </form>
                   </td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="%s" class="btn tiny%s">%s</button>
                     </form>
                   </td>
                 </tr>',
                e((string) ($user['name'] ?? $user['email'])),
                e((string) $user['email']),
                $authorized ? '<span class="pill ok">Approved</span>' : '<span class="pill warn">Pending</span>',
                $token,
                (int) $user['id'],
                $selected,
                $token,
                (int) $user['id'],
                $authorized ? 'revoke' : 'authorize',
                $authorized ? ' danger' : '',
                $authorized ? 'Remove access' : 'Approve'
            );
        }

        return <<<HTML
        <h1>People</h1>
        <p class="muted">Signing in proves who someone is. It grants nothing until you approve them here.</p>
        <table>
          <thead><tr><th>Person</th><th>Access</th><th>Role</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    // -------------------------------------------------------------- plugins

    /** @param array<string, mixed> $data */
    private function plugins(array $data): string
    {
        $token = e((string) $data['token']);

        $rows = '';
        foreach ((array) ($data['plugins'] ?? []) as $plugin) {
            $state = $plugin['active']
                ? '<span class="pill ok">Active</span>'
                : '<span class="pill">Inactive</span>';

            if ($plugin['missing']) {
                $state = '<span class="pill bad">Files missing</span>';
            } elseif ($plugin['incompatible'] !== null) {
                $state = '<span class="pill bad">' . e((string) $plugin['incompatible']) . '</span>';
            }

            $action = $plugin['active'] ? 'deactivate' : 'activate';
            $label = $plugin['active'] ? 'Deactivate' : 'Activate';

            $rows .= sprintf(
                '<tr>
                   <td><strong>%s</strong> <span class="muted">%s</span><br><span class="muted">%s</span></td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="slug" value="%s">
                       <button name="action" value="%s" class="btn tiny">%s</button>
                       <button name="action" value="uninstall" class="btn tiny danger"
                               onclick="return confirm(\'Uninstall? This removes the plugin\\\'s data permanently.\')">Uninstall</button>
                     </form>
                   </td>
                 </tr>',
                e((string) $plugin['name']),
                e((string) $plugin['version']),
                e((string) $plugin['description']),
                $state,
                $token,
                e((string) $plugin['slug']),
                $action,
                $label
            );
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="muted">No plugins are installed.</td></tr>';
        }

        $installer = $this->packageForm($data, 'plugin', '/admin/plugins/install');

        return <<<HTML
        <h1>Plugins</h1>
        <p class="muted">Deactivating keeps a plugin's data, so turning it back on restores everything.
           Uninstalling removes its data permanently.</p>
        {$installer}
        <table>
          <thead><tr><th>Plugin</th><th>Status</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
        HTML;
    }

    /**
     * The install-from-a-file form, shared by plugins and themes.
     *
     * Says out loud what installing a package means. Everyone knows a plugin is
     * code; not everyone has connected that to "this runs on my site with the
     * same access the site has", and an admin about to install something from a
     * forum post is exactly who needs the reminder.
     *
     * @param array<string, mixed> $data
     */
    private function packageForm(array $data, string $kind, string $action): string
    {
        $token = e((string) $data['token']);

        if (empty($data['uploadsAllowed'])) {
            return <<<HTML
            <fieldset>
              <legend>Install from a file</legend>
              <p class="muted small">Switched off on this site. Remove
                 <code>allow_package_uploads</code> from <code>config.php</code> to turn it back on,
                 or upload the folder over FTP.</p>
            </fieldset>
            HTML;
        }

        $folder = $kind === 'plugin' ? 'plugins/' : 'themes/';

        return <<<HTML
        <fieldset>
          <legend>Install from a file</legend>
          <form method="post" action="{$action}" enctype="multipart/form-data" class="toolbar">
            <input type="hidden" name="_token" value="{$token}">
            <input type="file" name="package" accept=".zip,application/zip" required>
            <button class="btn secondary">Install</button>
          </form>
          <p class="muted small">A .zip containing one folder. Uploading a {$kind} that is already
             installed replaces its files and keeps its settings.</p>
          <p class="muted small"><strong>A {$kind} is code that runs on this site</strong>, with the same
             access this site has to your database and your video service. Install them from sources you
             trust, exactly as you would anything else you run on a server. You can also copy the folder
             into <code>{$folder}</code> over FTP and skip this form entirely.</p>
        </fieldset>
        HTML;
    }

    // --------------------------------------------------------------- themes

    /** @param array<string, mixed> $data */
    private function themes(array $data): string
    {
        $token = e((string) $data['token']);
        $settings = (array) ($data['settings'] ?? []);

        $cards = '';
        foreach ((array) ($data['themes'] ?? []) as $theme) {
            $badge = $theme['active'] ? '<span class="pill ok">Active</span>' : '';

            $button = $theme['active']
                ? ''
                : sprintf(
                    '<form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="slug" value="%s">
                       <button name="action" value="activate" class="btn tiny">Activate</button>
                     </form>',
                    $token,
                    e((string) $theme['slug'])
                );

            $warning = $theme['parentMissing']
                ? '<p class="pill bad">Parent theme "' . e((string) $theme['parent']) . '" is not installed</p>'
                : '';

            $cards .= sprintf(
                '<div class="card"><h3>%s %s</h3><p class="muted">%s</p>%s%s</div>',
                e((string) $theme['name']),
                $badge,
                e((string) $theme['description']),
                $warning,
                $button
            );
        }

        // Customizer fields, rendered from the theme's own declared schema.
        $sections = '';
        foreach ((array) ($data['customizer'] ?? []) as $section) {
            if (!is_array($section) || !isset($section['settings'])) {
                continue;
            }

            $fields = '';
            foreach ((array) $section['settings'] as $key => $definition) {
                $fields .= $this->customizerField((string) $key, (array) $definition, $settings);
            }

            $sections .= sprintf(
                '<fieldset><legend>%s</legend>%s</fieldset>',
                e((string) ($section['label'] ?? '')),
                $fields
            );
        }

        $installer = $this->packageForm($data, 'theme', '/admin/themes/install');

        return <<<HTML
        <h1>Appearance</h1>
        {$installer}
        <div class="cards">{$cards}</div>

        <h2>Customize</h2>
        <form method="post">
          <input type="hidden" name="_token" value="{$token}">
          {$sections}
          <button class="btn" name="action" value="customize">Save appearance</button>
        </form>
        HTML;
    }

    /**
     * @param array<string, mixed>  $definition
     * @param array<string, mixed>  $settings
     */
    private function customizerField(string $key, array $definition, array $settings): string
    {
        $type = (string) ($definition['type'] ?? 'text');
        $label = (string) ($definition['label'] ?? $key);
        $help = (string) ($definition['help'] ?? '');
        $value = (string) ($settings[$key] ?? ($definition['default'] ?? ''));
        $name = 'settings[' . e($key) . ']';

        $input = match ($type) {
            'color' => sprintf(
                '<input type="color" name="%s" value="%s"><input type="text" name="%s" value="%s" class="hexbox">',
                $name,
                e($value),
                $name,
                e($value)
            ),
            'bool' => sprintf(
                '<input type="checkbox" name="%s" value="1"%s>',
                $name,
                $value === '1' ? ' checked' : ''
            ),
            'select' => (function () use ($name, $definition, $value): string {
                $options = '';
                foreach ((array) ($definition['choices'] ?? []) as $choice) {
                    $options .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        e((string) $choice),
                        (string) $choice === $value ? ' selected' : '',
                        e((string) $choice)
                    );
                }
                return sprintf('<select name="%s">%s</select>', $name, $options);
            })(),
            'number' => sprintf('<input type="number" name="%s" value="%s">', $name, e($value)),
            'url'    => sprintf('<input type="url" name="%s" value="%s">', $name, e($value)),
            default  => sprintf('<input type="text" name="%s" value="%s">', $name, e($value)),
        };

        $helpHtml = $help !== '' ? '<span class="muted small">' . e($help) . '</span>' : '';

        return sprintf('<label>%s %s %s</label>', e($label), $input, $helpHtml);
    }

    // ------------------------------------------------------------ providers

    /** @param array<string, mixed> $data */
    private function providers(array $data): string
    {
        $token = e((string) $data['token']);
        $sections = '';

        foreach ((array) ($data['kinds'] ?? []) as $kind => $info) {
            $options = '';
            foreach ((array) $info['options'] as $option) {
                $disabled = $option['missingExtensions'] !== [] ? ' disabled' : '';
                $suffix = $option['missingExtensions'] !== []
                    ? ' — needs ' . implode(', ', $option['missingExtensions'])
                    : '';

                $options .= sprintf(
                    '<option value="%s"%s%s>%s%s</option>',
                    e((string) $option['slug']),
                    $option['slug'] === $info['active'] ? ' selected' : '',
                    $disabled,
                    e((string) $option['label']),
                    e($suffix)
                );
            }

            $fields = '';
            /** @var list<SettingField> $declared */
            $declared = $info['fields'];
            foreach ($declared as $field) {
                $stored = (array) $info['values'];
                $isSet = isset($stored[$field->key]) && $stored[$field->key] !== '';

                // A stored secret is never sent back to the browser. The
                // placeholder says it exists; leaving the box empty keeps it.
                $value = $field->isSecret() ? '' : (string) ($stored[$field->key] ?? '');
                $placeholder = $field->isSecret() && $isSet ? ' placeholder="•••••••• (unchanged)"' : '';

                $inputType = match ($field->type) {
                    SettingField::TYPE_SECRET => 'password',
                    SettingField::TYPE_EMAIL  => 'email',
                    SettingField::TYPE_URL    => 'url',
                    SettingField::TYPE_NUMBER => 'number',
                    default                   => 'text',
                };

                $fields .= sprintf(
                    '<label>%s <input type="%s" name="credentials[%s]" value="%s"%s>
                       <span class="muted small">%s</span></label>',
                    e($field->label),
                    $inputType,
                    e($field->key),
                    e($value),
                    $placeholder,
                    e($field->help)
                );
            }

            $sections .= sprintf(
                '<fieldset>
                   <legend>%s</legend>
                   <form method="post">
                     <input type="hidden" name="_token" value="%s">
                     <input type="hidden" name="kind" value="%s">
                     <label>Service <select name="slug" onchange="this.form.submit()">%s</select></label>
                     %s
                     <div class="actions">
                       <button class="btn secondary" name="action" value="test">Test</button>
                       <button class="btn" name="action" value="activate">Save and use this</button>
                     </div>
                   </form>
                 </fieldset>',
                e(ucfirst((string) $kind)),
                $token,
                e((string) $kind),
                $options,
                $fields
            );
        }

        return <<<HTML
        <h1>Services</h1>
        <p class="muted">Switching a service runs its own connection test first. If the test fails,
           nothing changes — a service that is not working now would otherwise fail silently later.</p>
        {$sections}
        HTML;
    }

    // ------------------------------------------------------------- settings

    /** @param array<string, mixed> $data */
    private function settings(array $data): string
    {
        $token = e((string) $data['token']);
        $settings = (array) ($data['settings'] ?? []);
        $geo = (array) ($data['geo'] ?? []);

        $zones = '';
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $zones .= sprintf(
                '<option value="%s"%s>%s</option>',
                e($zone),
                ($settings['timezone'] ?? 'UTC') === $zone ? ' selected' : '',
                e($zone)
            );
        }

        $jobs = '';
        foreach ((array) ($data['cronJobs'] ?? []) as $job) {
            $jobs .= sprintf(
                '<tr><td>%s</td><td class="muted">%s</td><td class="muted">%s</td><td class="muted">%s</td></tr>',
                e((string) $job['slug']),
                e((string) ($job['last_run_at'] ?? 'never')),
                e((string) ($job['last_status'] ?? '—')),
                e((string) ($job['next_run_at'] ?? '—'))
            );
        }

        $geoList = static fn (array $list): string => $list === []
            ? '<span class="muted">not set</span>'
            : e(implode(', ', $list));

        $checked = static fn (string $key): string => in_array(
            (string) ($settings[$key] ?? '0'),
            ['1', 'true', 'on', 'yes'],
            true
        ) ? ' checked' : '';

        $membersDefault = $checked('members_thumbnail_default');
        $allowIndexing = $checked('allow_indexing');
        $podcastExplicit = $checked('podcast_explicit');
        $subscriptionsEnabled = $checked('subscriptions_enabled');
        $subscriberCount = (int) ($data['subscriberCount'] ?? 0);

        return <<<HTML
        <h1>Settings</h1>

        <form method="post">
          <input type="hidden" name="_token" value="{$token}">
          <label>Site name <input type="text" name="site_name" value="{$this->attr($settings['site_name'] ?? '')}"></label>
          <label>Timezone <select name="timezone">{$zones}</select></label>

          <label class="checkbox">
            <input type="checkbox" name="members_thumbnail_default" value="1"{$membersDefault}>
            Withhold thumbnails from anyone who cannot watch
          </label>
          <p class="muted small">The starting point for every video and category. Anyone signed out or
             not yet approved sees a "Members only" placeholder instead of the artwork — the image URL
             is never sent to them. Individual categories and videos can override this either way.</p>

          <fieldset>
            <legend>Search engines</legend>
            <label class="checkbox">
              <input type="checkbox" name="allow_indexing" value="1"{$allowIndexing}>
              Let search engines index this site
            </label>
            <p class="muted small">Off by default. While it is off every page sends
               <code>noindex</code>, <code>robots.txt</code> refuses everything, and
               <code>/sitemap.xml</code> is not served — so the three can never disagree. Turning it on
               lists only content that is already public; drafts, hidden videos, members-only content,
               and share links are never included either way.</p>
          </fieldset>

          <fieldset>
            <legend>Email about new videos</legend>
            <label class="checkbox">
              <input type="checkbox" name="subscriptions_enabled" value="1"{$subscriptionsEnabled}>
              Let people subscribe to new videos
            </label>
            <p class="muted small">Adds a subscribe box to the library and to every category, series,
               and speaker page. No account is needed to subscribe, and every email carries an
               unsubscribe link that works in one tap. Only public videos are ever announced —
               members-only content is not news to a list anybody can join.</p>
            <p class="muted small">{$subscriberCount} subscriber(s). Sending needs an email service
               configured under <a href="/admin/providers">Services</a>.</p>
          </fieldset>

          <fieldset>
            <legend>Podcast feed</legend>
            <p class="muted small">Your feed is at <code>/podcast</code>, and there is one per category,
               series, and playlist — for example <code>/podcast/series/advent</code>. Only public
               content appears in them. Episodes link back here rather than straight to the video file,
               so the download is signed fresh each time and unpublishing something actually withdraws
               it.</p>

            <label>Author shown in podcast apps
              <input type="text" name="podcast_author" value="{$this->attr($settings['podcast_author'] ?? '')}">
            </label>
            <label>Owner name
              <input type="text" name="podcast_owner_name" value="{$this->attr($settings['podcast_owner_name'] ?? '')}">
            </label>
            <label>Owner email
              <input type="email" name="podcast_owner_email" value="{$this->attr($settings['podcast_owner_email'] ?? '')}">
            </label>
            <p class="muted small">Apple requires an owner name and address before it will accept a
               submission. Neither is shown to listeners.</p>

            <label>Artwork URL
              <input type="url" name="podcast_image_url" value="{$this->attr($settings['podcast_image_url'] ?? '')}">
            </label>
            <p class="muted small">A square image, at least 1400×1400. Directories reject a feed without
               one.</p>

            <label>Category
              <input type="text" name="podcast_category" value="{$this->attr($settings['podcast_category'] ?? '')}">
            </label>

            <label class="checkbox">
              <input type="checkbox" name="podcast_explicit" value="1"{$podcastExplicit}>
              Mark this podcast as explicit
            </label>
          </fieldset>

          <button class="btn">Save</button>
        </form>

        <h2>Scheduled tasks</h2>
        <p class="muted">These run automatically on ordinary page visits. For a more reliable schedule,
           point your host's cron at <code>{$this->attr($data['baseUrl'] ?? '')}/cron?key=…</code> —
           the key is in config.php.</p>
        <table>
          <thead><tr><th>Task</th><th>Last run</th><th>Result</th><th>Next</th></tr></thead>
          <tbody>{$jobs}</tbody>
        </table>

        <h2>Move this site's setup elsewhere</h2>
        <p class="muted">Exports the settings on this page, your theme customisations, and which plugins
           are switched on. <strong>Credentials are deliberately left out</strong> — they are encrypted
           with this install's key and would not decrypt anywhere else, so exporting them would produce
           a file that is both a liability and useless.</p>
        <div class="actions">
          <a class="btn secondary" href="/admin/settings/export">Download settings</a>
        </div>
        <form method="post" action="/admin/settings/import" enctype="multipart/form-data" class="toolbar">
          <input type="hidden" name="_token" value="{$token}">
          <input type="file" name="settings" accept=".json,application/json" required>
          <button class="btn secondary">Import</button>
        </form>
        <p class="muted small">Importing applies theme customisations to whichever theme is active here,
           not the one named in the file — bringing settings across should never silently change how the
           site looks by switching to a theme that may not be installed.</p>

        <h2>Country restrictions</h2>
        <p class="muted">These live in config.php and cannot be edited here on purpose: whitelisting
           the wrong country locks you out of this screen, and recovery has to be possible over FTP.</p>
        <ul class="plain">
          <li>Viewers: {$geoList($geo['viewers'] ?? [])}</li>
          <li>Admin: {$geoList($geo['admin'] ?? [])}</li>
          <li>Always allowed: {$geoList($geo['bypass'] ?? [])}</li>
        </ul>
        HTML;
    }

    private function attr(mixed $value): string
    {
        return e((string) $value);
    }

    private function css(): string
    {
        return <<<'CSS'
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin:0; background:#0b1220; color:#e2e8f0;
               font:15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Inter, sans-serif; }
        a { color:#38bdf8; text-decoration:none; }
        a:hover { text-decoration:underline; }
        .bar { display:flex; align-items:center; gap:1.25rem; padding:0 1.5rem; min-height:3.5rem;
               background:#0f172a; border-bottom:1px solid rgba(148,163,184,.16); flex-wrap:wrap; }
        .brand { font-weight:650; color:#e2e8f0; }
        .bar nav { display:flex; gap:1rem; flex-wrap:wrap; }
        .bar nav a { color:#94a3b8; font-size:.9375rem; }
        .bar nav a.active, .bar nav a:hover { color:#e2e8f0; text-decoration:none; }
        .spacer { flex:1; }
        .muted { color:#94a3b8; }
        .small { font-size:.8125rem; }
        main { max-width:64rem; margin:0 auto; padding:2rem 1.5rem 4rem; }
        h1 { font-size:1.5rem; margin:0 0 1.5rem; font-weight:650; letter-spacing:-.01em; }
        h2 { font-size:1.125rem; margin:2.5rem 0 1rem; font-weight:600; }
        h3 { font-size:1rem; margin:0 0 .375rem; font-weight:600; }
        table { width:100%; border-collapse:collapse; margin:1rem 0; }
        th, td { text-align:left; padding:.75rem .5rem; border-bottom:1px solid rgba(148,163,184,.14);
                 vertical-align:top; }
        th { font-size:.8125rem; text-transform:uppercase; letter-spacing:.04em; color:#94a3b8; font-weight:600; }
        td.right, th.right { text-align:right; }
        label { display:block; margin-bottom:1rem; font-size:.875rem; font-weight:550; }
        input, select, textarea { width:100%; margin-top:.375rem; padding:.5rem .75rem; border-radius:8px;
                border:1px solid rgba(148,163,184,.26); background:rgba(15,23,42,.6); color:#e2e8f0;
                font:inherit; font-size:.9375rem; }
        input[type="checkbox"], input[type="color"] { width:auto; }
        .hexbox { width:8rem; display:inline-block; margin-left:.5rem; }
        .btn { display:inline-block; padding:.5rem 1.125rem; border-radius:8px; border:1px solid transparent;
               background:#38bdf8; color:#0b1220; font:inherit; font-weight:600; font-size:.9375rem;
               cursor:pointer; }
        .btn.secondary { background:transparent; border-color:rgba(148,163,184,.3); color:#e2e8f0; }
        .btn.tiny { padding:.25rem .625rem; font-size:.8125rem; }
        .btn.danger { background:transparent; border-color:rgba(239,68,68,.5); color:#fca5a5; }
        form.inline { display:inline-flex; gap:.375rem; align-items:center; margin:0; }
        form.inline select { width:auto; margin:0; }
        .toolbar { display:flex; gap:.75rem; align-items:center; margin-bottom:1rem; }
        .toolbar input { margin:0; }
        .pill { display:inline-block; padding:.125rem .5rem; border-radius:999px; font-size:.75rem;
                border:1px solid rgba(148,163,184,.3); color:#94a3b8; }
        .pill.ok { border-color:rgba(34,197,94,.45); color:#4ade80; }
        .pill.warn { border-color:rgba(245,158,11,.45); color:#fbbf24; }
        .pill.bad { border-color:rgba(239,68,68,.45); color:#fca5a5; }
        .tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(9rem,1fr)); gap:1rem; }
        .tile { background:#0f172a; border:1px solid rgba(148,163,184,.16); border-radius:12px; padding:1.25rem; }
        .tile.warn { border-color:rgba(245,158,11,.45); }
        .tile .n { display:block; font-size:1.75rem; font-weight:650; }
        .tile .l { display:block; color:#94a3b8; font-size:.8125rem; }
        .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(16rem,1fr)); gap:1rem; }
        .card { background:#0f172a; border:1px solid rgba(148,163,184,.16); border-radius:12px; padding:1.25rem; }
        .cols { display:grid; grid-template-columns:2fr 1fr; gap:2rem; }
        @media (max-width:48rem) { .cols { grid-template-columns:1fr; } }
        fieldset { border:1px solid rgba(148,163,184,.2); border-radius:12px; padding:1.25rem; margin:0 0 1.5rem; }
        legend { padding:0 .5rem; font-weight:600; }
        .flash { padding:.75rem 1.125rem; border-radius:9px; margin-bottom:1.5rem; font-size:.9375rem;
                 border:1px solid rgba(34,197,94,.5); background:rgba(34,197,94,.08); }
        .flash.error { border-color:rgba(239,68,68,.5); background:rgba(239,68,68,.08); }
        ul.plain { list-style:none; padding:0; }
        ul.plain li { padding:.375rem 0; border-bottom:1px solid rgba(148,163,184,.1);
                      display:flex; justify-content:space-between; align-items:center; gap:.5rem; }
        textarea { width:100%; margin-top:.375rem; padding:.5rem .75rem; border-radius:8px;
                   border:1px solid rgba(148,163,184,.26); background:rgba(15,23,42,.6);
                   color:#e2e8f0; font:inherit; font-size:.9375rem; resize:vertical; }
        select[multiple] { height:auto; }
        label.checkbox { display:flex; align-items:center; gap:.5rem; font-weight:400; }
        label.checkbox input { width:auto; margin:0; }
        /* Share URLs are meant to be copied, so they are a selectable input
           rather than text: click selects the whole thing. */
        .urlbox { margin-top:.375rem; font-size:.75rem; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
                  color:#7dd3fc; background:rgba(15,23,42,.75); cursor:pointer; }
        code { background:rgba(15,23,42,.8); padding:.125rem .375rem; border-radius:5px; font-size:.875rem; }
        .actions { display:flex; gap:.75rem; margin-top:1rem; }

        /* ---------------------------------------------------------- uploads */
        .dropzone { border:1.5px dashed rgba(148,163,184,.35); border-radius:12px; padding:1.5rem;
                    text-align:center; transition:border-color .15s, background .15s; }
        .dropzone.is-over { border-color:#38bdf8; background:rgba(56,189,248,.06); }
        .dropzone p { margin:0 0 .5rem; }
        .dropzone p:last-child { margin-bottom:0; }
        .linklike { color:#38bdf8; cursor:pointer; text-decoration:underline; font-weight:inherit;
                    display:inline; margin:0; }
        .upload-list { list-style:none; padding:0; margin:1rem 0 0; }
        .upload-row { display:grid; grid-template-columns:1fr auto; gap:.375rem .75rem;
                      align-items:center; padding:.75rem 0;
                      border-bottom:1px solid rgba(148,163,184,.14); }
        .upload-name { font-weight:550; overflow-wrap:anywhere; }
        .upload-actions { grid-row:1 / span 2; align-self:center; }
        .upload-status { grid-column:1; }
        /* Full width under both columns, so the bar is readable at any length. */
        .upload-track { grid-column:1 / -1; height:4px; border-radius:2px;
                        background:rgba(148,163,184,.2); overflow:hidden; }
        .upload-bar { display:block; height:100%; width:0; background:#38bdf8;
                      transition:width .2s ease-out; }
        .upload-row.is-done .upload-bar { background:#22c55e; }
        .upload-row.is-error .upload-bar { background:#ef4444; }
        CSS;
    }
}
