-- Somewhere to keep the thumbnail of a video this site does not host.
--
-- WHY NOT thumbnail_file
--
-- `{videos}.thumbnail_file` holds the provider's own FILENAME, and its comment
-- in 0001 says so: the signed CDN URL is built from it at render time, with the
-- pull-zone key. An imported YouTube or Vimeo video has no such filename and
-- nothing to sign it with — its thumbnail is a complete, public URL at
-- i.ytimg.com or vimeocdn.com.
--
-- Putting one in the other column would work for exactly as long as nobody
-- looked, and then break in the worst available way: the signer would prepend a
-- pull zone to an absolute URL and produce an address that 404s at the CDN,
-- which this codebase already knows is indistinguishable from a rejected token.
-- So it is a separate column that means a separate thing.
--
-- WHY NOT DERIVE IT
--
-- YouTube's thumbnail is derivable from the id — i.ytimg.com/vi/{id}/hqdefault.jpg
-- always exists. Vimeo's is not: it lives on a hashed vimeocdn.com path that
-- only the oEmbed response knows. One of the two has to be stored, so both are,
-- because a rule that applies to half the imported videos is a rule somebody
-- gets wrong.
--
-- The members-only rule is unaffected and worth restating: this decides what
-- the thumbnail IS, not who is shown it. Whether this site hands it out is
-- decided where every other thumbnail is decided, in VideoPresenter — and for
-- an imported video the honest note is that withholding it here does not make
-- it secret, because it is already public at the source.
--
-- RE-RUNNABLE, per 0021: deployment is `git pull` and the migration runs on
-- whichever request arrives next, which the host may kill part way through.

SET @needs_column := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{videos}', '`', '')
     AND COLUMN_NAME = 'external_thumbnail_url'
);

SET @ddl := IF(
  @needs_column,
  CONCAT('ALTER TABLE ', '{videos}', ' ADD COLUMN external_thumbnail_url VARCHAR(500) NULL AFTER thumbnail_file'),
  'DO 0'
);

PREPARE add_external_thumbnail FROM @ddl;
EXECUTE add_external_thumbnail;
DEALLOCATE PREPARE add_external_thumbnail;

-- The lookup the importer makes before creating a row, so the same video
-- pasted twice is found rather than duplicated. Not UNIQUE: a permanently
-- deleted row and a re-import of the same link is a legitimate sequence, and a
-- unique key would refuse it for as long as the old row sat in the trash.
SET @needs_index := (
  SELECT COUNT(*) = 0
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{videos}', '`', '')
     AND INDEX_NAME = 'idx_provider_identity'
);

SET @ddl := IF(
  @needs_index,
  CONCAT('ALTER TABLE ', '{videos}', ' ADD KEY idx_provider_identity (provider, provider_id)'),
  'DO 0'
);

PREPARE add_provider_identity FROM @ddl;
EXECUTE add_provider_identity;
DEALLOCATE PREPARE add_provider_identity;
