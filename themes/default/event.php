<?php
/**
 * One event, and the form for putting your name down.
 *
 * NO ACCOUNT IS NEEDED, so the form asks for a name and an address and fills
 * them in for somebody who happens to be signed in. Every refusal — not open
 * yet, closed, already happened, nothing to fill in — is a sentence saying what
 * to do next rather than an absent form, because a form that simply is not
 * there reads as the site being broken.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $event
 * @var string $signupState
 * @var int $taken
 * @var int|null $placesLeft
 * @var array<string, mixed>|null $yours
 * @var \Portal\Auth\User|null $me
 * @var string $token
 */

declare(strict_types=1);

$event ??= [];
$signupState ??= 'not_offered';
$taken ??= 0;
$placesLeft ??= null;
$yours ??= null;
$me ??= null;
$token ??= '';

$when = static function (?string $stamp): string {
    if ($stamp === null || $stamp === '') {
        return '';
    }

    $time = strtotime($stamp);

    return $time === false ? $stamp : date('l j F Y, g:ia', $time);
};

$open = $signupState === \Portal\Events\SignupWindow::OPEN;

echo $template->partial('header', get_defined_vars());
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<h1 class="page-title"><?= e((string) ($event['title'] ?? '')) ?></h1>
<p class="page-subtitle">
  <?= e($when((string) ($event['starts_at'] ?? ''))) ?>
  <?php if (!empty($event['location'])): ?>
    &middot; <?= e((string) $event['location']) ?>
  <?php endif ?>
</p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<?php if (empty($event['is_published'])): ?>
  <p class="notice error">This is a draft. Nobody else can see it yet.</p>
<?php endif ?>

<?php if (!empty($event['description'])): ?>
  <div class="event-description"><?= nl2br(e((string) $event['description'])) ?></div>
<?php endif ?>

<?php if ($yours !== null): ?>
  <section class="event-yours" aria-labelledby="yours-heading">
    <h2 class="section-title" id="yours-heading">
      <?= $yours['state'] === 'going' ? 'You are down for this' : 'You are on the waiting list' ?>
    </h2>

    <p class="muted small">
      <?php if ((int) $yours['guests'] > 0): ?>
        You and <?= (int) $yours['guests'] ?> other<?= (int) $yours['guests'] === 1 ? '' : 's' ?>.
      <?php endif ?>
      <?php if ($yours['state'] === 'waiting'): ?>
        We will let you know if a place comes free — you do not need to do anything.
      <?php endif ?>
    </p>

    <?php if ($me !== null): ?>
      <form method="post" action="/events/cancel" class="inline">
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <input type="hidden" name="event" value="<?= e((string) $event['slug']) ?>">
        <button class="btn secondary">Take my name off</button>
      </form>
    <?php else: ?>
      <p class="muted small">Use the link you were given to take your name off.</p>
    <?php endif ?>
  </section>
<?php endif ?>

<?php if ($signupState !== \Portal\Events\SignupWindow::NOT_OFFERED): ?>
  <section aria-labelledby="signup-heading">
    <h2 class="section-title" id="signup-heading">
      <?= $yours === null ? 'Put your name down' : 'Change your booking' ?>
    </h2>

    <?php if ($placesLeft !== null): ?>
      <p class="muted small">
        <?php if ($placesLeft > 0): ?>
          <?= (int) $placesLeft ?> place<?= $placesLeft === 1 ? '' : 's' ?> left.
        <?php else: ?>
          It is full — anybody signing up now goes on the waiting list, in order.
        <?php endif ?>
      </p>
    <?php endif ?>

    <?php if (!$open): ?>
      <?php
      /*
       * Said, not hidden. An absent form reads as the site being broken, where
       * "sign-up opens on Monday" tells somebody to come back.
       */
      ?>
      <p class="notice"><?= e(\Portal\Events\SignupWindow::explain(
          $signupState,
          $when((string) ($event['signup_opens_at'] ?? ''))
      )) ?></p>
    <?php else: ?>
      <form method="post" action="/events/signup" class="event-signup">
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <input type="hidden" name="event" value="<?= e((string) $event['slug']) ?>">

        <label>Your name
          <input type="text" name="name" required maxlength="190"
                 value="<?= e((string) ($yours['name'] ?? $me?->name ?? '')) ?>">
        </label>

        <label>Email
          <input type="email" name="email" required maxlength="190"
                 value="<?= e((string) ($yours['email'] ?? $me?->email ?? '')) ?>">
          <span class="muted small">So we can let you know if anything changes. No account needed.</span>
        </label>

        <?php if ((int) ($event['max_guests'] ?? 0) > 0): ?>
          <label>Bringing anybody?
            <select name="guests">
              <?php for ($i = 0; $i <= (int) $event['max_guests']; $i++): ?>
                <option value="<?= $i ?>"<?= (int) ($yours['guests'] ?? 0) === $i ? ' selected' : '' ?>>
                  <?= $i === 0 ? 'Just me' : $i . ' other' . ($i === 1 ? '' : 's') ?>
                </option>
              <?php endfor ?>
            </select>
            <span class="muted small">Everybody you bring takes a place, so please count them.</span>
          </label>
        <?php endif ?>

        <label>Anything we should know? <span class="muted small">— optional</span>
          <input type="text" name="note" maxlength="500">
        </label>

        <button class="btn"><?= $yours === null ? 'Put me down' : 'Update' ?></button>
      </form>
    <?php endif ?>
  </section>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
