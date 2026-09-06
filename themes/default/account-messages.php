<?php
/**
 * What this site may send you, and how.
 *
 * Three separate choices, because the three channels are not equivalent: an
 * email costs nothing and arrives where things arrive, a text costs the church
 * money and lands on a lock screen at whatever hour it is sent, and a push
 * notification needs a device you registered in the browser.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array{email_opt_out: bool, sms_opt_in: bool, phone: ?string} $prefs
 * @var bool   $phoneReadable
 * @var string $token
 */

declare(strict_types=1);

$prefs ??= ['email_opt_out' => false, 'sms_opt_in' => false, 'phone' => null];
$phoneReadable ??= true;
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">What we send you</h1>
<p class="page-subtitle"><a href="/account">Your account</a> · <a href="/account/notifications">What you have been sent</a></p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice ok"><?= e((string) $flash['message']) ?></div>
<?php endif ?>

<form method="post" class="card">
  <input type="hidden" name="_token" value="<?= e($token) ?>">

  <label class="check">
    <input type="checkbox" name="by_email" value="1" <?= $prefs['email_opt_out'] ? '' : 'checked' ?>>
    Email me about what is going on
  </label>
  <p class="muted small">On unless you turn it off. This does not affect things you asked for
     individually, like a share link or a rota reminder.</p>

  <label class="check">
    <input type="checkbox" name="by_sms" value="1" <?= $prefs['sms_opt_in'] ? 'checked' : '' ?>>
    Text me
  </label>
  <p class="muted small"><strong>Off unless you turn it on.</strong> A text costs the church money
     and arrives on your lock screen, so we do not send one just because we have your number.</p>

  <label>Your mobile number
    <input type="tel" name="phone" value="<?= e((string) ($prefs['phone'] ?? '')) ?>"
           placeholder="07700 900123">
  </label>

  <?php if (!$phoneReadable): ?>
    <?php
      /*
       * Said to the person who typed it, because they are the only one who can
       * fix it. Without this the opt-in is ticked, the number is stored, and no
       * text ever arrives with nothing anywhere explaining why.
       */
    ?>
    <div class="notice error">
      <strong>We cannot read that number.</strong>
      <p class="muted small">Texts will not go to it. Check it is a mobile number, and include the
         country code if you are outside the church's own country — for example +44 7700 900123.</p>
    </div>
  <?php endif ?>

  <p class="muted small">Push notifications are separate: they go to a browser you have turned them
     on in, and you turn them off in that browser or from the bell on any page.</p>

  <button class="btn">Save</button>
</form>

<?= $template->partial('footer', get_defined_vars()) ?>
