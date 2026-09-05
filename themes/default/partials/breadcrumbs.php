<?php
/**
 * Where this page sits, shown.
 *
 * The trail has been built since the metadata work and only ever went into
 * JSON-LD, so the site described its own shape to crawlers and never to
 * readers. On a library nested three deep that is the difference between
 * browsing and guessing at URLs.
 *
 * The last crumb is the current page and is not a link. Linking it gives a
 * control that appears to do something and reloads what you are looking at,
 * and a screen reader announces a link to here from here.
 *
 * A trail of one — just the Library — is not drawn at all: "Library" on the
 * library page is a heading that has learned to look like navigation.
 *
 * Restricted sections are already absent by the time this runs; the trail is
 * filtered where it is built, in Portal\Content\Breadcrumbs, so a theme cannot
 * leak one by rendering carelessly.
 *
 * Each crumb carries `path` (relative, what a browser follows) and `url`
 * (absolute, what the JSON-LD in the head uses). This renders `path`: a site
 * reachable at more than one address should not send a reader to the canonical
 * one halfway through browsing.
 *
 * @var list<array{name: string, path: string, url: string}> $breadcrumbs
 */

declare(strict_types=1);

$breadcrumbs ??= [];

if (count($breadcrumbs) < 2) {
    return;
}

$last = count($breadcrumbs) - 1;
?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
  <ol>
    <?php foreach ($breadcrumbs as $i => $crumb): ?>
      <li>
        <?php if ($i === $last): ?>
          <span aria-current="page"><?= e((string) $crumb['name']) ?></span>
        <?php else: ?>
          <a href="<?= e((string) ($crumb['path'] ?? $crumb['url'])) ?>"><?= e((string) $crumb['name']) ?></a>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ol>
</nav>
