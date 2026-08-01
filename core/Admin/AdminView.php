<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Auth\Guard;
use Portal\Content\Category;
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
            'categories'    => $this->categories($data),
            'category-edit' => $this->categoryEdit($data),
            'users'      => $this->users($data),
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

        return <<<HTML
        <h1>Videos <span class="muted">({$total})</span></h1>
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
        $published = $video->isPublished
            ? '<span class="pill ok">Published</span>'
            : '<span class="pill">Draft</span>';

        return <<<HTML
        <p class="muted small"><a href="/admin/videos">&larr; All videos</a></p>
        <h1>{$title} {$published}</h1>

        <form method="post" action="/admin/videos">
          <input type="hidden" name="_token" value="{$token}">
          <input type="hidden" name="id" value="{$video->id}">

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
        HTML;
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
            $rows = '<tr><td colspan="3" class="muted">No plugins are installed. '
                . 'Add one by copying its folder into plugins/.</td></tr>';
        }

        return <<<HTML
        <h1>Plugins</h1>
        <p class="muted">Deactivating keeps a plugin's data, so turning it back on restores everything.
           Uninstalling removes its data permanently.</p>
        <table>
          <thead><tr><th>Plugin</th><th>Status</th><th></th></tr></thead>
          <tbody>{$rows}</tbody>
        </table>
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

        return <<<HTML
        <h1>Appearance</h1>
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

        $membersDefault = in_array(
            (string) ($settings['members_thumbnail_default'] ?? '0'),
            ['1', 'true', 'on', 'yes'],
            true
        ) ? ' checked' : '';

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
