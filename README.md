# Video Portal

A self-hosted video portal and media library that installs on ordinary shared
hosting. Upload a ZIP, open `/install.php`, answer six screens.

It merges two things that are usually separate products:

- a **secure video portal** — approved viewers, private per-recipient share
  links, watch progress, watermarking
- a **media library CMS** — nested categories, series, speakers, tags,
  playlists, comments

Video hosting, sign-in, and email are each **swappable services**, chosen during
install and changeable later from the admin area without touching code.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer, with `pdo_mysql`, `openssl`, `mbstring`, `json` |
| Database | MySQL 8.0+ or MariaDB 10.6+ |
| Web server | Apache with `mod_rewrite` (nginx and LiteSpeed work with an equivalent rule) |
| Recommended | `curl` (video and email), `zip` (installing plugins/themes), `gd` (image resizing) |

No SSH, no Composer, no Node, and no background worker are required on the
server. The release ZIP ships its dependencies, and scheduled work runs on
ordinary page visits.

The installer checks all of this first and tells you which control-panel
setting to change for anything missing.

---

## Installing

### From the release branch (recommended if the host has git)

`release/1.0` carries the application **and its dependencies**, so nothing needs
Composer on the server:

```bash
git clone -b release/1.0 https://github.com/MarineTeam/Video-Portal.git yoursite
```

Updating afterwards is one command, and never touches your `config.php`:

```bash
cd yoursite && git pull
```

Development branches deliberately do **not** work this way: `vendor/` is
gitignored there, so a clone of `main` or a feature branch fatals on its first
`require`. Clone `release/1.0`, or run `composer install --no-dev` yourself.

### From a ZIP

1. Create an empty MySQL database in your hosting control panel, and a user with
   full privileges on it.
2. Upload the release ZIP and extract it.
3. Point your domain's document root at `public/` if you can. If you cannot, the
   included root `.htaccess` serves the site out of `public/` and blocks direct
   access to everything else.
4. Open `https://yourdomain.com/install.php`.
5. Work through the wizard. It renames itself when it finishes.

Afterwards, set `config.php` to permissions `600` or `640`. It holds your
database password and encryption keys.

### Choosing services

The install wizard asks you to pick three, and each has a **Test** button that
must pass before you can continue:

| | Options | Notes |
|---|---|---|
| **Sign-in** | Auth0, OpenID Connect, local accounts | Local works immediately. Auth0 and OIDC need the site reachable over HTTPS first. |
| **Video** | bunny.net Stream | Videos upload straight from the browser to the provider. |
| **Email** | Resend, SMTP, PHP `mail()` | If your host blocks outbound HTTPS, SMTP is usually the one that works. |

Any of them can be changed later under **Admin → Services**. Switching runs the
new service's connection test first, and refuses the switch if it fails.

---

## How it fits together

```
public/          document root — front controller and installer
core/            application code (never web-accessible)
  Auth/          identity, roles, capabilities, scoped grants
  Content/       categories, series, videos, taxonomy
  Controllers/   request handlers
  Install/       the setup wizard
  Mail/          email providers
  Plugins/       hook bus and plugin lifecycle
  Providers/     the swappable-service registry
  Themes/        template resolution and the customizer
  Video/         video providers and token signing
plugins/         drop-in plugin folders
themes/          drop-in theme folders (default/ always present)
storage/         logs and caches
config.php       written by the installer
```

### Permissions

Code never asks "is this person an admin?" — it asks whether they hold a
**capability**. A person can hold one four ways:

1. the `admin` role, which short-circuits every check
2. their role's capabilities
3. a permission group they belong to
4. a **scoped grant** — site-wide, or on a category, series, or video

Category grants are inherited by descendants, so granting *manage videos* on
Sermons covers Sermons/2026/Advent without granting it again.

Two rules the implementation is built around:

- **Fails closed.** Any error resolves to "denied".
- **No self-escalation.** `admin` is a role, never a capability, so someone who
  can hand out every capability still cannot make themselves an administrator.

Signing in proves who someone is and grants nothing. An administrator approves
each account before it can watch anything.

