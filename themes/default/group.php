<?php
/**
 * One small group.
 *
 * $group is a GroupCard and has no address on it. $address is the ONLY way one
 * reaches this page, and the controller filled it in by asking GroupAddress —
 * which answers null for anybody who is not actually in the group, including
 * somebody who has asked and somebody on the waiting list.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var \Portal\Groups\GroupCard $group
 * @var string|null $address
 * @var list<array<string, mixed>> $people
 * @var bool $iLead
 * @var string $token
 */

declare(strict_types=1);

$address ??= null;
$people ??= [];
$iLead ??= false;
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<p class="muted small"><a href="/groups">&larr; Small groups</a></p>
<h1 class="page-title"><?= e($group->name) ?></h1>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<p class="muted">
  <?php if ($group->area !== null): ?><?= e($group->area) ?><?php endif ?>
  <?php if ($group->meets !== null): ?> · <?= e($group->meets) ?><?php endif ?>
</p>

<?php if ($group->description !== null): ?>
  <p><?= nl2br(e($group->description)) ?></p>
<?php endif ?>

<?php if ($group->needsALeader()): ?>
  <p class="muted small">Nobody is leading this one at the moment.</p>
<?php else: ?>
  <p class="muted small">Led by <?= e(implode(', ', $group->leaders)) ?></p>
<?php endif ?>

<?php if ($address !== null): ?>
  <div class="notice ok">
    <strong>Where it meets:</strong> <?= e($address) ?>
    <p class="muted small">This is somebody's home. It is shown to the people in the group and
       nobody else — please keep it that way.</p>
  </div>
<?php endif ?>

<?php if ($group->myState === null): ?>
  <form method="post" action="/groups/ask" class="card">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <input type="hidden" name="group" value="<?= e($group->slug) ?>">
    <label>Anything you would like them to know?
      <textarea name="note" rows="3"></textarea>
    </label>
    <p class="muted small">
      <?= $group->hasRoom()
          ? 'Whoever leads the group will get back to you, and send you the details then.'
          : 'This one is full, so you will go on the list and be asked when a place comes free.' ?>
    </p>
    <button class="btn"><?= $group->hasRoom() ? 'Ask to join' : 'Put me on the list' ?></button>
  </form>
<?php else: ?>
  <p class="muted small">
    <?= e(match ($group->myState) {
        'requested' => 'You have asked to join. Waiting for an answer.',
        'waiting'   => 'You are on the list for this one.',
        'leader'    => 'You lead this group.',
        'member'    => 'You are in this group.',
        default     => '',
    }) ?>
    <?php if (in_array($group->myState, ['requested', 'waiting', 'member'], true)): ?>
      <form method="post" action="/groups/leave" class="inline">
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <input type="hidden" name="group" value="<?= e($group->slug) ?>">
        <button class="btn tiny secondary">
          <?= $group->myState === 'member' ? 'Leave' : 'Never mind' ?>
        </button>
      </form>
    <?php endif ?>
  </p>
<?php endif ?>

<?php
/*
 * The leader's own screen. Not an admin page: leading is a row about THIS
 * group, so answering lives here behind a check against this group rather than
 * behind a capability that would make every leader a moderator of all of them.
 */
?>
<?php if ($iLead && $people !== []): ?>
  <h2 class="section-title">Who has asked</h2>
  <table>
    <tbody>
      <?php foreach ($people as $person): ?>
        <tr>
          <td>
            <strong><?= e((string) $person['person_name']) ?></strong>
            <div class="muted small"><?= e((string) $person['state']) ?></div>
            <?php if (!empty($person['note'])): ?>
              <p class="muted small"><?= e((string) $person['note']) ?></p>
            <?php endif ?>
          </td>
          <td class="right">
            <?php if (in_array((string) $person['state'], ['requested', 'waiting'], true)): ?>
              <form method="post" action="/groups/answer" class="inline">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="group" value="<?= e($group->slug) ?>">
                <input type="hidden" name="person" value="<?= (int) $person['user_id'] ?>">
                <input type="text" name="reply" placeholder="Anything to say back">
                <button name="answer" value="yes" class="btn tiny">Yes</button>
                <button name="answer" value="no" class="btn tiny secondary">No</button>
              </form>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
