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
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<?php
/*
 * Back, and the trail above it, answer different questions: Back is where you
 * came from, the trail is where this video LIVES. Somebody who arrived from a
 * search or a shared link has no useful "back", and the trail is the only thing
 * that tells them the sermon is part three of a series.
 */
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

<?php
/*
 * Audio mode.
 *
 * The video above plays in a cross-origin iframe, so this site cannot change
 * its speed, cannot put anything on a lock screen, and cannot keep it playing
 * with the screen off. This is the same sermon as an ordinary <audio> element
 * on this origin, where all three are possible.
 *
 * Rendered as a <details> so it costs nothing until somebody asks for it —
 * `preload="none"` means no bytes are fetched, and the panel works with
 * scripting off: the audio plays, and only the speed control and sleep timer
 * are missing, which is the right thing to lose.
 */
?>
<?php
/*
 * A top-level view variable, like $downloadUrl beside it — NOT a key on
 * $video. The first version read $video['listenUrl'], which is always empty,
 * so the route worked, the setting worked, and the page never used either. A
 * smoke check that opened the page caught it; nothing else could have.
 */
$listenUrl ??= null;
?>
<?php if ($listenUrl !== null && $listenUrl !== ''): ?>
  <details class="listen">
    <summary>
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
        <path d="M4 14v-2a8 8 0 0 1 16 0v2"/>
        <rect x="2.5" y="13" width="4" height="7" rx="1.5"/>
        <rect x="17.5" y="13" width="4" height="7" rx="1.5"/>
      </svg>
      Listen
    </summary>

    <div class="listen-body">
      <audio id="portal-audio" controls preload="none"
             src="<?= e($listenUrl) ?>"></audio>

      <?php
      /*
       * Both controls start hidden and are revealed by the script. A speed
       * menu that does nothing is worse than no speed menu, and this is the
       * one part of the panel that genuinely cannot work without JavaScript.
       */
      ?>
      <div class="listen-controls" id="portal-audio-controls" hidden>
        <label>
          Speed
          <select id="portal-audio-speed">
            <option value="0.75">0.75&times;</option>
            <option value="1" selected>1&times;</option>
            <option value="1.25">1.25&times;</option>
            <option value="1.5">1.5&times;</option>
            <option value="1.75">1.75&times;</option>
            <option value="2">2&times;</option>
          </select>
        </label>

        <label>
          Sleep in
          <select id="portal-audio-sleep">
            <option value="0" selected>&mdash;</option>
            <option value="300">5 min</option>
            <option value="900">15 min</option>
            <option value="1800">30 min</option>
            <option value="3600">1 hour</option>
            <option value="-1">End of this</option>
          </select>
        </label>

        <span class="muted small" id="portal-audio-sleep-state" hidden></span>
      </div>

      <p class="muted small">
        Audio only. Where you get to is remembered in the same place as the video, so you can start
        listening here and finish watching, or the other way round.
      </p>
    </div>
  </details>
<?php endif ?>
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

    <?php
    /*
     * Marking by hand, beside the other two because it answers the same shape
     * of question about the same video. The label states what the video IS and
     * pressing it changes that, matching the pair above.
     *
     * Offered whatever the player reported, since the whole case for it is the
     * watching this site never saw — in the car, on somebody's television, or a
     * recording that ends in two minutes of credits so the heartbeat never
     * reached the end.
     */
    $watched = !empty($video['watched']);
    ?>
    <form method="post" action="/watch/mark" class="inline">
      <?= $csrfField ?>
      <input type="hidden" name="video_id" value="<?= (int) $video['id'] ?>">
      <input type="hidden" name="action" value="<?= $watched ? 'unwatched' : 'watched' ?>">
      <button type="submit" class="btn secondary tiny<?= $watched ? ' on' : '' ?>"
              aria-pressed="<?= $watched ? 'true' : 'false' ?>">
        <?= $watched ? 'Watched' : 'Mark as watched' ?>
      </button>
    </form>
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

