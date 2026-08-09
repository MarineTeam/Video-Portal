-- What actually got watched.
--
-- A daily total per video, not a row per view.
--
-- Two reasons, and both matter. A row per view grows without limit on a site
-- that succeeds, and then needs a purge job that has to run on hosts where cron
-- is optional — the same trap scheduled publishing avoided. And a row per view
-- is a record of who watched what, which is a liability nobody asked this
-- product to hold: a library owner wants to know what is worth making more of,
-- not what one person watched on a Tuesday.
--
-- The cost is stated rather than hidden: there is no per-viewer history here
-- and no way to add one later without a different table. Per-recipient
-- tracking on SHARE links is a separate thing that already exists, because
-- there the question genuinely is "did this person open it".

CREATE TABLE IF NOT EXISTS {video_views} (
  video_id    INT UNSIGNED NOT NULL,

  -- DATE, in the site's timezone as PHP resolves it. Grouping by day is what
  -- every question asked of this table needs, and storing a timestamp would
  -- mean every read did the grouping instead.
  day         DATE         NOT NULL,

  views       INT UNSIGNED NOT NULL DEFAULT 0,

  -- Watched to the end, by the same 95% rule the resume logic uses. Kept
  -- beside views rather than derived, because "started" and "finished" are
  -- different questions and the ratio between them is the interesting one.
  completions INT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (video_id, day),
  -- "The last thirty days across everything" is the other query.
  KEY idx_day (day),
  CONSTRAINT fk_view_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
