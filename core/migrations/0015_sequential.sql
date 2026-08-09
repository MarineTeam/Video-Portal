-- Watching a course in order.
--
-- One column, and the design is almost entirely in what is NOT here.
--
-- No table of unlocks. Whether an episode is watchable is derived from
-- {watch_progress}, which already records what somebody finished — a separate
-- unlock ledger would be a second copy of that fact, and the two would
-- disagree the first time progress was cleared or a video was reordered.
--
-- No per-viewer state at all, in fact. The question "may this person watch
-- this yet" is answered from the running order and their completed videos,
-- both of which are already stored, so there is nothing to keep in step and
-- nothing to migrate when an editor rearranges a series.
--
-- Off by default, and opt-in per series rather than site-wide: most series are
-- a collection of sermons that people dip into, and locking those would be
-- actively wrong. A course is the exception, and the person who made one knows
-- which it is.
ALTER TABLE {series}
  ADD COLUMN sequential TINYINT(1) NOT NULL DEFAULT 0;
