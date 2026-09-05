-- Teams, services, and the asks that make up a rota.
--
-- A ROTA IS A LIST OF ASKS, not a list of facts. That is the shape the whole
-- section hangs off: every row here is somebody being invited to serve, which
-- they have answered or have not. A schema that stored "who is on" instead
-- cannot express the state every rota actually spends its week in — half
-- answered — and the builder would have no way to see who still has to be
-- chased.
--
-- So `state` is the column, and 'invited' is the default. An unanswered ask is
-- a first-class thing rather than a missing row.
--
-- WHY {rota_assignments} HAS ITS OWN UNIQUE KEY
--
-- uniq_service_person is the backstop for "somebody is only on a service once".
-- It is a backstop and not the enforcement: the repository asks first and says
-- so in words, because a caught constraint error reaches a person as "something
-- went wrong" when the true answer is "they are already down for this".
--
-- WHY BLOCKOUTS ARE NOT A CONSTRAINT
--
-- A day somebody cannot serve is recorded here and nothing in the schema stops
-- an assignment landing on one. That is deliberate and it is the rule for this
-- section: the builder is WARNED and may go ahead. A rota that argues with the
-- person offering to help is a rota nobody helps with, and the organiser
-- frequently knows something the calendar does not.

CREATE TABLE IF NOT EXISTS {rota_teams} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(500) NULL,
  position      INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_order (position, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The jobs within a team: "Sound desk", "Second reading". A team may have none,
-- in which case an ask names the team alone.
CREATE TABLE IF NOT EXISTS {rota_positions} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  position      INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_team (team_id, position),
  CONSTRAINT fk_rota_position_team FOREIGN KEY (team_id)
    REFERENCES {rota_teams} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who is on a team, and what they usually do there. `position_id` is a default
-- for the builder to accept or override, never a restriction: somebody who
-- usually runs sound can still be asked to read.
CREATE TABLE IF NOT EXISTS {rota_team_members} (
  team_id       INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  position_id   INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (team_id, user_id),
  KEY idx_person (user_id),
  KEY idx_position (position_id),
  CONSTRAINT fk_rota_member_team FOREIGN KEY (team_id)
    REFERENCES {rota_teams} (id) ON DELETE CASCADE,
  CONSTRAINT fk_rota_member_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE,
  CONSTRAINT fk_rota_member_position FOREIGN KEY (position_id)
    REFERENCES {rota_positions} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One occasion. `starts_at` is WALL CLOCK in the site's timezone, not an
-- instant: a service at ten o'clock is at ten o'clock, and storing an instant
-- would move it by an hour twice a year — which is the one week of the year
-- when a rota has to be right.
CREATE TABLE IF NOT EXISTS {rota_services} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title         VARCHAR(190) NOT NULL,
  starts_at     DATETIME     NOT NULL,
  notes         TEXT         NULL,
  is_published  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_when (starts_at, is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {rota_assignments} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id    INT UNSIGNED NOT NULL,
  team_id       INT UNSIGNED NOT NULL,
  position_id   INT UNSIGNED NULL,
  user_id       INT UNSIGNED NOT NULL,

  -- invited | accepted | declined. Never "confirmed by the organiser": the
  -- only person who can answer an ask is the person asked.
  state         VARCHAR(16)  NOT NULL DEFAULT 'invited',

  -- Their words when they answered, optional either way. A decline with a
  -- reason is what lets the builder find somebody else knowing why.
  reason        VARCHAR(300) NULL,

  answered_at   DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_service_person (service_id, user_id),
  KEY idx_service (service_id, team_id),
  KEY idx_person (user_id, state),
  CONSTRAINT fk_rota_assignment_service FOREIGN KEY (service_id)
    REFERENCES {rota_services} (id) ON DELETE CASCADE,
  CONSTRAINT fk_rota_assignment_team FOREIGN KEY (team_id)
    REFERENCES {rota_teams} (id) ON DELETE CASCADE,
  CONSTRAINT fk_rota_assignment_position FOREIGN KEY (position_id)
    REFERENCES {rota_positions} (id) ON DELETE SET NULL,
  CONSTRAINT fk_rota_assignment_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Days somebody cannot serve. Inclusive at both ends, because that is how
-- people say it: "I'm away the 3rd to the 10th" includes the 10th.
CREATE TABLE IF NOT EXISTS {rota_blockouts} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  starts_on     DATE         NOT NULL,
  ends_on       DATE         NOT NULL,
  reason        VARCHAR(200) NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_person_span (user_id, starts_on, ends_on),
  CONSTRAINT fk_rota_blockout_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
