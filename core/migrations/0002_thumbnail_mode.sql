-- Members-only thumbnail placeholder.
--
-- A video can be listed publicly while still requiring an account to watch.
-- That is the normal arrangement here, not an oversight: /watch is guarded and
-- the library is not, so anyone can browse the catalogue and only an approved
-- account can play anything. This adds the option to withhold the ARTWORK as
-- well, replacing it with a placeholder for anyone who cannot play the video.
--
-- Three values rather than a boolean, because inheritance needs a way to say
-- "I have no opinion" that is distinct from "definitely off":
--
--   default  defer upward — a video to its category chain, a category to its
--            parent, and finally to the site setting
--   public   always show the real thumbnail
--   members  show a placeholder to anyone who cannot watch this
--
-- Deliberately the same shape as watermark_mode. An admin who has learned one
-- inheritance rule in this application should not have to learn a second.

ALTER TABLE {videos}
  ADD COLUMN thumbnail_mode ENUM('default','public','members')
    NOT NULL DEFAULT 'default' AFTER watermark_mode;

ALTER TABLE {categories}
  ADD COLUMN thumbnail_mode ENUM('default','public','members')
    NOT NULL DEFAULT 'default' AFTER hidden;
