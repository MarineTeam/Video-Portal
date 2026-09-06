<?php
/**
 * What is on.
 *
 * A members-only event is absent from this list rather than shown and refused —
 * the filtering happens in the repository, so a theme cannot leak one by
 * rendering carelessly.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $events
 * @var list<array<string, mixed>> $mine
 */

declare(strict_types=1);

$events ??= [];
$mine ??= [];

$when = static function (string $stamp): string {
    $time = strtotime($stamp);

    return $time === false ? $stamp : date('D j M Y, g:ia', $time);
};

echo $template->partial('header', get_defined_vars());
echo $template->partial('breadcrumbs', get_defined_vars());
?>

<h1 class="page-title">What is on</h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<?php if ($mine !== []): ?>
  <section aria-labelledby="mine-heading">
    <h2 class="section-title" id="mine-heading">You are down for</h2>
    <ul class="event-list">
      <?php foreach ($mine as $signup): ?>
        <li>
          <a href="/events/<?= e((string) $signup['slug']) ?>"><?= e((string) $signup['title']) ?></a>
          <span class="muted small">
            <?= e($when((string) $signup['starts_at'])) ?>
            <?php if ($signup['state'] === 'waiting'): ?>
              &middot; on the waiting list
            <?php endif ?>
          </span>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>

<?php if ($events === []): ?>
  <div class="empty">Nothing is coming up.</div>
<?php else: ?>
  <ul class="event-list">
    <?php foreach ($events as $event): ?>
      <li>
        <a href="/events/<?= e((string) $event['slug']) ?>"><strong><?= e((string) $event['title']) ?></strong></a>
        <span class="muted small">
          <?= e($when((string) $event['starts_at'])) ?>
          <?php if (!empty($event['location'])): ?>
            &middot; <?= e((string) $event['location']) ?>
          <?php endif ?>
        </span>
        <?php if (!empty($event['member_only'])): ?>
          <span class="pill warn">members only</span>
        <?php endif ?>
        <?php if (empty($event['is_published'])): ?>
          <span class="pill warn">draft</span>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
