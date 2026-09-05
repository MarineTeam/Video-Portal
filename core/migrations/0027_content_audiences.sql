-- Content restricted to named groups.
--
-- Until now viewing had two settings: published, and members-only. "Everyone
-- who has been approved" was the finest grain available, so a course for the
-- youth group or a recording for elders had nowhere to live except a share
-- link per person.
--
-- WHAT A GROUP IS HERE
--
-- {permission_groups}, reused rather than a third kind of grouping invented
-- beside it. This application already has two -- permission groups, which hold
-- users and may carry capabilities, and viewer groups, which hold email
-- addresses for share recipients. A third would be one more place to look when
-- somebody asks why a person cannot see something.
--
-- A permission group with no capabilities is simply a named set of people,
-- which is exactly what an audience is. The screen says so.
--
-- PRECEDENCE
--
-- The presence of ANY row for a scope means that scope is restricted. The
-- nearest scope with an opinion decides, which is the same rule watermark,
-- thumbnail and download modes already use -- an administrator who has learned
-- one inheritance rule here should not have to learn a fourth.
--
--   a row on the video   -> those groups decide
--   else a row on its series -> those groups decide
--   else                 -> unrestricted
--
-- CATEGORIES ARE DELIBERATELY NOT A LEVEL YET, and this is a scope decision
-- rather than an oversight. Categories nest arbitrarily, so a category-level
-- restriction means walking an ancestor chain inside the listing query --
-- which is where a leak would hide, because the failure is silent and shows
-- content to somebody who should not have it. It deserves its own pass with
-- its own tests rather than being folded in here.
--
-- ON DELETE CASCADE both ways: a deleted group must not leave a restriction
-- nothing can satisfy, and a deleted video must not leave a row pointing at
-- nothing. There is no scope_type foreign key to enforce, so the sweep for a
-- deleted series happens in the repository.

CREATE TABLE IF NOT EXISTS {content_audiences} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  scope_type  ENUM('series','video') NOT NULL,
  scope_id    INT UNSIGNED NOT NULL,
  group_id    INT UNSIGNED NOT NULL,

  created_at  DATETIME NOT NULL,

  PRIMARY KEY (id),

  -- One row per group per scope. Restricting to the same group twice is not a
  -- different restriction, and a duplicate would make the "is this restricted"
  -- test count rather than exist.
  UNIQUE KEY uniq_scope_group (scope_type, scope_id, group_id),

  -- The index the listing query leans on: it asks "does this scope have any
  -- rows" once per row of a page.
  KEY idx_scope (scope_type, scope_id),

  CONSTRAINT fk_audience_group FOREIGN KEY (group_id)
    REFERENCES {permission_groups} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
