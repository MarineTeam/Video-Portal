<?php
/**
 * The small-group directory.
 *
 * Every group here is a Portal\Groups\GroupCard, which has NO ADDRESS PROPERTY.
 * A directory says roughly where a group meets — the area — and never the house
 * it meets in.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<\Portal\Groups\GroupCard> $groups
 * @var list<\Portal\Groups\GroupCard> $mine
 * @var string $token
 */

declare(strict_types=1);

$groups ??= [];
$mine ??= [];
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">Small groups</h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<?php if ($mine !== []): ?>
  <h2 class="section-title">Yours</h2>
  <ul class="calendar-entries">
    <?php foreach ($mine as $group): ?>
      <li>
        <a href="/groups/<?= e($group->slug) ?>"><strong><?= e($group->name) ?></strong></a>
        <span class="muted small"><?= e(match ($group->myState) {
            'requested' => 'you have asked — waiting for an answer',
            'waiting'   => 'on the list',
            'leader'    => 'you lead this one',
            default     => 'you are in this one',
        }) ?></span>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?php if ($groups === []): ?>
  <div class="empty">No groups listed yet.</div>
<?php else: ?>
  <?php foreach ($groups as $group): ?>
    <article class="card">
      <h2><a href="/groups/<?= e($group->slug) ?>"><?= e($group->name) ?></a></h2>

      <p class="muted small">
        <?php if ($group->area !== null): ?><?= e($group->area) ?><?php endif ?>
        <?php if ($group->meets !== null): ?> · <?= e($group->meets) ?><?php endif ?>
        <?php if ($group->placesLeft() !== null): ?>
          · <?= $group->hasRoom()
              ? (int) $group->placesLeft() . ' place(s) left'
              : 'full, with a waiting list' ?>
        <?php endif ?>
      </p>

      <?php if ($group->description !== null): ?>
        <p><?= e(mb_substr($group->description, 0, 300)) ?></p>
      <?php endif ?>

      <?php if ($group->needsALeader()): ?>
        <p class="muted small">Nobody is leading this one at the moment.</p>
      <?php else: ?>
        <p class="muted small">Led by <?= e(implode(', ', $group->leaders)) ?></p>
      <?php endif ?>
    </article>
  <?php endforeach ?>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
