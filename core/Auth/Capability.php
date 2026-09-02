<?php

declare(strict_types=1);

namespace Portal\Auth;

/**
 * The capability vocabulary.
 *
 * Capabilities are the only thing the application ever checks. Code never asks
 * "is this person an admin?" — it asks "may this person publish content?".
 * That indirection is what makes granular, delegated permissions possible at
 * all: an editor scoped to one category needs the same code path as a
 * site-wide administrator, differing only in what they hold.
 */
final class Capability
{
    // Content
    public const MANAGE_CATEGORIES = 'manage_categories';
    public const MANAGE_SERIES     = 'manage_series';
    public const MANAGE_VIDEOS     = 'manage_videos';
    public const MANAGE_SPEAKERS   = 'manage_speakers';
    public const MANAGE_FILES      = 'manage_files';
    public const PUBLISH_CONTENT   = 'publish_content';

    // Sharing
    public const MANAGE_SHARES     = 'manage_shares';

    /**
     * Hand out a link to something you can already watch.
     *
     * Deliberately NOT manage_shares, which is the administrator's version:
     * that one reaches every link on the site, creates them in bulk for any
     * video, revokes anybody's, and edits expiry. This one lets a person share
     * one video at a time and touch nothing but their own links.
     *
     * Scopable, which is the point of it existing separately — a group can be
     * given sharing rights over one category without being given the sharing
     * screen.
     */
    public const SHARE_CONTENT     = 'share_content';
    public const MANAGE_VIEWERS    = 'manage_viewers';

    /**
     * Take a copy of something you can already watch, to keep.
     *
     * Separate from SHARE_CONTENT because it is a different risk. A share link
     * expires, can be revoked, and names the person it was made for; a
     * downloaded file does none of those things and cannot be recalled once it
     * exists. Somebody trusted to hand out a link for a week is not
     * automatically somebody trusted to hand out the file.
     *
     * Scopable, which is most of the point: a course can be made available
     * offline to the group taking it without opening the rest of the library.
     * It answers WHO; `DownloadPolicy` answers WHAT, and a download needs both.
     */
    public const DOWNLOAD_CONTENT  = 'download_content';

    // Community (phase 4, declared now so grants made today keep meaning)
    public const MODERATE_COMMENTS = 'moderate_comments';

    // Administration
    public const MANAGE_USERS       = 'manage_users';
    public const MANAGE_PERMISSIONS = 'manage_permissions';
    public const MANAGE_PLUGINS     = 'manage_plugins';
    public const MANAGE_THEMES      = 'manage_themes';
    public const MANAGE_PROVIDERS   = 'manage_providers';
    public const MANAGE_SETTINGS    = 'manage_settings';
    public const VIEW_AUDIT_LOG     = 'view_audit_log';
    public const VIEW_ANALYTICS     = 'view_analytics';

    /*
     * There was a VIEW_CONTENT here, commented "held by every authorized
     * viewer; the thing an unapproved account conspicuously lacks". Both halves
     * of that were false, and it was enforced NOWHERE.
     *
     * Watching is gated on `users.authorized` — the approval flag an
     * administrator sets on the People screen — plus the content's own
     * visibility. An unapproved account lacks it because can() refuses every
     * capability to an unauthorized user, not because of this one. So granting
     * or revoking it changed nothing in either direction, while appearing on
     * the permissions screen as a real control.
     *
     * Removed rather than enforced. Making it real would have meant two
     * mechanisms deciding one thing, and the standing rule here is that two
     * implementations of a permission rule eventually disagree — with the
     * failure being an approved viewer who cannot watch, reported as the site
     * being broken. Approval is the documented flow and the one people use.
     */

