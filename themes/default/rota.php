<?php
/**
 * Your rota.
 *
 * YES AND NO ARE THE TWO BIGGEST THINGS ON THIS PAGE, and that is the design
 * rather than a styling accident. The one job this page has is getting an
 * answer out of somebody who opened it on a phone between other things: if
 * answering takes reading, scrolling, or a decision about which control to use,
 * the ask goes unanswered and the builder spends the week chasing.
 *
 * So an unanswered ask leads, its two buttons are large and adjacent, and the
 * optional reason is a field they can ignore. Everything else on the page —
 * dates away, cover, what they have already accepted — sits underneath.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $asks
 * @var list<array<string, mixed>> $blockouts
 * @var list<array<string, mixed>> $coverWanted
 * @var int $me
 * @var string $token
 */

declare(strict_types=1);

$asks ??= [];
$blockouts ??= [];
$coverWanted ??= [];
$token ??= '';
$me ??= 0;

$when = static function (string $stamp): string {
    $time = strtotime($stamp);

    return $time === false ? $stamp : date('D j M, g:ia', $time);
};

$day = static function (string $stamp): string {
    $time = strtotime($stamp);

    return $time === false ? $stamp : date('j M Y', $time);
};

// Unanswered first — the whole reason somebody opened this page.
$waiting = array_values(array_filter($asks, static fn (array $a): bool => $a['state'] === 'invited'));
$settled = array_values(array_filter($asks, static fn (array $a): bool => $a['state'] !== 'invited'));

echo $template->partial('header', get_defined_vars());
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<h1 class="page-title">Your rota</h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<?php if ($waiting !== []): ?>
  <section class="rota-waiting" aria-labelledby="rota-waiting-heading">
    <h2 class="section-title" id="rota-waiting-heading">
      Waiting for your answer
    </h2>

    <?php foreach ($waiting as $ask): ?>
      <div class="rota-ask">
        <p class="rota-ask-what">
          <strong><?= e((string) $ask['team_name']) ?></strong><?php
            if (!empty($ask['position_name'])) { echo ' — ' . e((string) $ask['position_name']); }
          ?>
        </p>
        <p class="rota-ask-when">
          <?= e((string) $ask['service_title']) ?> · <?= e($when((string) $ask['starts_at'])) ?>
        </p>

        <form method="post" action="/rota">
          <input type="hidden" name="_token" value="<?= e($token) ?>">
          <input type="hidden" name="id" value="<?= (int) $ask['id'] ?>">

          <?php
          /*
           * The reason is above the buttons and optional. Below them it would
           * be filled in after the answer had already been sent; asked for
           * before, it would be a form to complete rather than a question to
           * answer.
           */
          ?>
          <label class="rota-reason">
            Anything to add? <span class="muted small">— optional</span>
            <input type="text" name="reason" maxlength="300"
                   placeholder="I can do the early one only, …">
          </label>

          <div class="rota-answer">
            <button class="btn rota-yes" name="action" value="accept">Yes, I can</button>
            <button class="btn secondary rota-no" name="action" value="decline">No, sorry</button>
          </div>
        </form>
      </div>
    <?php endforeach ?>
  </section>
<?php else: ?>
  <p class="muted">Nothing is waiting for an answer.</p>
<?php endif ?>

<?php if ($settled !== []): ?>
  <section aria-labelledby="rota-settled-heading" style="margin-top:2.5rem">
    <h2 class="section-title" id="rota-settled-heading">What you have answered</h2>

    <table>
      <thead><tr><th>When</th><th>What</th><th>You said</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($settled as $ask): ?>
          <tr>
            <td><?= e($when((string) $ask['starts_at'])) ?><br>
                <span class="muted small"><?= e((string) $ask['service_title']) ?></span></td>
            <td><?= e((string) $ask['team_name']) ?><?php
                  if (!empty($ask['position_name'])) { echo ' — ' . e((string) $ask['position_name']); }
                ?></td>
            <td>
              <?= $ask['state'] === 'accepted' ? 'Yes' : 'No' ?>
              <?php if (!empty($ask['reason'])): ?>
                <br><span class="muted small"><?= e((string) $ask['reason']) ?></span>
              <?php endif ?>
            </td>
            <td class="right">
              <?php if ($ask['state'] === 'accepted' && empty($ask['cover_requested_at'])): ?>
                <form method="post" action="/rota" class="inline">
                  <input type="hidden" name="_token" value="<?= e($token) ?>">
                  <input type="hidden" name="id" value="<?= (int) $ask['id'] ?>">
                  <input type="hidden" name="reason" value="">
                  <button class="btn tiny secondary" name="action" value="request-cover">Ask for cover</button>
                </form>
              <?php elseif (!empty($ask['cover_requested_at'])): ?>
                <span class="muted small">Asking for cover</span>
                <form method="post" action="/rota" class="inline">
                  <input type="hidden" name="_token" value="<?= e($token) ?>">
                  <input type="hidden" name="id" value="<?= (int) $ask['id'] ?>">
                  <button class="btn tiny secondary" name="action" value="cancel-cover">Take back</button>
                </form>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </section>
