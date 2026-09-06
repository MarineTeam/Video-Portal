<?php
/**
 * One service: the running order, and who is serving.
 *
 * THIS PAGE IS MEANT TO BE PRINTED, and the print rules are in theme.css rather
 * than here so a child theme inherits them. What that means for the markup is
 * that the order comes first and everything chrome-like — navigation, buttons,
 * the tab bar — is something the stylesheet can remove without the page losing
 * its sense.
 *
 * @var \Portal\Themes\TemplateLoader $template
 * @var array<string, mixed> $service
 * @var list<array<string, mixed>> $plan
 * @var list<\Portal\Rota\Assignment> $serving
 */

declare(strict_types=1);

$service ??= [];
$plan ??= [];
$serving ??= [];

$when = static function (string $stamp): string {
    $time = strtotime($stamp);

    return $time === false ? $stamp : date('l j F Y, g:ia', $time);
};

$kindLabel = static fn (string $kind): string => match ($kind) {
    'hymn'    => 'Hymn',
    'reading' => 'Reading',
    default   => '',
};

echo $template->partial('header', get_defined_vars());
?>

<article class="service-sheet">
  <header class="service-head">
    <h1 class="page-title"><?= e((string) ($service['title'] ?? '')) ?></h1>
    <p class="page-subtitle"><?= e($when((string) ($service['starts_at'] ?? ''))) ?></p>

    <?php if (empty($service['is_published'])): ?>
      <p class="notice error no-print">This service is a draft. Nobody else can see it yet.</p>
    <?php endif ?>
  </header>

  <?php if ($plan !== []): ?>
    <section aria-labelledby="order-heading">
      <h2 class="section-title" id="order-heading">The order</h2>

      <ol class="service-order">
        <?php foreach ($plan as $item): ?>
          <li>
            <span class="service-order-what">
              <?php $label = $kindLabel((string) $item['kind']); ?>
              <?php if ($label !== ''): ?>
                <span class="muted small"><?= e($label) ?></span>
              <?php endif ?>
              <strong><?= e((string) $item['title']) ?></strong>
            </span>
            <?php if (!empty($item['reference'])): ?>
              <span class="service-order-ref"><?= e((string) $item['reference']) ?></span>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ol>
    </section>
  <?php endif ?>

  <?php if ($serving !== []): ?>
    <section aria-labelledby="serving-heading">
      <h2 class="section-title" id="serving-heading">Who is serving</h2>

      <?php
      /*
       * Only the people who said yes — the controller filters, so a theme
       * cannot print an invitation as though it were an arrangement. Somebody
       * reading this sheet on Sunday morning is reading it to know who is
       * there.
       */
      ?>
      <ul class="service-serving">
        <?php foreach ($serving as $person): ?>
          <li>
            <span class="service-serving-role"><?= e($person->label()) ?></span>
            <span class="service-serving-who"><?= e($person->personName) ?></span>
          </li>
        <?php endforeach ?>
      </ul>
    </section>
  <?php endif ?>

  <?php if ($plan === [] && $serving === []): ?>
    <p class="muted">Nothing has been arranged for this service yet.</p>
  <?php endif ?>
</article>

<p class="no-print" style="margin-top:2rem">
  <?php
  /*
   * A plain link to the browser's own print, rather than a scripted one. It
   * works with scripting off, it is what the keyboard shortcut does anyway, and
   * a button that calls window.print() is a button that does nothing on the one
   * browser where the call is blocked.
   */
  ?>
  <span class="muted small">Use your browser's Print to put this on paper — the page is laid out
     for it.</span>
</p>

<?= $template->partial('footer', get_defined_vars()) ?>
