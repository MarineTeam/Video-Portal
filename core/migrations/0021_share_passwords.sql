-- A passphrase on a share link.
--
-- Independent of access_mode, and that is the whole point. An `account` link
-- already proves who you are and a `gate` link already proves you hold the
-- address it was sent to; a passphrase is the third, orthogonal thing --
-- something you KNOW -- so a link can be "whoever holds this AND knows the
-- word", which is what handing one link to a group needs.
--
-- NULL means no passphrase, which is every share that already exists. The
-- column is nullable rather than defaulted to '' so "no passphrase" and "a
-- passphrase that hashes to the empty string" can never be confused.
--
-- 255 because that is the width password_hash() documents as safe for any
-- algorithm it may return, including ones PHP has not shipped yet. A hash
-- stored in a column too narrow for it truncates silently, and the link then
-- refuses a passphrase that is correct.
--
-- This column must never leave the server. Share::fromRow() reduces it to a
-- boolean the moment it is read, nothing renders it, and it is never put in
-- the recipient's email -- which says only that a passphrase is needed.
--
-- ---------------------------------------------------------------------------
-- WRITTEN TO BE RE-RUNNABLE, unlike every other ALTER in this directory.
--
-- Deployment here is `git pull` on a live host and the pending migrations run
-- on whichever request arrives next. That request can be killed by the host's
-- execution limit at any point -- including between the ALTER succeeding and
-- the {schema_version} row being written. The next request then re-runs this
-- file, and a plain `ADD COLUMN` fails with "Duplicate column name" every time
-- from then on. On a host with no shell there is no way to repair that.
--
-- The smoke suite has a check for exactly this recovery. It has been passing
-- only because the newest migration happened to be a DELETE, which re-runs
-- harmlessly -- so the property it claims to prove has never actually held for
-- an ALTER. That is recorded rather than papered over.
--
-- `ADD COLUMN IF NOT EXISTS` would be simpler but is MariaDB-only; MySQL 8
-- rejects it, and this ships to both. Hence the information_schema check and a
-- prepared statement, which both accept.
--
-- Note the REPLACE: the {shares} token expands to a BACKTICKED identifier,
-- which is what the ALTER needs and what information_schema must not see.

SET @needs_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{shares}', '`', '')
     AND COLUMN_NAME = 'password_hash'
);

SET @ddl := IF(
  @needs_column,
  CONCAT('ALTER TABLE ', '{shares}', ' ADD COLUMN password_hash VARCHAR(255) NULL AFTER access_mode'),
  'DO 0'
);

PREPARE add_share_password FROM @ddl;
EXECUTE add_share_password;
DEALLOCATE PREPARE add_share_password;
