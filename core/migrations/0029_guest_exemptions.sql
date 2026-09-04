-- Addresses excused from the organisation check, one at a time.
--
-- The case it exists for: a site requires membership of an organisation, and
-- one person legitimately has no account in it — a visiting speaker, somebody's
-- spouse, a contractor. Without this the only options are adding them to the
-- organisation, which is somebody else's system and often not yours to change,
-- or switching the whole site to EITHER, which loosens it for everybody to
-- admit one person.
--
-- IT WAIVES THE ORGANISATION CHECK AND NOTHING ELSE.
--
-- Not the allowlist, not the approval flag, not email verification. A waiver
-- that skipped everything would be an admin backdoor wearing the word "guest",
-- and the difference is invisible from the screen that grants it — so it is
-- enforced in one function with a test that fails when it starts skipping more
-- than it should.
--
-- The practical effect is that a guest still has to be on the address list
-- under BOTH, and still has to be approved like anybody else. What they are
-- excused is the one check they cannot possibly satisfy.
--
-- OFF BY DEFAULT, with its own switch. An exemption list that is live the
-- moment a row exists would mean adding somebody "to see how it works" quietly
-- opening a door — and this is a door whose whole purpose is bypassing a check.
--
-- No expiry column, deliberately. A guest whose visit has ended is removed, and
-- a date that silently stops working is a person locked out with nothing on any
-- screen saying why. If timed access is wanted later it belongs on the screen
-- as a visible state, not as an invisible one.

CREATE TABLE IF NOT EXISTS {guest_exemptions} (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Normalised by Str::normalizeEmail on write, matching how the check reads
  -- it. Two spellings of one address must not become two rows, one of which
  -- somebody later removes believing they closed the door.
  email       VARCHAR(191) NOT NULL,

  -- Who this is and why they are excused. Free text, and the screen asks for
  -- it: an exemption nobody can account for later is one nobody dares remove.
  note        VARCHAR(500) NULL,

  added_by    VARCHAR(191) NULL,
  created_at  DATETIME NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
