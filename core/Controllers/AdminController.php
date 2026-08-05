<?php

declare(strict_types=1);

namespace Portal\Controllers;

use Portal\Admin\AdminView;
use Portal\Auth\Capability;
use Portal\Auth\UserRepository;
use Portal\Content\CategoryRepository;
use Portal\Content\ThumbnailPolicy;
use Portal\Content\VideoRepository;
use Portal\Http\HttpException;
use Portal\Http\Request;
use Portal\Http\Response;
use Portal\Plugins\PluginManager;
use Portal\Providers\ProviderRegistry;
use Portal\Support\Audit;
use Portal\Support\PackageInstaller;
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
            'canUpload'  => $this->canUpload(),
            'trashed'    => $videos->trashedCount(),
        ]);
    }

    /**
     * Edit one video.
     *
     * The listing deliberately stays a listing. Everything worth changing about
     * a video — description, categories, watermark, artwork — needs room to
     * explain itself, and inline forms in a table row give none.
     *
     * @param array<string, string> $params
     */
    public function editVideo(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $video = $videos->find((int) ($params['id'] ?? 0));
        if ($video === null) {
            throw HttpException::notFound('That video does not exist.');
        }

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        return $this->admin('video-edit', [
            'video'          => $video,
            'categories'     => $categories->all(true),
            'assigned'       => $videos->categoryIds($video->id),
            'series'         => $this->seriesRepo()->all(true),
            'speakers'       => $this->speakerRepo()->all(),
            'inheritedLabel' => $this->inheritedThumbnailLabel($videos, $video),
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

        /*
         * A rejected value comes back as a message on the form, not as a 400.
         *
         * Every other admin screen here already did this; the video save did
         * not, so a mistyped date or a backwards schedule threw the editor onto
         * an error page with their other changes lost. The repository is still
         * the thing that refuses — this only decides how the refusal is shown.
         */
        try {
            return $this->saveVideo($request, $videos, $video, $id, $action);
        } catch (HttpException $e) {
            /*
             * Only a bad value. A 403 has to stay a 403 — turning "you may not
             * publish" into a flash message would make a refused action look
             * like a failed one, and the capability checks in this switch are
             * the point of them being there.
             */
            if ($e->status !== 400) {
                throw $e;
            }

            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function saveVideo(
        Request $request,
        VideoRepository $videos,
        \Portal\Content\Video $video,
        int $id,
        string $action
    ): Response {
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
                /*
                 * Absent and empty are different answers.
                 *
                 * A field the form did not send means "leave this alone"; a
                 * field sent empty means "clear it". Collapsing the two —
                 * which this did — makes any POST carrying a subset of the
                 * form silently destroy everything it left out. A smoke check
                 * that saved a thumbnail setting detached the video from its
                 * series and its speaker, and nothing said so.
                 *
                 * The real edit form always submits every select, so this was
                 * invisible from the browser. It is still wrong: a plugin
                 * screen, a future partial form, or a bulk action would each
                 * hit it, and the loss looks like the data was never there.
                 */
                $whole = $request->input('_whole_form') !== null;

                $seriesRaw = $request->input('series_id');
                $speakerRaw = $request->input('speaker_id');

                $videos->update($id, [
                    'title'          => $request->input('title') ?? $video->title,
                    'description'    => $request->input('description') === null
                        ? $video->description
                        : $request->input('description'),
                    'watermark_mode' => $request->input('watermark_mode') ?? $video->watermarkMode,
                    'thumbnail_mode' => $request->input('thumbnail_mode') ?? $video->thumbnailMode,
                    // Zero means "none", which has to be expressible — so an
                    // empty selection becomes null rather than 0, which no
                    // series or speaker will ever have as an id.
                    'published_at'   => $request->input('published_at') === null
                        ? $video->publishedAt
                        : $request->input('published_at'),
                    'unpublish_at'   => $request->input('unpublish_at') === null
                        ? $video->unpublishAt
                        : $request->input('unpublish_at'),
                    'series_id'      => $seriesRaw === null
                        ? $video->seriesId
                        : (($s = (int) $seriesRaw) > 0 ? $s : null),
                    'speaker_id'     => $speakerRaw === null
                        ? $video->speakerId
                        : (($p = (int) $speakerRaw) > 0 ? $p : null),
                    /*
                     * Checkboxes and multi-selects are the cases where absent
                     * and empty genuinely cannot be told apart: a browser sends
                     * nothing for an unchecked box and nothing for a category
                     * list with none ticked.
                     *
                     * So the form declares itself complete with a hidden field.
                     * Present, presence is the value and unticking really does
                     * clear. Missing, these are left alone — which is what a
                     * partial POST means everywhere else in this handler, and
                     * the only reading under which "save the thumbnail mode"
                     * cannot also mean "make this public and uncategorised".
                     */
                    'member_only'    => $whole ? $request->input('member_only') !== null : $video->memberOnly,
                    'hidden'         => $whole ? $request->input('hidden') !== null : $video->hidden,
                    'premiere'       => $whole ? $request->input('premiere') !== null : $video->premiere,
                    'featured'       => $whole ? $request->input('featured') !== null : $video->featured,
                    'pinned'         => $whole ? $request->input('pinned') !== null : $video->pinned,
                ]);

                if ($whole) {
                    $categoryIds = array_map('intval', $request->inputArray('categories'));
                    $videos->setCategories($id, $categoryIds);
                }

                Audit::log($this->db(), $this->user()?->email, 'video.update', 'video', (string) $id, $video->title);
                return $this->back($request, 'Video saved.');
        }
    }

    // --------------------------------------------------------- distribution

    public function installPlugin(Request $request): Response
    {
        return $this->installPackage($request, PackageInstaller::KIND_PLUGIN, Capability::MANAGE_PLUGINS);
    }

    public function installTheme(Request $request): Response
    {
        return $this->installPackage($request, PackageInstaller::KIND_THEME, Capability::MANAGE_THEMES);
    }

    /**
     * Install a plugin or theme from an uploaded ZIP.
     *
     * Both kinds share everything but a capability and a directory, and the
     * dangerous part — extraction — is the same code either way. Two copies of
     * this would eventually be two different sets of safety checks.
     */
    private function installPackage(Request $request, string $kind, string $capability): Response
    {
        $this->verifyCsrf($request);
        $this->require($capability);

        if (!PackageInstaller::uploadsAllowed($this->config())) {
            return $this->back(
                $request,
                'Installing from a file is switched off on this site. Remove allow_package_uploads '
                . 'from config.php to turn it back on, or upload the folder over FTP.',
                'error'
            );
        }

        /** @var array<string, mixed> $file */
        $file = (array) ($request->files['package'] ?? []);

        $result = (new PackageInstaller($kind))->installUpload($file);

        Audit::log(
            $this->db(),
            $this->user()?->email,
            $kind . '.install',
            $kind,
            $result['slug'] ?? null,
            $result['ok'] ? 'installed' : $result['message']
        );

        // Discovery is memoised, and the listing it holds was taken before this
        // upload existed. Without forgetting it first, the screen reports
        // "Installed" and then does not list the thing it just installed.
        if ($result['ok'] && $kind === PackageInstaller::KIND_PLUGIN) {
            $plugins = $this->container->get(PluginManager::class);
            $plugins->forgetDiscovered();
            $plugins->sync();
        }

        if ($result['ok'] && $kind === PackageInstaller::KIND_THEME) {
            $this->themeManager()->forgetDiscovered();
            $this->themeManager()->sync();
        }

        return $this->back($request, $result['message'], $result['ok'] ? 'success' : 'error');
    }

    /**
     * Download this site's configuration as JSON.
     *
     * Deliberately excludes every credential. Provider secrets are encrypted
     * with this install's APP_KEY and would not decrypt anywhere else, so
     * exporting them would produce a file that is both a liability and useless.
     */
    public function exportSettings(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        $themes = $this->themeManager();

        $payload = [
            'exportedAt' => date('c'),
            'version'    => PORTAL_VERSION,
            'settings'   => $this->exportableSettings(),
            'theme'      => [
                'active'   => $themes->activeSlug(),
                'settings' => $themes->settings($themes->activeSlug()),
            ],
            'plugins'    => $this->db()->column('SELECT slug FROM {plugins} WHERE is_active = 1'),
        ];

        Audit::log($this->db(), $this->user()?->email, 'settings.export');

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

        return Response::html($json)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header(
                'Content-Disposition',
                'attachment; filename="video-portal-settings-' . date('Y-m-d') . '.json"'
            )
            ->private();
    }

    public function importSettings(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        /** @var array<string, mixed> $file */
        $file = (array) ($request->files['settings'] ?? []);
        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->back($request, 'No file was chosen.', 'error');
        }

        $decoded = json_decode((string) file_get_contents($tmp), true);

        if (!is_array($decoded)) {
            return $this->back($request, 'That file is not a settings export.', 'error');
        }

        $applied = $this->applyImport($decoded);

        Audit::log($this->db(), $this->user()?->email, 'settings.import', null, null, implode(', ', $applied));

        return $this->back($request, $applied === []
            ? 'Nothing in that file could be applied.'
            : 'Imported: ' . implode(', ', $applied) . '.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string> what was actually changed
     */
    private function applyImport(array $payload): array
    {
        $applied = [];

        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $pairs = [];
            foreach ($payload['settings'] as $key => $value) {
                // Only keys this version knows about. An export from a newer
                // version would otherwise write settings nothing reads, which
                // then reappear if the site is upgraded and behave unexpectedly.
                if (is_string($key) && is_scalar($value) && in_array($key, self::EXPORTABLE_SETTINGS, true)) {
                    $pairs[$key] = (string) $value;
                }
            }

            if ($pairs !== []) {
                $this->config()->setSettings($pairs);
                $applied[] = count($pairs) . ' site setting(s)';
            }
        }

        if (isset($payload['theme']['settings']) && is_array($payload['theme']['settings'])) {
            $themes = $this->themeManager();
            $values = [];
            foreach ($payload['theme']['settings'] as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $values[$key] = (string) $value;
                }
            }

            if ($values !== []) {
                // Applied to the CURRENTLY active theme, not the one named in
                // the file. Importing settings must not silently switch the
                // site's appearance to a theme that may not be installed.
                $themes->saveSettings($themes->activeSlug(), $values);
                $applied[] = 'theme customisations';
            }
        }

        return $applied;
    }

    /** @return array<string, string|null> */
    private function exportableSettings(): array
    {
        $out = [];
        foreach (self::EXPORTABLE_SETTINGS as $key) {
            $out[$key] = $this->config()->setting($key);
        }

        return $out;
    }

    /**
     * Settings safe to move between installs.
     *
     * An allow-list rather than "everything except secrets", because the
     * dangerous direction is a setting added later that nobody remembers to
     * exclude. A new key is invisible to export until someone adds it here on
     * purpose.
     */
    private const EXPORTABLE_SETTINGS = [
        'site_name',
        'timezone',
        'watermark_default',
        'members_thumbnail_default',
        'geo_enabled',
        'admin_geo_enabled',
    ];

    // ---------------------------------------------------------- permissions

    public function permissions(Request $request): Response
    {
        $this->require(Capability::MANAGE_PERMISSIONS);

        $repo = $this->permissionRepo();

        return $this->admin('permissions', [
            'roles'        => $repo->roles(),
            'groups'       => $repo->groups(),
            'grants'       => $repo->grants(),
            'capabilities' => Capability::all(),
            'siteOnly'     => Capability::siteOnly(),
            'categories'   => $this->container->get(CategoryRepository::class)->all(true),
            'seriesList'   => $this->seriesRepo()->all(true),
        ]);
    }

    public function savePermissions(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_PERMISSIONS);

        $repo = $this->permissionRepo();
        $action = $request->input('action') ?? '';
        $actor = $this->user()?->email;

        try {
            $message = match ($action) {
                'role' => $this->saveRoleCapabilities($repo, $request, $actor),
                'group-create' => $this->createPermissionGroup($repo, $request, $actor),
                'group-delete' => $this->deletePermissionGroup($repo, $request, $actor),
                'group-capabilities' => $this->saveGroupCapabilities($repo, $request, $actor),
                'group-add-member' => $this->addPermissionGroupMember($repo, $request, $actor),
                'group-remove-member' => $this->removePermissionGroupMember($repo, $request, $actor),
                'grant' => $this->createGrant($repo, $request, $actor),
                'revoke' => $this->revokeGrant($repo, $request, $actor),
                default => throw HttpException::badRequest('Unknown action.'),
            };
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }

        // Anything cached during this request now describes the state the admin
        // just changed away from.
        $this->container->get(\Portal\Auth\Capabilities::class)->flush();

        return $this->back($request, $message);
    }

    private function saveRoleCapabilities(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $roleId = (int) ($request->input('role_id') ?? 0);
        $repo->setRoleCapabilities($roleId, $request->inputArray('capabilities'));

        Audit::log($this->db(), $actor, 'permissions.role', 'role', (string) $roleId);

        return 'Role updated.';
    }

    private function createPermissionGroup(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = $repo->createGroup(
            $request->input('name') ?? '',
            $request->input('description')
        );

        Audit::log($this->db(), $actor, 'permissions.group.create', 'group', (string) $id);

        return 'Group created.';
    }

    private function deletePermissionGroup(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $repo->deleteGroup($id);

        Audit::log($this->db(), $actor, 'permissions.group.delete', 'group', (string) $id);

        return 'Group deleted. Everyone in it loses whatever it granted.';
    }

    private function saveGroupCapabilities(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $repo->setGroupCapabilities($id, $request->inputArray('capabilities'));

        Audit::log($this->db(), $actor, 'permissions.group.capabilities', 'group', (string) $id);

        return 'Group permissions updated.';
    }

    private function addPermissionGroupMember(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $email = $request->input('email') ?? '';
        $repo->addGroupMember($id, $email);

        Audit::log($this->db(), $actor, 'permissions.group.add', 'group', (string) $id, $email);

        return 'Added to the group.';
    }

    private function removePermissionGroupMember(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('group_id') ?? 0);
        $email = $request->input('email') ?? '';
        $repo->removeGroupMember($id, $email);

        Audit::log($this->db(), $actor, 'permissions.group.remove', 'group', (string) $id, $email);

        return 'Removed from the group.';
    }

    private function createGrant(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $subjectType = $request->input('subject_type') ?? 'email';

        // The scope picker is one dropdown — "site", or "category:12" — because
        // a type select plus an id select would let someone choose a category
        // type with a series id and produce a grant that silently matches
        // nothing.
        [$scopeType, $scopeId] = $this->parseScope($request->input('scope') ?? 'site');

        $repo->grant(
            $subjectType,
            $subjectType === 'email'
                ? ($request->input('email') ?? '')
                : ($request->input('subject_id') ?? '0'),
            $request->input('capability') ?? '',
            $scopeType,
            $scopeId,
            $actor
        );

        Audit::log($this->db(), $actor, 'permissions.grant', null, null, $request->input('capability'));

        return 'Permission granted.';
    }

    private function revokeGrant(
        \Portal\Auth\PermissionRepository $repo,
        Request $request,
        ?string $actor
    ): string {
        $id = (int) ($request->input('grant_id') ?? 0);
        $repo->revoke($id);

        Audit::log($this->db(), $actor, 'permissions.revoke', null, (string) $id);

        return 'Permission removed.';
    }

    private function permissionRepo(): \Portal\Auth\PermissionRepository
    {
        return new \Portal\Auth\PermissionRepository($this->db());
    }

    /**
     * "site" or "category:12" into a type and an id.
     *
     * Anything unrecognised becomes site-wide rather than throwing. That is the
     * safe direction here only because the repository re-validates the type and
     * refuses a scoped grant with no id — this is parsing, not authorisation.
     *
     * @return array{0: string, 1: int}
     */
    private function parseScope(string $raw): array
    {
        if (!str_contains($raw, ':')) {
            return ['site', 0];
        }

        [$type, $id] = explode(':', $raw, 2);

        return [$type, (int) $id];
    }

    // ---------------------------------------------------------------- trash

    public function trash(Request $request): Response
    {
        $this->require(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        return $this->admin('trash', ['videos' => $videos->trashed()]);
    }

    /**
     * Restore, or destroy for good.
     *
     * Permanent deletion removes the video at the provider FIRST, and gives up
     * if that fails. Removing only the local row would be worse than useless:
     * the file is still at the provider, so the next sync re-imports it and the
     * admin is left believing the delete silently failed at random.
     */
    public function updateTrash(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_VIDEOS);

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        $id = (int) ($request->input('id') ?? 0);
        $video = $videos->find($id) ?? $this->trashedVideo($id);

        if ($video === null) {
            return $this->back($request, 'That video is not in the trash.', 'error');
        }

        if (($request->input('action') ?? '') === 'restore') {
            $videos->restore($id);
            Audit::log($this->db(), $this->user()?->email, 'video.restore', 'video', (string) $id, $video->title);

            return $this->back($request, 'Restored “' . $video->title . '”.');
        }

        // Permanent.
        try {
            $this->container->get(\Portal\Video\VideoProvider::class)->deleteVideo($video->providerId);
        } catch (Throwable $e) {
            return $this->back($request, sprintf(
                'Could not delete “%s” at your video service, so it has been left in the trash: %s '
                . 'Deleting it here alone would not work — the next sync would bring it straight back.',
                $video->title,
                $e->getMessage()
            ), 'error');
        }

        $videos->forceDelete($id);
        Audit::log($this->db(), $this->user()?->email, 'video.purge', 'video', (string) $id, $video->title);

        return $this->back($request, 'Deleted “' . $video->title . '” for good.');
    }

    /** find() hides trashed rows, which is exactly what this screen works on. */
    private function trashedVideo(int $id): ?\Portal\Content\Video
    {
        $row = $this->db()->first('SELECT * FROM {videos} WHERE id = ? AND deleted_at IS NOT NULL', [$id]);

        return $row === null ? null : \Portal\Content\Video::fromRow($row);
    }

    // --------------------------------------------------------------- series

    public function series(Request $request): Response
    {
        $this->require(Capability::MANAGE_SERIES);

        return $this->admin('series', [
            'series'     => $this->seriesRepo()->all(true),
            'categories' => $this->container->get(CategoryRepository::class)->all(true),
        ]);
    }

    /** @param array<string, string> $params */
    public function editSeries(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_SERIES);

        $series = $this->seriesRepo()->find((int) ($params['id'] ?? 0));
        if ($series === null) {
            throw HttpException::notFound('That series does not exist.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        return $this->admin('series-edit', [
            'series'     => $series,
            'categories' => $this->container->get(CategoryRepository::class)->all(true),
            // In series order, so the list on screen is the running order.
            'episodes'   => $videos->forSeries($series->id, true),
            'available'  => $this->unassignedVideos($series->id),
        ]);
    }

    public function saveSeries(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SERIES);

        $repo = $this->seriesRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'series.delete', 'series', (string) $id);
                    return $this->back($request, 'Series deleted. Its videos were kept.');

                case 'update':
                    $repo->update($id, [
                        'title'        => $request->input('title'),
                        'slug'         => $request->input('slug'),
                        'description'  => $request->input('description'),
                        'category_id'  => $request->input('category_id'),
                        // Absent means unchecked; see updateVideo().
                        'is_published' => $request->input('is_published') !== null,
                        'member_only'  => $request->input('member_only') !== null,
                        'hidden'       => $request->input('hidden') !== null,
                        'featured'     => $request->input('featured') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'series.update', 'series', (string) $id);
                    return $this->back($request, 'Series saved.');

                case 'episodes':
                    $repo->setVideos($id, array_map('intval', $request->inputArray('videos')));
                    Audit::log($this->db(), $this->user()?->email, 'series.episodes', 'series', (string) $id);
                    return $this->back($request, 'Episodes updated.');

                case 'up':
                case 'down':
                    $repo->move((int) ($request->input('video') ?? 0), $action === 'up' ? -1 : 1);
                    return $this->back($request, '');

                default:
                    $created = $repo->create(['title' => $request->input('title')]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'series.create',
                        'series',
                        (string) $created->id,
                        $created->title
                    );
                    return $this->redirect('/admin/series/' . $created->id);
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    // ------------------------------------------------------------ playlists

    public function playlists(Request $request): Response
    {
        $this->require(Capability::MANAGE_SERIES);

        return $this->admin('playlists', [
            'playlists' => $this->playlistRepo()->all(true),
        ]);
    }

    /** @param array<string, string> $params */
    public function editPlaylist(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_SERIES);

        $playlist = $this->playlistRepo()->find((int) ($params['id'] ?? 0));
        if ($playlist === null) {
            throw HttpException::notFound('That playlist does not exist.');
        }

        /** @var VideoRepository $videos */
        $videos = $this->container->get(VideoRepository::class);

        /*
         * Unlike a series, a playlist does not own its videos, so there is no
         * "unassigned" pool to offer — every video in the library is a
         * candidate, including ones already on other playlists. The chosen ones
         * are listed separately and in order, because that order is the whole
         * point of the screen.
         */
        $chosen = $this->playlistRepo()->orderedVideoIds($playlist->id);

        return $this->admin('playlist-edit', [
            'playlist'  => $playlist,
            'items'     => $this->playlistRepo()->videos($playlist->id, true, true),
            'chosenIds' => $chosen,
            'available' => $videos->query(['includeUnpublished' => true, 'includeHidden' => true,
                                           'includeMemberOnly' => true], 1, 100)['items'],
        ]);
    }

    public function savePlaylist(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SERIES);

        $repo = $this->playlistRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'playlist.delete', 'playlist', (string) $id);
                    return $this->back($request, 'Playlist deleted. Its videos were kept.');

                case 'update':
                    $repo->update($id, [
                        'title'        => $request->input('title'),
                        'slug'         => $request->input('slug'),
                        'description'  => $request->input('description'),
                        // Absent means unchecked; see updateVideo().
                        'is_published' => $request->input('is_published') !== null,
                        'member_only'  => $request->input('member_only') !== null,
                        'hidden'       => $request->input('hidden') !== null,
                        'featured'     => $request->input('featured') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'playlist.update', 'playlist', (string) $id);
                    return $this->back($request, 'Playlist saved.');

                case 'items':
                    $repo->setVideos($id, array_map('intval', $request->inputArray('videos')));
                    Audit::log($this->db(), $this->user()?->email, 'playlist.items', 'playlist', (string) $id);
                    return $this->back($request, 'Playlist updated.');

                case 'up':
                case 'down':
                    // The playlist id travels with the move. Without it the
                    // neighbour lookup would find whichever row in any playlist
                    // held the adjacent position.
                    $repo->move($id, (int) ($request->input('video') ?? 0), $action === 'up' ? -1 : 1);
                    return $this->back($request, '');

                default:
                    $created = $repo->create(['title' => $request->input('title')]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'playlist.create',
                        'playlist',
                        (string) $created->id,
                        $created->title
                    );
                    return $this->redirect('/admin/playlists/' . $created->id);
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function playlistRepo(): \Portal\Content\PlaylistRepository
    {
        return $this->container->get(\Portal\Content\PlaylistRepository::class);
    }

    // ------------------------------------------------------------- homepage

    public function homeRows(Request $request): Response
    {
        $this->require(Capability::MANAGE_SETTINGS);

        return $this->admin('home-rows', [
            'rows'       => $this->homeRowRepo()->all(true),
            'categories' => $this->container->get(CategoryRepository::class)->all(true),
            'series'     => $this->seriesRepo()->all(true),
            'playlists'  => $this->playlistRepo()->all(true),
        ]);
    }

    public function saveHomeRow(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SETTINGS);

        $repo = $this->homeRowRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'home_row.delete', 'home_row', (string) $id);
                    return $this->back($request, 'Row removed.');

                case 'update':
                    $repo->update($id, [
                        'title'       => $request->input('title'),
                        'source_type' => $request->input('source_type'),
                        // The picker for the chosen source. One field per kind
                        // rather than one shared one, because a single select
                        // holding ids from three tables cannot say which table
                        // a number came from.
                        'source_id'   => $request->input('source_' . ($request->input('source_type') ?? '')),
                        'max_items'   => $request->input('max_items'),
                        'is_active'   => $request->input('is_active') !== null,
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'home_row.update', 'home_row', (string) $id);
                    return $this->back($request, 'Row saved.');

                case 'up':
                case 'down':
                    $repo->move($id, $action === 'up' ? -1 : 1);
                    return $this->back($request, '');

                default:
                    $source = (string) ($request->input('source_type') ?? '');
                    $created = $repo->create([
                        'title'       => $request->input('title'),
                        'source_type' => $source,
                        'source_id'   => $request->input('source_' . $source),
                        'max_items'   => $request->input('max_items'),
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'home_row.create',
                        'home_row',
                        (string) $created->id
                    );
                    return $this->back($request, 'Row added.');
            }
        } catch (HttpException $e) {
            if ($e->status !== 400) {
                throw $e;
            }

            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    private function homeRowRepo(): \Portal\Content\HomeRowRepository
    {
        return $this->container->get(\Portal\Content\HomeRowRepository::class);
    }

    // ------------------------------------------------------------- speakers

    public function speakers(Request $request): Response
    {
        $this->require(Capability::MANAGE_SPEAKERS);

        return $this->admin('speakers', [
            'speakers' => $this->speakerRepo()->all(),
            'editing'  => $this->speakerRepo()->find((int) ($request->query('edit') ?? 0)),
        ]);
    }

    public function saveSpeaker(Request $request): Response
    {
        $this->verifyCsrf($request);
        $this->require(Capability::MANAGE_SPEAKERS);

        $repo = $this->speakerRepo();
        $action = $request->input('action') ?? 'create';
        $id = (int) ($request->input('id') ?? 0);

        try {
            switch ($action) {
                case 'delete':
                    $count = $repo->videoCount($id);
                    $repo->delete($id);
                    Audit::log($this->db(), $this->user()?->email, 'speaker.delete', 'speaker', (string) $id);

                    return $this->back($request, $count === 0
                        ? 'Speaker removed.'
                        : sprintf(
                            'Speaker removed. %d video%s kept, now with no speaker.',
                            $count,
                            $count === 1 ? '' : 's'
                        ));

                case 'update':
                    $repo->update($id, [
                        'name'      => $request->input('name'),
                        'slug'      => $request->input('slug'),
                        'bio'       => $request->input('bio'),
                        'image_url' => $request->input('image_url'),
                    ]);
                    Audit::log($this->db(), $this->user()?->email, 'speaker.update', 'speaker', (string) $id);
                    return $this->back($request, 'Speaker saved.');

                default:
                    $created = $repo->create([
                        'name' => $request->input('name'),
                        'bio'  => $request->input('bio'),
                    ]);
                    Audit::log(
                        $this->db(),
                        $this->user()?->email,
                        'speaker.create',
                        'speaker',
                        (string) $created->id,
                        $created->name
                    );
                    return $this->back($request, 'Speaker added.');
            }
        } catch (HttpException $e) {
            return $this->back($request, $e->getMessage(), 'error');
        }
    }

    /**
     * Videos that could be added to this series.
     *
     * Anything already in ANOTHER series is excluded rather than offered and
     * then silently stolen: a video belongs to at most one series, so adding it
     * here would remove it from there without anyone being told.
     *
     * @return list<\Portal\Content\Video>
     */
    private function unassignedVideos(int $seriesId): array
    {
        $rows = $this->db()->all(
            'SELECT * FROM {videos}
              WHERE deleted_at IS NULL AND (series_id IS NULL OR series_id = ?)
              ORDER BY COALESCE(published_at, created_at) DESC
              LIMIT 200',
            [$seriesId]
        );

        return array_map(
            static fn (array $row): \Portal\Content\Video => \Portal\Content\Video::fromRow($row),
            $rows
        );
    }

    private function seriesRepo(): \Portal\Content\SeriesRepository
    {
        return $this->container->get(\Portal\Content\SeriesRepository::class);
    }

    private function speakerRepo(): \Portal\Content\SpeakerRepository
    {
        return $this->container->get(\Portal\Content\SpeakerRepository::class);
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

    /** @param array<string, string> $params */
    public function editCategory(Request $request, array $params): Response
    {
        $this->require(Capability::MANAGE_CATEGORIES);

        /** @var CategoryRepository $categories */
        $categories = $this->container->get(CategoryRepository::class);

        $category = $categories->find((int) ($params['id'] ?? 0));
        if ($category === null) {
            throw HttpException::notFound('That category does not exist.');
        }

        return $this->admin('category-edit', [
            'category'       => $category,
            'flat'           => $categories->all(true),
            'ancestors'      => $categories->ancestors($category->id),
            'inheritedLabel' => $this->inheritedCategoryThumbnailLabel($categories, $category),
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
                        'name'           => $request->input('name'),
                        'slug'           => $request->input('slug'),
                        'description'    => $request->input('description'),
                        'parent_id'      => ($p = (int) ($request->input('parent_id') ?? 0)) > 0 ? $p : null,
                        'thumbnail_mode' => $request->input('thumbnail_mode'),
                        // Absent means unchecked; see updateVideo().
                        'is_published'   => $request->input('is_published') !== null,
                        'member_only'    => $request->input('member_only') !== null,
                        'hidden'         => $request->input('hidden') !== null,
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

    /**
     * What "Inherit" resolves to for this video, said in words.
     *
     * A three-way setting whose default depends on a category chain and a site
     * setting is exactly the kind of thing an admin cannot verify by looking at
     * it. Rather than make them reason about the hierarchy, the form states the
     * answer the resolver actually gives.
     */
    private function inheritedThumbnailLabel(VideoRepository $videos, \Portal\Content\Video $video): string
    {
        $siteDefault = $this->config()->settingBool('members_thumbnail_default', false);

        // Asked with the video's OWN setting removed, which is the only way to
        // learn what it would fall back to.
        $withoutOwn = new \Portal\Content\Video(
            id: $video->id,
            providerId: $video->providerId,
            slug: $video->slug,
            title: $video->title,
            thumbnailMode: ThumbnailPolicy::INHERIT,
        );

        $resolved = $videos->thumbnailModes([$withoutOwn], $siteDefault)[$video->id]
            ?? ThumbnailPolicy::PUBLIC_ART;

        return $resolved === ThumbnailPolicy::MEMBERS
            ? 'Inherit — currently members only'
            : 'Inherit — currently shows the real thumbnail';
    }

    /**
     * The same question for a category: what would it inherit from its parents?
     *
     * @param \Portal\Content\Category $category
     */
    private function inheritedCategoryThumbnailLabel(
        CategoryRepository $categories,
        \Portal\Content\Category $category
    ): string {
        foreach (array_reverse($categories->ancestors($category->id)) as $ancestor) {
            if ($ancestor->thumbnailMode === ThumbnailPolicy::MEMBERS) {
                return 'Inherit — members only, from ' . $ancestor->name;
            }
            if ($ancestor->thumbnailMode === ThumbnailPolicy::PUBLIC_ART) {
                return 'Inherit — real thumbnails, from ' . $ancestor->name;
            }
        }

        return $this->config()->settingBool('members_thumbnail_default', false)
            ? 'Inherit — members only, from the site setting'
            : 'Inherit — real thumbnails, from the site setting';
    }

    /**
     * Is there a video service that could accept an upload?
     *
     * Asked so the Videos screen can offer an upload box only when one would
     * work. Showing it regardless makes it a trap: it looks like the way in,
     * and every attempt fails with an error from a service nobody configured.
     */
    private function canUpload(): bool
    {
        try {
            $provider = $this->container->get(\Portal\Video\VideoProvider::class);
        } catch (Throwable) {
            return false;
        }

        // Deliberately not a test() call. That reaches the network, and this
        // runs on every visit to the Videos screen — a slow or unreachable
        // provider would make the page hang rather than the upload fail.
        return $provider instanceof \Portal\Video\BunnyStreamProvider
            ? $provider->uploadsConfigured()
            : true;
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

        return $this->admin('plugins', [
            'plugins'        => $plugins->listForAdmin(),
            'uploadsAllowed' => PackageInstaller::uploadsAllowed($this->config()),
        ]);
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
            'themes'         => $themes->listForAdmin(),
            'customizer'     => $active->customizer,
            'settings'       => $themes->settings($active->slug) + $active->defaults(),
            'activeSlug'     => $active->slug,
            'uploadsAllowed' => PackageInstaller::uploadsAllowed($this->config()),
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
                'members_thumbnail_default' => $this->config()->setting('members_thumbnail_default', '0'),
                'allow_indexing'      => $this->config()->setting('allow_indexing', '0'),
                'podcast_author'      => $this->config()->setting('podcast_author', ''),
                'podcast_owner_name'  => $this->config()->setting('podcast_owner_name', ''),
                'podcast_owner_email' => $this->config()->setting('podcast_owner_email', ''),
                'podcast_image_url'   => $this->config()->setting('podcast_image_url', ''),
                'podcast_category'    => $this->config()->setting('podcast_category', 'Religion & Spirituality'),
                'podcast_explicit'    => $this->config()->setting('podcast_explicit', '0'),
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

        $this->config()->setSettings([
            'site_name' => $siteName,
            'timezone'  => $timezone,
            // Absent means unchecked, so this cannot be read with ?? — that
            // would make the setting impossible to turn back off.
            'members_thumbnail_default' => $request->input('members_thumbnail_default') !== null ? '1' : '0',
            'allow_indexing'   => $request->input('allow_indexing') !== null ? '1' : '0',
            'podcast_explicit' => $request->input('podcast_explicit') !== null ? '1' : '0',

            'podcast_author'      => trim($request->input('podcast_author') ?? ''),
            'podcast_owner_name'  => trim($request->input('podcast_owner_name') ?? ''),
            'podcast_owner_email' => trim($request->input('podcast_owner_email') ?? ''),
            'podcast_image_url'   => trim($request->input('podcast_image_url') ?? ''),
            'podcast_category'    => trim($request->input('podcast_category') ?? ''),
        ]);
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
