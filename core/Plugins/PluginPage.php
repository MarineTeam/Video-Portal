<?php

declare(strict_types=1);

namespace Portal\Plugins;

use Portal\Admin\AdminView;
use Portal\Controllers\Controller;
use Portal\Http\Response;

/**
 * Base class for a plugin's admin screen.
 *
 * Building the first two bundled plugins turned up a gap: `addAdminPage()`
 * handed a plugin a route and nothing else. To render a settings form an author
 * would have to reimplement the CSRF token, the flash message, the navigation,
 * and the entire admin shell — and the CSRF part is the kind of thing that gets
 * skipped, because a plugin still appears to work without it.
 *
 * Extending this gives a plugin the same chrome and the same protections as a
 * core screen, and makes the secure path the shortest one:
 *
 *     final class MyPage extends PluginPage
 *     {
 *         public function show(Request $r): Response
 *         {
 *             $this->require(Capability::MANAGE_PLUGINS);
 *
 *             if ($r->method === 'POST') {
 *                 $this->verifyCsrf($r);
 *                 // ...save...
 *                 return $this->back($r, 'Saved.');
 *             }
 *
 *             return $this->page('My Plugin', '<form>...</form>');
 *         }
 *     }
 *
 * Note the single handler for GET and POST: `addAdminPage()` registers both
 * methods on one path, so a plugin that forgets to branch on the method would
 * silently render its form in response to a save.
 */
abstract class PluginPage extends Controller
{
    /**
     * Render $body inside the admin shell.
     *
     * @param string $navKey which navigation entry to highlight; pass the
     *                       plugin slug, matching the key addAdminPage() uses
     */
    protected function page(string $title, string $body, string $navKey = ''): Response
    {
        $view = new AdminView();

        $html = $view->shell($body, [
            'screen'   => $navKey === '' ? '' : 'plugin:' . $navKey,
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'title'    => $title,
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]);

        // Admin pages reflect one person's permissions and carry their CSRF
        // token; a shared cache must never hold one.
        return Response::html($html)->private();
    }

    /**
     * The hidden field every plugin form needs.
     *
     * Exposed as ready-made HTML rather than a raw token, because a token an
     * author has to place themselves is one they can forget to place.
     */
    protected function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . e($this->csrfToken()) . '">';
    }
}
