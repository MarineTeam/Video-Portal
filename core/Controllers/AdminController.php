<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminView;
use Portal\Auth\Capability;
use Portal\Auth\UserRepository;
use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginManager;
use Portal\Providers\ProviderRegistry;
use Portal\Support\Audit;
use Throwable;

/**
 * The admin area.
 *
 * Every handler re-checks its own capability. The route-level `admin.area`
 * guard only decides who gets through the front door — it deliberately admits
 * anyone with any admin capability, so a category editor is not met with a 403
 * on /admin itself. Relying on it for authorisation would give that editor the
 * run of the place.
 */
final class AdminController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $stats = [];

        try {
            $stats = [
                'videos'     => (int) $this->db()->value('SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL'),
                'published'  => (int) $this->db()->value(
                    'SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL AND is_published = 1 AND status = "ready"'
                ),
                'processing' => (int) $this->db()->value(
                    'SELECT COUNT(*) FROM {videos} WHERE deleted_at IS NULL AND status = "processing"'
                ),
                'categories' => (int) $this->db()->value('SELECT COUNT(*) FROM {categories}'),
                'users'      => (int) $this->db()->value('SELECT COUNT(*) FROM {users}'),
                'pending'    => (int) $this->db()->value('SELECT COUNT(*) FROM {users} WHERE authorized = 0'),
            ];
        } catch (Throwable $e) {
            error_log('Portal: dashboard counts failed: ' . $e->getMessage());
        }

        return $this->admin('dashboard', [
            'stats'    => $stats,
            'activity' => $this->guard()->can(Capability::VIEW_AUDIT_LOG)
                ? Audit::recent($this->db(), 15)
                : [],
            'providers' => $this->providerSummary(),
        ]);
    }

    // --------------------------------------------------------------- videos

    public function videos(Request $request): Response
    {
        $this->require(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $page = max(1, (int) ($request->query('page') ?? 1));
        $result = $videos->query([
            'includeUnpublished' => true,
            'includeProcessing'  => true,
            'includeHidden'      => true,
            'includeMemberOnly'  => true,
            'search'             => $request->query('q') ?? '',
        ], $page, 25);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        return $this->admin('videos', [
            'videos'     => $result['items'],
            'total'      => $result['total'],
            'page'       => $page,
            'search'     => $request->query('q') ?? '',
            'categories' => $categories->all(true),
        ]);
    }

    public function updateVideo(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $id = (int) ($request->input('id') ?? 0);
        $video = $videos->find($id);

        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        $action = $request->input('action') ?? 'save';

        switch ($action) {
            case 'delete':
                $videos->softDelete($id);
                Audit::log($this->db(), $this->user()?->email, 'video.delete', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video moved to trash.');

            case 'publish':
                $this->require(Capability::PUBLISH_CONTENT);
                $videos->update($id, ['is_published' => true, 'published_at' => date('Y-m-d H:i:s')]);
                Audit::log($this->db(), $this->user()?->email, 'video.publish', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video published.');

            case 'unpublish':
                $this->require(Capability::PUBLISH_CONTENT);
                $videos->update($id, ['is_published' => false]);
                Audit::log($this->db(), $this->user()?->email, 'video.unpublish', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video unpublished.');

            default:
                $videos->update($id, [
                    'title'          => $request->input('title') ?? $video->title,
                    'description'    => $request->input('description'),
                    'watermark_mode' => $request->input('watermark_mode') ?? $video->watermarkMode,
                ]);

                $categoryIds = array_map('intval', $request->inputArray('categories'));
                $videos->setCategories($id, $categoryIds);

                Audit::log($this->db(), $this->user()?->email, 'video.update', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video saved.');
        }
    }

    // ----------------------------------------------------------- categories

    public function categories(Request $request): Response
    {
        $this->require(Capability::MANAGE_CATEGORIES);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        return $this->admin('categories', [
            'tree' => $categories->tree(true),
            'flat' => $categories->all(true),
        ]);
    }

    public function saveCategory(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_CATEGORIES);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $categories->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'category.delete', 'category', (string) $id);
                    return $this->back($request, 'Category deleted. Its videos were not removed.');

                case 'update':
                    $categories->update($id, [
                        'name'        => $request->input('name'),
                        'description' => $request->input('description'),
                        'parent_id'   => ($p = (int) ($request->input('parent_id') ?? 0)) > 0 ? $p : null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'category.update', 'category', (string) $id);
                    return $this->back($request, 'Category saved.');

                case 'import':
                    return $this->importCollections($request);

                default:
                    $created = $categories->create([
                        'name'      => $request->input('name'),
                        'parent_id' => ($p = (int) ($request->input('parent_id') ?? 0)) > 0 ? $p : null,
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'category.create',
                        'category',
                        (string) $created->id,
                        $created->name
                    );
                    return $this->back($request, 'Category created.');
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function importCollections(Request $request): Response
    {
        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        try {
            $provider = $this->container->get(\Portal\Video\VideoProvider::class);
            $result = $categories->importCollections($provider->listCollections());
        } catch (Throwable $e) {
            return $this->back($request, 'Could not read collections: ' . $e->getMessage(), 'error');
        }

        Audit::log($this->db(), $this->user()?->email, 'category.import', null, null, sprintf(
            '%d created, %d already present',
            $result['created'],
            $result['skipped']
        ));

        return $this->back($request, sprintf(
            'Imported %d new categor%s. %d already existed and were left untouched.',
            $result['created'],
            $result['created'] === 1 ? 'y' : 'ies',
            $result['skipped']
        ));
    }

    // ---------------------------------------------------------------- users

    public function users(Request $request): Response
    {
        $this->require(Capability::MANAGE_USERS);

        $users = $this->db()->all(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name
               FROM {users} u LEFT JOIN {roles} r ON r.id = u.role_id
              ORDER BY u.authorized ASC, u.created_at DESC
              LIMIT 200'
        );

        return $this->admin('users', [
            'users' => $users,
            'roles' => $this->db()->all('SELECT * FROM {roles} ORDER BY position'),
        ]);
    }

    public function saveUser(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_USERS);

        /** @var UserRepository $users */
        $users = $this->container->get(UserRepository::class);

        $id = (int) ($request->input('id') ?? 0);
        $action = $request->input('action') ?? '';
        $target = $users->find($id);

        if ($target === null) {
            throw HttpException::notFound('That account does not exist.');
        }

        switch ($action) {
            case 'authorize':
                $users->setAuthorized($id, true, $this->user()?->email);
                Audit::log($this->db(), $this->user()?->email, 'user.authorize', 'user', (string) $id, $target->email);
                return $this->back($request, $target->email . ' can now watch videos.');

            case 'revoke':
                // Removing the last administrator is an unrecoverable lockout
                // on a host with no shell access.
                if ($users->isLastAdmin($id)) {
                    return $this->back($request, 'This is the only administrator. Promote someone else first.', 'error');
                }
                $users->setAuthorized($id, false, $this->user()?->email);
                Audit::log($this->db(), $this->user()?->email, 'user.revoke', 'user', (string) $id, $target->email);
                return $this->back($request, 'Access removed for ' . $target->email . '.');

            case 'role':
                $this->require(Capability::MANAGE_PERMISSIONS);

                $role = $request->input('role') ?? '';
                if ($target->isAdmin() && $role !== Capability::ROLE_ADMIN && $users->isLastAdmin($id)) {
                    return $this->back($request, 'This is the only administrator. Promote someone else first.', 'error');
                }

                $users->setRole($id, $role);
                Audit::log($this->db(), $this->user()?->email, 'user.role', 'user', (string) $id, $target->email . ' → ' . $role);
                return $this->back($request, 'Role updated.');

            default:
                return $this->back($request, 'Unknown action.', 'error');
        }
    }

    // -------------------------------------------------------------- plugins

    public function plugins(Request $request): Response
    {
        $this->require(Capability::MANAGE_PLUGINS);

        /** @var PluginManager $plugins */
        $plugins = $this->container->get(PluginManager::class);

        return $this->admin('plugins', ['plugins' => $plugins->listForAdmin()]);
    }

    public function togglePlugin(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_PLUGINS);

        /** @var PluginManager $plugins */
        $plugins = $this->container->get(PluginManager::class);

        $slug = $request->input('slug') ?? '';
        $action = $request->input('action') ?? '';

        $result = match ($action) {
            'activate'   => $plugins->activate($slug),
            'deactivate' => $plugins->deactivate($slug),
            'uninstall'  => $plugins->uninstall($slug),
            default      => ['ok' => false, 'message' => 'Unknown action.'],
        };

        Audit::log($this->db(), $this->user()?->email, 'plugin.' . $action, 'plugin', $slug, $result['message']);

        return $this->back($request, $result['message'], $result['ok'] ? 'success' : 'error');
    }

    // --------------------------------------------------------------- themes

    public function themes(Request $request): Response
    {
        $this->require(Capability::MANAGE_THEMES);

        $themes = $this->themeManager();
        $active = $themes->active();

        return $this->admin('themes', [
            'themes'     => $themes->listForAdmin(),
            'customizer' => $active->customizer,
            'settings'   => $themes->settings($active->slug) + $active->defaults(),
            'activeSlug' => $active->slug,
        ]);
    }

    public function saveTheme(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_THEMES);

        $themes = $this->themeManager();
        $action = $request->input('action') ?? 'customize';

        if ($action === 'activate') {
            $result = $themes->activate($request->input('slug') ?? '');
            Audit::log($this->db(), $this->user()?->email, 'theme.activate', 'theme', $request->input('slug'));
            return $this->back($request, $result['message'], $result['ok'] ? 'success' : 'error');
        }

        $values = [];
        foreach ((array) ($request->post['settings'] ?? []) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        // Unchecked boxes are simply absent from a form post, so every declared
        // bool has to be defaulted off or it could never be turned off.
        foreach ($themes->active()->settingDefinitions() as $key => $definition) {
            if (($definition['type'] ?? '') === 'bool' && !isset($values[$key])) {
                $values[$key] = '0';
            }
        }

        $themes->saveSettings($themes->activeSlug(), $values);
        Audit::log($this->db(), $this->user()?->email, 'theme.customize', 'theme', $themes->activeSlug());

        return $this->back($request, 'Appearance saved.');
    }

    // ------------------------------------------------------------ providers

    public function providers(Request $request): Response
    {
        $this->require(Capability::MANAGE_PROVIDERS);

        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $kinds = [];
        foreach ([ProviderRegistry::KIND_AUTH, ProviderRegistry::KIND_VIDEO, ProviderRegistry::KIND_MAIL] as $kind) {
            $active = $registry->activeSlug($kind);
            $kinds[$kind] = [
                'options' => $registry->describe($kind),
                'active'  => $active,
                'fields'  => $active === null ? [] : $registry->fieldsFor($kind, $active),
                'values'  => $active === null ? [] : $registry->credentials($kind, $active),
            ];
        }

        return $this->admin('providers', ['kinds' => $kinds]);
    }

    public function saveProvider(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_PROVIDERS);

        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $kind = $request->input('kind') ?? '';
        $slug = $request->input('slug') ?? '';
        $action = $request->input('action') ?? 'save';

        $credentials = [];
        foreach ((array) ($request->post['credentials'] ?? []) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $credentials[$key] = trim((string) $value);
            }
        }

        try {
            $registry->saveCredentials($kind, $slug, $credentials);

            if ($action === 'test') {
                $result = $registry->safeTest($registry->build($kind, $slug));
                return $this->back(
                    $request,
                    $result->message . ($result->detail !== null ? ' — ' . $result->detail : ''),
                    $result->ok ? 'success' : 'error'
                );
            }

            // Activation runs the provider's own test first; a service that
            // fails now would otherwise fail silently for weeks.
            $result = $registry->activate($kind, $slug);

            Audit::log($this->db(), $this->user()?->email, 'provider.activate', $kind, $slug, $result->message);

            return $this->back(
                $request,
                $result->ok
                    ? ucfirst($kind) . ' service switched to ' . $slug . '. ' . $result->message
                    : 'Not switched: ' . $result->message,
                $result->ok ? 'success' : 'error'
            );
        } catch (Throwable $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ------------------------------------------------------------- settings

    public function settings(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        /** @var \Portal\Support\Cron $cron */
        $cron = $this->container->get(\Portal\Support\Cron::class);

        return $this->admin('settings', [
            'settings' => [
                'site_name' => $this->config()->setting('site_name', 'Video Portal'),
                'timezone'  => $this->config()->setting('timezone', 'UTC'),
            ],
            'cronJobs' => $cron->jobs(),
            'baseUrl'  => $this->config()->baseUrl(),
            // Geo lists are shown read-only: they live in config.php on
            // purpose, so a mistaken entry can be undone over FTP rather than
            // locking the author out of the screen that made it.
            'geo' => [
                'viewers' => $this->config()->csv('geo_whitelist'),
                'admin'   => $this->config()->csv('admin_geo_whitelist'),
                'bypass'  => $this->config()->csv('admin_geo_bypass_emails'),
            ],
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $siteName = trim($request->input('site_name') ?? '');
        $timezone = $request->input('timezone') ?? 'UTC';

        if ($siteName === '') {
            return $this->back($request, 'The site needs a name.', 'error');
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $this->back($request, 'That is not a recognised timezone.', 'error');
        }

        $this->config()->setSettings(['site_name' => $siteName, 'timezone' => $timezone]);
        Audit::log($this->db(), $this->user()?->email, 'settings.update');

        return $this->back($request, 'Settings saved.');
    }

    // -------------------------------------------------------------- helpers

    /** @param array<string, mixed> $data */
    private function admin(string $screen, array $data): Response
    {
        $view = new AdminView();

        return Response::html($view->render($screen, $data + [
            'screen'   => $screen,
            'user'     => $this->user(),
            'guard'    => $this->guard(),
            'siteName' => $this->config()->setting('site_name', 'Video Portal'),
            'token'    => $this->csrfToken(),
            'flash'    => $this->flash(),
            'nav'      => $this->adminNav(),
        ]))->private();
    }

    /**
     * Navigation, filtered by what this person can actually do.
     *
     * Showing a link that leads to a 403 is worse than not showing it: it
     * reads as a broken site rather than a permission boundary.
     *
     * @return list<array{label: string, path: string, key: string}>
     */
    private function adminNav(): array
    {
        $items = [
            ['label' => 'Dashboard',  'path' => '/admin',            'key' => 'dashboard',  'cap' => null],
            ['label' => 'Videos',     'path' => '/admin/videos',     'key' => 'videos',     'cap' => Capability::MANAGE_VIDEOS],
            ['label' => 'Categories', 'path' => '/admin/categories', 'key' => 'categories', 'cap' => Capability::MANAGE_CATEGORIES],
            ['label' => 'People',     'path' => '/admin/users',      'key' => 'users',      'cap' => Capability::MANAGE_USERS],
            ['label' => 'Plugins',    'path' => '/admin/plugins',    'key' => 'plugins',    'cap' => Capability::MANAGE_PLUGINS],
            ['label' => 'Appearance', 'path' => '/admin/themes',     'key' => 'themes',     'cap' => Capability::MANAGE_THEMES],
            ['label' => 'Services',   'path' => '/admin/providers',  'key' => 'providers',  'cap' => Capability::MANAGE_PROVIDERS],
            ['label' => 'Settings',   'path' => '/admin/settings',   'key' => 'settings',   'cap' => Capability::MANAGE_SETTINGS],
        ];

        $visible = [];
        foreach ($items as $item) {
            if ($item['cap'] === null || $this->guard()->can($item['cap'])) {
                $visible[] = ['label' => $item['label'], 'path' => $item['path'], 'key' => $item['key']];
            }
        }

        return $visible;
    }

    /** @return array<string, array{slug: ?string, ok: bool}> */
    private function providerSummary(): array
    {
        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $summary = [];
        foreach ([ProviderRegistry::KIND_AUTH, ProviderRegistry::KIND_VIDEO, ProviderRegistry::KIND_MAIL] as $kind) {
            $slug = $registry->activeSlug($kind);
            $summary[$kind] = ['slug' => $slug, 'ok' => $slug !== null];
        }

        return $summary;
    }
}
