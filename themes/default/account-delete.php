<?php
/**
 * Delete your own account.
 *
 * What stays behind is listed ABOVE the button, with how to deal with it first,
 * so nobody learns afterwards that something outlived the account.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, array{count: int, reason: string}> $stays
 * @var string|null $problem
 * @var string $token
 */

declare(strict_types=1);

$stays ??= [];
$problem ??= null;
$token ??= '';

// Plain names for the tables AccountDeletion::KEPT lists. A table the theme
// has no name for still appears, under its reason, rather than being dropped.
$names = [
    'event_signups'        => 'Event sign-ups',
    'form_responses'       => 'Form responses',
    'prayer_requests'      => 'Prayer requests',
    'broadcast_recipients' => 'Messages sent to you',
    'schedule_people'      => 'Names on a schedule',
    'rota_assignments'     => 'Rota slots somebody covered for you',
];

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Delete your account</h1>
<p class="page-subtitle"><a href="/account">Your account</a></p>

<?php if ($problem !== null): ?>
  <div class="notice error"><p><?= e($problem) ?></p></div>
<?php endif ?>

<p>This cannot be undone. <a href="/account/export.json">Download your data</a> first if you
   want to keep anything.</p>

<h2>What goes</h2>
<p class="muted">Your account, watch history, saved videos, notes, bookmarks and reading places, group and team memberships,
   subscriptions and the notifications you were sent, reminders, calendar feeds, chat messages,
   comments, ratings and reactions, and every device signed in to this account.</p>

<p class="muted small">A comment somebody else replied to is emptied rather than deleted, so their
   reply is not lost — it reads "This comment was removed", with nothing of yours left in it.</p>

<?php if ($stays !== []): ?>
  <h2>What stays, no longer connected to you</h2>
  <ul>
    <?php foreach ($stays as $table => $row): ?>
      <li>
        <strong><?= e($names[$table] ?? $table) ?></strong> (<?= (int) $row['count'] ?>) —
        <?= e($row['reason']) ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<form method="post" class="stacked-form" autocomplete="off">
  <input type="hidden" name="_token" value="<?= e($token) ?>">

  <label>
    Type your email address to confirm
    <input type="email" name="confirm_email" required autocapitalize="off" spellcheck="false">
  </label>

  <button class="btn danger" type="submit">Delete my account</button>
</form>

<?= $template->partial('footer', get_defined_vars()) ?>
