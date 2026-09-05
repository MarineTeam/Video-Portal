-- Handing a slot over.
--
-- Somebody who accepted and then cannot make it asks the team to take it. The
-- first person to say "I'll take it" gets it, and the rota then shows who they
-- are covering for.
--
-- WHY THREE COLUMNS RATHER THAN A SEPARATE TABLE
--
-- A cover request is a STATE OF AN ASK, not a thing of its own: at most one can
-- be outstanding per assignment, and taking it changes who the assignment is
-- for. A {rota_cover_requests} table would mean two rows that have to agree
-- about who holds a slot — and the handover would be two writes, which is
-- exactly what cannot be made atomic without a transaction wrapped round every
-- reader.
--
-- As columns, the handover is ONE conditional UPDATE. That is the whole design:
--
--   UPDATE ... SET user_id = :taker, covering_for_user_id = :asker,
--                  cover_requested_at = NULL, cover_note = NULL
--    WHERE id = :id AND cover_requested_at IS NOT NULL AND user_id = :asker
--
-- Two people pressing the button in the same second both run that statement.
-- The first matches one row. The second matches NOTHING — the slot is no longer
-- open and is no longer held by the person who asked — so it reports an honest
-- refusal rather than silently overwriting the first, which is what a
-- read-then-write would do.
--
-- cover_note is CLEARED by the handover, deliberately. It was the previous
-- person's aside to the organiser — "sorry, my sister's wedding" — and carrying
-- it onto the new holder's row would attribute one person's words to another.
--
-- covering_for_user_id is ON DELETE SET NULL rather than CASCADE: an account
-- being removed must not delete the slot somebody is covering. The rota loses
-- the "covering for" line and keeps the person who is actually serving.
--
-- RE-RUNNABLE, per 0021.

SET @needs_requested := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{rota_assignments}', '`', '')
     AND COLUMN_NAME = 'cover_requested_at'
);

SET @ddl := IF(
  @needs_requested,
  CONCAT('ALTER TABLE ', '{rota_assignments}', ' ADD COLUMN cover_requested_at DATETIME NULL AFTER answered_at'),
  'DO 0'
);

PREPARE add_cover_requested FROM @ddl;
EXECUTE add_cover_requested;
DEALLOCATE PREPARE add_cover_requested;

SET @needs_note := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{rota_assignments}', '`', '')
     AND COLUMN_NAME = 'cover_note'
);

SET @ddl := IF(
  @needs_note,
  CONCAT('ALTER TABLE ', '{rota_assignments}', ' ADD COLUMN cover_note VARCHAR(300) NULL AFTER cover_requested_at'),
  'DO 0'
);

PREPARE add_cover_note FROM @ddl;
EXECUTE add_cover_note;
DEALLOCATE PREPARE add_cover_note;

SET @needs_covering := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{rota_assignments}', '`', '')
     AND COLUMN_NAME = 'covering_for_user_id'
);

SET @ddl := IF(
  @needs_covering,
  CONCAT('ALTER TABLE ', '{rota_assignments}',
         ' ADD COLUMN covering_for_user_id INT UNSIGNED NULL AFTER cover_note'),
  'DO 0'
);

PREPARE add_covering_for FROM @ddl;
EXECUTE add_covering_for;
DEALLOCATE PREPARE add_covering_for;

-- The constraint is added separately from the column, because a re-run that
-- was killed between the two would otherwise have a column and no key and no
-- way to notice.
SET @needs_fk := (
  SELECT COUNT(*) = 0
    FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{rota_assignments}', '`', '')
     AND CONSTRAINT_NAME = 'fk_rota_covering_for'
);

SET @ddl := IF(
  @needs_fk,
  CONCAT('ALTER TABLE ', '{rota_assignments}',
         ' ADD CONSTRAINT fk_rota_covering_for FOREIGN KEY (covering_for_user_id)',
         ' REFERENCES ', '{users}', ' (id) ON DELETE SET NULL'),
  'DO 0'
);

PREPARE add_covering_fk FROM @ddl;
EXECUTE add_covering_fk;
DEALLOCATE PREPARE add_covering_fk;

-- What the team's "slots going spare" list reads.
SET @needs_index := (
  SELECT COUNT(*) = 0
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{rota_assignments}', '`', '')
     AND INDEX_NAME = 'idx_cover_open'
);

SET @ddl := IF(
  @needs_index,
  CONCAT('ALTER TABLE ', '{rota_assignments}', ' ADD KEY idx_cover_open (cover_requested_at, team_id)'),
  'DO 0'
);

PREPARE add_cover_index FROM @ddl;
EXECUTE add_cover_index;
DEALLOCATE PREPARE add_cover_index;
