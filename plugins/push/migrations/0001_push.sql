-- Browser push subscriptions.
--
-- A subscription is issued by the BROWSER, not by this site: the endpoint,
-- the public key and the auth secret all come from the push service and are
-- opaque here. So this table stores what it was handed and never generates any
-- of it.

CREATE TABLE IF NOT EXISTS {push_subscriptions} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- The push service's URL for this browser. Long, because Firefox and FCM
  -- endpoints run well past what a VARCHAR(255) would hold, and a truncated
  -- endpoint is a subscription that can never be delivered to or matched
  -- against on renewal.
  endpoint      VARCHAR(500) NOT NULL,

  -- The browser's P-256 public key and 16-byte auth secret, base64url. Both
  -- are needed to encrypt anything for it; neither is a secret this site
  -- chose, and losing them means the subscription is dead.
  p256dh        VARCHAR(200) NOT NULL,
  auth_secret   VARCHAR(50)  NOT NULL,

  -- Who it belongs to, when anybody was signed in. Nullable on purpose: a
  -- browser can subscribe without an account, and the site is public. ON
  -- DELETE CASCADE, because a subscription outliving its account would keep
  -- notifying somebody who has left.
  user_id       INT UNSIGNED NULL,

  -- Consecutive failures. Reset on any success, so it answers "is this
  -- endpoint broken now" rather than "has it ever failed" — the second number
  -- would eventually retire a healthy subscription that had a bad week.
  failure_count TINYINT UNSIGNED NOT NULL DEFAULT 0,

  created_at    DATETIME     NOT NULL,
  last_sent_at  DATETIME     NULL,

  PRIMARY KEY (id),

  -- One row per endpoint. A browser re-subscribes on its own schedule — after
  -- a permission change, a service worker update, or simply because the push
  -- service rotated it — and posts the same endpoint again. Enforced here
  -- rather than by reading first, because that read-then-write is precisely
  -- what duplicates a subscription and sends every notification twice.
  --
  -- Indexed on a prefix: the column is longer than InnoDB will index whole.
  UNIQUE KEY uniq_endpoint (endpoint(191)),
  KEY idx_user (user_id),

  CONSTRAINT fk_push_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which videos have already been pushed.
--
-- The third ledger of this shape, after {announced_videos} for email and
-- {webhook_seen_videos} for endpoints, and separate from both for the same
-- reason: they answer different questions, and a site that switches push on a
-- year after email must not have its decision made by the email history.
--
-- There is no publish event to hook — a scheduled video becomes visible when a
-- comparison starts returning true — so the job asks in reverse and fire-once
-- comes from a PRIMARY KEY, not from the job running exactly once.
CREATE TABLE IF NOT EXISTS {pushed_videos} (
  video_id   INT UNSIGNED NOT NULL,
  pushed_at  DATETIME     NOT NULL,

  PRIMARY KEY (video_id),
  CONSTRAINT fk_pushed_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything that already exists counts as already pushed.
--
-- Without this, switching the plugin on for a library that has been up for a
-- year would fire the entire back catalogue at every browser that had ever
-- subscribed. Nobody is subscribed at the moment this runs, so nothing would
-- go out today — but the guard belongs here rather than in the job, because by
-- the time somebody subscribes the library is no longer new and the job cannot
-- tell the difference.
INSERT IGNORE INTO {pushed_videos} (video_id, pushed_at)
SELECT id, NOW() FROM {videos} WHERE deleted_at IS NULL;
