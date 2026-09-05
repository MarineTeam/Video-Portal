<?php
/**
 * Document head and site header.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string      $siteName
 * @var string|null $logoUrl
 * @var string      $title      page title, already plain text
 * @var string      $assetsUrl  base URL for this theme's assets
 * @var string      $currentPath
 * @var array{name: string, email: string, isAdmin: bool}|null $currentUser
 * @var list<array{label: string, href: string}> $nav
 */

declare(strict_types=1);

$title ??= '';
$siteName ??= 'Video Portal';
$nav ??= [];
$currentUser ??= null;
$currentPath ??= '/';
$assetsUrl ??= '/theme-asset/default';

$documentTitle = $title !== '' ? "{$title} — {$siteName}" : $siteName;

/*
 * The bottom tab bar's destinations, worked out here because the top nav has
 * to know which of its own links are duplicated below.
 *
 * Four from $nav at most, then the account. Five is what fits across the
 * narrowest phone this product supports without the labels truncating, and a
 * tab bar whose labels are cut in half is a row of guesses.
 *
 * $nav is already filtered by `site_nav`, so a plugin's destination reaches
 * both bars or neither.
 */
$tabs = array_slice($nav, 0, 4);
$tabs[] = $currentUser !== null
    ? ['label' => 'Account', 'href' => '/account']
    : ['label' => 'Sign in', 'href' => '/auth/login'];

$tabHrefs = array_map(static fn (array $t): string => $t['href'], $tabs);

/**
 * A 20px line icon for a tab, chosen by its address.
 *
 * Inline SVG rather than an icon font or a sprite, for the reason the admin
 * sidebar gives: one more request that has to succeed before the navigation is
 * legible, on hosts where a mis-set MIME type is an ordinary kind of failure.
 *
 * An address nothing matches gets a dot rather than nothing, so a plugin's
 * destination looks deliberate instead of broken.
 */
