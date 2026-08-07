<?php
/**
 * A single video.
 *
 * The embed URL arrives already signed and short-lived. It is never cached or
 * stored anywhere — it expires, and a stale one produces a 403 that looks to a
 * viewer like the video is broken.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array{
 *   id: int, title: string, description: ?string, embedUrl: string,
 *   duration: ?int, speaker: ?string, series: ?array{title: string, url: string},
 *   recordedAt: ?string, resumeAt: int
 * } $video
 * @var list<array<string, mixed>> $related
 * @var string $backUrl
 */

declare(strict_types=1);

$backUrl ??= '/';
$related ??= [];

echo $template->partial('header', get_defined_vars());
?>

<p style="margin:0 0 1.25rem">
  <a href="<?= e($backUrl) ?>" class="card-meta">&larr; Back</a>
</p>

<?php if (!empty($video['premiering'])): ?>
  <?php
  /*
   * A premiere. Announced, dated, and deliberately not playable — the embed URL
   * was never minted, so there is nothing here to reach for with developer
   * tools.
   */
  ?>
  <div class="premiere">
    <p class="premiere-label">Premieres</p>
    <p class="premiere-date"><?= e((string) ($video['premiereAt'] ?? 'soon')) ?></p>
  </div>
<?php else: ?>
<div class="player">
  <?php
  /*
   * `allow` lists exactly what the player legitimately needs. Notably absent
   * is autoplay: it is disabled at the URL level too, and a viewer who did not
   * ask for sound is not owed a surprise.
   */
  ?>
  <iframe
    src="<?= e($video['embedUrl']) ?>"
    title="<?= e($video['title']) ?>"
    loading="lazy"
    allow="accelerometer; gyroscope; encrypted-media; picture-in-picture; fullscreen"
    allowfullscreen></iframe>

  <?php
  /*
   * Overlay hook. The watermark plugin draws the viewer's email here; it sits
   * outside the iframe because nothing can be drawn inside a cross-origin one.
   */
  do_action('player_overlay', $video);
  ?>
</div>
<?php endif ?>

<h1 class="page-title" style="margin-top:1.5rem"><?= e($video['title']) ?></h1>

<p class="page-subtitle">
  <?php
  $bits = [];
  if (!empty($video['series']['title'])) {
      $bits[] = '<a href="' . e($video['series']['url']) . '">' . e($video['series']['title']) . '</a>';
  }
  if (!empty($video['speakerLink']['url'])) {
      $bits[] = '<a href="' . e($video['speakerLink']['url']) . '">'
              . e($video['speakerLink']['name']) . '</a>';
  } elseif (!empty($video['speaker'])) {
      // A speaker with no directory entry — the name still shows, just flat.
      $bits[] = e($video['speaker']);
  }
  if (!empty($video['recordedAt'])) { $bits[] = e($video['recordedAt']); }
  if (!empty($video['duration']))   { $bits[] = e(\Portal\Support\Str::duration((int) $video['duration'])); }
  echo implode(' &middot; ', $bits);
  ?>
</p>

<?php if (!empty($video['description'])): ?>
  <div class="video-description" style="max-width:48rem">
    <?php
    /*
     * Descriptions are written by editors, not the public, but they still pass
     * through nl2br(e()) rather than being echoed raw — an editor account is
     * exactly what an attacker would target to get stored HTML onto every
     * viewer's page.
     */
    echo nl2br(e($video['description']));
    ?>
  </div>
<?php endif ?>

<?php
/*
 * Save buttons.
 *
 * Plain form posts, one per list, so this works with scripting off and needs no
 * JavaScript to keep two buttons in sync with the server. Each is a toggle: the
 * label states what the video IS, and pressing it changes that.
 */
