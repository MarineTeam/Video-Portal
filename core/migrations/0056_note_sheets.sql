-- Sermon note sheets: the fill-in-the-blank paper handed out at the door.
--
-- See Portal\Content\NoteSheet for the rules. Two tables because the sheet is
-- the editor's and the answers are each member's, and they are deleted by
-- different people for different reasons.

CREATE TABLE IF NOT EXISTS {note_sheets} (
  -- One sheet per video. The primary key IS the rule, so two editors saving at
  -- once update one row rather than racing to create two.
  video_id     INT UNSIGNED NOT NULL,

  -- Plain text; three or more underscores mark a gap. An empty outline means
  -- "no sheet" and the row is KEPT, with its version: removing a sheet and
  -- putting one back must not restart at version 1, or answers written under
  -- the old version-1 outline would line up against the new one silently.
  outline      MEDIUMTEXT   NOT NULL,

  -- Bumped when the wording changes (NoteSheet::fingerprint), and stored with
  -- every set of answers so a sheet can say it has changed since.
  version      INT UNSIGNED NOT NULL DEFAULT 1,
  fingerprint  CHAR(40)     NOT NULL,

  updated_at   DATETIME     NOT NULL,

  PRIMARY KEY (video_id),
  CONSTRAINT fk_note_sheet_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {note_sheet_answers} (
  -- No nullable user: keeping answers needs an account, and the sheet says so.
  -- A signed-out visitor can still read, fill in and print it.
  user_id        INT UNSIGNED NOT NULL,
  video_id       INT UNSIGNED NOT NULL,

  -- The outline version these were written under.
  sheet_version  INT UNSIGNED NOT NULL,

  -- A JSON list, one string per gap, in gap order.
  answers        MEDIUMTEXT   NOT NULL,

  updated_at     DATETIME     NOT NULL,

  -- ATOMICITY: saved as it is typed, so several saves from one page are in
  -- flight at once. One row per person per sheet, written as an upsert.
  PRIMARY KEY (user_id, video_id),
  KEY idx_video (video_id),

  CONSTRAINT fk_note_answer_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE,
  CONSTRAINT fk_note_answer_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