$tabIcon = static function (string $href): string {
    $paths = [
        '/'                 => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
        '/search'           => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        '/live'             => '<circle cx="12" cy="12" r="3"/><path d="M6.5 6.5a8 8 0 0 0 0 11"/>'
                             . '<path d="M17.5 6.5a8 8 0 0 1 0 11"/>',
        '/saved'            => '<path d="M6 3h12v18l-6-4.5L6 21z"/>',
        '/account'          => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6 8-6s8 2 8 6"/>',
        '/auth/login'       => '<path d="M14 3h5v18h-5"/><path d="M3 12h11"/><path d="m10 8 4 4-4 4"/>',
        '/account/password' => '<rect x="4" y="10" width="16" height="10" rx="2"/>'
                             . '<path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    ];

    $d = $paths[$href] ?? '<circle cx="12" cy="12" r="3"/>';

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"'
        . ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $d . '</svg>';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($documentTitle) ?></title>

<?php
/*
 * Private by default. Settings → "Let search engines index this site" is the
 * one deliberate act that changes it, and it governs robots.txt and
 * sitemap.xml at the same time so the three can never disagree.
 */
$allowIndexing ??= false;
?>
<meta name="robots" content="<?= $allowIndexing ? 'index, follow' : 'noindex, nofollow' ?>">

<?php
/*
 * What this link looks like when somebody pastes it somewhere.
 *
 * Deliberately NOT gated on $allowIndexing. That setting decides whether a
 * crawler may index the site; this decides what a preview card looks like when
 * a person chooses to share a page. A private site still wants a legible card
 * in a group chat, and a card is only ever built for a page the fetcher could
 * already reach — an unfurler carries no session, so a guarded page hands it a
 * sign-in redirect and there is nothing to preview.
 *
 * $pageMeta is null on any screen that has not built one, and this block then
 * renders nothing rather than guessing. A card assembled from whatever happens
 * to be in scope is how a members-only thumbnail ends up on somebody's server.
 */
$pageMeta ??= null;

if ($pageMeta !== null):
    $ogTitle = $pageMeta->title !== '' ? $pageMeta->title : $siteName;
?>
<meta property="og:site_name" content="<?= e($siteName) ?>">
<meta property="og:type" content="<?= e($pageMeta->type) ?>">
<meta property="og:title" content="<?= e($ogTitle) ?>">
<meta name="twitter:card" content="<?= e($pageMeta->twitterCard()) ?>">
<meta name="twitter:title" content="<?= e($ogTitle) ?>">
<?php if ($pageMeta->description !== ''): ?>
<meta name="description" content="<?= e($pageMeta->description) ?>">
<meta property="og:description" content="<?= e($pageMeta->description) ?>">
<meta name="twitter:description" content="<?= e($pageMeta->description) ?>">
<?php endif ?>
<?php if ($pageMeta->canonical !== null): ?>
<link rel="canonical" href="<?= e($pageMeta->canonical) ?>">
<meta property="og:url" content="<?= e($pageMeta->canonical) ?>">
<?php endif ?>
<?php
    /*
     * Absent for anything whose artwork is members-only, and absent means
     * ABSENT — there is no fallback to a site logo here. og:image is fetched
     * by a stranger's server with no session and cached there afterwards, so a
     * withheld thumbnail put here is handed to exactly the people the setting
     * exists to keep it from.
     */
    if ($pageMeta->imageUrl !== null):
?>
<meta property="og:image" content="<?= e($pageMeta->imageUrl) ?>">
<meta name="twitter:image" content="<?= e($pageMeta->imageUrl) ?>">
<?php endif ?>
<?php
    $breadcrumbList = $pageMeta->breadcrumbList();

    foreach (array_filter([$pageMeta->structured, $breadcrumbList]) as $block):
        /*
         * JSON_UNESCAPED_SLASHES keeps URLs readable; JSON_HEX_TAG is the one
         * that matters — it turns `<` into < so a title containing
         * "</script>" cannot close this block and start running.
         */
?>
<script type="application/ld+json"><?= json_encode(
    $block,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<?php endforeach ?>
<?php endif ?>

<link rel="stylesheet" href="<?= e(isset($themeAsset) ? $themeAsset('theme.css') : $assetsUrl . '/theme.css') ?>">

<?php
/*
 * Feed discovery. Browsers and podcast apps look for these; they are how
 * somebody subscribes without being told a URL. Both feeds carry public
 * content only, whatever the indexing setting says — a person who pastes the
 * address into a podcast app is not a crawler.
 */
?>
<link rel="alternate" type="application/rss+xml"
      title="<?= e($siteName) ?> — latest" href="/feed">
<link rel="alternate" type="application/rss+xml"
      title="<?= e($siteName) ?> — podcast" href="/podcast">

<?php
/*
 * Installable-app tags.
 *
 * The manifest is what makes a browser offer "Add to Home Screen"; without it
 * there is no install prompt and no standalone mode, however many service
 * workers are registered.
 *
 * theme-color paints the browser and task-switcher chrome to match the site.
 * The apple- tags are the iOS equivalent of the manifest, which Safari still
 * only partly reads.
 *
 * apple-touch-icon points at the PNG, not the SVG. iOS ignores SVG here and
 * would fall back to a screenshot of the page — which is what it did until the
 * raster icons existed.
 */
?>
<?php
/*
 * crossorigin="use-credentials" on a SAME-ORIGIN manifest, which looks wrong
 * and is not.
 *
 * Chrome fetches the manifest as a subresource with credentials OMITTED by
 * default. Anything in front of the site that decides based on a cookie — a
 * Cloudflare challenge, a WAF, an auth layer — therefore sees that fetch as an
 * anonymous request and can answer it with an HTML challenge page. Chrome then
 * fails to parse the manifest and silently degrades to offering a bookmark
 * shortcut rather than an install, with nothing on the page saying why.
 *
 * This attribute makes the fetch carry cookies, so it is judged the same way
 * the page around it was. Harmless when nothing is in front of the site.
 */
?>
<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
<link rel="icon" type="image/svg+xml" href="/icon.svg">
<link rel="apple-touch-icon" sizes="192x192" href="/icon-192.png">
<meta name="theme-color" content="<?= e($themeColor ?? '#0f172a') ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(mb_substr($siteName, 0, 12)) ?>">

<?php
/*
 * The `head` action is where the theme's own functions.php writes the
 * customizer variables, and where plugins add their own tags. It fires before
 * any body content so the custom properties are applied on first paint and
 * there is no flash of the default palette.
 */
do_action('head');
?>
</head>
<body>

<header class="site-header">
  <div class="wrap">
    <a class="brand" href="/">
      <?php if (!empty($logoUrl)): ?>
        <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>">
      <?php else: ?>
        <?= e($siteName) ?>
      <?php endif ?>
    </a>

    <nav class="site-nav">
      <?php foreach ($nav as $item): ?>
        <a href="<?= e($item['href']) ?>"
           <?= in_array($item['href'], $tabHrefs, true) ? 'data-tab' : '' ?>
           <?= $item['href'] === $currentPath ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
      <?php endforeach ?>

      <?php
      /*
       * Where a plugin puts a control rather than a link.
       *
       * `site_nav` can already add an anchor, which is no use to anything that
       * needs a button and a script — the push subscribe control spent its
       * first release as a floating box in the footer because there was
       * nowhere else for it to go, and people did not find it.
       *
       * Before the account and sign-out links, so plugin controls sit together
       * and the session links stay where people expect them.
       */
      do_action('header_actions');
      ?>

      <?php if ($currentUser !== null): ?>
        <?php if (!empty($currentUser['isAdmin'])): ?>
          <a href="/admin">Admin</a>
        <?php endif ?>
        <?php
        /*
         * The account area, with the unread count on it.
         *
         * Without a link here the page is reachable only by typing its URL,
         * which is how the password form spent its first release — and a
         * notification record nobody can find is the same as not keeping one.
         */
        ?>
        <a href="/account" data-tab <?= '/account' === $currentPath ? 'aria-current="page"' : '' ?>>
          Account<?php if (!empty($currentUser['unreadNotifications'])): ?>
            <span class="pill"><?= (int) $currentUser['unreadNotifications'] ?></span>
          <?php endif ?>
        </a>
        <a href="/auth/logout">Sign out</a>
      <?php else: ?>
        <a href="/auth/login" data-tab>Sign in</a>
      <?php endif ?>
    </nav>
  </div>
</header>

<?php
/*
 * The bottom tab bar, on phones only.
 *
 * The top navigation wraps under the brand on a narrow screen, which puts the
 * places people go most at the top of a tall page and out of thumb reach. This
 * is the same set of destinations, drawn where a thumb already is.
 *
 * Built from $nav rather than a second hardcoded list, so a plugin adding a
 * destination gets it here too — and so the two can never disagree about what
 * this site's navigation is. A link that appears here is marked `data-tab`
 * above and hidden from the top nav at the same width, because the same word
 * twice on one screen reads as two different things.
 *
 * It is fixed, so it is drawn here rather than in the footer only to keep the
 * tab list and the marking in one file; its position in the document does not
 * decide where it lands.
 *
 * No JavaScript, deliberately, like every other navigation in this product:
 * these are links, and a tab bar that needs a script to change page is one that
 * fails on exactly the connections phones have.
 */
?>
<nav class="tab-bar" aria-label="Primary">
  <?php foreach ($tabs as $tab): ?>
    <a href="<?= e($tab['href']) ?>"
       <?= $tab['href'] === $currentPath ? 'aria-current="page"' : '' ?>>
      <?= $tabIcon($tab['href']) ?>
      <span><?= e($tab['label']) ?></span>
    </a>
  <?php endforeach ?>
</nav>

<?php
/*
 * Announcements.
 *
 * Above the content and inside <main>'s wrap, so they line up with the page
 * rather than spanning the window. Dismissal is a form post — no JavaScript —
 * so it works everywhere and the server is what remembers, via a cookie.
 */
$announcements ??= [];
?>
<?php if ($announcements !== []): ?>
  <div class="wrap">
    <?php foreach ($announcements as $announcement): ?>
      <div class="announcement is-<?= e($announcement['level']) ?>" role="status">
        <div>
          <?php if ($announcement['title'] !== ''): ?>
            <strong><?= e($announcement['title']) ?></strong>
          <?php endif ?>
          <?php
          /*
           * nl2br(e()) rather than raw HTML. An announcement is written by an
           * administrator, but an administrator account is exactly what an
           * attacker would want in order to get stored markup onto every
           * viewer's page — including the sign-in page.
           */
          ?>
          <span><?= nl2br(e($announcement['body'])) ?></span>
        </div>

        <?php if (!empty($announcement['dismissible'])): ?>
          <form method="post" action="/announcements/dismiss" class="announcement-dismiss">
            <input type="hidden" name="id" value="<?= (int) $announcement['id'] ?>">
            <button type="submit" aria-label="Dismiss this message">&times;</button>
          </form>
        <?php endif ?>
      </div>
    <?php endforeach ?>
  </div>
<?php endif ?>

<?php
/*
 * On air.
 *
 * Above the content on every page, not only the homepage: somebody who arrives
 * at a sermon from a search engine while the service is going out should be
 * told, and the moment it matters is the one hour a week when it is happening.
 *
 * A link, not an embed. Loading a player into every page would be a second
 * stream starting on top of whatever the person came to watch.
 */
$liveNow ??= null;
?>
<?php if (is_array($liveNow)): ?>
  <div class="wrap">
    <a class="live-banner" href="<?= e((string) ($liveNow['url'] ?? '/live')) ?>">
      <span class="live-dot" aria-hidden="true"></span>
      <span><strong>Live now</strong> — <?= e((string) $liveNow['title']) ?></span>
      <span class="live-go">Watch &rarr;</span>
    </a>
  </div>
<?php endif ?>

<main>
  <div class="wrap">
