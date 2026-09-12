-- Keys for the read API.
--
-- A KEY IS A PASSWORD A MACHINE KEEPS IN A CONFIG FILE
--
-- Which settles every column here:
--
--   ONLY THE HASH IS STORED. A key that can be read back out of the database is
--   one that leaks with a backup, a support session, or a careless export. The
--   presented key is hashed and matched; nothing on this site can tell you what
--   a key is after the moment it was made.
--
--   SHOWN ONCE. The plaintext exists for exactly one page render. There is no
--   "show it again", because there is nothing to show — and a screen offering
--   one would mean the hash above was a pretence.
--
--   A PREFIX IS KEPT so the list means something. Without it a screen of keys
--   is a screen of identical rows, and somebody revoking one has to guess. It
--   is the first eight characters, which identifies a key among a handful
--   without being enough to use.
--
--   THE ROW SURVIVES REVOCATION. `revoked_at` rather than a delete, so "who
--   made this, when, and when was it last used" outlives the key itself. That
--   history is the entire value of an audit trail after an incident, and
--   deleting the row destroys it at precisely the moment somebody needs it.
--
-- SCOPES HAVE NO HIERARCHY. Held as a JSON list and compared by exact
-- membership — see Portal\Api\Scope, at length. events:read must never imply
-- events:registrations, because the difference between them is a list of phone
-- numbers.

CREATE TABLE IF NOT EXISTS {api_keys} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- What it is for, in somebody's own words. The only way to tell two keys
  -- apart when deciding which to revoke.
  name          VARCHAR(190) NOT NULL,

  /*
   * SHA-256 of the key. Not password_hash(): this is a 32-byte random value
   * rather than something a person chose, so there is nothing to brute-force
   * and no reason to make every API request pay for a slow KDF. A key with 256
   * bits of entropy is not guessed by grinding hashes.
   */
  key_hash      CHAR(64)     NOT NULL,

  -- The first characters, for the list. See the note above.
  key_prefix    VARCHAR(12)  NOT NULL,

  -- A JSON list of scope strings. Exact membership; no hierarchy.
  scopes        VARCHAR(500) NOT NULL DEFAULT '[]',

  created_by    VARCHAR(190) NULL,
  created_at    DATETIME     NOT NULL,

  -- Answers "is this still in use", which is the question before revoking one
  -- somebody has forgotten about.
  last_used_at  DATETIME     NULL,
  uses          INT UNSIGNED NOT NULL DEFAULT 0,

  -- Soft, and the row stays. See the note above.
  revoked_at    DATETIME     NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_key_hash (key_hash),
  KEY idx_live (revoked_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('manage_api_keys', 'Create and revoke keys for the read API');
