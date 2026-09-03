-- Who may sign in at all, and a record of everyone turned away.
--
-- TWO GATES, ANSWERING TWO DIFFERENT QUESTIONS
--
--   this table      may this ADDRESS be here at all?      site policy, set
--                                                         ahead of time, in
--                                                         bulk, before anybody
--                                                         has an account
--
--   users.authorized  has an administrator approved THIS   already exists,
--                     account?                             already checked on
--                                                          every request
--
-- Both must pass. Neither is derived from the other, and that is deliberate:
-- the application this is ported from kept the same fact in both places, with
-- the account flag recomputed from the allowlist on every request, so granting
-- access on the accounts screen appeared to work and was silently undone on the
-- person's next page load. They needed a fourth commit and a unified write path
-- to repair it. Two independent questions need no write path and cannot
-- disagree.
--
-- OFF BY DEFAULT. An empty allowlist with the feature switched on would refuse
-- everybody, so the setting is what turns it on, and the screen refuses to
-- enable it while the list is empty.
--
-- EXEMPTIONS ARE NOT OPTIONAL. Administrators and local-password accounts are
-- never refused by this gate. Deployment here is `git pull` on a host with no
-- shell: an allowlist that can lock the last administrator out of the screen
-- that would undo it is a site nobody can recover. This is the same rule
-- require_verified_email ships with, for the same reason.
--
-- ---------------------------------------------------------------------------
--
-- {access_attempts} is the other half, and it exists because a refusal is
-- currently invisible. Somebody turned away has no account, so there is no row
-- anywhere that says they tried — the administrator hears about it only if the
-- person can find another way to make contact. The audit log records what
-- accounts DID, not who was stopped at the door.
--
-- No credential is ever stored here, only the address that was offered.

CREATE TABLE IF NOT EXISTS {signin_allowlist} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Normalised on write by Str::normalizeEmail, which is also what the check
  -- compares against. Two ways of writing the same address must not become two
  -- rows, one of which somebody later suspends believing they closed the door.
  email       VARCHAR(191) NOT NULL,

  -- Suspended rather than deleted keeps the history of who added it and when,
  -- which is the question asked after somebody gets in who should not have.
  status      ENUM('active','suspended') NOT NULL DEFAULT 'active',

  -- Free text for the administrator: which cohort, which course, who asked.
  note        VARCHAR(500) NULL,

  added_by    VARCHAR(191) NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email),
  KEY idx_status (status, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {access_attempts} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  email       VARCHAR(191) NOT NULL,

  -- Which gate refused, so the fix is obvious: an address nobody has added, or
  -- one that was added and then suspended, or an account still waiting for
  -- approval. Three different actions.
  reason      VARCHAR(32) NOT NULL,

  -- 'local', 'auth0', 'oidc' — how they tried to get in. A site that has just
  -- switched provider needs to be able to tell those apart.
  provider    VARCHAR(64) NULL,

  ip          VARCHAR(45) NULL,

  -- Cleared by an administrator who has dealt with it. Not a status on the
  -- attempt itself: the attempt is a fact and does not change.
  reviewed_at DATETIME NULL,

  created_at  DATETIME NOT NULL,

  PRIMARY KEY (id),
  KEY idx_recent (created_at),
  KEY idx_email (email, created_at),
  KEY idx_unreviewed (reviewed_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The pruning job. Attempts are kept 90 days.
--
-- Seeded here rather than left to the installer, because an existing site never
-- re-runs the seeder — a job with no row is never due, so it does nothing,
-- silently, forever. That has already happened once in this project, to
-- notifications.send, and Cron::ensureCoreJobs exists so it cannot happen
-- again. This row makes the job due on sites that upgrade rather than install.
INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('access_attempts.prune', 86400, NOW(), 1);
