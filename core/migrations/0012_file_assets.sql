-- Files attached to a video: notes, slides, a handout.
--
-- The row records where a file went and what it was called; the file itself
-- lives under storage/, outside the document root. That split is the whole
-- access-control story. A file inside public/ is reachable by URL no matter
-- what this table says, so a members-only video's notes would be public to
-- anybody who guessed the path — and "unguessable filename" is obscurity, not
-- permission. Serving through PHP costs a little speed and buys the ability to
-- refuse.

CREATE TABLE IF NOT EXISTS {file_assets} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- What it is attached to. Nullable so a standalone library of files can be
  -- added later without a migration; nothing offers that yet.
  video_id      INT UNSIGNED NULL,

  -- Where it is, relative to the storage root — "assets/2026/03/<random>.pdf".
  -- Random, never derived from what was uploaded: see AssetPolicy.
  path          VARCHAR(255) NOT NULL,

  -- What to call it on the way back out. The name a person recognises, kept
  -- apart from the name on disk so neither has to compromise for the other.
  original_name VARCHAR(190) NOT NULL,

  -- Served from an allowlist keyed on extension, not from what the browser
  -- claimed on upload. Stored so the download does not have to re-derive it.
  content_type  VARCHAR(120) NOT NULL,

  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,

  -- An address rather than a user id: the account may go and the record of who
  -- attached a file should outlive it.
  uploaded_by   VARCHAR(254) NOT NULL DEFAULT '',

  position      INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_video (video_id, position, id),
  UNIQUE KEY uniq_path (path),
  CONSTRAINT fk_asset_video FOREIGN KEY (video_id)
    REFERENCES {videos} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