<?php endif ?>

<?php
/*
 * Slots going spare. Everybody's, not just this person's teams — a hole in
 * Sunday morning is a problem for the whole church, and the person who can fill
 * it is often not on that team yet.
 *
 * Their own request is skipped: taking your own slot back is the "Take back"
 * button above, and offering it here as well would be two controls for one act
 * that read as different things.
 */
$spare = array_values(array_filter(
    $coverWanted,
    static fn (array $slot): bool => (int) $slot['user_id'] !== (int) $me
));
?>
<?php if ($spare !== []): ?>
  <section aria-labelledby="rota-cover-heading" style="margin-top:2.5rem">
    <h2 class="section-title" id="rota-cover-heading">Can anybody cover?</h2>

    <table>
      <thead><tr><th>When</th><th>What</th><th>Who asked</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($spare as $slot): ?>
          <tr>
            <td><?= e($when((string) $slot['starts_at'])) ?><br>
                <span class="muted small"><?= e((string) $slot['service_title']) ?></span></td>
            <td><?= e((string) $slot['team_name']) ?><?php
                  if (!empty($slot['position_name'])) { echo ' — ' . e((string) $slot['position_name']); }
                ?></td>
            <td><?= e((string) $slot['person_name']) ?>
              <?php if (!empty($slot['cover_note'])): ?>
                <br><span class="muted small"><?= e((string) $slot['cover_note']) ?></span>
              <?php endif ?>
            </td>
            <td class="right">
              <form method="post" action="/rota" class="inline">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="id" value="<?= (int) $slot['id'] ?>">
                <button class="btn tiny" name="action" value="take-cover">I'll take it</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </section>
<?php endif ?>

<section aria-labelledby="rota-away-heading" style="margin-top:2.5rem">
  <h2 class="section-title" id="rota-away-heading">Days you cannot serve</h2>

  <?php
  /*
   * The limitation is stated here rather than discovered. Somebody who believes
   * this stops them being asked will ignore the ask that arrives anyway, and
   * the builder chases them for a week.
   */
  ?>
  <p class="muted small">Whoever builds the rota is warned when they reach these dates. It does not
     stop them asking — they may know something you have not put here — so you may still be asked,
     and you can still say no.</p>

  <?php if ($blockouts !== []): ?>
    <table>
      <tbody>
        <?php foreach ($blockouts as $away): ?>
          <tr>
            <td><?= e($day((string) $away['starts_on'])) ?>
              <?php if ($away['ends_on'] !== $away['starts_on']): ?>
                &ndash; <?= e($day((string) $away['ends_on'])) ?>
              <?php endif ?>
            </td>
            <td class="muted small"><?= e((string) ($away['reason'] ?? '')) ?></td>
            <td class="right">
              <form method="post" action="/rota" class="inline">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="id" value="<?= (int) $away['id'] ?>">
                <button class="btn tiny secondary" name="action" value="remove-blockout">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>

  <form method="post" action="/rota" class="rota-away-form">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <label>From <input type="date" name="from" required></label>
    <label>To <input type="date" name="to" required></label>
    <label>Why <span class="muted small">— optional</span>
      <input type="text" name="reason" maxlength="200"></label>
    <button class="btn secondary" name="action" value="add-blockout">Add</button>
  </form>
</section>

<?= $template->partial('footer', get_defined_vars()) ?>
