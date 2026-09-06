-- A spreadsheet feeding a schedule.
--
-- ONE SHEET PER SCHEDULE, which is what the UNIQUE key says. The grid layout
-- already puts several jobs in one sheet, so two sheets feeding one rota buys
-- nothing and costs a screen that has to explain which one wins when they
-- disagree about the same day.
--
-- WHAT IS REMEMBERED BETWEEN RUNS, AND WHY
--
-- `etag` and `last_modified` are sent back as conditional headers, and
-- `content_hash` catches the case where the server offers neither. Between them
-- an unchanged sheet costs ONE request and no parsing and no writes — which is
-- the difference between a sync that can run every quarter of an hour on shared
-- hosting and one that cannot.
--
-- `last_status` and `last_message` exist because a sync that fails on somebody
-- else's server fails silently otherwise: nothing on the calendar changes, so
-- the only symptom is a rota that quietly stops being updated. The screen shows
-- what happened and when, in the words the other end used.

CREATE TABLE IF NOT EXISTS {schedule_sources} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id   INT UNSIGNED NOT NULL,

  -- The CSV export URL, derived from whatever the person pasted. Long, because
  -- a Google export URL with a sheet id on it comfortably passes 200.
  url           VARCHAR(1000) NOT NULL,

  -- 'rows' (one line per person per date) or 'grid' (a date per line, a job per
  -- column). See SheetLayout for why both are offered.
  layout        VARCHAR(8)   NOT NULL DEFAULT 'rows',

  -- 'auto', 'dmy' or 'mdy'. Under 'auto' a date that could be read two ways is
  -- REFUSED rather than guessed — see SheetDate. This column is how somebody
  -- resolves that, once, for a sheet that writes 5/9 and means September.
  date_order    VARCHAR(4)   NOT NULL DEFAULT 'auto',

  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,

  etag          VARCHAR(190) NULL,
  last_modified VARCHAR(64)  NULL,
  content_hash  CHAR(64)     NULL,

  last_run_at   DATETIME     NULL,
  last_status   VARCHAR(16)  NULL,
  last_message  VARCHAR(500) NULL,
  last_rows     INT UNSIGNED NOT NULL DEFAULT 0,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_schedule (schedule_id),
  CONSTRAINT fk_schedule_source FOREIGN KEY (schedule_id)
    REFERENCES {schedules} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pulls every enabled source. Seeded here rather than left to the installer,
-- because a site installed before this migration existed would never get a row
-- for it — and a job with no row is never due, so it would do nothing, silently
-- and for ever. That has already happened once in this project.
INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('schedules.sync', 900, NOW(), 1);
