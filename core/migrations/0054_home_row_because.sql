-- A "Because you watched" row for the homepage builder.
--
-- source_type is an ENUM, so a new kind of row is a schema change. Widened
-- here rather than converted to VARCHAR: the ENUM is what refuses a row the
-- renderer does not know how to draw, and HomeRow::sanitizeSource() refusing it
-- in PHP as well is a second line, not a replacement for this one.
--
-- RE-RUNNABLE, for the reason 0021 records at length: deployment is `git pull`,
-- the request running migrations can be killed between the ALTER and the
-- {schema_version} write, and a MODIFY that fails the second time fails for
-- ever on a host with no shell. Checked by reading the column's current type,
-- so a second run is a no-op rather than a second MODIFY.
--
-- The existing values are listed in their existing ORDER. MySQL stores an ENUM
-- as an index into the list, so reordering them in a MODIFY would silently
-- relabel every row already saved — a "series" row becoming a "category" one
-- with nothing in any log to say so. The new value goes on the END.

SET @needs_because := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{home_rows}', '`', '')
     AND COLUMN_NAME = 'source_type'
     AND COLUMN_TYPE LIKE '%''because''%'
);

SET @ddl := IF(
  @needs_because,
  CONCAT(
    'ALTER TABLE ', '{home_rows}',
    ' MODIFY source_type ENUM(''latest'',''featured'',''category'',''series'',''playlist'',''continue'',''because'')',
    ' NOT NULL DEFAULT ''latest'''
  ),
  'DO 0'
);

PREPARE widen_home_row_source FROM @ddl;
EXECUTE widen_home_row_source;
DEALLOCATE PREPARE widen_home_row_source;
