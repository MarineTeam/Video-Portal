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

<?php
/*
 * The chat.
 *
 * THE MESSAGES ARE RENDERED HERE, SERVER-SIDE, and the script then takes over
 * appending to them. Not because a no-script chat is a good chat — it is a page
 * you have to reload — but because two things genuinely must not depend on a
 * script loading:
 *
 *   READING. Somebody arriving at a finished stream is reading a transcript,
 *   which is ordinary content and should be in the HTML.
 *
 *   MODERATING. The hide and mute controls below are real forms with real
 *   tokens, so the one action you need most on the evening something is
 *   misbehaving is the one that does not need the thing that is misbehaving.
 *   This is the same reasoning as the admin navigation opening on a checkbox.
 */
?>
<?php if (($chatState ?? '') !== ''): ?>
  <section class="chat"
           data-chat="/live/<?= e((string) $stream['slug']) ?>/chat"
           data-token="<?= e((string) ($chatToken ?? '')) ?>"
           data-cursor="<?= (int) ($chatCursor ?? 0) ?>"
           data-moderate="<?= !empty($chatCanModerate) ? '1' : '0' ?>">

    <h2>Chat</h2>

    <div class="chat-log" data-chat-log>
      <?php foreach (($chatMessages ?? []) as $message): ?>
        <div class="chat-message<?= !empty($message['mine']) ? ' chat-mine' : '' ?>"
             data-message="<?= (int) $message['id'] ?>">
          <span class="chat-author"><?= e((string) $message['author']) ?></span>
          <span class="chat-body"><?= e((string) $message['body']) ?></span>

          <?php if (!empty($chatCanModerate) && empty($message['mine'])): ?>
            <form method="post" action="/live/<?= e((string) $stream['slug']) ?>/chat/moderate"
                  class="chat-moderate">
              <input type="hidden" name="_token" value="<?= e((string) ($chatToken ?? '')) ?>">
              <input type="hidden" name="message" value="<?= (int) $message['id'] ?>">
              <input type="hidden" name="_plain" value="1">
              <button name="action" value="hide" class="chat-hide">hide</button>
              <button name="action" value="mute" class="chat-mute">mute</button>
            </form>
          <?php endif ?>
        </div>
      <?php endforeach ?>

      <?php if (($chatMessages ?? []) === []): ?>
        <p class="muted">Nothing said yet.</p>
      <?php endif ?>
    </div>

    <p class="chat-notice" data-chat-notice><?= e((string) ($chatClosed ?? '')) ?></p>

    <?php if (($chatState ?? '') === 'open' && !empty($chatMayPost)): ?>
      <form method="post" action="/live/<?= e((string) $stream['slug']) ?>/chat" data-chat-form>
        <input type="hidden" name="_token" value="<?= e((string) ($chatToken ?? '')) ?>">
        <?php
        /*
         * `_plain` marks a submission the BROWSER made, because the script
         * builds its own body from two fields and never sends it. Its presence
         * is what tells the controller to answer with a redirect and a flash
         * instead of a screenful of JSON — see LiveChatController::answer().
         */
        ?>
        <input type="hidden" name="_plain" value="1">
        <?php
        /*
         * aria-label rather than a visually-hidden <label>. This theme has no
         * class for one, and a placeholder is not a label: it disappears the
         * moment somebody types, so a screen reader arriving at a half-filled
         * box would have nothing to announce.
         */
        ?>
        <input type="text" name="body" maxlength="500" autocomplete="off"
               aria-label="Message" placeholder="Say something" data-chat-input>
        <button class="btn">Send</button>
      </form>
    <?php elseif (($chatState ?? '') !== 'open'): ?>
      <p class="muted small"><?= e((string) ($chatClosed ?? '')) ?></p>
    <?php else: ?>
      <p class="muted small"><a href="/auth/login">Sign in</a> to join the chat.</p>
    <?php endif ?>
  </section>

  <script src="<?= e(isset($themeAsset)
      ? $themeAsset('live-chat.js')
      : ($assetsUrl ?? '/theme-asset/default') . '/live-chat.js') ?>" defer></script>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
