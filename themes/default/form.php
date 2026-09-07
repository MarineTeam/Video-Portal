<?php
/**
 * Filling a form in.
 *
 * No account, no JavaScript. A connect card that needs either is a card the
 * people it was built for cannot send — which is everybody who has just walked
 * in, on somebody else's phone, on a bad signal.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $form
 * @var list<array<string, mixed>> $questions
 * @var array<int, string> $errors
 * @var array<string, mixed> $given
 * @var string $token
 */

declare(strict_types=1);

use Portal\Forms\FieldType;
use Portal\Forms\FormRepository;

$questions ??= [];
$errors ??= [];
$given ??= [];
$token ??= '';

/** What the person typed last time, so a refusal does not empty the page. */
$was = static function (int $id) use ($given): mixed {
    return $given['q' . $id] ?? null;
};

echo $template->partial('header', get_defined_vars());
?>

<h1 class="page-title"><?= e((string) $form['title']) ?></h1>

<?php if (!empty($form['description'])): ?>
  <p class="page-subtitle"><?= nl2br(e((string) $form['description'])) ?></p>
<?php endif ?>

<?php if ($errors !== []): ?>
  <div class="notice error">
    There is something to fix below — nothing has been sent yet, and what you wrote is still here.
  </div>
<?php endif ?>

<?php if (empty($form['is_open'])): ?>
  <div class="notice">This one is closed.</div>
<?php elseif ($questions === []): ?>
  <div class="empty">This form has no questions on it yet.</div>
<?php else: ?>

<form method="post" class="card">
  <input type="hidden" name="_token" value="<?= e($token) ?>">

  <?php foreach ($questions as $question): ?>
    <?php
      $id = (int) $question['id'];
      $type = (string) $question['type'];
      $options = FormRepository::optionsOf($question);
      $name = 'q' . $id;
      $error = $errors[$id] ?? null;
      $required = !empty($question['is_required']);
    ?>

    <div class="field<?= $error === null ? '' : ' field-error' ?>">
      <?php if (FieldType::isMultiple($type) || $type === FieldType::CHOICE): ?>
        <fieldset>
          <legend><?= e((string) $question['label']) ?><?= $required ? ' *' : '' ?></legend>
          <?php
            $picked = (array) ($was($id) ?? []);
            if (!FieldType::isMultiple($type)) {
                $picked = $was($id) === null ? [] : [$was($id)];
            }
          ?>
          <?php foreach ($options as $option): ?>
            <label class="check">
              <input type="<?= FieldType::isMultiple($type) ? 'checkbox' : 'radio' ?>"
                     name="<?= e($name) ?><?= FieldType::isMultiple($type) ? '[]' : '' ?>"
                     value="<?= e($option) ?>"
                     <?= in_array($option, $picked, true) ? 'checked' : '' ?>>
              <?= e($option) ?>
            </label>
          <?php endforeach ?>
        </fieldset>

      <?php else: ?>
        <label>
          <?= e((string) $question['label']) ?><?= $required ? ' *' : '' ?>

          <?php if ($type === FieldType::PARAGRAPH): ?>
            <textarea name="<?= e($name) ?>" rows="5"><?= e((string) ($was($id) ?? '')) ?></textarea>

          <?php elseif ($type === FieldType::DROPDOWN): ?>
            <select name="<?= e($name) ?>">
              <option value="">— choose —</option>
              <?php foreach ($options as $option): ?>
                <option value="<?= e($option) ?>"
                  <?= (string) ($was($id) ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
              <?php endforeach ?>
            </select>

          <?php elseif ($type === FieldType::YES_NO): ?>
            <select name="<?= e($name) ?>">
              <option value="">— choose —</option>
              <option value="yes" <?= (string) ($was($id) ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
              <option value="no" <?= (string) ($was($id) ?? '') === 'no' ? 'selected' : '' ?>>No</option>
            </select>

          <?php else: ?>
            <?php
              /*
               * The input type is a hint to the browser's keyboard, never the
               * check. The server has the last word on every one of these —
               * see FieldType.
               */
              $html = match ($type) {
                  FieldType::EMAIL  => 'email',
                  FieldType::PHONE  => 'tel',
                  FieldType::NUMBER => 'number',
                  FieldType::DATE   => 'date',
                  default           => 'text',
              };
            ?>
            <input type="<?= e($html) ?>" name="<?= e($name) ?>"
                   value="<?= e((string) ($was($id) ?? '')) ?>">
          <?php endif ?>
        </label>
      <?php endif ?>

      <?php if (!empty($question['help'])): ?>
        <p class="muted small"><?= e((string) $question['help']) ?></p>
      <?php endif ?>

      <?php if ($error !== null): ?>
        <p class="notice error small"><?= e($error) ?></p>
      <?php endif ?>
    </div>
  <?php endforeach ?>

  <button class="btn">Send</button>
</form>

<?php endif ?>

<?= $template->partial('footer', get_defined_vars()) ?>
