-- What language a sermon is IN.
--
-- WHICH IS NOT WHAT LANGUAGE THE INTERFACE IS IN
--
-- The whole reason these are columns rather than a setting. Both mixed cases
-- are ordinary rather than exotic: a congregation with Spanish-speaking members
-- and an English-speaking preacher, and the Spanish service at the same church
-- listed on the same site. A single "language" setting doing both jobs would
-- mean switching the interface to Spanish filtered the library to Spanish
-- sermons — hiding content from the people most likely to be looking for it,
-- and doing it silently.
--
-- See Portal\I18n\ContentLanguage, where the precedence lives: the video, then
-- its series, then the site's own setting. Nearest wins, and NULL means "ask
-- the next one up" rather than "none" — which is why both columns are nullable
-- and neither has a default.
--
-- UNKNOWN IS A REAL ANSWER. A library imported from a provider has no language
-- on anything, and backfilling it all to the site's language would assert
-- something nobody checked — after which a filter built on it would
-- confidently exclude the wrong things. So this migration writes NO DATA. It
-- adds two columns and leaves them empty, and every existing row keeps saying
-- "nobody has said", which is the truth.
--
-- Re-runnable, for the reason 0021 records at length: deployment is `git pull`
-- and the request running the migrations can be killed between the ALTER and
-- the {schema_version} write, after which a plain ADD COLUMN fails for ever on
-- a host with no shell.
--
-- VARCHAR(16) rather than CHAR(2): a BCP 47 tag is `pt-BR` as often as `pt`,
-- and a two-character column would silently truncate the region — turning
-- Brazilian Portuguese into Portuguese on the way into the database, where
-- nobody would ever see it happen.

SET @needs_video_language := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{videos}', '`', '')
     AND COLUMN_NAME = 'language'
);

SET @ddl := IF(
  @needs_video_language,
  CONCAT('ALTER TABLE ', '{videos}', ' ADD COLUMN language VARCHAR(16) NULL AFTER description'),
  'DO 0'
);

PREPARE add_video_language FROM @ddl;
EXECUTE add_video_language;
DEALLOCATE PREPARE add_video_language;

SET @needs_series_language := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{series}', '`', '')
     AND COLUMN_NAME = 'language'
);

SET @ddl := IF(
  @needs_series_language,
  CONCAT('ALTER TABLE ', '{series}', ' ADD COLUMN language VARCHAR(16) NULL AFTER description'),
  'DO 0'
);

PREPARE add_series_language FROM @ddl;
EXECUTE add_series_language;
DEALLOCATE PREPARE add_series_language;

-- No index on either.
--
-- Deliberate, and worth writing down so nobody adds one as an obvious
-- improvement. Language is a very low-cardinality column — a site has one
-- language, or two — so an index on it selects most of the table and MySQL
-- would ignore it anyway. What makes a language filter fast is the existing
-- publication and date keys the listing query already uses; this is an extra
-- term inside that, not a lookup of its own.
