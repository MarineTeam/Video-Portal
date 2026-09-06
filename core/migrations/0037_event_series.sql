-- Recurring events.
--
-- A SERIES IS NOT ITSELF AN EVENT.
--
-- That is the rule this table exists to obey, and it is the reason there is a
-- separate table at all. The tempting shape is to make the first meeting carry
-- the repeat rule and generate the rest from it — and then deleting the first
-- meeting is an act that deletes the year. Somebody cancelling one week in
-- January loses every Tuesday until December, and there is no undo, because the
-- rule went with the row.
--
-- So the series is a template and nothing else. Every date is an ORDINARY event
-- row with its own slug, its own capacity and its own sign-up list, and every
-- one of them can be edited, cancelled or filled independently. Deleting the
-- series leaves the meetings standing — `series_id` is ON DELETE SET NULL — and
-- what that means in practice is that the strongest thing an organiser can do
-- to a series is stop it generating more.
--
-- THE EXCLUSION LIST IS WHY A CANCELLATION STICKS
--
-- Delete one generated event and the next generator run would put it straight
-- back, because the rule still names that date. So a deletion writes the date
-- to {event_series_exclusions} IN THE SAME TRANSACTION as the delete. Either
-- both happen or neither does — a delete that committed without its exclusion
-- would restore the cancelled meeting within the day, which is the failure
-- people would report as "the site un-cancelled my event".
--
-- WHY series_date IS A COLUMN
--
-- It is the date the rule produced, kept alongside the event's own starts_at so
-- the two can differ. Somebody may move one meeting to the Wednesday; the row
-- then starts on the Wednesday and still answers "this is the Tuesday the rule
-- generated", so the next run does not decide the Tuesday is missing and make a
-- second one. Without it, moving a meeting duplicates it.

CREATE TABLE IF NOT EXISTS {event_series} (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title           VARCHAR(190) NOT NULL,
  description     TEXT         NULL,
  location        VARCHAR(190) NULL,

  -- The rule, as it would be written in a calendar file. Validated by
  -- Portal\Events\Recurrence before it ever reaches here.
  rrule           VARCHAR(255) NOT NULL,

  -- The first meeting: a wall clock and a zone, like every other time here.
  starts_at       DATETIME     NOT NULL,
  duration_minutes INT UNSIGNED NULL,
  timezone        VARCHAR(64)  NULL,

  -- The template each generated event is stamped from. Changing one of these
  -- affects meetings generated AFTER the change and leaves the rest alone,
  -- which is the only behaviour that does not silently rewrite a hall booking
  -- somebody already advertised.
  is_published    TINYINT(1)   NOT NULL DEFAULT 0,
  member_only     TINYINT(1)   NOT NULL DEFAULT 0,
  signup_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
  capacity        INT UNSIGNED NULL,
  max_guests      INT UNSIGNED NOT NULL DEFAULT 0,

  -- How far ahead this has been materialised, so a run knows what it has
  -- already done without re-expanding the whole rule.
  generated_to    DATE         NULL,

  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_generate (generated_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {event_series_exclusions} (
  series_id     INT UNSIGNED NOT NULL,
  excluded_on   DATE         NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (series_id, excluded_on),
  CONSTRAINT fk_exclusion_series FOREIGN KEY (series_id)
    REFERENCES {event_series} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which series produced an event, and for which date.
--
-- Re-runnable per 0021: deployment is `git pull` and the migration runs on
-- whichever request arrives next.
SET @needs_series := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{events}', '`', '')
     AND COLUMN_NAME = 'series_id'
);

SET @ddl := IF(
  @needs_series,
  CONCAT('ALTER TABLE ', '{events}', ' ADD COLUMN series_id INT UNSIGNED NULL AFTER id'),
  'DO 0'
);

PREPARE add_series_id FROM @ddl;
EXECUTE add_series_id;
DEALLOCATE PREPARE add_series_id;

SET @needs_series_date := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{events}', '`', '')
     AND COLUMN_NAME = 'series_date'
);

SET @ddl := IF(
  @needs_series_date,
  CONCAT('ALTER TABLE ', '{events}', ' ADD COLUMN series_date DATE NULL AFTER series_id'),
  'DO 0'
);

PREPARE add_series_date FROM @ddl;
EXECUTE add_series_date;
DEALLOCATE PREPARE add_series_date;

-- One event per series per generated date.
--
-- NULLs are distinct to MySQL, so every ordinary event — both columns NULL —
-- sits outside this key rather than colliding with the others. That is the
-- behaviour wanted here and it is worth stating, because this codebase has been
-- caught by the same fact working against it three times.
SET @needs_unique := (
  SELECT COUNT(*) = 0
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{events}', '`', '')
     AND INDEX_NAME = 'uniq_series_date'
);

SET @ddl := IF(
  @needs_unique,
  CONCAT('ALTER TABLE ', '{events}', ' ADD UNIQUE KEY uniq_series_date (series_id, series_date)'),
  'DO 0'
);

PREPARE add_series_unique FROM @ddl;
EXECUTE add_series_unique;
DEALLOCATE PREPARE add_series_unique;

SET @needs_fk := (
  SELECT COUNT(*) = 0
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{events}', '`', '')
     AND CONSTRAINT_NAME = 'fk_event_series'
);

-- SET NULL, not CASCADE. Deleting a series must not delete the meetings: they
-- are ordinary events with their own sign-up lists, and the strongest thing
-- deleting a series can mean is that no more are generated.
SET @ddl := IF(
  @needs_fk,
  CONCAT('ALTER TABLE ', '{events}',
         ' ADD CONSTRAINT fk_event_series FOREIGN KEY (series_id)',
         ' REFERENCES ', '{event_series}', ' (id) ON DELETE SET NULL'),
  'DO 0'
);

PREPARE add_series_fk FROM @ddl;
EXECUTE add_series_fk;
DEALLOCATE PREPARE add_series_fk;
