-- Offline downloads: what may be taken, and the capability for who may take it.
--
-- Three values rather than a boolean, the same shape as watermark_mode and
-- thumbnail_mode, because inheritance needs a way to say "I have no opinion"
-- that is distinct from "definitely no":
--
--   default  defer upward — a video to its series, then to its category chain,
--            then to the site setting
--   allow    downloadable, whatever the level above says
--   block    not downloadable, whatever the level above says
--
-- An admin who has learned one inheritance rule in this application should not
-- have to learn a third, so this one is deliberately identical in shape. The
-- one addition is SERIES, which the other two do not have a column for: a
-- season of a course is exactly the unit somebody wants to make available
-- offline, and saying it once beats ticking forty videos.
--
-- Everything defaults to `default`, which resolves to the site setting, which
-- is OFF. A download is the one thing this application hands out that it can
-- never take back — a share link expires and an unpublished video stops
-- playing, but a file on somebody's phone is there for good. That is a
-- decision for whoever runs the site, not one an upgrade should make on their
-- behalf by turning something on.
--
-- The capability follows 0022's reasoning exactly: the seeder writes every
-- capability in Capability::all() at INSTALL, so a fresh site gets this for
-- free, but an existing site never re-runs the seeder. Without this row the
-- capability would exist in PHP, be checkable, always answer false, and never
-- appear on the permissions screen — the shape of the defect that left scoped
-- grants stored and enforced nowhere for five phases.
--
-- Granted to nobody here, for the same reason 0022 granted share_content to
-- nobody. Administrators already hold it, since the admin role short-circuits
-- every check rather than holding capabilities explicitly.
--
-- RE-RUNNABLE, per 0021. Deployment is `git pull` and pending migrations run on
-- whichever request arrives next, which the host may kill between the ALTER
-- succeeding and the {schema_version} row being written. `ADD COLUMN IF NOT
-- EXISTS` is MariaDB-only and MySQL 8 rejects it, hence information_schema and
-- a prepared statement, which both accept.
--
-- One guarded statement per table rather than one guard for all three: they are
-- separate ALTERs and a request killed between them must leave each able to
-- re-run on its own.

SET @needs_video_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{videos}', '`', '')
     AND COLUMN_NAME = 'download_mode'
);

SET @ddl := IF(
  @needs_video_column,
  CONCAT(
    'ALTER TABLE ', '{videos}',
    ' ADD COLUMN download_mode ENUM(''default'',''allow'',''block'')',
    ' NOT NULL DEFAULT ''default'' AFTER thumbnail_mode'
  ),
  'DO 0'
);

PREPARE add_video_download_mode FROM @ddl;
EXECUTE add_video_download_mode;
DEALLOCATE PREPARE add_video_download_mode;

SET @needs_series_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{series}', '`', '')
     AND COLUMN_NAME = 'download_mode'
);

SET @ddl := IF(
  @needs_series_column,
  CONCAT(
    'ALTER TABLE ', '{series}',
    ' ADD COLUMN download_mode ENUM(''default'',''allow'',''block'')',
    ' NOT NULL DEFAULT ''default'' AFTER hidden'
  ),
  'DO 0'
);

PREPARE add_series_download_mode FROM @ddl;
EXECUTE add_series_download_mode;
DEALLOCATE PREPARE add_series_download_mode;

SET @needs_category_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{categories}', '`', '')
     AND COLUMN_NAME = 'download_mode'
);

SET @ddl := IF(
  @needs_category_column,
  CONCAT(
    'ALTER TABLE ', '{categories}',
    ' ADD COLUMN download_mode ENUM(''default'',''allow'',''block'')',
    ' NOT NULL DEFAULT ''default'' AFTER thumbnail_mode'
  ),
  'DO 0'
);

PREPARE add_category_download_mode FROM @ddl;
EXECUTE add_category_download_mode;
DEALLOCATE PREPARE add_category_download_mode;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('download_content', 'Download a video they can watch, for offline viewing');
