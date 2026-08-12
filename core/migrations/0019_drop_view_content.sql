-- Remove `view_content`, a capability that was enforced nowhere.
--
-- It was declared, described, carried by three of the four default roles, and
-- offered on the permissions screen as a grantable, revocable control. The only
-- mention of it anywhere in the application outside its own declaration was a
-- line in canSeeAdmin() that explicitly SKIPPED it.
--
-- Watching has always been gated on `users.authorized` — the approval flag an
-- administrator sets on the People screen — together with the content's own
-- visibility. An unapproved account fails every capability check because
-- Capabilities::can() refuses an unauthorized user outright, not because of
-- this capability. So granting or revoking it changed nothing in either
-- direction.
--
-- Removed rather than enforced: making it real would have meant two mechanisms
-- deciding one thing, and the failure mode of that is an approved viewer who
-- cannot watch, which arrives as "the site is broken".
--
-- This migration only cleans up what earlier installs stored. A fresh install
-- never creates the row, because it is gone from Capability::all().

-- Order matters: the join rows and grants reference the capability, and the
-- foreign keys are ON DELETE CASCADE in some installs and absent in others
-- depending on when the schema was created. Deleting the dependents explicitly
-- makes the outcome the same either way rather than depending on that history.

DELETE rc FROM {role_capabilities} rc
  JOIN {capabilities} c ON c.id = rc.capability_id
 WHERE c.slug = 'view_content';

DELETE gc FROM {group_capabilities} gc
  JOIN {capabilities} c ON c.id = gc.capability_id
 WHERE c.slug = 'view_content';

-- Any individually issued grant of it. These were as decorative as the role
-- rows, so nobody loses access they were actually using.
DELETE g FROM {grants} g
  JOIN {capabilities} c ON c.id = g.capability_id
 WHERE c.slug = 'view_content';

DELETE FROM {capabilities} WHERE slug = 'view_content';
