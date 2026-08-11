<?php
/**
 * Change your own password.
 *
 * Reached by somebody who is already signed in and holds a local password —
 * which on a site using Auth0 means an administrator holding the break-glass
 * credential. That is the account most worth rotating and, until this page
 * existed, the one account whose password could never be changed at all.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string       $token
 * @var int          $minimum
 * @var list<string> $problems
 */

declare(strict_types=1);

$token ??= '';
$minimum ??= 12;
$problems ??= [];

// A change is reported through the query string rather than a flash, because
// the session is deliberately rebuilt on the way here — every other session for
// the account is ended, and a flash stored in the old one would not survive it.
$changed = ($_GET['changed'] ?? '') === '1';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Change your password</h1>

<?php if ($changed): ?>
  <div class="notice ok">
    <p><strong>Your password has been changed.</strong></p>
    <p>Everywhere else that was signed in to this account has been signed out. You are still
       signed in here.</p>
  </div>
  <p><a class="btn secondary" href="/">Back to the library</a></p>

<?php else: ?>

  <?php if ($problems !== []): ?>
    <div class="notice error">
      <ul>
        <?php foreach ($problems as $problem): ?>
          <li><?= e($problem) ?></li>
        <?php endforeach ?>
      </ul>
    </div>
  <?php endif ?>

  <form method="post" class="stacked-form" autocomplete="off">
    <input type="hidden" name="_token" value="<?= e($token) ?>">

    <label>
      Current password
      <input type="password" name="current_password" autocomplete="current-password" required>
    </label>

    <label>
      New password
      <input type="password" name="new_password" autocomplete="new-password"
             minlength="<?= (int) $minimum ?>" required>
    </label>

    <label>
      New password again
      <input type="password" name="confirm_password" autocomplete="new-password"
             minlength="<?= (int) $minimum ?>" required>
    </label>

    <p class="muted small">At least <?= (int) $minimum ?> characters. Length is what matters —
       a few ordinary words together beat a short one with symbols in it.</p>

    <button class="btn" type="submit">Change password</button>
  </form>

  <p class="muted small">Changing it signs out every other browser and device using this account.</p>

<?php endif ?>

<?php echo $template->partial('footer', get_defined_vars());
