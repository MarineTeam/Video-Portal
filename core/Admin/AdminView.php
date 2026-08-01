<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Auth\Guard;
use Portal\Content\Category;
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
            'videos'     => $this->videos($data),
            'categories' => $this->categories($data),
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
                   <td><strong>%s</strong><br><span class="muted">%s</span></td>
                   <td>%s</td>
                   <td>%s</td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="%s" class="btn tiny">%s</button>
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'Move this video to trash?\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                e($video->title),
                e(Str::duration($video->duration) ?: '—'),
                $status,
                $published,
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

        return <<<HTML
        <h1>Videos <span class="muted">({$total})</span></h1>
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

            $rows .= sprintf(
                '<tr>
                   <td>%s<strong>%s</strong> %s<br><span class="muted">/category/%s</span></td>
                   <td class="right">
                     <form method="post" class="inline">
                       <input type="hidden" name="_token" value="%s">
                       <input type="hidden" name="id" value="%d">
                       <button name="action" value="delete" class="btn tiny danger"
                               onclick="return confirm(\'Delete this category? Videos in it are kept.\')">Delete</button>
                     </form>
                   </td>
                 </tr>',
                $indent,
                e($category->name),
                $imported,
                e($category->slug),
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

        return <<<HTML
        <h1>Settings</h1>

        <form method="post">
          <input type="hidden" name="_token" value="{$token}">
          <label>Site name <input type="text" name="site_name" value="{$this->attr($settings['site_name'] ?? '')}"></label>
          <label>Timezone <select name="timezone">{$zones}</select></label>
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
        CSS;
    }
}
