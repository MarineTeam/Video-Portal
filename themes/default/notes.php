<?php
/**
 * Everything one person has written, in one place they can print.
 *
 * Printing is the point of the page existing separately from the watch pages.
 * A note taken during a service is often wanted on paper afterwards, and there
 * is no route from twelve watch pages to one sheet.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $notes
 * @var string $heading
 */

declare(strict_types=1);

$notes ??= [];
$heading ??= 'My notes';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e($heading) ?></h1>

<?php if ($notes === []): ?>
  <div class="empty">
    Nothing written yet. There is a notes box under every video, and only you
    can read what goes in it.
  </div>
<?php else: ?>
  <p class="muted small no-print">
    Only you can read these. Use your browser's print command for a paper copy.
  </p>

  <?php foreach ($notes as $note): ?>
    <article class="note">
      <h2 class="section-title">
        <a href="/watch/<?= e((string) $note['slug']) ?>"><?= e((string) $note['title']) ?></a>
      </h2>
      <p class="muted small"><?= e((string) $note['updated_at']) ?></p>
      <?php
      /*
       * Escaped and printed as plain text, never as markup. This is the one
       * thing on the site written by a viewer and shown back to them, and the
       * moment it renders as HTML it becomes somewhere to keep a script that
       * runs on the next visit — to their own account, which is exactly where
       * it would do the most.
       */
      ?>
      <pre class="note-body"><?= e((string) $note['body']) ?></pre>
    </article>
  <?php endforeach ?>

  <style>
    .note-body { white-space: pre-wrap; word-break: break-word; font: inherit; }
    @media print {
      .no-print, header, footer, nav { display: none !important; }
      .note { break-inside: avoid; }
    }
  </style>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
