<?php
/**
 * The forms anybody can fill in.
 *
 * A members-only form is absent from this list rather than shown and refused.
 * The decision is made once, in FormRepository — a template that filtered would
 * be a second place that can disagree, and the failure there is a title in
 * front of somebody it was hidden from.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $forms
 */

declare(strict_types=1);

$forms ??= [];

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Forms</h1>

<?php if ($forms === []): ?>
  <div class="empty">Nothing to fill in at the moment.</div>
<?php else: ?>
  <ul class="calendar-entries">
    <?php foreach ($forms as $form): ?>
      <li>
        <a href="/forms/<?= e((string) $form['slug']) ?>"><strong><?= e((string) $form['title']) ?></strong></a>
        <?php if (!empty($form['description'])): ?>
          <span class="muted small"><?= e((string) $form['description']) ?></span>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
