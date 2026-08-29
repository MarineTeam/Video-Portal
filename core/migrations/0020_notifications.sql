-- What this site has told somebody, kept after it told them.
--
-- Every notification goes out over a channel that cannot be re-read: an email
-- lands in a mailbox this app has no access to, and a push notification is
-- gone the moment it is dismissed -- or never arrives at all, because the
-- browser was closed, the permission was refused, or the subscription had
-- silently expired. So the site has been announcing things for two phases with
-- no way for anybody to find out what it said.
--
-- KEYED BY EMAIL, not by user id, and that is the whole design.
--
-- Subscriptions in this product are email-based: anyone can follow a category
-- without an account, which is deliberate and predates accounts having any say
-- in it. A user_id column would therefore be null for most rows and would make
-- the record disagree with the thing it is recording. Email is also the
-- identity that survives an account being deleted and recreated -- the same
-- reasoning {reactions} uses for reactor_email.
--
-- The consequence is worth stating plainly: an anonymous subscriber generates
-- rows nobody can read, because there is no account to read them from. That is
-- correct rather than wasteful. The moment they create an account with that
-- address, their history is already there.

CREATE TABLE IF NOT EXISTS {notifications} (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Normalised before it gets here, so a lookup by the signed-in user's
  -- address matches whatever case the subscription was created in.
  recipient_email VARCHAR(254) NOT NULL,

  -- Which way it went out. One row per channel, so somebody who gets both an
  -- email and a push sees that both were sent rather than one of them.
  channel         VARCHAR(16)  NOT NULL,

  -- COPIED, not looked up.
  --
  -- The title is what the notification actually said. Reading it back off the
  -- video would rewrite history every time somebody renamed one, and would
  -- leave a row saying nothing at all once the video was deleted. The url is
  -- stored for the same reason -- it is where the notification pointed, which
  -- is a fact about the past.
  title           VARCHAR(300) NOT NULL,
  url             VARCHAR(500) NOT NULL DEFAULT '',

  -- Nullable, and SET NULL rather than CASCADE. Deleting a video must not
  -- erase the record that people were told about it; it only stops the row
  -- claiming to point at something that still exists.
  video_id        INT UNSIGNED NULL,

  read_at         DATETIME     NULL,
  created_at      DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- The only query this table serves: one person's list, newest first, and
  -- the unread count drawn from the same index.
  KEY idx_recipient (recipient_email, created_at),
  KEY idx_unread (recipient_email, read_at),

  CONSTRAINT fk_notification_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
