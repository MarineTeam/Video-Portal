-- Name the four ways the two sign-in checks combine.
--
-- They were previously two implicit facts -- whether each gate was configured,
-- plus an `all`/`either` combiner -- which covered the same four behaviours and
-- gave a misspelling nothing to be misspelled AGAINST. The rule that matters is
-- that an unrecognised value resolves to the strictest mode, and a setting with
-- only two legal values and no name for the others cannot express it.
--
--   BOTH          organisation AND the address list
--   ORGANIZATION  membership only; the list is ignored
--   ALLOWLIST     the list only; membership is ignored
--   EITHER        one of them is enough
--
-- There is deliberately no value meaning "neither". The way to have no gate is
-- to configure no gate, which is what every fresh install already is.
--
-- WHAT THIS MIGRATION DOES NOT DO: it does not turn anything on. A site that
-- has configured no gate still refuses nobody, whatever mode is stored here --
-- "configured" and "counted by the mode" are separate facts in Guard, and the
-- first is what a fresh install lacks. Writing BOTH into a site that has never
-- set up an organisation would otherwise refuse every visitor on the next
-- request, on hosting with no shell, from a screen only an administrator can
-- reach.
--
-- INSERT IGNORE, then a conditional UPDATE: the row may already exist on a
-- site that has been through this migration once and had the request killed
-- before {schema_version} was written. See 0021.

INSERT IGNORE INTO {settings} (`key`, `value`, updated_at)
VALUES ('signin_mode', 'BOTH', NOW());

-- Carry across whichever combiner was chosen before. `either` was the only
-- non-default value it could hold, and it means the same thing under the new
-- name; anything else was `all`, which is BOTH.
UPDATE {settings} SET `value` = 'EITHER', updated_at = NOW()
 WHERE `key` = 'signin_mode'
   AND EXISTS (
     SELECT 1 FROM (SELECT `value` FROM {settings} WHERE `key` = 'signin_gate_mode') AS old
      WHERE old.`value` = 'either'
   );

DELETE FROM {settings} WHERE `key` = 'signin_gate_mode';