### Plugins

Drop a folder into `plugins/` with a `plugin.php` carrying a header comment,
then activate it in the admin area. The hook API follows WordPress conventions:

```php
<?php
/**
 * Plugin Name: My Plugin
 * Version: 1.0.0
 */

$plugin->addFilter('video_list', function (array $videos): array {
    return $videos;
});

$plugin->addAction('player_overlay', function ($video): void {
    echo '<div class="my-overlay"></div>';
});
```

Plugins can register routes, admin pages, settings, capabilities, scheduled
jobs, and their own database migrations. They can be enabled or disabled
**per category**, with the nearest ancestor winning.

A plugin that throws while loading is deactivated automatically rather than
taking the site down — on shared hosting there is no shell to disable it from.

### Themes

Drop a folder into `themes/` with a `theme.json`. Templates resolve through the
active theme, then its parent, then the bundled default, so a theme only needs
to contain the files it actually changes. Dropping in `video.php` overrides the
core one; deleting it restores the original.

The customizer form is generated from the theme's own `theme.json`, and values
become CSS custom properties.

### Categories override provider collections

Collections at your video provider can be imported as a starting point. After
that, local categories win. A re-import never renames a category you have
edited, and syncing never overwrites a video title you have changed.

---

## Running it

Scheduled work — syncing video status, cleaning up expired links, purging
sessions — runs automatically on a small fraction of page visits. That is enough
for most sites.

If your host offers cron jobs, pointing one at this every 15 minutes is more
reliable on a quiet site:

```
https://yourdomain.com/cron?key=YOUR_CRON_SECRET
```

The secret is in `config.php` and is shown once on the installer's final screen.

---

## Development

```bash
composer install
```

```bash
pwsh tools/lint.ps1
```

```bash
php vendor/phpunit/phpunit/phpunit
```

Database-backed tests skip themselves unless `PORTAL_TEST_DB` is set:

```bash
PORTAL_TEST_DB="mysql://root@127.0.0.1:3306/portal_test" php vendor/phpunit/phpunit/phpunit
```

The end-to-end smoke test installs into a scratch database, serves the app, and
drives the real HTTP surface. It refuses to run if a `config.php` already
exists:

```bash
php tools/smoke.php
```

To verify only that the schema is valid:

```bash
php tools/schema-check.php
```

### Cutting a release

```bash
powershell -ExecutionPolicy Bypass -File tools\release-branch.ps1 -Push
```

Installs production dependencies, checks that every class resolves and that the
dependencies load, then commits the deployable tree onto `release/1.0`.

The tree is assembled from scratch each time, so a file removed upstream
disappears from the release too. The commit is parented to the previous
release, so history stays linear and deployed servers can `git pull` — a
rebuilt orphan commit would diverge and force every server to reset. It refuses
to publish if `config.php` ever reaches the index, if `vendor/` does not, or if
anything fails to resolve.

`tools/build-release.ps1` produces a ZIP instead, for hosts without git.

---

## Security notes

Things that are the way they are on purpose:

- **`base_url` is never derived from the request.** Every emailed link is built
  from `config.php`, because `Host` is attacker-controlled on most shared hosts.
- **Provider credentials are encrypted at rest** with AES-256-GCM. The realistic
  leak on shared hosting is a database dump, which hands over tables but not the
  filesystem holding the key.
- **Playback URLs are signed and short-lived**, minted per request and never
  stored.
- **The video provider's API key never reaches the browser.** Uploads are
  authorised by a signed ticket good for one specific video.
- **Sessions live in the database**, not PHP's file handler — a shared temp
  directory is frequently readable by every other account on the machine.
- **Country restrictions live in `config.php`**, not the database. Whitelisting
  the wrong country would otherwise lock you out of the screen that did it, with
  no way back on a host with no shell.

---

## Status

Phase 1 (foundation) is complete: installer, providers, permissions, plugins,
themes, content model, library, playback, uploads, and the admin area.

Still to come: private share links and bundles, plugin and theme installation
from a ZIP, comments and ratings, feeds, push notifications, and live streaming.
