-- Publishing to the podcast becomes a choice, per video.
--
-- WHY IT IS OPT-IN
--
-- It is the one publication in this product that cannot be taken back. Every
-- other withdrawal — members-only, hiding, scheduling out — takes effect on the
-- next request. A podcast app that has fetched an episode keeps it, and
-- un-listing the video from the feed stops only NEW downloads. So an episode
-- goes out only when somebody ticks the box. See Portal\Content\PodcastEpisode.
--
-- NOT BACKFILLED, AND THAT IS A BEHAVIOUR CHANGE ON AN EXISTING SITE
--
-- Until this migration every public video was a podcast episode with nobody
-- having opted in. Backfilling in_podcast = 1 for those would carry that
-- un-chosen state forward and make the new rule meaningless for every video
-- that already exists — which is most of them. So nothing is backfilled, and
-- the podcast feed of a site that already had one is EMPTY after this runs
-- until an editor ticks the episodes they actually want published. The same
-- call Marine-team made for the same reason, and it is said on the settings
-- screen rather than only here.
--
-- The RSS feed, which carries links rather than downloadable files, is not
-- affected.
--
-- The column is the INTENT. Whether a ticked video is in the feed right now is
-- decided at request time by the ordinary visibility rules, so a ticked video
-- that becomes members-only leaves the feed and comes back by itself.
--
-- Re-runnable, for the reason 0021 records: deployment is `git pull` and the
-- request running migrations can be killed between the ALTER and the
-- {schema_version} write.

SET @needs_in_podcast := (
  SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = REPLACE('{videos}', '`', '')
     AND COLUMN_NAME = 'in_podcast'
);

SET @ddl := IF(
  @needs_in_podcast,
  CONCAT('ALTER TABLE ', '{videos}', ' ADD COLUMN in_podcast TINYINT(1) NOT NULL DEFAULT 0 AFTER featured'),
  'DO 0'
);

PREPARE add_in_podcast FROM @ddl;
EXECUTE add_in_podcast;
DEALLOCATE PREPARE add_in_podcast;
