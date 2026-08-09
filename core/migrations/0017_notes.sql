-- Notes somebody takes while watching.
--
-- The privacy decision is the whole table, so it is stated here rather than
-- only on the screen: these are PRIVATE. Nobody but the person who wrote them
-- can read them — not an editor, not an administrator, and there is no admin
-- screen that lists them. That is the same stance the view-count table takes
-- for a related reason: this product holds what it must to be useful and
-- deliberately does not hold what it would then have to be trusted with.
--
-- A note is per person per video, which makes it the same shape as
-- {watch_progress} — and it is stored for the same reason: somebody who wrote
-- half a page during a service expects to find it there next week.

CREATE TABLE IF NOT EXISTS {video_notes} (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- No nullable user. There is nowhere to put a note from somebody with no
  -- account, and pretending otherwise — a cookie, a session — would lose it
  -- the first time a browser was cleared, which is worse than saying up front
  -- that this needs an account.
  user_id    INT UNSIGNED NOT NULL,
  video_id   INT UNSIGNED NOT NULL,

  -- Plain text. Rendered escaped and never as HTML: a note is the one thing
  -- here written by a viewer and shown back to them, and the moment it renders
  -- as markup it becomes a place to store a script that runs on the next
  -- visit.
  body       MEDIUMTEXT   NOT NULL,

  created_at DATETIME     NOT NULL,
  updated_at DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- ATOMICITY: one note per person per video. The panel autosaves, so several
  -- saves are in flight at once from one page; without this a slow first
  -- request and a fast second produce two rows and the older one wins on the
  -- next read.
  UNIQUE KEY uniq_user_video (user_id, video_id),

  -- The notes page: everything one person wrote, most recent first.
  KEY idx_recent (user_id, updated_at),

  CONSTRAINT fk_note_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE,
  CONSTRAINT fk_note_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
