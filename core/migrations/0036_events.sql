-- Events, and signing up for them.
--
-- WALL CLOCK PLUS A ZONE, NEVER AN INSTANT
--
-- `starts_at` is 19:30 as somebody wrote it, and `timezone` says which 19:30.
-- Storing an instant instead would be a UTC column that reads 19:30 in January
-- and 18:30 in July, because the offset changes and the wall clock does not —
-- and the week the clocks go back is the week somebody turns up an hour early
-- to a service they have attended for twenty years.
--
-- The zone is per event rather than site-wide because a site can hold an event
-- somewhere else — a conference, a mission trip, a linked church abroad — and a
-- column that cannot express that would be silently wrong rather than absent.
--
-- CAPACITY IS NULLABLE AND NULL MEANS UNLIMITED
--
-- Not zero. Zero is a real answer meaning "nobody may sign up", and a schema
-- that used it for "no limit" cannot tell a full event from an open one. Most
-- events have no limit, so the common case is the one that stores nothing.
--
-- A PARTY SIZE, NOT A HEAD COUNT
--
-- `guests` is how many somebody is bringing, and the party is 1 + guests. A
-- guest counts as a place because a guest sits somewhere; the alternative — a
-- guest list nobody counts — is how a hall for sixty ends up with ninety people
-- in it. The generated column exists so that "is there room" is one SUM over a
-- number the database maintains, rather than arithmetic every caller has to
-- remember.
--
-- ONE SIGN-UP PER ADDRESS PER EVENT
--
-- uniq_event_person is the backstop; the repository looks first and reuses the
-- row, because somebody who cancelled and changed their mind is the same
-- person, not a second one. Cancelling keeps the row so the waiting list can
-- still be walked in the order people joined it.

CREATE TABLE IF NOT EXISTS {events} (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug            VARCHAR(191) NOT NULL,
  title           VARCHAR(190) NOT NULL,
  description     TEXT         NULL,
  location        VARCHAR(190) NULL,

  starts_at       DATETIME     NOT NULL,
  ends_at         DATETIME     NULL,
  timezone        VARCHAR(64)  NULL,
  all_day         TINYINT(1)   NOT NULL DEFAULT 0,

  is_published    TINYINT(1)   NOT NULL DEFAULT 0,

  -- A members-only event is INVISIBLE to a stranger rather than refused. The
  -- listing leaves it out and the page 404s, because "you may not see this
  -- event" tells somebody there is an event.
  member_only     TINYINT(1)   NOT NULL DEFAULT 0,

  signup_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
  capacity        INT UNSIGNED NULL,
  signup_opens_at  DATETIME    NULL,
  signup_closes_at DATETIME    NULL,
  max_guests      INT UNSIGNED NOT NULL DEFAULT 0,

  created_at      DATETIME     NOT NULL,
  updated_at      DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_when (starts_at, is_published)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {event_signups} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id      INT UNSIGNED NOT NULL,

  -- No account needed. The people a church most wants at an event are the ones
  -- who never made one, so name and email are the identity and `user_id` is
  -- filled in only when somebody happened to be signed in.
  name          VARCHAR(190) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(60)  NULL,
  user_id       INT UNSIGNED NULL,

  guests        INT UNSIGNED NOT NULL DEFAULT 0,

  -- Maintained by the database so "is there room" is one SUM rather than
  -- arithmetic every caller has to remember to do the same way.
  party_size    INT UNSIGNED AS (guests + 1) STORED,

  -- going | waiting | cancelled
  state         VARCHAR(16)  NOT NULL DEFAULT 'going',

  note          VARCHAR(500) NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_event_person (event_id, email),

  -- The waiting list is walked in the order people joined it, so the id order
  -- is load-bearing rather than incidental.
  KEY idx_queue (event_id, state, id),
  KEY idx_person (email),

  CONSTRAINT fk_signup_event FOREIGN KEY (event_id)
    REFERENCES {events} (id) ON DELETE CASCADE,
  CONSTRAINT fk_signup_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('manage_events', 'Create events and see who has signed up');