$savedLists ??= [];
$saveAction ??= '/saved';
$csrfField ??= '';
?>
<?php if ($saveAction !== '' && ($currentUser ?? null) !== null): ?>
  <p class="save-actions">
    <?php foreach ([
        'favorite'    => ['Favourite', 'Favourited'],
        'watch_later' => ['Watch later', 'Saved for later'],
    ] as $list => [$off, $on]):
        $isSaved = in_array($list, $savedLists, true); ?>
      <form method="post" action="<?= e($saveAction) ?>" class="inline">
        <?= $csrfField ?>
        <input type="hidden" name="video" value="<?= (int) $video['id'] ?>">
        <input type="hidden" name="list" value="<?= e($list) ?>">
        <button type="submit" class="btn secondary tiny<?= $isSaved ? ' on' : '' ?>"
                aria-pressed="<?= $isSaved ? 'true' : 'false' ?>">
          <?= e($isSaved ? $on : $off) ?>
        </button>
      </form>
    <?php endforeach ?>
  </p>
<?php endif ?>

<?php
/*
 * Attachments. Above chapters because a handout is something people came for,
 * where a chapter list is something they use once they are watching.
 */
$attachments ??= [];
?>
<?php if ($attachments !== []): ?>
  <section class="attachments" aria-labelledby="attachments-heading">
    <h2 class="section-title" id="attachments-heading">Files</h2>
    <ul class="attachment-list">
      <?php foreach ($attachments as $file): ?>
        <li>
          <a href="/asset/<?= (int) $file['id'] ?>/<?= rawurlencode($file['name']) ?>">
            <?= e($file['name']) ?>
            <span class="muted"> · <?= e($file['size']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>

<?php
/*
 * Chapters.
 *
 * Above the transcript and not collapsed: there are a handful of them, they
 * are the fastest way into a long recording, and hiding the thing somebody
 * came for behind a click is the wrong default.
 */
$chapters ??= [];
?>
<?php if ($chapters !== []): ?>
  <section class="chapters" aria-labelledby="chapters-heading">
    <h2 class="section-title" id="chapters-heading">Chapters</h2>
    <ol class="chapter-list">
      <?php foreach ($chapters as $chapter): ?>
        <li>
          <a href="?t=<?= (int) $chapter['start'] ?>">
            <span class="chapter-time"><?= e(\Portal\Support\Str::duration((int) $chapter['start'])) ?></span>
            <span><?= e($chapter['title']) ?></span>
          </a>
        </li>
      <?php endforeach ?>
    </ol>
  </section>
<?php endif ?>

<?php
/*
 * The transcript.
 *
 * Collapsed by default: it can run to thousands of lines, and a page that
 * opens two screens below the video is worse than one where the transcript is
 * a click away. <details> does that with no JavaScript at all, and the browser
 * still finds text inside a closed one when somebody uses Ctrl+F.
 */
$transcript ??= [];
?>
<?php if ($transcript !== []): ?>
  <details class="transcript" id="transcript">
    <summary>Transcript<span class="muted"> · <?= count($transcript) ?> lines</span></summary>

    <ol class="transcript-lines">
      <?php foreach ($transcript as $cue): ?>
        <li>
          <?php
          /*
           * The timestamp is a link to the same page with ?t=seconds rather
           * than a button that seeks. Seeking inside a cross-origin player
           * needs JavaScript and the provider's own protocol; a link works
           * now, is shareable, and survives that being added later.
           */
          ?>
          <a class="transcript-time"
             href="?t=<?= (int) $cue['start'] ?>#transcript"><?= e(\Portal\Support\Str::duration((int) $cue['start'])) ?></a>
          <span><?= e($cue['text']) ?></span>
        </li>
      <?php endforeach ?>
    </ol>
  </details>
<?php endif ?>

<?php do_action('after_video', $video) ?>

<?php if ($related !== []): ?>
  <section aria-labelledby="related-heading" style="margin-top:3rem">
    <h2 class="section-title" id="related-heading">More like this</h2>
    <div class="video-grid">
      <?php foreach ($related as $item): ?>
        <?= $template->partial('video-card', ['video' => $item]) ?>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php
/*
 * Resume position. Rendered as data rather than inline script arguments so the
 * player JS can read it without the template needing to know anything about
 * how playback is controlled.
 */
?>
<div id="portal-player-data"
     data-video-id="<?= (int) $video['id'] ?>"
     data-resume-at="<?= (int) ($video['resumeAt'] ?? 0) ?>"
     data-start-at="<?= (int) ($video['startAt'] ?? 0) ?>"
     hidden></div>

<script src="<?= e($assetsUrl ?? '/theme-asset/default') ?>/player.js" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
