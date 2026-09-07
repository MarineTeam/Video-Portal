-- Song metadata, and what a licence return is built from.
--
-- KEYED TO (book, number), NOT TO A CONTENTS ROW
--
-- This is the load-bearing decision. Re-indexing a book DELETES and rewrites
-- every {book_contents} row — that is what replaceContents does, and it has to,
-- because a re-scan produces a different set of entries. Metadata hung off a
-- contents id would go with them, and somebody would re-index a hymnal and
-- silently lose every CCLI number in it. The number is the durable identity of
-- a hymn, which is the same reason a share link goes by number.
--
-- WHAT A LICENCE RETURN MAY COUNT
--
-- {song_uses} records a song being USED — sung in a service. It is not the
-- lookup counter, and the two must never be added together: somebody opening a
-- hymn on their phone is not a performance, and a return that counts it is a
-- return that overstates. A licence return is a legal document with money
-- attached, so the report reads only this table and says so on its face.

CREATE TABLE IF NOT EXISTS {book_songs} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,

  -- The hymn number. See the note above: this, not a contents row id.
  number        INT UNSIGNED NOT NULL,

  -- What a licence return has to name. Author and copyright are what the
  -- publisher wants; the CCLI number is what the return is filed under, and a
  -- song without one CANNOT go on a return at all — which is why the report
  -- lists those separately rather than quietly leaving them out.
  author        VARCHAR(300) NULL,
  copyright     VARCHAR(300) NULL,
  ccli_number   VARCHAR(20)  NULL,

  -- For whoever is playing. `song_key` rather than `key`, which is reserved.
  song_key      VARCHAR(12)  NULL,
  tempo         VARCHAR(40)  NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_song (book_id, number),
  KEY idx_ccli (ccli_number),
  CONSTRAINT fk_song_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One song, sung once, on one date.
CREATE TABLE IF NOT EXISTS {song_uses} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,
  number        INT UNSIGNED NOT NULL,

  on_date       DATE         NOT NULL,

  -- Which service, where it came from one. Nullable because a song can be
  -- recorded as sung without a plan — a midweek meeting, something added on
  -- the morning — and refusing those would make the return understate.
  service_id    INT UNSIGNED NULL,

  -- 'plan' | 'present' | 'manual'. Kept because a return somebody has to sign
  -- is worth being able to explain: "the plan said so" and "somebody typed it
  -- in afterwards" are different kinds of evidence.
  source        VARCHAR(10)  NOT NULL DEFAULT 'manual',

  created_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),

  /*
   * Recording the same song twice for the same service is one use.
   *
   * MySQL treats NULLs as distinct in a UNIQUE key, so this constrains
   * plan-sourced rows (which carry a service) and deliberately does NOT
   * constrain manual ones — a song genuinely sung twice on one day, at two
   * services with no plans, is two uses and a return should say so.
   */
  UNIQUE KEY uniq_use (book_id, number, on_date, service_id),

  KEY idx_range (on_date, book_id, number),

  CONSTRAINT fk_use_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Let a service plan's hymn item point at an actual hymn.
--
-- The `reference` column stays exactly as it is — "245", "H&M 245", "insert"
-- are all things people write and refusing any of them would send somebody to
-- a different piece of paper. This is an OPTIONAL link somebody makes
-- deliberately, and only a linked item can be counted towards a licence
-- return: parsing that free text would be guessing, and a guess on a legal
-- document is worse than a gap.
--
-- Re-runnable, for the reason 0021 records at length: deployment is `git pull`
-- and the request running the migrations can be killed between the ALTER and
-- the {schema_version} write, after which a plain ADD COLUMN fails for ever on
-- a host with no shell.

SET @needs_book := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{service_plan_items}', '`', '')
     AND COLUMN_NAME = 'book_id'
);

SET @ddl := IF(
  @needs_book,
  CONCAT('ALTER TABLE ', '{service_plan_items}', ' ADD COLUMN book_id INT UNSIGNED NULL AFTER reference'),
  'DO 0'
);

PREPARE add_plan_book FROM @ddl;
EXECUTE add_plan_book;
DEALLOCATE PREPARE add_plan_book;

SET @needs_number := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{service_plan_items}', '`', '')
     AND COLUMN_NAME = 'song_number'
);

SET @ddl := IF(
  @needs_number,
  CONCAT('ALTER TABLE ', '{service_plan_items}', ' ADD COLUMN song_number INT UNSIGNED NULL AFTER book_id'),
  'DO 0'
);

PREPARE add_plan_number FROM @ddl;
EXECUTE add_plan_number;
DEALLOCATE PREPARE add_plan_number;
