-- Sharing, for people who are not administrators.
--
-- The seeder writes every capability in Capability::all() at INSTALL, so a
-- fresh site gets this one for free. An existing site never re-runs the
-- seeder, so without this row the capability would exist in PHP, be checkable,
-- always answer false, and never appear on the permissions screen -- the exact
-- shape of the defect that left scoped grants stored and enforced nowhere for
-- five phases.
--
-- INSERT IGNORE against the unique slug, so this is safe to re-run: the
-- migration a killed request re-applies must not fail on the second attempt.
-- See 0021 for why that matters on these hosts.
--
-- Granted to NOBODY here, deliberately. It is not added to the viewer role,
-- because "everyone who can watch can also hand out links" is a policy
-- decision for whoever runs the site, not a default this migration should make
-- on their behalf. Administrators already have it, since the admin role
-- short-circuits every check rather than holding capabilities explicitly.

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('share_content', 'Share a video they can watch, and revoke their own links');
