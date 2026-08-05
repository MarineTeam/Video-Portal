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

<link rel="stylesheet" href="<?= e($assetsUrl) ?>/theme.css">

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
           <?= $item['href'] === $currentPath ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
      <?php endforeach ?>

      <?php if ($currentUser !== null): ?>
        <?php if (!empty($currentUser['isAdmin'])): ?>
          <a href="/admin">Admin</a>
        <?php endif ?>
        <a href="/auth/logout">Sign out</a>
      <?php else: ?>
        <a href="/auth/login">Sign in</a>
      <?php endif ?>
    </nav>
  </div>
</header>

<main>
  <div class="wrap">
