-- Reactions: a response to a video, not a score for it.
--
-- ONE table, unlike ratings, which needed a second for its weighted totals.
-- There is nothing to derive here: a count is COUNT(*) over an index, and a
-- stored counter would be right until the first thing that goes wrong and then
-- wrong forever with nothing to compare it against. Ratings pays for its cache
-- because the leaderboard weights every video against the site average; a
-- reaction count weights nothing.
--
-- And nothing is added to {videos}. A plugin that ALTERs a core table has to
-- un-ALTER it on uninstall, and a failed un-ALTER leaves a column nothing owns
-- in a table nothing will migrate again.

CREATE TABLE IF NOT EXISTS {reactions} (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id     INT UNSIGNED NOT NULL,

  -- The account, when there is one. ON DELETE SET NULL as with comments and
  -- ratings: removing somebody's login should not quietly rewrite history.
  user_id      INT UNSIGNED NULL,

  -- The identity the unique key is built on. Email rather than user id, so an
  -- account deleted and recreated does not get a second go.
  reactor_email VARCHAR(254) NOT NULL,

  -- One of ReactionPolicy::kinds(). VARCHAR rather than ENUM on purpose: the
  -- vocabulary lives in PHP where it can be read alongside its labels, and an
  -- ENUM would mean a migration every time a word changed. Unknown values are
  -- refused before they reach here and ignored on the way out, so a row left
  -- behind by an older vocabulary is inert rather than broken.
  kind         VARCHAR(32)  NOT NULL,

  created_at   DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- ONE OF EACH KIND per person per video -- the design in a constraint.
  --
  -- Ratings has UNIQUE(video_id, rater_email): a second rating replaces the
  -- first, because a judgement has one answer. Here `kind` is in the key, so
  -- somebody may say "Amen" AND "Thankful" and neither displaces the other,
  -- while pressing the same button twice cannot count twice.
  --
  -- Enforced here rather than by reading first and writing second. Two tabs, or
  -- one impatient double-click, is all a read-then-write needs to record the
  -- same reaction twice.
  UNIQUE KEY uniq_reaction (video_id, reactor_email, kind),

  -- Counting one video's reactions, which is what every page render asks.
  KEY idx_video (video_id, kind),

  CONSTRAINT fk_reaction_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE,
  CONSTRAINT fk_reaction_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
