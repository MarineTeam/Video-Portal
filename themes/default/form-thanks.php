<?php
/**
 * What somebody sees after sending a form.
 *
 * Its own page rather than a flash on the form, because a page that still shows
 * the form with a green bar over it is a page people fill in again.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $form
 * @var string $thanks
 */

declare(strict_types=1);

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e((string) $form['title']) ?></h1>

<div class="notice ok"><?= e((string) $thanks) ?></div>

<p class="muted small"><a href="/">Back to the site</a></p>

<?= $template->partial('footer', get_defined_vars()) ?>
