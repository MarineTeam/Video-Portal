<?php
/**
 * One live stream.
 *
 * The embed is only present while the stream is actually on — see the
 * controller. Before it starts there is nothing to watch, and loading somebody
 * else's frame early would make a request to their server on behalf of every
 * visitor who opened the page hours beforehand.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $stream
 * @var string $embedUrl
 * @var string $heading
 */

declare(strict_types=1);

use Portal\Content\LiveStreamPolicy;

$stream ??= [];
$embedUrl ??= '';
$heading ??= (string) ($stream['title'] ?? 'Live');

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e($heading) ?></h1>

<?php if ($embedUrl !== ''): ?>
  <div class="player">
    <?php
    /*
     * `allow` lists what a live player legitimately needs. Autoplay is
     * included here, unlike the recorded player: somebody who opened a page
     * that says LIVE NOW came to watch it now, and a stream that waits for a
     * second click is one people report as broken.
     */
    ?>
    <iframe
      src="<?= e($embedUrl) ?>"
      title="<?= e($heading) ?>"
      allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
      allowfullscreen
      referrerpolicy="strict-origin-when-cross-origin"
      loading="lazy"></iframe>
  </div>

<?php elseif (($stream['state'] ?? '') === LiveStreamPolicy::SCHEDULED): ?>
  <div class="premiere">
    <p class="premiere-label">Starts</p>
    <p class="premiere-date"><?= e((string) ($stream['starts_at'] ?? 'soon')) ?></p>
  </div>

<?php else: ?>
  <div class="empty">
    This stream has ended.
    <?php if (!empty($stream['video_id'])): ?>
      A recording is on its way.
    <?php endif ?>
  </div>
<?php endif ?>

<?php if (!empty($stream['description'])): ?>
  <p class="page-subtitle" style="max-width:44rem"><?= nl2br(e((string) $stream['description'])) ?></p>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
