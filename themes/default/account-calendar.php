<?php
/**
 * A calendar feed for your own dates.
 *
 * The address on this page IS the password. There is no other authentication —
 * a calendar application cannot log in — so the page has to say that plainly
 * rather than presenting the URL as an ordinary link.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var string|null $feedToken
 * @var string|null $lastUsed
 * @var int    $fetches
 * @var string $base
 * @var string $token
 */

declare(strict_types=1);

$feedToken ??= null;
$lastUsed ??= null;
$fetches ??= 0;
$base ??= '';
$token ??= '';

$url = $feedToken === null ? '' : $base . '/calendar/mine/' . $feedToken . '.ics';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Your calendar</h1>
<p class="page-subtitle"><a href="/account">Your account</a></p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice ok"><?= e((string) $flash['message']) ?></div>
<?php endif ?>

<p class="muted">Your rota, the events you have signed up to, and the dates a schedule names you
   on — in whatever calendar you already use. It updates itself; you do not come back here.</p>

<?php if ($feedToken === null): ?>
  <div class="empty">
    <p>You do not have one yet.</p>
    <p class="muted small">Nothing exists until you ask for it, so there is no address to be
       found or guessed for an account that never wanted one.</p>
  </div>

  <form method="post">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <button class="btn" name="action" value="issue">Make me a calendar address</button>
  </form>

<?php else: ?>

  <?php
  /*
   * Stated before the address, not after it. Somebody who does not know this
   * pastes the URL into a shared family calendar, and it is their rota and
   * their whereabouts for the next six months.
   */
  ?>
  <div class="notice">
    <strong>This address is the password.</strong>
    <p class="muted small">Anybody who has it can read your dates — there is nothing else to sign
       in with, because a calendar application cannot. Treat it like a password: paste it into
       your own calendar and nowhere else.</p>
  </div>

  <label>Your calendar address
    <input type="text" readonly value="<?= e($url) ?>" onclick="this.select()"
           style="width:100%;font-family:monospace">
  </label>

  <p class="muted small">
    <?php if ($lastUsed === null): ?>
      Nothing has fetched it yet. A calendar usually asks within the hour.
    <?php else: ?>
      Last fetched <?= e((string) $lastUsed) ?> · <?= (int) $fetches ?> time(s).
      <?php /* The only evidence that the feed is working: a subscription that
                silently stopped looks exactly like a rota with nothing on it. */ ?>
    <?php endif ?>
  </p>

  <h2 class="section-title">If you think somebody else has it</h2>

  <form method="post">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <button class="btn secondary" name="action" value="issue"
            onclick="return confirm('Replace it? Every calendar using the old address will stop.')">
      Replace the address
    </button>
    <button class="btn secondary" name="action" value="stop"
            onclick="return confirm('Stop the feed? Every calendar using it will find nothing.')">
      Stop the feed
    </button>
  </form>

  <p class="muted small">Replacing it gives you a new address and <strong>stops the old one
     everywhere at once</strong>. That is deliberate: a feed has no idea who is reading it, so
     there is no list of subscribers to remove one from — one action has to end them all.</p>

<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
