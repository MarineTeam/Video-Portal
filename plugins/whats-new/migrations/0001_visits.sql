-- When did this person last leave?
--
-- ONE ROW PER ACCOUNT, and the primary key is the design: it caps the table at
-- the size of {users} and it makes the create idempotent, so two requests
-- arriving together cannot both insert.
--
-- Nothing is added to {users}, and {users}.last_seen_at is deliberately not
-- used. Two reasons, and the second is the one that matters:
--
--   * It records the last time somebody was seen, which is NOW on the request
--     that would read it. "New since your last visit" needs the visit BEFORE
--     this one, which is a different fact and nothing in core keeps it.
--   * Even if it did, the meaning would be core's to change. A plugin whose
--     feature quietly changes when somebody adjusts a core throttle is a
--     plugin that breaks without anybody editing it.
--
-- And nothing is ALTERed. A plugin that ALTERs a core table has to un-ALTER it
-- on uninstall, and a failed un-ALTER leaves a column nothing owns in a table
-- nothing will migrate again.

CREATE TABLE IF NOT EXISTS {whats_new_visits} (
  -- The account. Signed-in people only: an anonymous visitor has no identity
  -- that survives a cleared cookie, and a "new since your last visit" marker
  -- built on one would reset itself and badge the whole library.
  user_id    INT UNSIGNED NOT NULL,

  -- The end of the PREVIOUS visit -- the value everything is compared against.
  --
  -- NULL until there has been a previous visit, which is exactly the first
  -- time somebody signs in. Nothing is badged then, deliberately: on a first
  -- visit every video is new, and a library where every card says "New" says
  -- nothing at all.
  marker_at  DATETIME     NULL,

  -- The most recent request. Rolled into marker_at when somebody comes back
  -- after a gap, which is the whole mechanism.
  seen_at    DATETIME     NOT NULL,

  PRIMARY KEY (user_id),

  CONSTRAINT fk_whats_new_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
