<?php

declare(strict_types=1);

namespace Portal\Install;

use Portal\Providers\SettingField;

/**
 * The installer's HTML.
 *
 * Entirely self-contained — no theme, no database, no plugins. It has to render
 * on a server where nothing is configured yet, which is precisely when a themed
 * page would fail and leave someone staring at a blank screen.
 */
final class InstallerView
{
    /** @param array<string, mixed> $data */
    public function render(string $step, array $data = []): string
    {
        $body = match ($step) {
            Installer::STEP_REQUIREMENTS => $this->requirements($data),
            Installer::STEP_DATABASE     => $this->database($data),
            Installer::STEP_SITE         => $this->site($data),
            Installer::STEP_PROVIDERS    => $this->providers($data),
            Installer::STEP_ADMIN        => $this->admin($data),
            Installer::STEP_FINISH       => $this->finish($data),
            default                      => '<p>Unknown step.</p>',
        };

        return $this->layout($step, $body, $data);
    }

    /** @param array<string, mixed> $data */
    private function layout(string $step, string $body, array $data): string
    {
        $steps = '';
        $reached = true;
        foreach (Installer::steps() as $candidate) {
            $isCurrent = $candidate === $step;
            $class = $isCurrent ? 'current' : ($reached ? 'done' : '');
            if ($isCurrent) {
                $reached = false;
            }
            $steps .= sprintf(
                '<li class="%s">%s</li>',
                $class,
                e(Installer::stepLabel($candidate))
            );
        }

        $error = '';
        if (!empty($data['error'])) {
            $error = '<div class="notice error"><strong>' . e((string) $data['error']) . '</strong>';
            if (!empty($data['errorDetail'])) {
                $error .= '<pre>' . e((string) $data['errorDetail']) . '</pre>';
            }
            $error .= '</div>';
        }

        $css = $this->css();

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>Install Video Portal</title>
        <style>{$css}</style>
        </head>
        <body>
        <div class="shell">
          <header>
            <h1>Video Portal</h1>
            <ol class="steps">{$steps}</ol>
          </header>
          <main class="card">
            {$error}
            {$body}
          </main>
          <footer>Version {$this->version()}</footer>
        </div>
        </body>
        </html>
        HTML;
    }

    private function version(): string
    {
        return e(PORTAL_VERSION);
    }

    // ---------------------------------------------------------------- steps

