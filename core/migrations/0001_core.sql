-- Video Portal — Phase 1 core schema.
--
-- Conventions
--   * `{table}` is expanded to the configured prefix by Portal\Db.
--   * utf8mb4 / utf8mb4_unicode_ci everywhere, so emoji in titles survive.
--   * Timestamps are DATETIME in UTC, never TIMESTAMP: TIMESTAMP is bounded by
--     2038 and silently shifts with the session time zone, and share expiry
--     maths must not depend on server configuration.
--   * Emails are stored normalized (trimmed, lower-cased) by the application.
--     VARCHAR(254) is the RFC maximum.
--
-- Several UNIQUE constraints below exist specifically to replace Redis
-- atomic operations from the apps this replaces. They are marked "ATOMICITY".

-- ---------------------------------------------------------------- migrations

CREATE TABLE IF NOT EXISTS {schema_version} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version       VARCHAR(64)  NOT NULL,
  applied_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ settings

CREATE TABLE IF NOT EXISTS {settings} (
  `key`         VARCHAR(191) NOT NULL,
  `value`       LONGTEXT     NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service providers (auth / video / mail).
-- `credentials` is an AES-256-GCM blob, not plaintext JSON: the realistic leak
-- on shared hosting is a database dump, which hands over tables but not the
-- filesystem holding app_key.
CREATE TABLE IF NOT EXISTS {providers} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind          ENUM('auth','video','mail') NOT NULL,
  slug          VARCHAR(64)  NOT NULL,
  credentials   LONGTEXT     NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 0,
  last_tested_at DATETIME    NULL,
  last_test_ok  TINYINT(1)   NULL,
  last_test_message VARCHAR(500) NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_kind_slug (kind, slug),
  KEY idx_active (kind, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- identity & access

CREATE TABLE IF NOT EXISTS {roles} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  -- System roles cannot be renamed or deleted from the UI; deleting `admin`
  -- would be an unrecoverable lockout.
  is_system     TINYINT(1)   NOT NULL DEFAULT 0,
  position      INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {capabilities} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  description   VARCHAR(255) NULL,
  -- Capabilities registered by a plugin are removed when it uninstalls.
  owner_plugin  VARCHAR(64)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {role_capabilities} (
  role_id       INT UNSIGNED NOT NULL,
  capability_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, capability_id),
  KEY idx_capability (capability_id),
  CONSTRAINT fk_rolecap_role FOREIGN KEY (role_id)
    REFERENCES {roles} (id) ON DELETE CASCADE,
  CONSTRAINT fk_rolecap_capability FOREIGN KEY (capability_id)
    REFERENCES {capabilities} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {users} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(254) NOT NULL,
  name          VARCHAR(190) NULL,
  role_id       INT UNSIGNED NULL,

  -- Identity from the active auth provider. `auth_provider` is recorded so a
  -- later provider switch can tell "this account predates the switch" from
  -- "this is a fresh account", rather than silently matching on email alone.
  auth_provider VARCHAR(64)  NULL,
  auth_subject  VARCHAR(255) NULL,
  email_verified TINYINT(1)  NOT NULL DEFAULT 0,

  -- Only used by the local auth provider; NULL for Auth0/OIDC accounts.
  password_hash VARCHAR(255) NULL,

  -- Authorization is separate from authentication. Signing in proves who you
  -- are; `authorized` is an admin decision about whether you may watch
  -- anything. Defaults to 0 — approval fails closed.
  authorized    TINYINT(1)   NOT NULL DEFAULT 0,
  authorized_at DATETIME     NULL,
  authorized_by VARCHAR(254) NULL,

  last_seen_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email),
  KEY idx_authorized (authorized),
  KEY idx_role (role_id),
  KEY idx_subject (auth_provider, auth_subject),
  CONSTRAINT fk_user_role FOREIGN KEY (role_id)
    REFERENCES {roles} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-text labels on viewers ("elders", "staff", "2026 cohort"). Used to pull
-- a group of addresses into a share dialog. Grants no access by itself.
CREATE TABLE IF NOT EXISTS {user_tags} (
  user_id       INT UNSIGNED NOT NULL,
  tag           VARCHAR(30)  NOT NULL,
  PRIMARY KEY (user_id, tag),
  KEY idx_tag (tag),
  CONSTRAINT fk_usertag_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named capability bundles, assignable to many users at once.
CREATE TABLE IF NOT EXISTS {permission_groups} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {group_capabilities} (
  group_id      INT UNSIGNED NOT NULL,
  capability_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, capability_id),
  KEY idx_capability (capability_id),
  CONSTRAINT fk_groupcap_group FOREIGN KEY (group_id)
    REFERENCES {permission_groups} (id) ON DELETE CASCADE,
  CONSTRAINT fk_groupcap_capability FOREIGN KEY (capability_id)
    REFERENCES {capabilities} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {group_members} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id      INT UNSIGNED NOT NULL,
  -- Membership is keyed by email, always: permissions can then be set up for
  -- someone who has never signed in, and survive their account being deleted
  -- and recreated. user_id is a convenience link, filled in on first login.
  email         VARCHAR(254) NOT NULL,
  user_id       INT UNSIGNED NULL,
  added_at      DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- Email is NOT NULL precisely so this UNIQUE actually deduplicates; MySQL
  -- treats NULLs as distinct and would happily store the same member twice.
  UNIQUE KEY uniq_group_email (group_id, email),
  KEY idx_user (user_id),
  CONSTRAINT fk_groupmember_group FOREIGN KEY (group_id)
    REFERENCES {permission_groups} (id) ON DELETE CASCADE,
  CONSTRAINT fk_groupmember_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scoped capability grants: "this person may manage videos, but only inside
-- the Sermons category". scope_type='site' with scope_id=0 means unscoped.
CREATE TABLE IF NOT EXISTS {grants} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subject_type  ENUM('user','group','role','email') NOT NULL,
  -- Both NOT NULL with a neutral default, so uniq_grant below genuinely
  -- prevents duplicates. A nullable column in a UNIQUE key does not: MySQL
  -- considers every NULL distinct, and the same grant could be inserted
  -- unboundedly many times.
  subject_id    INT UNSIGNED NOT NULL DEFAULT 0,
  email         VARCHAR(254) NOT NULL DEFAULT '',
  capability_id INT UNSIGNED NOT NULL,
  scope_type    ENUM('site','category','series','video') NOT NULL DEFAULT 'site',
  scope_id      INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  created_by    VARCHAR(254) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_grant (subject_type, subject_id, email, capability_id, scope_type, scope_id),
  KEY idx_lookup (capability_id, scope_type, scope_id),
  KEY idx_subject (subject_type, subject_id),
  KEY idx_email (email),
  CONSTRAINT fk_grant_capability FOREIGN KEY (capability_id)
    REFERENCES {capabilities} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ taxonomy

-- Self-referencing for arbitrary nesting. `path` caches the materialized
-- ancestor chain ("/1/7/22/") so the permission and plugin-override resolvers
-- can walk ancestors in one indexed query instead of N round trips.
CREATE TABLE IF NOT EXISTS {categories} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id     INT UNSIGNED NULL,
  slug          VARCHAR(191) NOT NULL,
  name          VARCHAR(190) NOT NULL,
  description   TEXT         NULL,
  image_url     VARCHAR(500) NULL,
  path          VARCHAR(500) NOT NULL DEFAULT '/',
  depth         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  position      INT          NOT NULL DEFAULT 0,

  -- Set when this category was created by importing a bunny.net collection.
  -- Kept so a re-import updates rather than duplicates. Local edits always
  -- win afterwards: the import never overwrites a name an admin has changed.
  provider_collection_id VARCHAR(191) NULL,

  is_published  TINYINT(1)   NOT NULL DEFAULT 1,
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,
  hidden        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- Globally unique rather than unique-per-parent. Per-parent uniqueness reads
  -- more naturally, but parent_id is NULL for top-level categories and MySQL
  -- treats NULLs as distinct — so it would not actually prevent two root
  -- categories sharing a slug. A flat unique slug also keeps /category/{slug}
  -- routing unambiguous without resolving the whole ancestor path first.
  UNIQUE KEY uniq_slug (slug),
  KEY idx_parent_position (parent_id, position),
  KEY idx_path (path(191)),
  KEY idx_provider_collection (provider_collection_id),
  CONSTRAINT fk_category_parent FOREIGN KEY (parent_id)
    REFERENCES {categories} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {series} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id   INT UNSIGNED NULL,
  slug          VARCHAR(191) NOT NULL,
  title         VARCHAR(190) NOT NULL,
  description   TEXT         NULL,
  image_url     VARCHAR(500) NULL,
  position      INT          NOT NULL DEFAULT 0,
  is_published  TINYINT(1)   NOT NULL DEFAULT 1,
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,
  hidden        TINYINT(1)   NOT NULL DEFAULT 0,
  featured      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_category (category_id, position),
  CONSTRAINT fk_series_category FOREIGN KEY (category_id)
    REFERENCES {categories} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {speakers} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  name          VARCHAR(190) NOT NULL,
  bio           TEXT         NULL,
  image_url     VARCHAR(500) NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {tags} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {taggables} (
  tag_id        INT UNSIGNED NOT NULL,
  taggable_type ENUM('video','series','category') NOT NULL,
  taggable_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (tag_id, taggable_type, taggable_id),
  KEY idx_target (taggable_type, taggable_id),
  CONSTRAINT fk_taggable_tag FOREIGN KEY (tag_id)
    REFERENCES {tags} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 301 redirects after a rename, so links printed in a bulletin keep working.
CREATE TABLE IF NOT EXISTS {slug_aliases} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type   ENUM('video','series','category','speaker') NOT NULL,
  target_id     INT UNSIGNED NOT NULL,
  slug          VARCHAR(191) NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_type_slug (target_type, slug),
  KEY idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------- videos

CREATE TABLE IF NOT EXISTS {videos} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Identity at the video provider. bunny.net remains the source of truth for
  -- the media itself; this table is the source of truth for everything about
  -- how the media is organized and presented.
  provider      VARCHAR(64)  NOT NULL DEFAULT 'bunny',
  provider_id   VARCHAR(191) NOT NULL,
  provider_collection_id VARCHAR(191) NULL,

  slug          VARCHAR(191) NOT NULL,
  title         VARCHAR(200) NOT NULL,
  description   TEXT         NULL,

  duration      INT UNSIGNED NULL,
  -- Provider's own thumbnail filename; the signed CDN URL is built at render
  -- time and never stored, because it expires.
  thumbnail_file VARCHAR(255) NULL,
  width         INT UNSIGNED NULL,
  height        INT UNSIGNED NULL,

  status        ENUM('processing','ready','failed') NOT NULL DEFAULT 'processing',
  encode_progress TINYINT UNSIGNED NOT NULL DEFAULT 0,

  speaker_id    INT UNSIGNED NULL,
  series_id     INT UNSIGNED NULL,
  series_position INT        NOT NULL DEFAULT 0,

  position      INT          NOT NULL DEFAULT 0,
  featured      TINYINT(1)   NOT NULL DEFAULT 0,
  pinned        TINYINT(1)   NOT NULL DEFAULT 0,

  is_published  TINYINT(1)   NOT NULL DEFAULT 1,
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,
  hidden        TINYINT(1)   NOT NULL DEFAULT 0,

  -- Per-video watermark override. 'default' defers to the global setting.
  -- Resolution order is exemption > share > video > global.
  watermark_mode ENUM('default','on','off') NOT NULL DEFAULT 'default',

  published_at  DATETIME     NULL,
  recorded_at   DATETIME     NULL,
  provider_created_at DATETIME NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  -- Soft delete. Phase 4 exposes a Trash UI; the column exists now so nothing
  -- has to be back-filled later.
  deleted_at    DATETIME     NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_provider_video (provider, provider_id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_status (status),
  KEY idx_series (series_id, series_position),
  KEY idx_speaker (speaker_id),
  KEY idx_listing (deleted_at, is_published, position),
  KEY idx_published_at (published_at),
  CONSTRAINT fk_video_series FOREIGN KEY (series_id)
    REFERENCES {series} (id) ON DELETE SET NULL,
  CONSTRAINT fk_video_speaker FOREIGN KEY (speaker_id)
    REFERENCES {speakers} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A video can appear in several categories. The presence of ANY row here is
-- what makes local taxonomy override the imported bunny.net collection.
CREATE TABLE IF NOT EXISTS {video_categories} (
  video_id      INT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED NOT NULL,
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0,
  position      INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (video_id, category_id),
  KEY idx_category (category_id, position),
  CONSTRAINT fk_videocat_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE,
  CONSTRAINT fk_videocat_category FOREIGN KEY (category_id)
    REFERENCES {categories} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resume / continue-watching.
CREATE TABLE IF NOT EXISTS {watch_progress} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  video_id      INT UNSIGNED NOT NULL,
  position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at  DATETIME     NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- ATOMICITY: one row per person per video. The client posts progress every
  -- 10s from several tabs; without this an upsert race duplicates rows and
  -- "continue watching" shows the same video twice.
  UNIQUE KEY uniq_user_video (user_id, video_id),
  KEY idx_recent (user_id, updated_at),
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE,
  CONSTRAINT fk_progress_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- sharing

CREATE TABLE IF NOT EXISTS {shares} (
  -- Opaque, unguessable, and the public URL component. Not auto-increment:
  -- sequential ids would let anyone enumerate every share ever created.
  id            VARCHAR(64)  NOT NULL,
  video_id      INT UNSIGNED NOT NULL,

  -- Denormalized so the shares table renders without touching videos, and so
  -- a share's own audit trail survives the video being deleted.
  video_title   VARCHAR(200) NOT NULL,

  recipient_email VARCHAR(254) NOT NULL,

  -- 'account': recipient signs in with the configured auth provider and the
  --            session email must equal recipient_email.
  -- 'gate':    recipient proves control of the address via an emailed HMAC
  --            magic link; no account is ever created.
  access_mode   ENUM('account','gate') NOT NULL DEFAULT 'account',

  created_at    DATETIME     NOT NULL,
  -- Expiry is a comparison against this column. Rows are NEVER deleted at
  -- expiry: Extend and Restore have to work on a lapsed link, which is
  -- impossible if the row is gone. Cleanup is a separate, explicit action.
  expires_at    DATETIME     NOT NULL,

  -- Revocation is a soft, idempotent flag. Permanent deletion is a distinct
  -- and irreversible action, so an accidental revoke is always recoverable.
  revoked_at    DATETIME     NULL,
  -- Remembered on revoke so Restore puts back the original expiry rather than
  -- silently inventing a new one.
  previous_expires_at DATETIME NULL,

  watermark_mode ENUM('default','on','off') NOT NULL DEFAULT 'default',

  bundle_id     VARCHAR(64)  NULL,
  -- Marks a share created by a video's private invite list, so the list can
  -- manage exactly its own links and leave ad-hoc shares to the same person
  -- untouched.
  via_private_list TINYINT(1) NOT NULL DEFAULT 0,

  emailed_at    DATETIME     NULL,
  -- Kept verbatim. A failed send must never lose the link — the admin sees the
  -- provider's own error, fixes the config, and re-sends.
  email_error   VARCHAR(500) NULL,

  view_count    INT UNSIGNED NOT NULL DEFAULT 0,
  first_viewed_at DATETIME   NULL,
  last_viewed_at  DATETIME   NULL,
  play_count    INT UNSIGNED NOT NULL DEFAULT 0,
  furthest_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  completed_at  DATETIME     NULL,

  created_by    VARCHAR(254) NULL,

  PRIMARY KEY (id),
  KEY idx_recipient (recipient_email, expires_at),
  KEY idx_video (video_id),
  KEY idx_bundle (bundle_id),
  KEY idx_expiry (expires_at, revoked_at),
  KEY idx_created (created_at),
  CONSTRAINT fk_share_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {bundles} (
  id            VARCHAR(64)  NOT NULL,
  recipient_email VARCHAR(254) NOT NULL,
  access_mode   ENUM('account','gate') NOT NULL DEFAULT 'account',
  created_at    DATETIME     NOT NULL,
  -- Only ever extended, never shortened: a recipient's bundle link must not
  -- start failing because one of their newer shares was short-lived.
  expires_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- ATOMICITY: exactly one bundle per recipient. Replaces the Redis
  -- bundle-by-email pointer key; makes the lookup a unique-index hit rather
  -- than a scan, and makes a concurrent double-create impossible.
  UNIQUE KEY uniq_recipient (recipient_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ids only. Titles and live/dead status are re-read from {shares} on every
-- render, which is why revoking or extending a share is reflected on the
-- bundle page instantly with no write to the bundle at all.
CREATE TABLE IF NOT EXISTS {bundle_items} (
  bundle_id     VARCHAR(64)  NOT NULL,
  share_id      VARCHAR(64)  NOT NULL,
  added_at      DATETIME     NOT NULL,
  PRIMARY KEY (bundle_id, share_id),
  KEY idx_share (share_id),
  CONSTRAINT fk_bundleitem_bundle FOREIGN KEY (bundle_id)
    REFERENCES {bundles} (id) ON DELETE CASCADE,
  CONSTRAINT fk_bundleitem_share FOREIGN KEY (share_id)
    REFERENCES {shares} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A video's persistent invite list. Deliberately a separate index rather than
-- "query shares where video=X and not expired": the list must be able to add,
-- remove, and re-add a person without disturbing an ordinary ad-hoc share to
-- that same person for that same video.
CREATE TABLE IF NOT EXISTS {private_list_entries} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id      INT UNSIGNED NOT NULL,
  email         VARCHAR(254) NOT NULL,
  share_id      VARCHAR(64)  NULL,
  added_at      DATETIME     NOT NULL,
  added_by      VARCHAR(254) NULL,
  PRIMARY KEY (id),
  -- ATOMICITY: adding someone twice is a no-op, not a second link.
  UNIQUE KEY uniq_video_email (video_id, email),
  KEY idx_share (share_id),
  CONSTRAINT fk_privatelist_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE,
  CONSTRAINT fk_privatelist_share FOREIGN KEY (share_id)
    REFERENCES {shares} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named address lists for the share dialogs. Grants no access on its own;
-- editing or deleting a group never touches links already created from it.
CREATE TABLE IF NOT EXISTS {viewer_groups} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {viewer_group_members} (
  group_id      INT UNSIGNED NOT NULL,
  email         VARCHAR(254) NOT NULL,
  added_at      DATETIME     NOT NULL,
  PRIMARY KEY (group_id, email),
  CONSTRAINT fk_viewergroupmember_group FOREIGN KEY (group_id)
    REFERENCES {viewer_groups} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Issued magic-link grants for access_mode='gate'. A row is created when the
-- link is emailed and consumed when it is clicked; the resulting cookie is
-- signed with GATE_SECRET and scoped to that one share's path.
CREATE TABLE IF NOT EXISTS {gate_grants} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type   ENUM('share','bundle') NOT NULL,
  target_id     VARCHAR(64)  NOT NULL,
  email         VARCHAR(254) NOT NULL,
  -- Only the hash is stored. A database dump must not yield working links.
  token_hash    CHAR(64)     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  consumed_at   DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  request_ip    VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token (token_hash),
  KEY idx_target (target_type, target_id),
  KEY idx_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- plugins and themes

CREATE TABLE IF NOT EXISTS {plugins} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  version       VARCHAR(32)  NOT NULL DEFAULT '0.0.0',
  is_active     TINYINT(1)   NOT NULL DEFAULT 0,
  -- Bundled plugins ship with the app and cannot be deleted from disk by the
  -- UI, only deactivated.
  is_bundled    TINYINT(1)   NOT NULL DEFAULT 0,
  settings      LONGTEXT     NULL,
  activated_at  DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-category on/off for a plugin. Resolution walks the category's ancestor
-- chain and the nearest explicit row wins; with no row anywhere, the plugin's
-- global is_active applies.
CREATE TABLE IF NOT EXISTS {plugin_category_overrides} (
  plugin_id     INT UNSIGNED NOT NULL,
  category_id   INT UNSIGNED NOT NULL,
  enabled       TINYINT(1)   NOT NULL,
  PRIMARY KEY (plugin_id, category_id),
  KEY idx_category (category_id),
  CONSTRAINT fk_pluginoverride_plugin FOREIGN KEY (plugin_id)
    REFERENCES {plugins} (id) ON DELETE CASCADE,
  CONSTRAINT fk_pluginoverride_category FOREIGN KEY (category_id)
    REFERENCES {categories} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {plugin_migrations} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  plugin_slug   VARCHAR(64)  NOT NULL,
  version       VARCHAR(64)  NOT NULL,
  applied_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_plugin_version (plugin_slug, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {themes} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  version       VARCHAR(32)  NOT NULL DEFAULT '0.0.0',
  parent_slug   VARCHAR(64)  NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 0,
  is_bundled    TINYINT(1)   NOT NULL DEFAULT 0,
  installed_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customizer values, kept per theme so switching away and back restores the
-- settings someone spent an afternoon on.
CREATE TABLE IF NOT EXISTS {theme_settings} (
  theme_slug    VARCHAR(64)  NOT NULL,
  `key`         VARCHAR(191) NOT NULL,
  `value`       LONGTEXT     NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (theme_slug, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- ops and telemetry

CREATE TABLE IF NOT EXISTS {audit_log} (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_email   VARCHAR(254) NULL,
  action        VARCHAR(64)  NOT NULL,
  target_type   VARCHAR(32)  NULL,
  target_id     VARCHAR(64)  NULL,
  detail        VARCHAR(500) NULL,
  ip            VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created (created_at),
  KEY idx_actor (actor_email),
  KEY idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window counters. Coarser than a sliding window, but it needs no
-- background sweeper, which shared hosting cannot reliably provide.
CREATE TABLE IF NOT EXISTS {rate_limits} (
  bucket        VARCHAR(191) NOT NULL,
  window_start  DATETIME     NOT NULL,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {cron_jobs} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(64)  NOT NULL,
  interval_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
  next_run_at   DATETIME     NOT NULL,
  last_run_at   DATETIME     NULL,
  last_status   VARCHAR(20)  NULL,
  last_message  VARCHAR(500) NULL,
  -- Held for the duration of a run and compared against a timeout, so a job
  -- that dies mid-flight on a killed shared-hosting request doesn't wedge the
  -- schedule forever.
  locked_at     DATETIME     NULL,
  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_due (is_enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ATOMICITY: fire-once guard for "a new video was published" notifications.
-- Replaces the Redis atomic SADD; INSERT IGNORE returning 0 affected rows is
-- the signal that someone else already sent it.
CREATE TABLE IF NOT EXISTS {announced_videos} (
  video_id      INT UNSIGNED NOT NULL,
  announced_at  DATETIME     NOT NULL,
  PRIMARY KEY (video_id),
  CONSTRAINT fk_announced_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server-side sessions. Stored in the database rather than PHP's file handler
-- because shared hosts routinely put session files in a world-readable /tmp
-- shared with every other account on the machine.
CREATE TABLE IF NOT EXISTS {sessions} (
  id            VARCHAR(128) NOT NULL,
  user_id       INT UNSIGNED NULL,
  payload       LONGTEXT     NOT NULL,
  ip            VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL,
  last_active_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id),
  KEY idx_last_active (last_active_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
