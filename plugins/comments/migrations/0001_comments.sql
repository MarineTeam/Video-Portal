-- Comments, and the reports people file against them.
--
-- A plugin's tables, created on activation and dropped on uninstall. They carry
-- the {prefix} token like everything else, so they sit alongside core's tables
-- in whatever database the site was installed into.

CREATE TABLE IF NOT EXISTS {comments} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id      INT UNSIGNED NOT NULL,

  -- One level of replies and no more. Deeper threads are a user-interface
  -- problem nobody has ever solved on a phone, and they turn moderation into
  -- archaeology.
  parent_id     INT UNSIGNED NULL,

  -- The account, when there is one. Nullable and ON DELETE SET NULL: removing
  -- somebody's login should not silently delete what they wrote.
  user_id       INT UNSIGNED NULL,

  -- Captured at posting time rather than joined at read time. A comment from
  -- four years ago still needs a name on it after the account is gone, and the
  -- display name at the time is more honest than whatever it is now.
  author_name   VARCHAR(190) NOT NULL,
  author_email  VARCHAR(254) NOT NULL,

  body          TEXT         NOT NULL,

  --   pending   awaiting a moderator
  --   approved  visible
  --   spam      hidden, kept so the same author can be recognised later
  --   removed   taken down; still rendered as a tombstone IF it has replies,
  --             because deleting it outright would orphan them
  status        ENUM('pending','approved','spam','removed') NOT NULL DEFAULT 'pending',

  report_count  INT UNSIGNED NOT NULL DEFAULT 0,

  -- Kept for moderation: two comments from the same address minutes apart from
  -- different countries is worth being able to see.
  ip            VARCHAR(45)  NOT NULL DEFAULT '',

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_video_thread (video_id, status, created_at),
  KEY idx_parent (parent_id),
  KEY idx_moderation (status, created_at),
  -- Finding an author's history is the first thing a moderator does with a
  -- spam report, so it is indexed rather than scanned.
  KEY idx_author (author_email, status),
  CONSTRAINT fk_comment_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_parent FOREIGN KEY (parent_id)
    REFERENCES {comments} (id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {comment_reports} (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  comment_id     INT UNSIGNED NOT NULL,
  reporter_email VARCHAR(254) NOT NULL,
  reason         VARCHAR(255) NOT NULL DEFAULT '',
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (id),
  -- One report per person per comment. Without this, one determined visitor
  -- could run the report count up and make an ordinary comment look like a
  -- crisis to whoever reviews the queue.
  UNIQUE KEY uniq_report (comment_id, reporter_email),
  CONSTRAINT fk_report_comment FOREIGN KEY (comment_id)
    REFERENCES {comments} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
