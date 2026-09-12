-- A member's own calendar feed.
--
-- THE TOKEN IS THE WHOLE OF THE AUTHENTICATION
--
-- A calendar application cannot log in. It fetches a URL on a timer, with no
-- session, no cookie and nobody watching — so the secret has to be in the URL,
-- and that single string is the entire access decision for somebody's rota,
-- their sign-ups and the dates a schedule names them on.
--
-- Which is why:
--
--   NOBODY HAS ONE UNTIL THEY ASK. No row until a member presses the button, so
--   a URL cannot be guessed for an account that never wanted a feed. A token
--   minted for everybody at install is a capability handed to anybody who ever
--   reads the database.
--
--   REPLACING IT IS THE ANSWER TO A LEAK, and it stops every subscriber at
--   once. That is the point rather than a side effect: a member who pasted the
--   URL into a shared family calendar needs one action that ends it everywhere,
--   not a list of subscribers to revoke one by one — which a feed cannot have,
--   because a feed has no idea who is reading it.
--
-- STORED IN PLAINTEXT, DELIBERATELY, AND IT IS NOT AN OVERSIGHT
--
-- Every other secret in this application is hashed. This one cannot be: the
-- member has to be able to copy the URL again to add the feed on a second
-- device, and a hash cannot be shown back. It is a CAPABILITY URL, the same
-- shape as the push endpoint — possession is the permission.
--
-- The column is named `feed_token` because Portal\Support\SecretGuard forbids
-- that key by name. So it cannot reach a data export, the read API, or anything
-- else that hands a payload out: the guard THROWS on it. The only place it is
-- ever rendered is the member's own settings page, which prints it directly
-- rather than through a guarded payload.

CREATE TABLE IF NOT EXISTS {calendar_feeds} (
  user_id       INT UNSIGNED NOT NULL,

  -- 32 random bytes as hex. Long enough that guessing is not a strategy, and
  -- UNIQUE so a collision is a failed insert rather than one member reading
  -- another's diary.
  feed_token    CHAR(64)     NOT NULL,

  /*
   * When a calendar last fetched it. The only evidence a member has that the
   * feed is working at all — a subscription that silently stopped looks
   * identical to a rota with nothing on it.
   */
  last_used_at  DATETIME     NULL,
  fetches       INT UNSIGNED NOT NULL DEFAULT 0,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (user_id),
  UNIQUE KEY uniq_feed_token (feed_token),
  CONSTRAINT fk_calendar_feed_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
