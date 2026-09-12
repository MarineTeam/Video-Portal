-- Live chat, for a stream nobody is watching alone.
--
-- IT POLLS. IT DOES NOT HOLD A SOCKET.
--
-- Which is the whole shape of this schema. A WebSocket needs a process that
-- outlives a request, and the hosts this product targets give you PHP behind
-- Apache and nothing else — no daemon, no supervisor, no way to keep anything
-- open. So a browser asks "anything after id 41?" every few seconds, which is
-- one indexed range scan per person per few seconds, and that is a cost a
-- shared host can actually pay.
--
-- Everything below follows from polling: an AUTO_INCREMENT id is the cursor, so
-- a client that has seen 41 asks for 42 and up and can never be handed the same
-- message twice or miss one that arrived between two polls.

CREATE TABLE IF NOT EXISTS {live_chat_messages} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  stream_id   INT UNSIGNED NOT NULL,

  -- Who said it. A chat message belongs to an account, so this is NOT NULL and
  -- there is no anonymous path: a room where nobody can be asked to stop is a
  -- room a moderator cannot moderate.
  user_id     INT UNSIGNED NOT NULL,

  /*
   * The name as it was at the time, copied rather than joined.
   *
   * Same reasoning as the notification record: reading it off {users} later
   * rewrites history on every rename, and leaves the row saying nothing at all
   * once an account is deleted. What a moderator needs to know afterwards is
   * who said it under the name everybody saw.
   */
  author      VARCHAR(190) NOT NULL,

  body        VARCHAR(500) NOT NULL,

  /*
   * HIDING KEEPS THE MESSAGE.
   *
   * A delete would throw away the evidence at the moment it becomes useful, and
   * it would let the same text be sent again past a moderator who had already
   * decided about it. The row stays, carries who hid it and when, and stops
   * being served — so the decision survives, and the body is still here to
   * compare a resend against.
   */
  hidden_at   DATETIME     NULL,
  hidden_by   VARCHAR(190) NULL,

  created_at  DATETIME     NOT NULL,

  PRIMARY KEY (id),

  /*
   * The one query this table exists for: "messages in this stream after id N".
   * stream_id first so the range on id is a contiguous scan inside one stream
   * rather than a filter over every stream the site has ever run.
   *
   * hidden_at is NOT in the key. A poll selects live messages, and a moderator
   * screen wants the hidden ones too — filtering in the index would make the
   * second query a full scan to save nothing on the first, which at these row
   * counts is already a few dozen rows.
   */
  KEY idx_poll (stream_id, id),

  CONSTRAINT fk_chat_stream FOREIGN KEY (stream_id)
    REFERENCES {live_streams} (id) ON DELETE CASCADE,

  /*
   * CASCADE on the account, unlike almost everywhere else in this schema.
   *
   * A chat message is a person talking, not a record of a transaction: there is
   * no obligation, legal or practical, to keep somebody's chat after their
   * account is gone, and `author` above means the transcript does not become
   * anonymous rubble. This is the one table where deleting an account should
   * take the words with it.
   */
  CONSTRAINT fk_chat_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mutes, PER STREAM.
--
-- Not a site-wide ban, and the difference is the point. Somebody who will not
-- stop arguing about the football during the Sunday service is not somebody who
-- should be locked out of every stream this church ever runs; the moderator on
-- the night wants them to stop tonight. A site-wide ban is a different decision,
-- made by somebody else, on a different screen — and this table cannot become
-- one by accident because the stream is half the key.
CREATE TABLE IF NOT EXISTS {live_chat_mutes} (
  stream_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,

  muted_by    VARCHAR(190) NULL,
  reason      VARCHAR(190) NULL,
  created_at  DATETIME     NOT NULL,

  /*
   * The PRIMARY KEY is the design, as it is for the access request and the
   * announced-video guard. One row per person per stream, so muting twice is
   * not an error and cannot double up — a moderator pressing the button again
   * because nothing visibly happened must not create a second row that a later
   * unmute leaves behind.
   */
  PRIMARY KEY (stream_id, user_id),

  CONSTRAINT fk_mute_stream FOREIGN KEY (stream_id)
    REFERENCES {live_streams} (id) ON DELETE CASCADE,
  CONSTRAINT fk_mute_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Moderating the chat during a stream.
--
-- Separate from moderate_comments, which governs the comment threads under
-- recorded videos. The two are different jobs done by different people at
-- different times: comment moderation is somebody working through a queue in
-- the week, and this is somebody watching a room in real time on a Sunday. A
-- single capability would mean handing the weekday moderator a live room, or
-- the Sunday volunteer the whole comment archive.
INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('moderate_chat', 'Hide messages and mute people during a live stream');
