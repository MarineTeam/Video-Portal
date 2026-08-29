<?php
/**
 * The links this person has handed out.
 *
 * Revoked and expired links stay listed. A member's list is the record of who
 * they gave access to, and a link disappearing the moment it lapses is how
 * somebody loses track of that.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<\Portal\Sharing\Share> $shares
 * @var string $token
 */

declare(strict_types=1);

$shares ??= [];
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Links you have shared</h1>
<p class="page-subtitle"><a href="/account">Your account</a></p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<?php if ($shares === []): ?>
  <div class="empty">
    <p>You have not shared anything.</p>
    <p class="muted small">A Share panel appears under a video when you are allowed to share it.</p>
  </div>

<?php else: ?>
  <table>
    <thead>
      <tr><th>Video</th><th>Sent to</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($shares as $share): ?>
        <?php
        $live = $share->isLive();
        $status = $share->isRevoked()
            ? '<span class="pill">Revoked</span>'
            : ($live
                ? '<span class="pill ok">Live</span>'
                : '<span class="pill">Expired</span>');
        ?>
        <tr>
          <td>
            <strong><?= e($share->videoTitle) ?></strong>
            <?php if ($share->passwordProtected): ?>
              <br><span class="muted small">Needs a passphrase</span>
            <?php endif ?>
          </td>
          <td><span class="muted"><?= e($share->recipientEmail) ?></span></td>
          <td>
            <?= $status ?>
            <br><span class="muted small">
              <?php
              /*
               * Opens, not "views". The number counts times the link was
               * followed, which is not the same as times the video was
               * watched — saying "views" would overstate what is known.
               */
              ?>
              <?= (int) $share->viewCount ?> open<?= $share->viewCount === 1 ? '' : 's' ?>
            </span>
          </td>
          <td class="right">
            <?php if ($live): ?>
              <form method="post" action="/share/revoke" class="inline">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="id" value="<?= e($share->id) ?>">
                <button class="btn tiny danger"
                        onclick="return confirm('Revoke this link? It stops working immediately.')">
                  Revoke
                </button>
              </form>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
