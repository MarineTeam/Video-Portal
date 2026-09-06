<?php
/**
 * When to be reminded of a rota you are on.
 *
 * The screen exists so somebody can turn these DOWN as well as on. A reminder
 * arriving at the wrong hour, or twice for something they already know about,
 * is what makes people ignore the ones that matter.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array{day_before: bool, day_of: bool, send_hour: int, timezone: ?string} $prefs
 * @var list<array<string, mixed>> $upcoming
 * @var bool   $linked
 * @var string $siteZone
 * @var string $token
 */

declare(strict_types=1);

$prefs ??= ['day_before' => true, 'day_of' => false, 'send_hour' => 18, 'timezone' => null];
$upcoming ??= [];
$linked ??= false;
$siteZone ??= 'UTC';
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Rota reminders</h1>
<p class="page-subtitle"><a href="/account">Your account</a> · <a href="/calendar">The calendar</a></p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice ok"><?= e((string) $flash['message']) ?></div>
<?php endif ?>

<?php
/*
 * Said before the form, not after it. Without this the screen is a form that
 * quietly does nothing: somebody picks an hour, saves, is never reminded of
 * anything, and has no way to find out that the missing piece was a link
 * somebody else has to make.
 */
?>
<?php if (!$linked): ?>
  <div class="notice error">
    <strong>Your account is not linked to a name on any rota yet.</strong>
    <p class="muted small">These rotas are kept as names rather than accounts, so somebody who
       looks after the calendar has to say which name is yours. Until they do, nothing here will
       send you anything — ask them, and it takes one click on their side.</p>
  </div>
<?php endif ?>

<form method="post" class="card">
  <input type="hidden" name="_token" value="<?= e($token) ?>">

  <label class="check">
    <input type="checkbox" name="day_before" value="1" <?= $prefs['day_before'] ? 'checked' : '' ?>>
    The day before
  </label>

  <label class="check">
    <input type="checkbox" name="day_of" value="1" <?= $prefs['day_of'] ? 'checked' : '' ?>>
    On the day itself
  </label>

  <label>At
    <select name="send_hour">
      <?php for ($hour = 0; $hour < 24; $hour++): ?>
        <option value="<?= $hour ?>" <?= (int) $prefs['send_hour'] === $hour ? 'selected' : '' ?>>
          <?= sprintf('%02d:00', $hour) ?>
        </option>
      <?php endfor ?>
    </select>
  </label>

  <label>Where you are
    <select name="timezone">
      <option value="">Same as the church (<?= e($siteZone) ?>)</option>
      <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?>
        <option value="<?= e($zone) ?>" <?= $prefs['timezone'] === $zone ? 'selected' : '' ?>>
          <?= e($zone) ?>
        </option>
      <?php endforeach ?>
    </select>
  </label>

  <p class="muted small">The hour is where <em>you</em> are, not where the server is. Reminders may
     arrive a little after it — this site runs its scheduled work when somebody visits, so a quiet
     evening can push one into the next morning. It will still say the right day.</p>

  <button class="btn">Save</button>
</form>

<h2 class="section-title">What you are on</h2>

<?php if ($upcoming === []): ?>
  <div class="empty">Nothing coming up.</div>
<?php else: ?>
  <ul class="calendar-entries">
    <?php foreach ($upcoming as $entry): ?>
      <li>
        <span class="calendar-chip" <?php
          if (!empty($entry['colour'])) {
              echo 'style="--chip: ' . e((string) $entry['colour']) . '"';
          }
        ?>>
          <?php if (!empty($entry['icon'])): ?><?= e((string) $entry['icon']) ?> <?php endif ?>
          <?= e((string) $entry['schedule_name']) ?>
        </span>
        <span class="calendar-who">
          <?= e(date('l j F', (int) strtotime((string) $entry['on_date']))) ?>
        </span>
        <?php if (!empty($entry['role'])): ?>
          <span class="muted small"><?= e((string) $entry['role']) ?></span>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