/*
 * Download this, when the capability and the content policy both allow it.
 *
 * Null otherwise, for the same reason as the share panel above: the controller
 * asks both questions again at the route, so this is a control rather than a
 * permission, and a link that 403s reads as a broken site rather than a
 * setting.
 */
$downloadUrl ??= null;
$downloadSlug ??= '';
?>
<?php if ($downloadUrl !== null): ?>
  <p style="margin:1rem 0" data-download-slug="<?= e((string) ($downloadSlug ?? '')) ?>">
    <a class="btn secondary" href="<?= e($downloadUrl) ?>" download>Download the file</a>
    <button class="btn secondary" id="offline-save" hidden>Save for offline</button>
    <span class="muted small" id="offline-status">Downloading gives you an MP4 to keep.</span>
  </p>

  <script src="<?= e(asset_url('/assets/offline.js')) ?>" defer></script>
  <script defer>
  /*
   * Two different things, offered side by side because they are not the same
   * and people want both.
   *
   * The LINK hands over an MP4 the way any download works — it lands in the
   * downloads folder and can be copied to a memory stick. It cannot be played
   * inside this site with no network.
   *
   * SAVE FOR OFFLINE puts the same file in this browser's storage, where the
   * service worker can serve it back with the range requests a player needs to
   * seek. It is not a file anybody can find in a folder.
   *
   * The button is hidden until the script decides the browser can do it, so a
   * browser that cannot shows only the link rather than a control that fails.
   */
  window.addEventListener('DOMContentLoaded', function () {
    var api = window.PortalOffline;
    var wrap = document.querySelector('[data-download-slug]');
    var button = document.getElementById('offline-save');
    var status = document.getElementById('offline-status');

    if (!api || !api.supported() || !wrap || !button) { return; }

    var slug = wrap.getAttribute('data-download-slug');
    button.hidden = false;

    api.list().then(function (rows) {
      var saved = rows.some(function (row) { return row.slug === slug; });
      if (saved) {
        button.disabled = true;
        status.textContent = 'Already saved on this device.';
      }
    });

    button.addEventListener('click', function () {
      button.disabled = true;
      status.textContent = 'Saving…';

      api.save(slug, function (loaded, total) {
        // Progress matters more than it looks. This is several hundred
        // megabytes; a silent wait reads as a broken button and people press
        // it again.
        status.textContent = total
          ? 'Saving… ' + Math.round((loaded / total) * 100) + '%'
          : 'Saving… ' + api.bytes(loaded);
      }).then(function () {
        status.textContent = 'Saved. It is in ';
        var link = document.createElement('a');
        link.href = '/account/downloads';
        link.textContent = 'your downloads';
        status.appendChild(link);
        status.appendChild(document.createTextNode('.'));
      }).catch(function (error) {
        // The reason, verbatim. "Download failed" is the answer to four
        // different problems and useful for none of them.
        button.disabled = false;
        status.textContent = error && error.message ? error.message : 'It could not be saved.';
      });
    });
  });
  </script>
<?php endif; ?>
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
     <?php
     /*
      * For the lock screen. The Media Session API wants a title, an artist and
      * artwork, and without them a phone shows the page URL — which tells
      * somebody in a car nothing about which sermon is playing.
      *
      * The artwork is whatever the preview card resolved to, so artwork that
      * was withheld from the page is withheld from the lock screen too rather
      * than being minted again here — an operating system caches what it is
      * handed, and a withheld frame given to one is not recallable.
      */
     ?>
     data-title="<?= e($video['title']) ?>"
     data-artist="<?= e((string) ($video['speaker'] ?? '')) ?>"
     data-artwork="<?= e((string) ($lockScreenArtwork ?? '')) ?>"
     hidden></div>

<script src="<?= e(isset($themeAsset) ? $themeAsset('player.js') : ($assetsUrl ?? '/theme-asset/default') . '/player.js') ?>" defer></script>

<?= $template->partial('footer', get_defined_vars()) ?>
