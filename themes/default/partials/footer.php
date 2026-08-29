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

<?php
/*
 * Register the site's ONE service worker.
 *
 * Here rather than in any plugin, and there must never be a second
 * registration: a scope has one active worker, so registering another script
 * at `/` silently replaces this one. Plugins add their handlers to this worker
 * through the `service_worker` filter instead.
 *
 * Wrapped in every guard it needs. A browser without service workers, a page
 * served over plain HTTP, and a private window that refuses registration all
 * end up doing nothing rather than throwing — none of the site depends on this
 * having worked.
 */
?>
<script>
(function () {
  if (!('serviceWorker' in navigator)) { return; }

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
      /* Not installable here. Everything else on the site still works. */
    });
  });
})();
</script>

</body>
</html>
