-- Chapters: named moments in a video.
--
-- Editorial, not derived. A transcript says what was said; a chapter says what
-- part of the recording this is, and somebody decides that. So they are stored
-- separately even though both are time markers, and neither is generated from
-- the other.
--
-- No summary table, unlike transcripts. Chapters are a handful of rows read
-- whole for one video and never searched across the library, so there is
-- nothing a flattened copy would make faster.

CREATE TABLE IF NOT EXISTS {chapters} (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id   INT UNSIGNED NOT NULL,

  -- Whole seconds, matching transcript cues and the ?t= link they both use.
  start_at   INT UNSIGNED NOT NULL,

  title      VARCHAR(190) NOT NULL,

  PRIMARY KEY (id),

  -- One chapter per moment. A list pasted in twice would otherwise render two
  -- identical links, and there is no reading of "two chapters starting at the
  -- same second" that anybody wants.
  UNIQUE KEY uniq_moment (video_id, start_at),

  CONSTRAINT fk_chapter_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
