<?php
/**
 * Taking a name off with the link, for somebody who has no account.
 *
 * A page with a button rather than an action on the GET. The link is fetched by
 * things that are not the person holding it — a mail client's preview, a
 * security scanner, a chat app's unfurler — and every one of those would cancel
 * a place if arriving here were enough.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed>|null $event
 * @var array<string, mixed>|null $signup
 * @var bool $done
 * @var string $message
 * @var string $token
 */

declare(strict_types=1);

$event ??= null;
$signup ??= null;
$done ??= false;
$message ??= '';
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<?php if ($done): ?>
  <h1 class="page-title">Taken off the list</h1>
  <p class="page-subtitle"><?= e($message) ?></p>
  <p><a class="btn secondary" href="/events">See what else is on</a></p>
<?php else: ?>
  <h1 class="page-title">Take your name off?</h1>

  <?php if ($event !== null): ?>
    <p class="page-subtitle">
      <?= e((string) $event['title']) ?><?php
        $time = strtotime((string) $event['starts_at']);
        if ($time !== false) { echo ' — ' . e(date('l j F Y, g:ia', $time)); }
      ?>
    </p>
  <?php endif ?>

  <?php if ($signup !== null && (int) $signup['guests'] > 0): ?>
    <p class="muted">This takes off you and <?= (int) $signup['guests'] ?>
       other<?= (int) $signup['guests'] === 1 ? '' : 's' ?>.</p>
  <?php endif ?>

  <form method="post">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <button class="btn danger">Yes, take my name off</button>
    <a class="btn secondary" href="/events">No, leave it</a>
  </form>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
