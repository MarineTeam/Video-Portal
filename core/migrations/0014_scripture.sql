-- Scripture references, so a library can be browsed by passage.
--
-- One row per reference per video, not a text column on {videos}. The whole
-- feature is "show me everything preached on Romans 8", which is a lookup by
-- book and chapter — and a comma-separated column would answer it with a LIKE
-- that matches Romans 8 inside Romans 80.

CREATE TABLE IF NOT EXISTS {scripture_refs} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id    INT UNSIGNED NOT NULL,

  -- The book's slug, resolved from however it was written. Storing the
  -- resolution rather than the text is the point: "Jn", "1 Cor" and
  -- "First Corinthians" are already one thing by the time they get here.
  book        VARCHAR(20)  NOT NULL,

  chapter     SMALLINT UNSIGNED NOT NULL,

  -- Null means the whole chapter, which is a real and common reference —
  -- "Psalm 23" — and is different from verse 0.
  verse       SMALLINT UNSIGNED NULL,

  -- The end of a range. end_chapter always has a value, equal to chapter for a
  -- single-chapter reference, so a query for "anything touching chapter N" is
  -- a BETWEEN rather than a pile of ORs over nullable columns.
  end_chapter SMALLINT UNSIGNED NOT NULL,
  end_verse   SMALLINT UNSIGNED NULL,

  -- What was actually written, kept for display next to the video and for
  -- working out why something was indexed the way it was.
  raw         VARCHAR(100) NOT NULL DEFAULT '',

  --   parsed  found in the description by the parser
  --   manual  typed into the scripture field by an editor
  --
  -- Which matters on re-scan: a manual reference is somebody's decision and
  -- must survive a description edit that no longer mentions it.
  source      ENUM('parsed','manual') NOT NULL DEFAULT 'parsed',

  created_at  DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- The browse query: a book, then a chapter range that overlaps.
  KEY idx_passage (book, chapter, end_chapter),
  KEY idx_video (video_id),

  -- The same passage recorded twice for one video is one passage. Enforced
  -- here rather than by reading first, because re-scanning a description is
  -- the ordinary path and a read-then-write would double every reference on a
  -- site where two requests save at once.
  --
  -- verse is nullable and MySQL treats NULLs in a unique key as distinct, so a
  -- whole-chapter reference would escape this. COALESCE into generated columns
  -- gives them something concrete to compare, the same trick {subscriptions}
  -- uses for its nullable scope.
  verse_key     SMALLINT UNSIGNED AS (COALESCE(verse, 0)) STORED,
  end_verse_key SMALLINT UNSIGNED AS (COALESCE(end_verse, 0)) STORED,
  UNIQUE KEY uniq_reference (video_id, book, chapter, verse_key, end_chapter, end_verse_key),

  CONSTRAINT fk_scripture_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Whether a video's description has been read for references yet.
--
-- A column rather than a ledger table, because the question is about the video
-- and has exactly one answer. NULL means "never scanned", which is what every
-- existing video is on the upgrade that applies this — so the backfill job has
-- something to work through and stops when it runs out, rather than rescanning
-- a library full of sermons that genuinely mention no passage.
ALTER TABLE {videos}
  ADD COLUMN scripture_scanned_at DATETIME NULL DEFAULT NULL;

-- The backfill.
--
-- A job rather than a one-off statement in this file, because parsing happens
-- in PHP and there is no SQL that could do it. Batched, because a library of a
-- thousand sermons must not turn one page view into a request the host kills.
INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('scripture.scan', 300, NOW(), 1);
