-- Transcripts: what was said, and when.
--
-- Two tables because the two are asked for separately. The cues drive the
-- panel beside the player and are read whole, in order, for one video. The
-- flattened text drives search and is read across every video at once — and
-- concatenating thousands of cue rows on every search would be the slowest
-- thing on the site.
--
-- Storing both is a duplication, and it is deliberate: the flattened copy is
-- derived, written in the same transaction, and never edited independently.

CREATE TABLE IF NOT EXISTS {transcripts} (
  video_id    INT UNSIGNED NOT NULL,

  -- Everything said, as one block. What search matches against.
  --
  -- MEDIUMTEXT rather than TEXT: an hour of speech is roughly 60,000
  -- characters and TEXT stops at 65,535, so a long sermon would be silently
  -- truncated mid-sentence — the kind of failure nobody notices until they
  -- search for something from the last ten minutes.
  body        MEDIUMTEXT   NOT NULL,

  -- How the cues were produced, for the admin screen to show. Free text
  -- rather than an enum: the useful value is whatever the site owner recognises
  -- ("Whisper", "the captioner", "typed by hand"), and an enum would need a
  -- migration every time somebody used a new tool.
  source      VARCHAR(100) NOT NULL DEFAULT '',

  cue_count   INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,

  -- One transcript per video. Replacing one is a delete and an insert rather
  -- than a merge: two transcripts of the same recording are not something to
  -- reconcile, they are one mistake.
  PRIMARY KEY (video_id),
  CONSTRAINT fk_transcript_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {transcript_cues} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id    INT UNSIGNED NOT NULL,

  -- Whole seconds. A transcript panel seeks to the second; sub-second
  -- precision is noise that would make two cues in the same second look
  -- different in the interface.
  start_at    INT UNSIGNED NOT NULL,
  end_at      INT UNSIGNED NOT NULL,

  text        VARCHAR(2000) NOT NULL,

  PRIMARY KEY (id),
  -- The only query: every cue for one video, in order.
  KEY idx_video_time (video_id, start_at),
  CONSTRAINT fk_cue_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
