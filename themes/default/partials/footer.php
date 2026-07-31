<?php
/**
 * Closes the document opened by partials/header.php.
 *
 * @var string $siteName
 */

declare(strict_types=1);

$siteName ??= 'Video Portal';
?>
  </div>
</main>

<footer class="site-footer">
  <div class="wrap">
    <?= e($siteName) ?>
    <?php do_action('footer') ?>
  </div>
</footer>

</body>
</html>
