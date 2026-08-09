-- Letting somebody fix their own comment.
--
-- One column. `updated_at` already exists and moves whenever a moderator
-- changes a status, so it cannot answer "did the author change the words" —
-- and that is the question a reader needs answered, because a comment that has
-- been rewritten under three replies is a different thing from the one they
-- replied to.
--
-- Null means never edited, which is what every existing comment is.
ALTER TABLE {comments}
  ADD COLUMN edited_at DATETIME NULL DEFAULT NULL;
