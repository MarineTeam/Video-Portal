-- Live streams.
--
-- A separate table from {videos}, and the separation is the design.
--
-- A live stream has no provider id, no duration, no thumbnail from an encoder,
-- no transcript, no chapters and no watch progress — and it has a schedule, an
-- embed somebody else hosts, and a state that changes with the clock. Putting
-- it in {videos} would mean a nullable half of that table and a `type` column
-- that every existing query would have to learn about, including the ones in
-- plugins and themes written before it existed.
--
-- The cost of keeping them apart is that a live stream does not appear in the
-- library, in search, or in a feed until it has a recording attached. That is
-- stated on the screen, and it is the right way round: the thing people search
-- for afterwards is the recording, which IS a video.

CREATE TABLE IF NOT EXISTS {live_streams} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  slug          VARCHAR(191) NOT NULL,
  title         VARCHAR(190) NOT NULL,
  description   TEXT         NULL,

  -- Somebody else's player. Validated in PHP before it ever gets here — see
  -- LiveStreamPolicy, where the refusals live — because this ends up in an
  -- iframe src, and a scheme other than https in that position is executable
  -- rather than merely wrong.
  embed_url     VARCHAR(500) NOT NULL,

  -- The schedule. Both nullable, and they mean different things when absent:
  -- no start is "made but never scheduled", which reads as not yet live; no
  -- end is "nobody knows when it finishes", which the safety net handles.
  starts_at     DATETIME     NULL,
  ends_at       DATETIME     NULL,

  -- When somebody pressed the button that says it is over. Beats every
  -- schedule: a stream that finished early must not keep claiming to be live
  -- until its planned end.
  ended_at      DATETIME     NULL,

  -- The recording, once there is one. ON DELETE SET NULL rather than CASCADE:
  -- deleting the recording must not delete the record that the stream happened.
  video_id      INT UNSIGNED NULL,

  is_published  TINYINT(1)   NOT NULL DEFAULT 1,
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),

  -- The one query the public side makes: what is on, ordered by when it starts.
  KEY idx_schedule (is_published, starts_at),

  CONSTRAINT fk_live_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
