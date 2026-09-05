<?php
/**
 * What this person has watched.
 *
 * The rows here are the same ones "continue watching" reads, which is why the
 * page says what clearing actually does. Somebody who expects a tidy-up and
 * gets their resume positions deleted has been surprised by their own site.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var list<array<string, mixed>> $history
 * @var string $token
 */

declare(strict_types=1);

$history ??= [];
$token ??= '';

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title">What you have watched</h1>
<p class="page-subtitle"><a href="/account">Your account</a></p>

<?php if (!empty($flash['message'])): ?>
  <div class="notice <?= ($flash['type'] ?? 'success') === 'error' ? 'error' : 'ok' ?>">
    <?= e((string) $flash['message']) ?>
  </div>
<?php endif ?>

<p class="muted small">
  This is also what decides where a video picks up when you come back to it, so forgetting something
  here means it starts from the beginning next time — and drops out of <strong>Continue
  watching</strong>. Nothing here is shown to anybody else.
</p>

<?php if ($history === []): ?>
  <div class="empty">
    <p>You have not watched anything yet.</p>
  </div>

<?php else: ?>
  <p>
    <a class="btn secondary" href="/account/export.json">Download all my data</a>
    <span class="muted small">Everything this site holds about you, as a JSON file.</span>
  </p>

  <table>
    <thead><tr><th>Video</th><th>How far</th><th>Last watched</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($history as $row): ?>
        <?php
        $duration = (int) ($row['duration_seconds'] ?? 0);
        $position = (int) ($row['position_seconds'] ?? 0);

        // Only shown when the duration is known. A percentage of zero is not
        // "0%", it is "we do not know", and printing the first is a lie the
        // reader has no way to check.
        $percent = $duration > 0 ? min(100, (int) round(($position / $duration) * 100)) : null;
        ?>
        <tr>
          <td><a href="/watch/<?= e((string) $row['slug']) ?>"><?= e((string) $row['title']) ?></a></td>
          <td class="muted small">
            <?php if (!empty($row['completed_at'])): ?>
              Finished
            <?php elseif ($percent !== null): ?>
              <?= (int) $percent ?>%
            <?php else: ?>
              &mdash;
            <?php endif ?>
          </td>
          <td class="muted small"><?= e((string) $row['updated_at']) ?></td>
          <td class="right">
            <?php
            /*
             * Marking, and forgetting, are two different requests and are two
             * different buttons on purpose. Taking the mark off leaves the row
             * — somebody undoing a mis-click has not asked to be forgotten —
             * where Forget deletes it and the video starts from the beginning.
             */
            $done = !empty($row['completed_at']);
            ?>
            <form method="post" action="/watch/mark" style="display:inline">
              <input type="hidden" name="_token" value="<?= e($token) ?>">
              <input type="hidden" name="video_id" value="<?= (int) $row['video_id'] ?>">
              <input type="hidden" name="action" value="<?= $done ? 'unwatched' : 'watched' ?>">
              <button class="btn small secondary"><?= $done ? 'Not watched' : 'Watched' ?></button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="_token" value="<?= e($token) ?>">
              <input type="hidden" name="video_id" value="<?= (int) $row['video_id'] ?>">
              <button class="btn small secondary">Forget</button>
            </form>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>

  <form method="post" style="margin-top:1.5rem"
        onsubmit="return confirm('Forget everything you have watched? Videos will start from the beginning again.')">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <button class="btn danger">Clear everything</button>
  </form>
<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
