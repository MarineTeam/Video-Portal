-- Small groups.
--
-- A GROUP THAT MEETS IN SOMEBODY'S LIVING ROOM MUST NEVER PUBLISH WHERE THEY
-- LIVE.
--
-- `area` and `address` are two columns on purpose. The area — "Northside",
-- "near the station" — is what a directory is for and is public. The address is
-- a private home, and it belongs to the person who opens their door on a
-- Tuesday rather than to this website.
--
-- One function decides who gets it (Portal\Groups\GroupAddress), and the type
-- every listing and public page receives (Portal\Groups\GroupCard) HAS NO
-- ADDRESS PROPERTY AT ALL — so a template that forgets to check has nothing to
-- print rather than printing a house.
--
-- Having merely asked does not qualify, and neither does being on the waiting
-- list. Otherwise anybody with an account learns where a leader lives by
-- pressing a button.
--
-- AN UNANSWERED REQUEST HOLDS A PLACE, AND A "NO" GIVES IT BACK
--
-- The obvious reading — count the members — is wrong, and wrong in a way that
-- is invisible until it happens to somebody. Promotion moves a person from the
-- waiting list to 'requested', which is not membership; if only members counted,
-- the place would still look free on the next run, so the next person would be
-- promoted into it too, and the next, until everybody waiting had been told a
-- place was theirs and all but one of them was wrong.
--
-- So a place is taken by state IN ('member', 'requested'), and a decline or a
-- withdrawal hands it back. See Portal\Groups\GroupRepository::taken().
--
-- A LEADER IS NOT STAFF
--
-- Leading a group is a ROW HERE, not a capability and not an admin role. A
-- leader answers requests for their own group and nothing else, and every write
-- they make carries the group id in its WHERE clause. Giving leaders a
-- capability would make every leader of every group a moderator of all of them.

CREATE TABLE IF NOT EXISTS {small_groups} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  name          VARCHAR(190) NOT NULL,
  description   TEXT         NULL,

  -- PUBLIC. Roughly where, so somebody can find one near them.
  area          VARCHAR(190) NULL,

  -- PRIVATE. Somebody's home. See the note above and GroupAddress.
  address       VARCHAR(300) NULL,

  -- Free text: "Tuesdays, 7.30pm, fortnightly" is what people actually write,
  -- and a structured field would refuse half of them.
  meets         VARCHAR(190) NULL,

  -- NULL means no limit. A group in a hall does not have a capacity in the way
  -- a group in a front room does.
  capacity      INT UNSIGNED NULL,

  is_published  TINYINT(1)   NOT NULL DEFAULT 1,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_published (is_published, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {small_group_members} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id      INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,

  -- 'leader' | 'member'. Leadership is this column and nothing else — see the
  -- note above.
  role          VARCHAR(16)  NOT NULL DEFAULT 'member',

  -- 'requested' | 'member' | 'waiting' | 'declined' | 'left'.
  --
  -- 'requested' HOLDS A PLACE. That is the counter-intuitive one and it is the
  -- whole reason promotion works: see the note above.
  state         VARCHAR(16)  NOT NULL DEFAULT 'requested',

  -- What somebody said when they asked, and what the leader said back.
  note          VARCHAR(500) NULL,
  reply         VARCHAR(500) NULL,

  requested_at  DATETIME     NULL,
  answered_at   DATETIME     NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- One row per person per group. Asking twice edits the ask rather than
  -- making a second one, which is also what stops a button anybody can press
  -- from filling a leader's screen.
  UNIQUE KEY uniq_membership (group_id, user_id),

  KEY idx_group_state (group_id, state),
  -- The waiting list is answered in order, so the wait means something.
  KEY idx_waiting (group_id, state, requested_at),
  KEY idx_person (user_id, state),

  CONSTRAINT fk_group_member_group FOREIGN KEY (group_id)
    REFERENCES {small_groups} (id) ON DELETE CASCADE,
  CONSTRAINT fk_group_member_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('manage_groups', 'Keep the small-group directory and see which groups have no leader');
