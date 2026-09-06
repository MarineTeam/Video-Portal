-- A way to cancel without an account.
--
-- Signing up needs no account, deliberately: the people a church most wants at
-- an event are the ones who never made one. That leaves a gap the first version
-- of this section had — somebody without an account could get on the list and
-- had no way off it, so the organiser's list slowly fills with people who told
-- somebody in person and were never removed.
--
-- The token in the link IS the authority, exactly as it is for unsubscribing.
-- Nothing else about the row is guessable from it, and it lets one person
-- cancel one place: 22 characters of base64url randomness, the same shape share
-- ids and subscription tokens use.
--
-- NOT an email address in a URL. "?email=…" would let anybody cancel anybody
-- else's place by typing, and it would put an address in a server log.
--
-- RE-RUNNABLE, per 0021.

SET @needs_token := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{event_signups}', '`', '')
     AND COLUMN_NAME = 'token'
);

SET @ddl := IF(
  @needs_token,
  CONCAT('ALTER TABLE ', '{event_signups}', ' ADD COLUMN token VARCHAR(64) NULL AFTER state'),
  'DO 0'
);

PREPARE add_signup_token FROM @ddl;
EXECUTE add_signup_token;
DEALLOCATE PREPARE add_signup_token;

SET @needs_index := (
  SELECT COUNT(*) = 0
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{event_signups}', '`', '')
     AND INDEX_NAME = 'uniq_signup_token'
);

SET @ddl := IF(
  @needs_index,
  CONCAT('ALTER TABLE ', '{event_signups}', ' ADD UNIQUE KEY uniq_signup_token (token)'),
  'DO 0'
);

PREPARE add_signup_token_index FROM @ddl;
EXECUTE add_signup_token_index;
DEALLOCATE PREPARE add_signup_token_index;
