-- Signing a television in, by code rather than by password.
--
-- RFC 8628, the OAuth device authorization grant, and the reason for it is the
-- input device: a television remote has four arrows and an OK button. Typing an
-- email address on one takes a minute and a half, and typing a password with
-- mixed case and a symbol is not realistically possible at all. So people reuse
-- something short and memorable on exactly the device that is hardest to type
-- on, which is the worst place in the product for a password box.
--
-- Instead the television shows a short code and the person signs in on the
-- phone already in their hand.
--
-- TWO CODES, AND THEY ARE NOT THE SAME KIND OF THING
--
--   The USER CODE is short, shown on the screen, read across a room and typed
--   on a phone. It is short because a person has to carry it in their head for
--   two seconds, and it is safe to be short because it lives for ten minutes
--   and can be tried only a few times.
--
--   The DEVICE CODE is long, never displayed, and the television keeps it. It
--   is what the television presents when it asks "has anybody approved me
--   yet", and it is the thing that turns into a session — so it is a
--   credential, and it is STORED HASHED, exactly like an API key. A pairing
--   table readable from a backup would otherwise be a list of ways to become
--   somebody.
--
-- See Portal\Tv\PairingCode for the alphabet, and Portal\Tv\Pairing for why
-- expiry is checked before approval.

CREATE TABLE IF NOT EXISTS {tv_pairings} (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

  /*
   * The short one, stored in the clear.
   *
   * Deliberately NOT hashed, unlike its long partner, and the difference is
   * worth stating because it looks inconsistent. This value is on a screen in
   * a room; hashing it would protect it from a reader of the database while it
   * is being broadcast to everybody present. What limits it is lifetime and
   * attempts, not secrecy — and storing it in the clear is what lets the
   * approval screen say "that code has expired" instead of "no".
   */
  user_code       VARCHAR(16)  NOT NULL,

  /*
   * SHA-256 of the long one. Not password_hash(): this is 32 random bytes
   * rather than something a person chose, so there is nothing to brute-force
   * and no reason to make a television polling every five seconds pay for a
   * slow KDF. The same reasoning as {api_keys}.
   */
  device_hash     CHAR(64)     NOT NULL,

  /*
   * Whatever the television can say about itself, for the approval screen.
   *
   * Free text and never trusted: it is a device-supplied string that a person
   * reads while deciding whether to approve. "Living room TV" helps somebody
   * confirm they are approving their own set rather than a stranger's; it is
   * not evidence of anything.
   */
  device_label    VARCHAR(120) NULL,

  -- Who approved it, once somebody has. NULL is the whole of "not yet".
  approved_by     INT UNSIGNED NULL,
  approved_at     DATETIME     NULL,

  /*
   * When the television collected its session.
   *
   * A pairing is good for ONE sign-in. Without this, a device code that was
   * once approved could be replayed for as long as the row lived — and the
   * television has it in a config file, where it stays.
   */
  claimed_at      DATETIME     NULL,

  /*
   * How many times the wrong code has been typed at this pairing.
   *
   * Eight characters from a 32-character alphabet is 40 bits, which nobody
   * guesses — but this is not about guessing one code. It is about somebody
   * typing codes at the approval screen to find ANY pending pairing, which on
   * a busy Sunday might be several. Attempts are counted per pairing and the
   * screen throttles per person as well.
   */
  attempts        INT UNSIGNED NOT NULL DEFAULT 0,

  expires_at      DATETIME     NOT NULL,
  created_at      DATETIME     NOT NULL,

  PRIMARY KEY (id),

  /*
   * The user code is unique among LIVE pairings only — which MySQL cannot
   * express, so it is not unique here and the lookup orders by id DESC and
   * takes the newest. A unique index would mean a code that had expired
   * months ago blocking a fresh one, forever, for a value there are only a
   * million of.
   */
  KEY idx_user_code (user_code, expires_at),

  UNIQUE KEY uniq_device (device_hash),

  CONSTRAINT fk_pairing_user FOREIGN KEY (approved_by)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clearing out pairings nobody completed.
--
-- A pairing is a credential with a ten-minute life, and a table of dead ones
-- is a table of things that must never be honoured again. The runner creates
-- the row on its next tick if this install predates the job — see
-- Cron::ensureCoreJobs(), which exists because a job with no row is never due
-- and does nothing, silently, forever.
INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('tv.pairings.purge', 3600, NOW(), 1);
