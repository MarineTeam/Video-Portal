-- The schedules calendar: a second, separate rota for people who never log in.
--
-- WHY IT IS SEPARATE FROM {rota_assignments}
--
-- The rota is a list of ASKS made of accounts: somebody is invited, answers,
-- and can be chased. This is a list of NAMES, usually typed into a spreadsheet
-- by whoever keeps it, for people who will never sign in to anything. Nobody is
-- asked and nobody answers; the calendar's job is to tell readers who is on.
--
-- Merging the two would mean either inventing accounts for people who do not
-- want one, or an assignments table where half the rows can never be answered.
-- Both are worse than two tables that mean two different things.
--
-- WHAT IS DELIBERATELY MISSING: RECURRENCE
--
-- The app this is ported from has recurrence columns on its schedules and
-- nothing has ever read them. The instruction is explicit — implement expansion
-- or drop the columns, do not port a promise nothing keeps — so they are not
-- here. A schedule is a list of dates. If repeating ones are wanted later they
-- can use the RRULE expander events already have, which is real.
--
-- THE MATCHING KEY
--
-- `match_key` is the accent-folded, punctuation-stripped, case-folded form of a
-- name, and it is what a sync matches on. "José", "Jose", "JOSE" and " jose "
-- are one person on four spreadsheet rows, and a calendar that treated them as
-- four would have somebody on the rota four times recognising none of it.
--
-- UNIQUE on the key, so the same name cannot become two people by being typed
-- twice. Aliases carry their own keys in the same shape, so a person known as
-- both "Bob" and "Robert" is found by either.

CREATE TABLE IF NOT EXISTS {schedules} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   VARCHAR(500) NULL,

  -- Shown side by side on one public page, so each needs to be told apart at a
  -- glance. Two emoji at most and a hex colour — validated in PHP, because a
  -- colour goes into a style attribute.
  icon          VARCHAR(16)  NULL,
  colour        VARCHAR(7)   NULL,

  position      INT          NOT NULL DEFAULT 0,

  -- A disabled schedule leaves the calendar without its dates being touched.
  -- That is exactly the case the device sync cannot report, and the note is
  -- here because this column is where the problem starts.
  is_enabled    TINYINT(1)   NOT NULL DEFAULT 1,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_order (is_enabled, position, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {schedule_people} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(190) NOT NULL,
  match_key     VARCHAR(190) NOT NULL,

  -- Filled in when somebody links this name to an account, which is the ONLY
  -- thing that turns reminders on for them. Somebody on a rota with no account
  -- gets no reminder — a real limitation, stated rather than papered over.
  user_id       INT UNSIGNED NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_match_key (match_key),
  KEY idx_account (user_id),
  CONSTRAINT fk_schedule_person_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Other names one person is written as. Same key shape, so a lookup checks both
-- tables and finds them either way.
CREATE TABLE IF NOT EXISTS {schedule_person_aliases} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id     INT UNSIGNED NOT NULL,
  name          VARCHAR(190) NOT NULL,
  match_key     VARCHAR(190) NOT NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_alias_key (match_key),
  KEY idx_person (person_id),
  CONSTRAINT fk_schedule_alias_person FOREIGN KEY (person_id)
    REFERENCES {schedule_people} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One person, on one schedule, on one day.
CREATE TABLE IF NOT EXISTS {schedule_entries} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id   INT UNSIGNED NOT NULL,
  person_id     INT UNSIGNED NOT NULL,
  on_date       DATE         NOT NULL,

  -- What they are doing that day. Free text: a spreadsheet column says
  -- "Reading", "Coffee", "Sound + projection", and refusing any of those would
  -- send whoever keeps it back to paper.
  role          VARCHAR(120) NULL,
  note          VARCHAR(300) NULL,

  -- Where the row came from, so a sync can replace what it wrote without
  -- touching what somebody typed by hand. Without it a sync either destroys
  -- manual entries or leaves its own stale ones behind.
  source        VARCHAR(16)  NOT NULL DEFAULT 'manual',

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- One person does one job on one day on one schedule. A spreadsheet re-read
  -- twice must not double every row, and this is what makes the sync's writes
  -- idempotent rather than its bookkeeping.
  UNIQUE KEY uniq_slot (schedule_id, on_date, person_id, role),

  KEY idx_day (schedule_id, on_date),
  KEY idx_person_day (person_id, on_date),

  CONSTRAINT fk_entry_schedule FOREIGN KEY (schedule_id)
    REFERENCES {schedules} (id) ON DELETE CASCADE,
  CONSTRAINT fk_entry_person FOREIGN KEY (person_id)
    REFERENCES {schedule_people} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('manage_schedules', 'Keep the schedules calendar and the list of people on it');
