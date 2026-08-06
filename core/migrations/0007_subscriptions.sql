-- Subscriptions, and the fire-once guard for announcing new content.
--
-- {announced_videos} already exists — it shipped in Phase 1 as the atomic
-- replacement for a Redis SADD and has had no consumer since. This is what it
-- was for: a PRIMARY KEY on video_id plus INSERT IGNORE means a video can be
-- announced exactly once, whatever happens to the job that does the announcing.
-- Nothing here re-creates it.

CREATE TABLE IF NOT EXISTS {subscriptions} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- The unsubscribe key, and the whole reason unsubscribing needs no account.
  --
  -- A stored random token rather than an HMAC of the address: it is revocable,
  -- it survives a key rotation, and deleting the row genuinely invalidates the
  -- link. An HMAC would keep working forever and could not be withdrawn.
  token       VARCHAR(64)  NOT NULL,

  email       VARCHAR(254) NOT NULL,

  -- The account, when there is one. ON DELETE SET NULL: removing a login must
  -- not silently resubscribe somebody by losing the record that they asked.
  user_id     INT UNSIGNED NULL,

  --   site      everything published
  --   category  one category and its children
  --   series    one series
  --   speaker   one person
  scope_type  ENUM('site','category','series','speaker') NOT NULL DEFAULT 'site',

  -- Which one. NULL for site. Deliberately not a foreign key: it points at
  -- three different tables depending on scope_type, so there is nothing single
  -- to constrain against. The repository drops a subscription whose target has
  -- been deleted.
  scope_id    INT UNSIGNED NULL,

  created_at  DATETIME     NOT NULL,
  last_sent_at DATETIME    NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_token (token),

  -- One subscription per address per thing. Subscribing twice is subscribing
  -- once, enforced here rather than by reading first — a double-submit is the
  -- ordinary way it happens, and the consequence of getting it wrong is two
  -- copies of every email.
  --
  -- scope_id is nullable and MySQL treats NULLs in a unique key as distinct,
  -- so a site-wide subscription would escape this. COALESCE into a generated
  -- column gives it something concrete to compare.
  scope_key   INT UNSIGNED AS (COALESCE(scope_id, 0)) STORED,
  UNIQUE KEY uniq_subscription (email, scope_type, scope_key),

  KEY idx_scope (scope_type, scope_id),
  CONSTRAINT fk_subscription_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything that already exists counts as already announced.
--
-- Without this, the first notification run on a site that has been up for a
-- year would mail its entire back catalogue. Nobody is subscribed yet on the
-- upgrade that applies this, so there is nothing to send anyway — but the
-- guard has to be here rather than in the job, because by the time somebody
-- subscribes the library is no longer new and the job cannot tell the
-- difference.
--
-- This runs exactly once, at the moment of upgrade, which is the only moment
-- the answer is unambiguous.
INSERT IGNORE INTO {announced_videos} (video_id, announced_at)
SELECT id, NOW() FROM {videos} WHERE deleted_at IS NULL;
