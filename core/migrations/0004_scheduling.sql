-- Scheduled publishing, an end date, and premieres.
--
-- published_at already existed and was already respected by every listing —
-- what was missing was any way to set it to a future date, and any way to say
-- when something should stop being shown. So this adds the other half of a
-- window that was only ever open at one end.
--
-- Deliberately NO cron job drives any of this. The schedule is evaluated in the
-- query, every time, which is the only design that is correct on the hosts this
-- product targets: shared hosting cron is optional and the built-in pseudo-cron
-- only fires on traffic, so a quiet site would publish things late or never.
-- Query-time evaluation cannot be late.

ALTER TABLE {videos}
  -- When to stop showing it. NULL means never, which is what everything
  -- existing means and what almost everything will always mean.
  ADD COLUMN unpublish_at DATETIME NULL AFTER published_at,

  -- Announce it before it plays.
  --
  -- A scheduled video is normally invisible until its date. A premiere is
  -- listed early, with the date shown, and still refuses to play — which is
  -- the difference between "we have not published this" and "this is coming on
  -- Sunday". Without the flag the two would need the same row to mean both.
  ADD COLUMN premiere TINYINT(1) NOT NULL DEFAULT 0 AFTER unpublish_at;

-- The listing filter now compares against unpublish_at on every query, so it
-- is worth an index rather than a scan.
ALTER TABLE {videos}
  ADD KEY idx_unpublish (unpublish_at);
