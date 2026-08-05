-- Announcements: a banner across the top of the site.
--
-- The thing a site owner reaches for when something is happening — a service
-- moved, the stream starts at seven, the archive is down for an hour. Short
-- lived by nature, which is why the date window is here rather than being
-- something somebody has to remember to switch off.
--
-- Evaluated in the query like every other schedule in this codebase, for the
-- same reason: cron is optional on these hosts, and a banner that stays up for
-- three days after the event because nothing triggered a job is worse than no
-- banner.

CREATE TABLE IF NOT EXISTS {announcements} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  title       VARCHAR(190) NOT NULL DEFAULT '',
  body        TEXT         NOT NULL,

  -- How loudly to say it. Purely presentational — nothing branches on this
  -- except the stylesheet.
  level       ENUM('info','success','warning') NOT NULL DEFAULT 'info',

  --   everyone  anybody who loads a page, signed in or not
  --   members   approved accounts only
  --   admins    people who can see the admin area
  --
  -- Not a security boundary. It decides who is BOTHERED by a message, and the
  -- messages themselves are not secrets — but "members" exists because telling
  -- signed-out visitors about something only members can attend is noise, and
  -- "admins" because a note about a migration is nobody else's business.
  audience    ENUM('everyone','members','admins') NOT NULL DEFAULT 'everyone',

  -- The window. Both nullable: no start means "now", no end means "until
  -- somebody switches it off".
  starts_at   DATETIME     NULL,
  ends_at     DATETIME     NULL,

  -- Can a reader make it go away? A notice about maintenance should be
  -- dismissible; one saying the site is read-only should not.
  dismissible TINYINT(1)   NOT NULL DEFAULT 1,

  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  position    INT          NOT NULL DEFAULT 0,

  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,

  PRIMARY KEY (id),
  -- Every page load asks "what is showing right now", so the window is
  -- indexed rather than scanned.
  KEY idx_showing (is_active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
