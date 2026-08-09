<?php
/**
 * What is on, and what is coming.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $streams
 * @var string $heading
 */

declare(strict_types=1);

use Portal\Content\LiveStreamPolicy;

$streams ??= [];
$heading ??= 'Live';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e($heading) ?></h1>

<?php if ($streams === []): ?>
  <div class="empty">Nothing scheduled.</div>
<?php else: ?>
  <ul class="title-list">
    <?php foreach ($streams as $stream): ?>
      <li>
        <a href="<?= e($stream['url']) ?>"><?= e($stream['title']) ?></a>
        <?php if ($stream['state'] === LiveStreamPolicy::LIVE): ?>
          <span class="pill bad">Live now</span>
        <?php elseif (!empty($stream['starts_at'])): ?>
          <span class="muted small"><?= e((string) $stream['starts_at']) ?></span>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
