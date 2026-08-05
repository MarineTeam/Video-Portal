-- Playlists, and the lists a viewer keeps for themselves.
--
-- A playlist can be renamed, so its old address has to keep working like every
-- other renameable thing here. The alias table's type column is an ENUM, so it
-- has to learn the new word before anything can write one.
ALTER TABLE {slug_aliases}
  MODIFY COLUMN target_type ENUM('video','series','category','speaker','playlist') NOT NULL;


-- Two different ideas that look alike and are deliberately not merged.
--
-- A playlist is CONTENT: someone made it, it has a name and a description and
-- a public address, and everybody sees the same one. A saved video is a
-- BOOKMARK: nobody made it, it has no name, and it is nobody's business but
-- the person who saved it. Modelling favourites as "a system playlist per
-- user" would put a row in the content table for every account that ever
-- clicked a heart, and every listing of playlists would then have to remember
-- to exclude them — which is the sort of thing one listing eventually forgets.

CREATE TABLE IF NOT EXISTS {playlists} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  title         VARCHAR(190) NOT NULL,
  description   TEXT         NULL,
  image_url     VARCHAR(500) NULL,

  position      INT          NOT NULL DEFAULT 0,

  -- The same three visibility switches every other piece of content has, so
  -- there is one set of rules to learn rather than a special case here.
  is_published  TINYINT(1)   NOT NULL DEFAULT 1,
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,
  hidden        TINYINT(1)   NOT NULL DEFAULT 0,
  featured      TINYINT(1)   NOT NULL DEFAULT 0,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_listing (is_published, hidden, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Membership, and the running order.
--
-- A join table rather than a column on {videos}, which is what series uses.
-- The difference is the point: a video belongs to at most ONE series, because
-- a series is the thing it is part of, and to any number of playlists, because
-- a playlist is somebody's selection. Same-shaped feature, different cardinality,
-- so different storage.
CREATE TABLE IF NOT EXISTS {playlist_items} (
  playlist_id INT UNSIGNED NOT NULL,
  video_id    INT UNSIGNED NOT NULL,
  position    INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,

  -- A video appears in a playlist once. Without this, adding it twice is a
  -- duplicate row that renders as a duplicate card and reorders unpredictably.
  PRIMARY KEY (playlist_id, video_id),
  KEY idx_order (playlist_id, position),
  -- Needed to answer "which playlists is this video in", which the edit screen
  -- asks for every video it lists.
  KEY idx_video (video_id),
  CONSTRAINT fk_item_playlist FOREIGN KEY (playlist_id)
    REFERENCES {playlists} (id) ON DELETE CASCADE,
  CONSTRAINT fk_item_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What one viewer has saved.
--
-- Favourites and watch-later are the same mechanism with different labels, so
-- they are one table with a `list` column rather than two tables that would
-- drift apart. Adding a third list later is an ENUM change and nothing else.
CREATE TABLE IF NOT EXISTS {saved_videos} (
  user_id    INT UNSIGNED NOT NULL,
  video_id   INT UNSIGNED NOT NULL,
  list       ENUM('favorite','watch_later') NOT NULL,
  created_at DATETIME     NOT NULL,

  -- Saving twice is saving once. Enforced here rather than by reading first
  -- and writing second, because a double-click is the normal way this happens.
  PRIMARY KEY (user_id, video_id, list),
  -- The Saved page reads one person's newest first.
  KEY idx_recent (user_id, list, created_at),
  CONSTRAINT fk_saved_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE,
  CONSTRAINT fk_saved_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