    /** @param array<string, mixed> $data */
    private function requirements(array $data): string
    {
        /** @var list<Requirement> $checks */
        $checks = $data['checks'] ?? [];
        $canProceed = (bool) ($data['canProceed'] ?? false);

        $rows = '';
        foreach ($checks as $check) {
            /*
             * isWarning() rather than "not blocking", which is the same answer
             * spelled a second way. Requirement already names both states, and
             * a rule written once here and once there is a rule that can drift
             * — the drift showing up as a missing extension rendered as a tick.
             */
            $state = $check->satisfied ? 'pass' : ($check->isWarning() ? 'warn' : 'fail');
            $icon = $check->satisfied ? '&check;' : ($check->isWarning() ? '!' : '&times;');

            $rows .= '<li class="' . $state . '">';
            $rows .= '<span class="icon">' . $icon . '</span>';
            $rows .= '<span class="body"><strong>' . e($check->label) . '</strong>';
            if ($check->detail !== '') {
                $rows .= '<span class="detail">' . e($check->detail) . '</span>';
            }
            if (!$check->satisfied && $check->fix !== '') {
                $rows .= '<span class="fix">' . e($check->fix) . '</span>';
            }
            $rows .= '</span></li>';
        }

        $action = $canProceed
            ? '<button class="btn" type="submit">Continue</button>'
            : '<p class="blocked">Fix the items marked in red, then reload this page.</p>
               <a class="btn secondary" href="?step=requirements">Check again</a>';

        return <<<HTML
        <h2>Can this server run Video Portal?</h2>
        <p class="lead">Checked before anything else, so nothing fails halfway through setup.</p>
        <ul class="checks">{$rows}</ul>
        <form method="post">
          <input type="hidden" name="step" value="requirements">
          {$action}
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function database(array $data): string
    {
        $values = $data['values'] ?? [];
        $test = $data['test'] ?? null;

        $testBlock = '';
        if (is_array($test)) {
            $class = $test['ok'] ? 'success' : 'error';
            $testBlock = '<div class="notice ' . $class . '">' . e((string) $test['message']);
            if (!empty($test['detail'])) {
                $testBlock .= '<span class="detail">' . e((string) $test['detail']) . '</span>';
            }
            $testBlock .= '</div>';
        }

        $continue = (is_array($test) && $test['ok'])
            ? '<button class="btn" type="submit" name="action" value="continue">Continue</button>'
            : '';

        return <<<HTML
        <h2>Database</h2>
        <p class="lead">Create an empty MySQL or MariaDB database in your hosting control panel,
           then enter its details here.</p>
        {$testBlock}
        <form method="post">
          <input type="hidden" name="step" value="database">
          {$this->field('db_host', 'Host', $values, 'text', 'Almost always localhost or 127.0.0.1.')}
          {$this->field('db_port', 'Port', $values, 'number', 'Leave at 3306 unless your host says otherwise.')}
          {$this->field('db_name', 'Database name', $values, 'text', 'Many hosts prefix this with your account name.')}
          {$this->field('db_user', 'Username', $values, 'text')}
          {$this->field('db_pass', 'Password', $values, 'password')}
          {$this->field('db_prefix', 'Table prefix', $values, 'text', 'Optional. Use one if this database is shared with another application.')}
          <div class="actions">
            <button class="btn secondary" type="submit" name="action" value="test">Test connection</button>
            {$continue}
          </div>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function site(array $data): string
    {
        $values = $data['values'] ?? [];
        $zones = '';
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            $selected = ($values['timezone'] ?? 'UTC') === $zone ? ' selected' : '';
            $zones .= '<option value="' . e($zone) . '"' . $selected . '>' . e($zone) . '</option>';
        }

        return <<<HTML
        <h2>Your site</h2>
        <p class="lead">The address below is used to build every link in every email this site sends.
           It is stored in config.php and never taken from the browser, because a request header
           can be forged.</p>
        <form method="post">
          <input type="hidden" name="step" value="site">
          {$this->field('site_name', 'Site name', $values, 'text', 'Shown in the header and in email subject lines.')}
          {$this->field('base_url', 'Site address', $values, 'url', 'Include https:// and no trailing slash.')}
          <label class="field">
            <span class="label">Timezone</span>
            <select name="timezone">{$zones}</select>
            <span class="help">Used when displaying dates and scheduling.</span>
          </label>
          <div class="actions">
            <button class="btn" type="submit">Continue</button>
          </div>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function providers(array $data): string
    {
        $kinds = $data['kinds'] ?? [];
        $selected = $data['selected'] ?? [];
        $values = $data['values'] ?? [];
        $tests = $data['tests'] ?? [];

        $sections = '';

        foreach ($kinds as $kind => $info) {
            $options = '';
            foreach ($info['options'] as $option) {
                $isSelected = ($selected[$kind] ?? '') === $option['slug'];
                $disabled = $option['missingExtensions'] !== [] ? ' disabled' : '';
                $suffix = $option['missingExtensions'] !== []
                    ? ' — needs ' . implode(', ', $option['missingExtensions'])
                    : '';

                $options .= sprintf(
                    '<option value="%s"%s%s>%s%s</option>',
                    e($option['slug']),
                    $isSelected ? ' selected' : '',
                    $disabled,
                    e($option['label']),
                    e($suffix)
                );
            }

            $fields = '';
            foreach ($info['fields'] as $field) {
                $fields .= $this->providerField($kind, $field, $values[$kind] ?? []);
            }

            $testBlock = '';
            if (isset($tests[$kind]) && is_array($tests[$kind])) {
                $result = $tests[$kind];
                $class = $result['ok'] ? 'success' : 'error';
                $testBlock = '<div class="notice ' . $class . '">' . e((string) $result['message']);
                if (!empty($result['detail'])) {
                    $testBlock .= '<pre>' . e((string) $result['detail']) . '</pre>';
                }
                $testBlock .= '</div>';
            }

            $sections .= sprintf(
                '<fieldset class="provider">
                   <legend>%s</legend>
                   <p class="help">%s</p>
                   <label class="field">
                     <span class="label">Service</span>
                     <select name="provider[%s]" onchange="this.form.submit()">%s</select>
                   </label>
                   %s
                   %s
                   <button class="btn secondary small" type="submit" name="action" value="test:%s">Test %s</button>
                 </fieldset>',
                e($info['title']),
                e($info['description']),
                e($kind),
                $options,
                $fields,
                $testBlock,
                e($kind),
                e(strtolower($info['title']))
            );
        }

        $allPassed = (bool) ($data['allPassed'] ?? false);
        $continue = $allPassed
            ? '<button class="btn" type="submit" name="action" value="continue">Continue</button>'
            : '<p class="help">Test each service before continuing. A service that is not
               working now will fail silently later.</p>';

        return <<<HTML
        <h2>Services</h2>
        <p class="lead">Video Portal talks to three outside services. Each can be changed later
           from the admin area without touching any code.</p>
        <form method="post">
          <input type="hidden" name="step" value="providers">
          {$sections}
          <div class="actions">{$continue}</div>
        </form>
        HTML;
    }

    /** @param array<string, string> $values */
    private function providerField(string $kind, SettingField $field, array $values): string
    {
        $name = "credentials[{$kind}][{$field->key}]";
        $value = $values[$field->key] ?? ($field->default ?? '');
        $required = $field->required ? ' required' : '';

        $input = match ($field->type) {
            SettingField::TYPE_SECRET => sprintf(
                '<input type="password" name="%s" value="%s" autocomplete="new-password"%s>',
                e($name),
                e((string) $value),
                $required
            ),
            SettingField::TYPE_BOOL => sprintf(
                '<input type="checkbox" name="%s" value="1"%s>',
                e($name),
                $value === '1' ? ' checked' : ''
            ),
            SettingField::TYPE_SELECT => (function () use ($name, $field, $value): string {
                $options = '';
                foreach ($field->choices as $key => $label) {
                    $options .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        e((string) $key),
                        (string) $value === (string) $key ? ' selected' : '',
                        e((string) $label)
                    );
                }
                return sprintf('<select name="%s">%s</select>', e($name), $options);
            })(),
            SettingField::TYPE_NUMBER => sprintf(
                '<input type="number" name="%s" value="%s"%s>',
                e($name),
                e((string) $value),
                $required
            ),
            default => sprintf(
                '<input type="%s" name="%s" value="%s"%s>',
                $field->type === SettingField::TYPE_URL ? 'url'
                    : ($field->type === SettingField::TYPE_EMAIL ? 'email' : 'text'),
                e($name),
                e((string) $value),
                $required
            ),
        };

        $help = $field->help !== '' ? '<span class="help">' . e($field->help) . '</span>' : '';

        return sprintf(
            '<label class="field"><span class="label">%s</span>%s%s</label>',
            e($field->label),
            $input,
            $help
        );
    }

    /** @param array<string, mixed> $data */
    private function admin(array $data): string
    {
        $values = $data['values'] ?? [];
        $needsPassword = (bool) ($data['needsPassword'] ?? true);
        $authLabel = e((string) ($data['authLabel'] ?? 'the sign-in service'));

        $passwordFields = $needsPassword
            ? $this->field('password', 'Password', [], 'password', 'At least 12 characters.')
              . $this->field('password_confirm', 'Confirm password', [], 'password')
            : '<p class="help">You chose ' . $authLabel . ', so there is no password to set here.
                 Sign in with this address once the install finishes and you will be the administrator.</p>';

        return <<<HTML
        <h2>Administrator account</h2>
        <p class="lead">This is the only account created without another administrator approving it.
           Everyone else who signs in starts with no access until you grant it.</p>
        <form method="post">
          <input type="hidden" name="step" value="admin">
          {$this->field('name', 'Your name', $values, 'text', 'Optional.')}
          {$this->field('email', 'Email address', $values, 'email', 'Used to sign in, and to identify you in the activity log.')}
          {$passwordFields}
          <div class="actions">
            <button class="btn" type="submit">Install</button>
          </div>
        </form>
        HTML;
    }

    /** @param array<string, mixed> $data */
    private function finish(array $data): string
    {
        /** @var InstallResult $result */
        $result = $data['result'];

        $cronUrl = e($result->cronUrl());
        $baseUrl = e($result->baseUrl);

        return <<<HTML
        <h2>Installed</h2>
        <p class="lead">Video Portal is ready. The installer has been disabled so it cannot be run again.</p>

        <div class="notice success">
          Sign in as <strong>{$result->adminEmail}</strong> to get started.
        </div>

        <h3>Two things worth doing now</h3>

        <h4>1. Set up a scheduled task</h4>
        <p>Background work — syncing video status, cleaning up expired links — runs automatically
           on ordinary page visits. That is enough for most sites. If your host offers cron jobs,
           pointing one at this URL every 15 minutes makes it more reliable:</p>
        <pre class="copyable">{$cronUrl}</pre>
        <p class="help">This URL contains a secret. It is shown here once; after this it lives only
           in config.php.</p>

        <h4>2. Check your file permissions</h4>
        <p>config.php now contains your database password and encryption keys. It should not be
           readable by anyone else on the server — 600 or 640 is right. Your host's file manager
           can set this.</p>

        <div class="actions">
          <a class="btn" href="{$baseUrl}">Go to the site</a>
          <a class="btn secondary" href="{$baseUrl}/admin">Open the admin area</a>
        </div>
        HTML;
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string, mixed> $values */
    private function field(
        string $name,
        string $label,
        array $values,
        string $type = 'text',
        string $help = ''
    ): string {
        $value = $type === 'password' ? '' : (string) ($values[$name] ?? '');
        $helpHtml = $help !== '' ? '<span class="help">' . e($help) . '</span>' : '';

        return sprintf(
            '<label class="field"><span class="label">%s</span>
             <input type="%s" name="%s" value="%s" autocomplete="%s">%s</label>',
            e($label),
            e($type),
            e($name),
            e($value),
            $type === 'password' ? 'new-password' : 'on',
            $helpHtml
        );
    }

    private function css(): string
    {
        return <<<'CSS'
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
          margin: 0; min-height: 100vh; padding: 2.5rem 1.25rem;
          background: #0f172a; color: #e2e8f0;
          font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Inter, sans-serif;
        }
        .shell { max-width: 44rem; margin: 0 auto; }
        header h1 { font-size: 1.25rem; font-weight: 650; margin: 0 0 1.25rem; letter-spacing: -.01em; }
        .steps {
          display: flex; flex-wrap: wrap; gap: .5rem; list-style: none;
          margin: 0 0 1.5rem; padding: 0; font-size: .8125rem;
        }
        .steps li {
          padding: .25rem .75rem; border-radius: 999px;
          border: 1px solid rgba(148,163,184,.22); color: #94a3b8;
        }
        .steps li.current { border-color: #38bdf8; color: #38bdf8; }
        .steps li.done { color: #22c55e; border-color: rgba(34,197,94,.4); }
        .card {
          background: rgba(30,41,59,.55); border: 1px solid rgba(148,163,184,.18);
          border-radius: 16px; padding: 2rem; backdrop-filter: blur(12px);
        }
        h2 { font-size: 1.375rem; margin: 0 0 .5rem; font-weight: 600; }
        h3 { font-size: 1.0625rem; margin: 2rem 0 .5rem; font-weight: 600; }
        h4 { font-size: .9375rem; margin: 1.5rem 0 .375rem; font-weight: 600; }
        .lead { color: #cbd5e1; margin: 0 0 1.75rem; }
        .help { display: block; color: #94a3b8; font-size: .8125rem; margin-top: .25rem; }
        .detail { display: block; color: #94a3b8; font-size: .8125rem; margin-top: .375rem; }

        .field { display: block; margin-bottom: 1.125rem; }
        .field .label { display: block; font-size: .875rem; font-weight: 550; margin-bottom: .375rem; }
        input, select {
          width: 100%; padding: .5625rem .8125rem; border-radius: 9px;
          border: 1px solid rgba(148,163,184,.28); background: rgba(15,23,42,.55);
          color: #e2e8f0; font: inherit; font-size: .9375rem;
        }
        input[type="checkbox"] { width: auto; }
        input:focus-visible, select:focus-visible, button:focus-visible {
          outline: 2px solid #38bdf8; outline-offset: 2px;
        }

        .actions { display: flex; gap: .75rem; align-items: center; margin-top: 1.5rem; flex-wrap: wrap; }
        .btn {
          display: inline-block; padding: .5625rem 1.25rem; border-radius: 9px;
          border: 1px solid transparent; background: #38bdf8; color: #0b1220;
          font: inherit; font-weight: 600; font-size: .9375rem; cursor: pointer; text-decoration: none;
        }
        .btn.secondary { background: transparent; border-color: rgba(148,163,184,.3); color: #e2e8f0; }
        .btn.small { padding: .375rem .875rem; font-size: .8125rem; }

        .notice {
          padding: .875rem 1.125rem; border-radius: 10px; margin-bottom: 1.5rem;
          border: 1px solid rgba(148,163,184,.25); background: rgba(15,23,42,.5); font-size: .9375rem;
        }
        .notice.error { border-color: rgba(239,68,68,.55); }
        .notice.success { border-color: rgba(34,197,94,.55); }
        .notice pre { margin: .625rem 0 0; white-space: pre-wrap; font-size: .8125rem; color: #94a3b8; }

        .checks { list-style: none; margin: 0 0 1.5rem; padding: 0; }
        .checks li { display: flex; gap: .75rem; padding: .625rem 0; border-bottom: 1px solid rgba(148,163,184,.12); }
        .checks .icon {
          flex: 0 0 1.5rem; height: 1.5rem; border-radius: 50%; display: grid; place-items: center;
          font-size: .8125rem; font-weight: 700;
        }
        .checks .pass .icon, .checks li.pass .icon { background: rgba(34,197,94,.18); color: #22c55e; }
        .checks li.fail .icon { background: rgba(239,68,68,.18); color: #ef4444; }
        .checks li.warn .icon { background: rgba(245,158,11,.18); color: #f59e0b; }
        .checks .body { flex: 1; min-width: 0; }
        .checks .fix { display: block; color: #fbbf24; font-size: .8125rem; margin-top: .375rem; }
        .blocked { color: #ef4444; font-weight: 550; }

        fieldset.provider {
          border: 1px solid rgba(148,163,184,.2); border-radius: 12px;
          padding: 1.25rem; margin: 0 0 1.5rem;
        }
        fieldset.provider legend { padding: 0 .5rem; font-weight: 600; font-size: .9375rem; }

        pre.copyable {
          background: rgba(15,23,42,.75); border: 1px solid rgba(148,163,184,.2);
          border-radius: 9px; padding: .875rem; overflow-x: auto;
          font-size: .8125rem; color: #7dd3fc; user-select: all;
        }
        footer { text-align: center; color: #64748b; font-size: .8125rem; margin-top: 1.5rem; }
        CSS;
    }
}
