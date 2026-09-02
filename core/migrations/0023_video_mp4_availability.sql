-- What the provider says about this video's downloadable MP4, cached.
--
-- WHY CACHE IT AT ALL
--
-- Resolving a download means asking bunny.net which renditions exist, because
-- guessing a height produces a URL that 404s and the 404 is indistinguishable
-- from a rejected token. That is one API call, which is a fair price for a
-- podcast enclosure fetched occasionally. It is not a fair price for the
-- downloads feature this precedes, where every video card on a listing would
-- have to ask whether it can be downloaded -- fifty outbound calls to render
-- one page, on shared hosting, inside a visitor's page view.
--
-- The sync job already fetches exactly these two facts on every pass and threw
-- them away. This stores them.
--
-- WHY mp4_checked_at IS THE IMPORTANT COLUMN
--
-- Every existing row gets has_mp4 = 0 from this migration, and that value is
-- NOT an answer -- nobody has asked yet. Reading it as one would tell every
-- site upgrading to this release that none of their videos has an MP4, in a
-- sentence naming a dashboard setting that is in fact switched on.
--
-- So NULL means "never asked" and sends the caller to the provider. Only a
-- non-NULL timestamp licenses trusting the other two columns. This is the same
-- distinction the sync bug got wrong in the other direction -- treating "not in
-- the page we fetched" as "gone from the provider" -- and it is written into
-- the schema here rather than left to each caller to remember.
--
-- WHY A STRING RATHER THAN A JOIN TABLE
--
-- The heights are a provider-owned fact, refreshed wholesale on every sync,
-- and nothing will ever query BY them -- the question is always "what does this
-- one video have". A join table would mean a delete-and-reinsert per video per
-- sync pass to store three integers.
--
-- Stored ascending and comma-separated, the shape parseResolutions() already
-- produces, so it round-trips without a second format to keep in step.
--
-- RE-RUNNABLE, per 0021. Deployment is `git pull` and pending migrations run on
-- whichever request arrives next, which the host may kill between the ALTER
-- succeeding and the {schema_version} row being written. `ADD COLUMN IF NOT
-- EXISTS` is MariaDB-only and MySQL 8 rejects it, hence information_schema and
-- a prepared statement, which both accept.

SET @needs_columns := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{videos}', '`', '')
     AND COLUMN_NAME = 'mp4_checked_at'
);

SET @ddl := IF(
  @needs_columns,
  CONCAT(
    'ALTER TABLE ', '{videos}',
    ' ADD COLUMN has_mp4 TINYINT(1) NOT NULL DEFAULT 0 AFTER encode_progress,',
    ' ADD COLUMN mp4_heights VARCHAR(191) NOT NULL DEFAULT '''' AFTER has_mp4,',
    ' ADD COLUMN mp4_checked_at DATETIME NULL AFTER mp4_heights'
  ),
  'DO 0'
);

PREPARE add_mp4_columns FROM @ddl;
EXECUTE add_mp4_columns;
DEALLOCATE PREPARE add_mp4_columns;
