<?php
/**
 * Generic single-item fallback — a speaker page, a static page, anything with
 * a title and a body that has no more specific template.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string  $heading
 * @var ?string $body
 * @var list<array<string, mixed>> $videos
 */

declare(strict_types=1);

$heading ??= $title ?? '';
$body ??= null;
$videos ??= [];

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e($heading) ?></h1>

<?php if ($body !== null && $body !== ''): ?>
  <div style="max-width:48rem"><?= nl2br(e($body)) ?></div>
<?php endif ?>

<?php if ($videos !== []): ?>
  <div class="video-grid" style="margin-top:2.5rem">
    <?php foreach ($videos as $video): ?>
      <?= $template->partial('video-card', ['video' => $video]) ?>
    <?php endforeach ?>
  </div>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
