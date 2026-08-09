-- The homepage, as rows somebody arranged.
--
-- Until now the front page was one list of everything, newest first, with a
-- continue-watching strip above it. That is the right default and a poor
-- ceiling: a media library's front page is editorial, and the thing an owner
-- most wants to change is what leads it.
--
-- Rows reference existing content rather than holding their own. A row that
-- points at a playlist shows whatever is on that playlist today, so curating
-- the playlist curates the homepage — one place to edit rather than two that
-- drift.

CREATE TABLE IF NOT EXISTS {home_rows} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Shown as the row heading. Blank falls back to a sensible name derived from
  -- the source, so an editor who adds a playlist row and saves gets the
  -- playlist's own title rather than an untitled row.
  title       VARCHAR(190) NOT NULL DEFAULT '',

  --   latest    newest published videos
  --   featured  videos flagged featured
  --   category  everything in one category, including its children
  --   series    one series, in running order
  --   playlist  one playlist, in its arranged order
  --   continue  this viewer's part-watched videos; empty for a stranger
  source_type ENUM('latest','featured','category','series','playlist','continue')
              NOT NULL DEFAULT 'latest',

  -- Which category, series, or playlist. NULL for the sources that need none.
  --
  -- Deliberately NOT a foreign key: it points at three different tables
  -- depending on source_type, so there is no single table to constrain against.
  -- The repository resolves it and drops a row whose target has been deleted,
  -- which is also what makes deleting a playlist safe.
  source_id   INT UNSIGNED NULL,

  -- How many to show. A row is a row, not a page: the link at its end goes to
  -- the full listing.
  max_items   TINYINT UNSIGNED NOT NULL DEFAULT 12,

  position    INT          NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,

  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_order (is_active, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
