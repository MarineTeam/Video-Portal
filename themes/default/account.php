<?php
/**
 * The account hub.
 *
 * Everything on this page is about the person looking at it. Until it existed
 * the only account page in the product was the password form, which was
 * reachable by typing its URL and from nowhere else.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var \Portal\Auth\User $account
 * @var int  $unread
 * @var bool $hasPassword
 */

declare(strict_types=1);

$unread ??= 0;
$hasPassword ??= false;

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Your account</h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice ok"><?= e((string) $flash['message']) ?></div>
<?php endif ?>

<section class="card" style="margin-bottom:2rem">
  <p style="margin:0 0 .25rem"><strong><?= e($account->name ?? $account->email) ?></strong></p>
  <p class="muted small" style="margin:0"><?= e($account->email) ?></p>

  <?php
  /*
   * Approval is the thing somebody in this state is actually wondering about,
   * so the account page says it rather than leaving them to infer it from a
   * video refusing to play.
   */
  ?>
  <?php if (!$account->isAdmin() && !$account->authorized): ?>
    <p class="muted small" style="margin:.75rem 0 0">
      Your account is waiting for approval. You can browse the library, and you will be able to
      watch once somebody approves you.
    </p>
  <?php endif ?>
</section>

<div class="account-grid">
  <a class="card account-tile" href="/account/notifications">
    <strong>Notifications</strong>
    <span class="muted small">
      <?php if ($unread > 0): ?>
        <?= (int) $unread ?> unread
      <?php else: ?>
        What this site has sent you
      <?php endif ?>
    </span>
  </a>

  <a class="card account-tile" href="/saved">
    <strong>Saved</strong>
    <span class="muted small">Favourites and watch later</span>
  </a>

  <a class="card account-tile" href="/notes">
    <strong>Notes</strong>
    <span class="muted small">What you wrote while watching</span>
  </a>

  <?php
  /*
   * Always listed, even for somebody who cannot currently share.
   * Withdrawing the capability leaves their existing links working, and
   * revoking those is the thing they would come here to do.
   */
  ?>
  <a class="card account-tile" href="/account/shared-links">
    <strong>Shared links</strong>
    <span class="muted small">What you have handed out</span>
  </a>

  <?php
  /*
   * Only for accounts that have a local password. Somebody who signs in
   * through an identity provider has no credential here, and the page itself
   * refuses them — linking to it would be offering a door that is locked.
   */
  ?>
  <?php if ($hasPassword): ?>
    <a class="card account-tile" href="/account/password">
      <strong>Password</strong>
      <span class="muted small">Change the one you sign in with</span>
    </a>
  <?php endif ?>
</div>

<?= $template->partial('footer', get_defined_vars()) ?>
