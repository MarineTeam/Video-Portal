<?php
/**
 * What this site has told you.
 *
 * A complete record whatever channel it went out over, which is the whole
 * point: an email is in a mailbox this app cannot read, and a push
 * notification is gone the moment it is dismissed — or never arrived at all,
 * because the browser was closed or permission was refused. This is the only
 * place any of it can be looked up afterwards.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $notifications
 * @var int    $unread
 * @var string $token
 */

declare(strict_types=1);

$notifications ??= [];
$unread ??= 0;
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Notifications</h1>
<p class="page-subtitle">
  <a href="/account">Your account</a> ·
  <?= $unread > 0 ? (int) $unread . ' unread' : 'Nothing unread' ?>
</p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice ok"><?= e((string) $flash['message']) ?></div>
<?php endif ?>

<?php if ($notifications === []): ?>
  <div class="empty">
    <p>Nothing yet.</p>
    <p class="muted small">
      Anything this site sends you — by email or as a push notification — is kept here, so you can
      catch up on a device that never received it.
    </p>
  </div>

<?php else: ?>
  <form method="post" class="toolbar" style="margin-bottom:1rem">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <?php if ($unread > 0): ?>
      <button class="btn secondary" name="action" value="read-all">Mark all read</button>
    <?php endif ?>
    <button class="btn secondary danger" name="action" value="clear"
            onclick="return confirm('Clear every notification? This cannot be undone.')">
      Clear all
    </button>
  </form>

  <ul class="notification-list">
    <?php foreach ($notifications as $item): ?>
      <?php
      $isUnread = ($item['read_at'] ?? null) === null;
      $url = trim((string) ($item['url'] ?? ''));
      ?>
      <li class="notification<?= $isUnread ? ' is-unread' : '' ?>">
        <div class="notification-body">
          <?php
          /*
           * The title is the one the notification carried, not the video's
           * current one — see the migration. A row whose video has since been
           * deleted keeps its text and loses only its link, which is why the
           * anchor is conditional rather than always rendered.
           */
          ?>
          <?php if ($url !== ''): ?>
            <a href="<?= e($url) ?>"><?= e((string) $item['title']) ?></a>
          <?php else: ?>
            <span><?= e((string) $item['title']) ?></span>
          <?php endif ?>

          <span class="muted small">
            <?= e(\Portal\Support\Str::since((string) ($item['created_at'] ?? ''))) ?>
            · <?= e((string) ($item['channel'] ?? '')) ?>
          </span>
        </div>

        <div class="notification-actions">
          <?php if ($isUnread): ?>
            <form method="post" class="inline">
              <input type="hidden" name="_token" value="<?= e($token) ?>">
              <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
              <button class="btn tiny" name="action" value="read">Mark read</button>
            </form>
          <?php endif ?>
          <form method="post" class="inline">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <button class="btn tiny danger" name="action" value="delete">Delete</button>
          </form>
        </div>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
