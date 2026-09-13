<?php
/**
 * A fill-in-the-blank sermon note sheet.
 *
 * One form around the whole outline, so the same markup is the script's
 * autosave, the no-script Save button, and the printed page — blanks print as
 * lines to write on, filled ones print with what was written.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array{title: string, url: string, slug: string} $video
 * @var list<array{type: string, text?: string, index?: int}> $segments
 * @var list<string> $answers
 * @var int    $version
 * @var bool   $changed
 * @var bool   $signedIn
 * @var bool   $savedPlain
 * @var string $token
 * @var string $loginUrl
 */

declare(strict_types=1);

$segments ??= [];
$answers ??= [];
$version ??= 1;
$changed ??= false;
$signedIn ??= false;
$savedPlain ??= false;
$token ??= '';
$loginUrl ??= '/auth/login';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e($video['title'] ?? '') ?></h1>
<p class="page-subtitle no-print">
  Note sheet · <a href="<?= e($video['url'] ?? '/') ?>">Watch the video</a>
</p>

<?php if ($changed): ?>
  <?php
  /*
   * Said, not repaired. A gap has no identity beyond its position, so there is
   * no honest way to move an answer to "where it belongs now" — only to show
   * what was written where it was written and let the person who wrote it
   * judge.
   */
  ?>
  <div class="notice error no-print" role="status">
    <p><strong>This sheet has changed since you filled it in.</strong> Your answers are shown in the
       order you wrote them, so some may now sit in the wrong blank.</p>
  </div>
<?php endif ?>

<?php if ($savedPlain): ?>
  <div class="notice ok no-print" role="status"><p>Saved.</p></div>
<?php endif ?>

<?php if (!$signedIn): ?>
  <p class="notice no-print">
    Fill it in and print it, or copy it. To keep your answers here,
    <a href="<?= e($loginUrl) ?>">sign in</a> — nothing typed before then is saved.
  </p>
<?php endif ?>

<form method="post" class="note-sheet" id="note-sheet"
      action="/sheets/<?= e(rawurlencode($video['slug'] ?? '')) ?>"
      data-save="<?= $signedIn ? '1' : '0' ?>">
  <?php if ($signedIn): ?>
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <input type="hidden" name="version" value="<?= (int) $version ?>">
    <input type="hidden" name="_plain" value="1">
  <?php endif ?>

  <div class="sheet-outline">
    <?php foreach ($segments as $segment): ?>
      <?php if ($segment['type'] === 'gap'): ?>
        <?php $index = (int) $segment['index']; ?>
        <input type="text" class="sheet-gap" name="answers[]"
               value="<?= e($answers[$index] ?? '') ?>"
               maxlength="<?= \Portal\Content\NoteSheet::MAX_ANSWER ?>"
               aria-label="Blank <?= $index + 1 ?>"
               autocomplete="off" autocapitalize="off"><?php
      else:
        echo nl2br(e((string) $segment['text']), false);
      endif ?>
    <?php endforeach ?>
  </div>

  <p class="sheet-actions no-print">
    <?php if ($signedIn): ?>
      <button class="btn secondary" type="submit">Save</button>
      <span id="sheet-status" class="muted small" role="status" aria-live="polite"></span>
    <?php endif ?>
    <button class="btn secondary" type="button" data-sheet-print hidden>Print</button>
  </p>
</form>

<script src="<?= e(isset($themeAsset) ? $themeAsset('note-sheet.js') : '/theme-asset/default/note-sheet.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
