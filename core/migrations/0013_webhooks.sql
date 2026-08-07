-- Webhooks: telling another system that something happened here.
--
-- Two tables rather than one, because an endpoint and a delivery have
-- different lifetimes. The endpoint is configuration somebody typed and
-- expects to survive; a delivery is a record of one attempt and is pruned.
-- Folding them together would mean either keeping every attempt forever or
-- losing the endpoint when the history is cleaned up.

CREATE TABLE IF NOT EXISTS {webhooks} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Capped well under MySQL's index limit; a URL longer than this is not one
  -- somebody typed. Validated in PHP before it ever gets here — see
  -- WebhookPolicy, which is where the interesting refusals live.
  url           VARCHAR(500) NOT NULL,

  -- The signing secret. Generated here, shown once, never derived from
  -- anything: a secret computed from the URL or an app key would be the same
  -- on two installs that happened to share either, and could not be rotated
  -- without rotating whatever it came from.
  secret        VARCHAR(64)  NOT NULL,

  -- A comma-separated list of event names, or '*' for all of them.
  --
  -- A list in a column rather than a join table. The alternative is a row per
  -- endpoint per event, which buys referential integrity over a set of names
  -- that are defined in PHP and not in the database — there is nothing to have
  -- integrity WITH. It is read whole on every dispatch and never queried by
  -- event, so there is no index to miss either.
  events        VARCHAR(500) NOT NULL DEFAULT '*',

  description   VARCHAR(200) NOT NULL DEFAULT '',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,

  -- Why it was switched off, when it was switched off by us rather than by a
  -- person. An endpoint that stopped working and silently stopped being tried
  -- is indistinguishable from one that never worked.
  disabled_reason VARCHAR(300) NOT NULL DEFAULT '',

  -- Consecutive failures. Reset on any success, so this is "how broken is it
  -- right now" rather than "how often has it ever failed" — the second number
  -- would eventually disable a healthy endpoint that had a bad week in 2027.
  failure_count INT UNSIGNED NOT NULL DEFAULT 0,

  last_status   INT          NULL,
  last_error    VARCHAR(500) NOT NULL DEFAULT '',
  last_delivered_at DATETIME NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {webhook_deliveries} (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  webhook_id    INT UNSIGNED NOT NULL,
  event         VARCHAR(50)  NOT NULL,

  -- The body, built once at enqueue time and never rebuilt.
  --
  -- Deliberate: a payload assembled at DELIVERY time would describe the video
  -- as it is when the request finally goes out, which on a retry an hour later
  -- is a different video — or a deleted one. A webhook says "this happened",
  -- and what happened does not change while we are trying to report it.
  payload       MEDIUMTEXT   NOT NULL,

  status        ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,

  -- When to try next. A column comparison rather than a scheduled job per
  -- delivery, for the same reason publishing is a comparison: nothing has to
  -- run on time for a date to have passed.
  next_attempt_at DATETIME   NOT NULL,

  response_status INT        NULL,
  error         VARCHAR(500) NOT NULL DEFAULT '',

  created_at    DATETIME     NOT NULL,
  delivered_at  DATETIME     NULL,

  PRIMARY KEY (id),

  -- The dispatcher's only query: pending work that is due, oldest first.
  KEY idx_due (status, next_attempt_at),
  KEY idx_webhook (webhook_id, created_at),

  CONSTRAINT fk_delivery_webhook FOREIGN KEY (webhook_id)
    REFERENCES {webhooks} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which videos have already been reported as published.
--
-- There is no publish event to hook. A scheduled video becomes visible because
-- a comparison in a query started returning true, with no code running at that
-- moment — the same problem the announcement job has, solved the same way: ask
-- what is visible now that has never been reported, and get fire-once from a
-- PRIMARY KEY and INSERT IGNORE rather than from the job running exactly once.
--
-- A separate ledger from {announced_videos} on purpose. They answer different
-- questions ("has this been emailed to subscribers" and "has this been sent to
-- an endpoint"), and a site that turns one of them on years after the other
-- must not have its decision made by the other one's history.
CREATE TABLE IF NOT EXISTS {webhook_seen_videos} (
  video_id   INT UNSIGNED NOT NULL,
  seen_at    DATETIME     NOT NULL,

  PRIMARY KEY (video_id),
  CONSTRAINT fk_webhook_seen_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything that already exists counts as already reported.
--
-- Without this, the first dispatch on a site that has been running for a year
-- would fire its whole back catalogue at whatever endpoint gets added first.
-- No endpoint exists on the upgrade that applies this, so nothing would be
-- sent today — but the guard has to be here rather than in the job, because by
-- the time somebody adds an endpoint the library is no longer new and the job
-- cannot tell the difference.
INSERT IGNORE INTO {webhook_seen_videos} (video_id, seen_at)
SELECT id, NOW() FROM {videos} WHERE deleted_at IS NULL;

-- The delivery job.
--
-- Also seeds notifications.send, which is not a webhook concern and is fixed
-- here because this is the first migration to notice. Core cron rows have only
-- ever been created by the INSTALLER, so a site installed before Phase 4 has
-- no row for it — and a job with no row is never due, so subscriptions on
-- those installs have been silently sending nothing. INSERT IGNORE, so a job
-- somebody deliberately disabled stays disabled.
INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES
  ('webhooks.deliver', 60, NOW(), 1),
  ('notifications.send', 900, NOW(), 1),
  ('webhooks.cleanup', 86400, NOW(), 1);
