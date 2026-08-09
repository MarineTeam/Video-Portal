-- Ratings, and the running totals derived from them.
--
-- Two tables rather than one, and neither of them is a column added to
-- {videos}. A plugin that ALTERs a core table has to un-ALTER it on uninstall,
-- and a failed un-ALTER leaves a column nothing owns in a table nothing will
-- migrate again. Everything this plugin knows lives in tables it can drop.

CREATE TABLE IF NOT EXISTS {ratings} (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id     INT UNSIGNED NOT NULL,

  -- The account, when there is one. ON DELETE SET NULL for the same reason as
  -- comments: removing somebody's login should not quietly rewrite history.
  user_id      INT UNSIGNED NULL,

  -- The identity the unique key is built on. Email rather than user id, so an
  -- account deleted and recreated does not get a second vote.
  rater_email  VARCHAR(254) NOT NULL,

  score        TINYINT UNSIGNED NOT NULL,

  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- One rating per person per video, enforced here rather than by reading
  -- first and writing second. Two tabs, or one impatient double-click, is all
  -- a read-then-write needs to record two votes from one person.
  UNIQUE KEY uniq_rating (video_id, rater_email),

  KEY idx_rater (rater_email),
  CONSTRAINT fk_rating_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE,
  CONSTRAINT fk_rating_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The aggregate, cached.
--
-- Derived data, and therefore recomputed from {ratings} after every write
-- rather than incremented. An incremented counter is right until the first
-- thing that goes wrong, and then it is wrong forever with nothing to compare
-- it against.
--
-- It exists because sorting the whole library by average is otherwise a
-- GROUP BY over every rating ever cast, which is fine on a seeded test
-- database and not fine on a real one. A video with no ratings has no row
-- here; absent means "nobody has rated this", which is the same answer as a
-- row of zeroes and one fewer state to write code against.
CREATE TABLE IF NOT EXISTS {rating_totals} (
  video_id   INT UNSIGNED NOT NULL,
  vote_count INT UNSIGNED NOT NULL DEFAULT 0,
  score_sum  INT UNSIGNED NOT NULL DEFAULT 0,
  average    DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (video_id),
  KEY idx_average (average, vote_count),
  CONSTRAINT fk_total_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
