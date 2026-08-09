<?php
/**
 * The unsubscribe page.
 *
 * Reached from a link in an email, by somebody with no account, possibly on a
 * phone, possibly years later. So: no sign-in, no explanation required, and the
 * button is the first thing on the page.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string  $token
 * @var bool    $found
 * @var ?string $description
 * @var bool    $done
 */

declare(strict_types=1);

$token ??= '';
$found ??= false;
$description ??= null;
$done ??= false;

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= $done ? 'Unsubscribed' : 'Unsubscribe' ?></h1>

<?php if ($done): ?>
  <p class="page-subtitle">Done. You will not get any more email about new videos.</p>
  <p><a class="btn secondary" href="/">Back to the library</a></p>

<?php elseif (!$found): ?>
  <?php
  /*
   * Deliberately the same page for "already unsubscribed" and "that token was
   * never real". A token is a credential, and "no such subscription" tells
   * whoever is probing that a different guess might work.
   */
  ?>
  <p class="page-subtitle">There is nothing to unsubscribe here — that link has already been
     used, or it has expired.</p>
  <p><a class="btn secondary" href="/">Back to the library</a></p>

<?php else: ?>
  <p class="page-subtitle">
    You are subscribed to <?= e($description ?? 'this site') ?>.
  </p>

  <?php
  /*
   * No CSRF token: the subscription token in the hidden field IS the
   * authority, and the person using it arrived from an email with no session
   * at all. See SubscriptionController::unsubscribe().
   */
  ?>
  <form method="post" action="/unsubscribe">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <p>
      <button class="btn" type="submit">Unsubscribe from this</button>
    </p>

    <?php
    /*
     * The second button matters more than it looks. Somebody who subscribed to
     * four things and remembers none of them needs one action that stops all
     * of it — otherwise they use the spam button, which costs the whole site's
     * deliverability rather than one subscription.
     */
    ?>
    <p>
      <button class="btn secondary" type="submit" name="all" value="1">
        Unsubscribe from everything on this site
      </button>
    </p>
  </form>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
