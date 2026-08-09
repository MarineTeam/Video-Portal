<?php
/**
 * The subscribe form.
 *
 * A partial so the library, a category, a series, and a speaker page all offer
 * the same thing without four copies of it. The scope travels in hidden
 * fields — the server re-checks that the named thing exists, so a tampered
 * form produces a refusal rather than a subscription to nothing.
 *
 * @var string  $subscribeScope    site, category, series, or speaker
 * @var ?int    $subscribeScopeId
 * @var string  $subscribeLabel    what they are subscribing to, in words
 * @var ?array  $currentUser
 */

declare(strict_types=1);

$subscribeScope ??= 'site';
$subscribeScopeId ??= null;
$subscribeLabel ??= 'new videos';
?>
<section class="subscribe" aria-labelledby="subscribe-heading">
  <h2 class="section-title" id="subscribe-heading">Get an email about <?= e($subscribeLabel) ?></h2>

  <?php
  /*
   * No CSRF token, deliberately — see SubscriptionController::subscribe(). It
   * would mean starting a session and setting a cookie for every anonymous
   * visitor to every listing, to protect a POST that borrows no authority.
   */
  ?>
  <form method="post" action="/subscribe" class="subscribe-form">
    <input type="hidden" name="scope" value="<?= e($subscribeScope) ?>">
    <?php if ($subscribeScopeId !== null): ?>
      <input type="hidden" name="scope_id" value="<?= (int) $subscribeScopeId ?>">
    <?php endif ?>

    <div class="field">
      <label class="visually-hidden" for="subscribe-email">Your email address</label>
      <input type="email" id="subscribe-email" name="email" required
             placeholder="you@example.com"
             value="<?= e((string) ($currentUser['email'] ?? '')) ?>"
             autocomplete="email">
    </div>

    <button class="btn secondary" type="submit">Subscribe</button>
  </form>

  <p class="muted small">One email per video, and every one of them has an unsubscribe link.
     No account needed.</p>
</section>
