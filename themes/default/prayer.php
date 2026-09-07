<?php
/**
 * The prayer wall.
 *
 * Every request here is a Portal\Prayer\PrayerRequest, which carries no user id
 * and no raw name — so this page cannot leak who asked even if it tried. The
 * name it shows comes from one function that answers "Anonymous" for an
 * anonymous request, whoever is reading.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<\Portal\Prayer\PrayerRequest> $requests
 * @var list<\Portal\Prayer\PrayerRequest> $mine
 * @var list<int> $prayed
 * @var string $token
 */

declare(strict_types=1);

$requests ??= [];
$mine ??= [];
$prayed ??= [];
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Prayer</h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<form method="post" action="/prayer" class="card">
  <input type="hidden" name="_token" value="<?= e($token) ?>">

  <label>What would you like prayer for?
    <textarea name="body" rows="4" required></textarea>
  </label>

  <label>Your name <input type="text" name="name" placeholder="Leave blank to stay anonymous"></label>

  <label class="check">
    <input type="checkbox" name="anonymous" value="1"> Post this anonymously
  </label>
  <p class="muted small">Anonymous means anonymous — the people who look after the wall cannot see
     who asked either, and your name is not stored at all. The cost is that an anonymous request
     cannot be taken down afterwards, because the site does not know it was yours.</p>

  <label>Who can read it
    <select name="visibility">
      <option value="members">Members of the church</option>
      <option value="everyone">Anybody</option>
      <option value="leaders">Only the people who lead</option>
    </select>
  </label>

  <p class="muted small">Somebody reads every request before it appears, so it will not go up
     straight away.</p>

  <button class="btn">Send</button>
</form>

<?php if ($mine !== []): ?>
  <h2 class="section-title">Yours</h2>
  <ul class="calendar-entries">
    <?php foreach ($mine as $request): ?>
      <li>
        <span class="muted small"><?= e($request->status === 'pending' ? 'waiting to be read' : 'on the wall') ?></span>
        <?= e(mb_substr($request->body, 0, 120)) ?>
        <form method="post" action="/prayer/withdraw" class="inline">
          <input type="hidden" name="_token" value="<?= e($token) ?>">
          <input type="hidden" name="id" value="<?= (int) $request->id ?>">
          <button class="btn tiny secondary">Take it down</button>
        </form>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<h2 class="section-title">On the wall</h2>

<?php if ($requests === []): ?>
  <div class="empty">Nothing here yet.</div>
<?php else: ?>
  <?php foreach ($requests as $request): ?>
    <article class="card">
      <p><strong><?= e($request->name) ?></strong>
         <span class="muted small"><?= e(date('j F', (int) strtotime($request->createdAt))) ?></span></p>

      <p><?= nl2br(e($request->body)) ?></p>

      <?php if ($request->isAnswered()): ?>
        <div class="notice ok">
          <strong>Answered.</strong> <?= e((string) $request->answerNote) ?>
        </div>
      <?php endif ?>

      <?php
        /*
         * A COUNT, never a list of names. There is no table of who pressed
         * this — the "you already did" memory is a cookie on the device.
         */
      ?>
      <p class="muted small">
        <?= $request->prayedCount === 1 ? '1 person has' : (int) $request->prayedCount . ' people have' ?>
        prayed for this.
        <?php if (in_array($request->id, $prayed, true)): ?>
          <span class="pill">including you</span>
        <?php else: ?>
          <form method="post" action="/prayer/pray" class="inline">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <input type="hidden" name="id" value="<?= (int) $request->id ?>">
            <button class="btn tiny secondary">I prayed for this</button>
          </form>
        <?php endif ?>
      </p>
    </article>
  <?php endforeach ?>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
