-- What a device cannot find out any other way.
--
-- A deleted row leaves no trace. So a phone that fetched the calendar last week
-- and asks "what has changed since then" is told about everything that was
-- added or edited and NOTHING about what was cancelled — and a cancelled date
-- sitting on somebody's phone is how they turn up when they should not.
--
-- Hence a tombstone. It is the only shape that answers "what is no longer
-- there" without sending the entire calendar every time.
--
-- IT IS KEPT NINETY DAYS AND NO LONGER. A register of every date ever cancelled
-- would grow for ever, and a device that has been off for a season is going to
-- want the whole window again in any case. Past that the sync answers `full`
-- rather than pretending — "we did not look" and "nothing was missing" are
-- different answers and only one of them is safe for a device to act on, which
-- is the same distinction the video sync had to learn the expensive way.
--
-- NOT a foreign key to {schedule_entries}. The row it describes is gone by
-- definition; a constraint pointing at it could never be satisfied.

CREATE TABLE IF NOT EXISTS {schedule_entry_tombstones} (
  entry_id      INT UNSIGNED NOT NULL,

  -- Carried rather than looked up, for the same reason the notification record
  -- copies a video's title: this row has to keep meaning something after the
  -- schedule it belonged to has itself been deleted.
  schedule_id   INT UNSIGNED NOT NULL,
  on_date       DATE         NOT NULL,

  deleted_at    DATETIME     NOT NULL,

  PRIMARY KEY (entry_id),
  KEY idx_when (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('schedules.prune', 86400, NOW(), 1);