    /**
     * Every capability with a description, seeded at install.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::MANAGE_CATEGORIES   => 'Create, edit, and reorder categories',
            self::MANAGE_SERIES       => 'Create and edit series',
            self::MANAGE_VIDEOS       => 'Upload, edit, and delete videos',
            self::MANAGE_SPEAKERS     => 'Manage the speaker directory',
            self::MANAGE_FILES        => 'Upload and manage file attachments',
            self::PUBLISH_CONTENT     => 'Publish and unpublish content',
            self::MANAGE_SHARES       => 'Create and revoke private share links',
            self::SHARE_CONTENT       => 'Share a video they can watch, and revoke their own links',
            self::DOWNLOAD_CONTENT    => 'Download a video they can watch, for offline viewing',
            self::MANAGE_VIEWERS      => 'Approve viewers and manage viewer groups',
            self::MODERATE_COMMENTS   => 'Review and remove comments',
            self::MANAGE_USERS        => 'Create and edit user accounts',
            self::MANAGE_PERMISSIONS  => 'Assign roles, groups, and permission grants',
            self::MANAGE_PLUGINS      => 'Activate, configure, and remove plugins',
            self::MANAGE_THEMES       => 'Install, switch, and customize themes',
            self::MANAGE_PROVIDERS    => 'Change the auth, video, and email services',
            self::MANAGE_SETTINGS     => 'Change site settings',
            self::VIEW_AUDIT_LOG      => 'Read the activity log',
            self::VIEW_ANALYTICS      => 'View viewing statistics',
        ];
    }

    /**
     * Capabilities that only ever make sense site-wide.
     *
     * Scoping "manage plugins" to a category is meaningless — plugins are
     * global — and offering it in the scope picker would imply a containment
     * that does not exist. The permissions UI hides the scope selector for
     * these, and Capabilities ignores a scope if one is somehow recorded.
     *
     * @return list<string>
     */
    public static function siteOnly(): array
    {
        return [
            self::MANAGE_USERS,
            self::MANAGE_PERMISSIONS,
            self::MANAGE_PLUGINS,
            self::MANAGE_THEMES,
            self::MANAGE_PROVIDERS,
            self::MANAGE_SETTINGS,
            self::VIEW_AUDIT_LOG,
        ];
    }

    /** Capabilities that can be granted against a category, series, or video. */
    public static function isScopable(string $capability): bool
    {
        return !in_array($capability, self::siteOnly(), true);
    }

    /**
     * The roles created at install, and what each holds.
     *
     * `admin` is absent on purpose: it short-circuits every check and holds
     * nothing explicitly, so it can never be partially revoked by editing a
     * join table.
     *
     * @return array<string, array{name: string, description: string, capabilities: list<string>}>
     */
    public static function defaultRoles(): array
    {
        return [
            'admin' => [
                'name'         => 'Administrator',
                'description'  => 'Full control over everything, including permissions.',
                'capabilities' => [], // implicit — see Capabilities::can()
            ],
            'editor' => [
                'name'        => 'Editor',
                'description' => 'Manages content across the whole site, but not users or settings.',
                'capabilities' => [
                    self::MANAGE_CATEGORIES,
                    self::MANAGE_SERIES,
                    self::MANAGE_VIDEOS,
                    self::MANAGE_SPEAKERS,
                    self::MANAGE_FILES,
                    self::PUBLISH_CONTENT,
                    self::MANAGE_SHARES,
                    self::MODERATE_COMMENTS,
                    self::VIEW_ANALYTICS,
                ],
            ],
            'contributor' => [
                'name'        => 'Contributor',
                'description' => 'Uploads and edits videos, but cannot publish them.',
                'capabilities' => [
                    self::MANAGE_VIDEOS,
                    self::MANAGE_FILES,
                ],
            ],
            /*
             * Viewer holds NOTHING, and that is the whole design rather than an
             * oversight.
             *
             * Watching is not a capability here — it follows from the account
             * being approved and the content being visible. Viewer exists so
             * that an approved person has a role which grants no admin power at
             * all, which is exactly what an empty list says.
             */
            'viewer' => [
                'name'         => 'Viewer',
                'description'  => 'Watches published videos. The default for an approved account.',
                'capabilities' => [],
            ],
        ];
    }

    public const ROLE_ADMIN  = 'admin';
    public const ROLE_VIEWER = 'viewer';
}
