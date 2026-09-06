<?php
/**
 * Who is on.
 *
 * No login, for readers or for the people named. Several schedules side by
 * side, grouped by day, with the reader's own dates marked once they have said
 * who they are.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, list<array<string, mixed>>> $days
 * @var list<array<string, mixed>> $schedules
 * @var list<array<string, mixed>> $people
 * @var array<string, mixed>|null $me
 * @var string $from
 * @var string $to
 * @var string $token
 */

declare(strict_types=1);

$days ??= [];
$schedules ??= [];
$people ??= [];
$me ??= null;
$token ??= '';

$dayName = static function (string $date): string {
    $time = strtotime($date);

    return $time === false ? $date : date('l j F', $time);
};

echo $template->partial('header', get_defined_vars());
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<h1 class="page-title">Who is on</h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<?php
/*
 * Choosing a name. Offered once and then reduced to a quiet "not you?" line —
 * a picker that stays large is a control people keep changing by accident, and
 * the whole value of this is that it is answered once.
 */
?>
<?php if ($people !== []): ?>
  <form method="post" action="/calendar/me" class="calendar-me">
    <input type="hidden" name="_token" value="<?= e($token) ?>">

    <?php if ($me === null): ?>
      <label>Which of these is you?
        <select name="person">
          <option value="0">— nobody in particular —</option>
          <?php foreach ($people as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e((string) $person['name']) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <button class="btn secondary">Remember me</button>
      <p class="muted small">Kept on this device only, so the calendar opens on your own dates.
         Nothing is sent anywhere and no account is involved.</p>
    <?php else: ?>
      <p class="muted small">
        Showing <strong><?= e((string) $me['name']) ?></strong>'s dates marked.
        <button class="btn tiny secondary" name="person" value="0">Not you?</button>
      </p>
    <?php endif ?>
  </form>
<?php endif ?>

<?php if ($schedules !== []): ?>
  <p class="calendar-key">
    <?php foreach ($schedules as $schedule): ?>
      <span class="calendar-chip" <?php
        // The colour was validated as a hex value when it was saved; anything
        // else became null, so there is nothing here to escape into CSS.
        if (!empty($schedule['colour'])) {
            echo 'style="--chip: ' . e((string) $schedule['colour']) . '"';
        }
      ?>>
        <?php if (!empty($schedule['icon'])): ?><?= e((string) $schedule['icon']) ?> <?php endif ?>
        <?= e((string) $schedule['name']) ?>
      </span>
    <?php endforeach ?>
  </p>
<?php endif ?>

<?php if ($days === []): ?>
  <div class="empty">Nothing on the rota between
    <?= e($dayName($from)) ?> and <?= e($dayName($to)) ?>.</div>
<?php else: ?>
  <?php foreach ($days as $date => $entries): ?>
    <section class="calendar-day" aria-labelledby="day-<?= e($date) ?>">
      <h2 class="section-title" id="day-<?= e($date) ?>"><?= e($dayName((string) $date)) ?></h2>

      <ul class="calendar-entries">
        <?php foreach ($entries as $entry): ?>
          <li<?= !empty($entry['mine']) ? ' class="mine"' : '' ?>>
            <span class="calendar-chip" <?php
              if (!empty($entry['colour'])) {
                  echo 'style="--chip: ' . e((string) $entry['colour']) . '"';
              }
            ?>>
              <?php if (!empty($entry['icon'])): ?><?= e((string) $entry['icon']) ?> <?php endif ?>
              <?= e((string) $entry['schedule_name']) ?>
            </span>

            <span class="calendar-who"><?= e((string) $entry['person_name']) ?></span>

            <?php if (!empty($entry['role'])): ?>
              <span class="muted small"><?= e((string) $entry['role']) ?></span>
            <?php endif ?>

            <?php if (!empty($entry['mine'])): ?>
              <span class="pill">you</span>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
    </section>
  <?php endforeach ?>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
