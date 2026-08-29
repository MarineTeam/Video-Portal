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

<?php elseif (!empty($video['locked'])): ?>
  <?php
  /*
   * Held back by a series that is meant to be watched in order. Like a
   * premiere, the embed URL was never minted, so there is nothing on the page
   * to reach for.
   *
   * It names the episode and links to it. "Locked" on its own is a dead end,
   * and the one thing this person needs is the way forward.
   */
  ?>
  <div class="premiere">
    <p class="premiere-label">Watch in order</p>
    <p class="premiere-date">
      Finish
      <a href="<?= e($video['locked']['url']) ?>"><?= e($video['locked']['title']) ?></a>
      first.
    </p>
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

/*
 * Scripture references, above the chapters.
 *
 * Chips rather than prose, and every one is a link to everything else on that
 * chapter — a reference printed as text says what was preached, a reference
 * that is a link turns the archive into something you can follow, which is the
 * whole reason for indexing them.
 */
$scripture ??= [];
/*
 * Defaulted, not assumed. A third-party theme or an older controller may not
 * pass this, and an undefined variable here is a fatal on the page whose whole
 * job is playing the video.
 */
$tags ??= [];

/*
 * Notes.
 *
 * Private to whoever wrote them — nobody else on this site can read them, and
 * there is no screen anywhere that lists other people's. Said on the panel
 * rather than only in the migration, because somebody deciding whether to write
 * something down during a service is deciding it right there.
 */
$note ??= '';
?>

<?php if (!empty($video['embedUrl'])): ?>
  <section class="notes" aria-labelledby="notes-heading">
    <h2 class="section-title" id="notes-heading">My notes</h2>
    <p class="muted small">
      Only you can read these. They save as you type, and they are all together
      on <a href="/notes">your notes page</a>.
    </p>

    <p>
      <button type="button" id="note-timestamp" class="btn tiny secondary">
        Add the current time
      </button>
      <span id="note-status" class="muted small" role="status" aria-live="polite"></span>
    </p>

    <label class="visually-hidden" for="note-body">Notes on this video</label>
    <textarea id="note-body" rows="8" data-video="<?= (int) ($video['id'] ?? 0) ?>"
              placeholder="Anything worth keeping."><?= e($note) ?></textarea>
  </section>

  <script>
  (function () {
    var box = document.getElementById('note-body');
    var status = document.getElementById('note-status');
    var stamp = document.getElementById('note-timestamp');
    if (!box) { return; }

    var videoId = parseInt(box.dataset.video || '0', 10);
    var timer = null;
    var lastSaved = box.value;

    function save() {
      if (box.value === lastSaved) { return; }
      var body = box.value;

      fetch('/notes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ videoId: videoId, body: body })
      }).then(function (response) {
        if (!response.ok) { throw new Error('refused'); }
        lastSaved = body;
        status.textContent = 'Saved';
      }).catch(function () {
        /* Said out loud. A note that silently failed to save is the worst
           outcome here: somebody keeps typing, trusting it. */
        status.textContent = 'Not saved — check your connection';
      });
    }

    /* Debounced, not per keystroke: this is a page somebody types a paragraph
       into, and a request per character is thousands of writes per service. */
    box.addEventListener('input', function () {
      status.textContent = '';
      if (timer) { clearTimeout(timer); }
      timer = setTimeout(save, 1500);
    });

    /* And on the way out, in the cases where the timer will not fire. pagehide
       covers what beforeunload does not, notably on iOS. */
    window.addEventListener('pagehide', save);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') { save(); }
    });

    if (stamp) {
      stamp.addEventListener('click', function () {
        /* Asked at the moment of the click rather than tracked, so the number
           cannot be stale. Absent player, absent button behaviour: this degrades
           to doing nothing rather than inserting a wrong time. */
        if (!window.portalPlayer) { return; }

        var at = window.portalPlayer.position();
        var text = Math.floor(at / 60) + ':' + ('0' + (at % 60)).slice(-2);

        var prefix = box.value === '' || box.value.slice(-1) === '\n' ? '' : '\n';
        box.value += prefix + text + ' ';
        box.focus();

        if (timer) { clearTimeout(timer); }
        timer = setTimeout(save, 1500);
      });
    }
  })();
  </script>
<?php endif ?>

<?php if ($scripture !== []): ?>
  <section class="scripture" aria-labelledby="scripture-heading">
    <h2 class="section-title" id="scripture-heading">Scripture</h2>
    <div class="chips">
      <?php foreach ($scripture as $reference): ?>
        <a class="chip" href="<?= e($reference['url']) ?>"><?= e($reference['label']) ?></a>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php if ($tags !== []): ?>
  <section class="tags" aria-labelledby="tags-heading">
    <h2 class="section-title" id="tags-heading">Tags</h2>
    <div class="chips">
      <?php foreach ($tags as $tag): ?>
        <a class="chip" href="<?= e($tag['url']) ?>"><?= e($tag['label']) ?></a>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

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

<?php
/*
 * Share this, for somebody holding share_content on this video.
 *
 * Null for everybody else, so this block renders nothing rather than a form
 * that would 403 — the controller checks the same capability again, against
 * the same video, because a hidden form is not a permission check.
 */
$sharePanel ??= null;
?>
<?php if ($sharePanel !== null): ?>
  <section class="card" id="share" style="margin:2rem 0;padding:1rem 1.25rem">
    <h2 class="section-title">Share this</h2>
    <form method="post" action="<?= e($sharePanel['action']) ?>">
      <?= $csrfField ?? '' ?>
      <input type="hidden" name="video_id" value="<?= (int) $sharePanel['videoId'] ?>">

      <label>Send to
        <input type="email" name="email" required autocomplete="off"
               placeholder="their@email.example">
      </label>

      <label>They open it by
        <select name="access_mode">
          <option value="account">Signing in as that address</option>
          <option value="gate">Confirming that address by email</option>
        </select>
      </label>

      <label>Expires after
        <input type="number" name="hours" value="72" min="1"
               max="<?= (int) $sharePanel['maxHours'] ?>"> hours
      </label>

      <label>Passphrase <span class="muted">(optional)</span>
        <input type="text" name="passphrase" autocomplete="off"
               minlength="<?= (int) $sharePanel['minimumPass'] ?>" maxlength="200"
               placeholder="Leave empty for none">
      </label>

      <button class="btn">Send the link</button>
    </form>

    <p class="muted small">
      A passphrase is never included in the email — tell them yourself. You can see and revoke
      everything you have shared on <a href="/account/shared-links">your account</a>.
    </p>
  </section>
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

<script src="<?= e(isset($themeAsset) ? $themeAsset('player.js') : ($assetsUrl ?? '/theme-asset/default') . '/player.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
