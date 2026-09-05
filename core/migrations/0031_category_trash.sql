-- A trash for categories, because deleting one destroyed everything under it.
--
-- WHAT WAS WRONG
--
-- Categories had no trash at all. `delete()` removed the row, and
-- fk_category_parent is ON DELETE CASCADE — so deleting "Sermons" permanently
-- destroyed "Sermons / 2019", "Sermons / 2020" and everything beneath them,
-- with no undo on a host with no shell and no database access.
--
-- The confirmation said: "Delete this category? Videos in it are kept."
--
-- That sentence is TRUE, and it is the reassuring half. Videos really do
-- survive — they lose the association and become uncategorised. The
-- subcategory tree does not survive, and nothing on the screen said so. So the
-- one person who read the warning carefully was told the thing they were about
-- to lose was safe.
--
-- WHAT REPLACES IT
--
-- The same soft delete videos have had since Phase 1, with the rule the spec
-- names: trashing a category sets ITS OWN flag and touches nothing inside it.
-- Children keep their parent id and stay reachable by direct URL; they simply
-- leave the browse tree along with their parent.
--
-- The cascade stays on the foreign key, and that is deliberate rather than an
-- oversight: it now only fires on a PERMANENT delete, which the repository
-- refuses while the category still has children. A constraint that cannot be
-- reached destructively is better than one removed, because removing it would
-- leave rows pointing at a parent that no longer exists the first time
-- somebody deletes around it.
--
-- {video_categories} also cascades, and equally never fires now: the
-- associations survive a trashing, which is what makes restore mean anything.
--
-- RE-RUNNABLE, per 0021: deployment is `git pull` and the migration runs on
-- whichever request arrives next, which the host may kill between the ALTER
-- and the {schema_version} write.

SET @needs_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{categories}', '`', '')
     AND COLUMN_NAME = 'deleted_at'
);

SET @ddl := IF(
  @needs_column,
  CONCAT('ALTER TABLE ', '{categories}', ' ADD COLUMN deleted_at DATETIME NULL AFTER hidden'),
  'DO 0'
);

PREPARE add_category_deleted_at FROM @ddl;
EXECUTE add_category_deleted_at;
DEALLOCATE PREPARE add_category_deleted_at;

-- The index the browse tree leans on: every listing now asks "not trashed"
-- alongside the parent it was already asking for.
SET @needs_index := (
  SELECT COUNT(*) = 0
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{categories}', '`', '')
     AND INDEX_NAME = 'idx_category_live'
);

SET @ddl := IF(
  @needs_index,
  CONCAT('ALTER TABLE ', '{categories}', ' ADD KEY idx_category_live (deleted_at, parent_id, position)'),
  'DO 0'
);

PREPARE add_category_index FROM @ddl;
EXECUTE add_category_index;
DEALLOCATE PREPARE add_category_index;
