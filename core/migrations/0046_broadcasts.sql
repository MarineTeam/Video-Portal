-- Broadcasts: reaching people by email, text and push.
--
-- RESOLVED INTO RECIPIENT ROWS BEFORE ANYTHING IS SENT
--
-- A send does not walk an audience and deliver as it goes. It first writes one
-- row per person per channel, and only then works through them. Three reasons,
-- and the third is the one that matters:
--
--   The reach preview and the send are then the same set, so the number
--   somebody was shown before pressing the button is the number that goes.
--
--   The run is resumable. Shared hosting kills long requests, and pseudo-cron
--   runs inside somebody's page view — a send of four hundred people WILL be
--   interrupted, and it has to carry on rather than start again.
--
--   ONE BAD ADDRESS DOES NOT STOP THE REST. A row that fails is marked failed
--   with the provider's own words and the run moves on, instead of an
--   exception ending the send at whoever happens to be alphabetically unlucky.
--
-- THE UNIQUE KEY IS WHAT MAKES A RESUMED RUN UNABLE TO SEND TWICE
--
-- (broadcast_id, channel, address) is UNIQUE, and resolution is INSERT IGNORE.
-- So re-resolving an interrupted broadcast cannot create a second row for
-- somebody who already has one, and every row carries its own state. Without
-- it, an interruption between "resolved" and "finished" would send the whole
-- audience a second copy — the failure people actually remember.
--
-- THREE CONSENT RULES, NOT ONE
--
-- Held in {broadcast_prefs} and applied by Portal\Broadcast\Consent. Email is
-- opt-OUT; SMS is opt-IN and needs a parseable number; push needs a registered
-- device and is not a preference at all. See that class for why folding them
-- together is both wrong and, for the text half, illegal in most places.

CREATE TABLE IF NOT EXISTS {broadcast_prefs} (
  user_id       INT UNSIGNED NOT NULL,

  -- OPT-OUT. Absent means "not opted out", which is why the column is a
  -- negative: a missing row has to mean email is allowed, and a positively
  -- named `email_opt_in` defaulting to 0 would silence everybody who has never
  -- visited a settings page.
  email_opt_out TINYINT(1)   NOT NULL DEFAULT 0,

  -- OPT-IN. Both this and a readable number are required.
  sms_opt_in    TINYINT(1)   NOT NULL DEFAULT 0,
  phone         VARCHAR(40)  NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (user_id),
  CONSTRAINT fk_broadcast_pref_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {broadcasts} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  subject       VARCHAR(300) NOT NULL,
  body          TEXT         NOT NULL,

  -- 'everyone' | 'group' | 'event'. An event's sign-ups INCLUDING the ones
  -- with no account, which is most of the point of having events at all.
  audience_type VARCHAR(16)  NOT NULL DEFAULT 'everyone',
  audience_id   INT UNSIGNED NULL,

  -- Which channels this goes out on. Three flags rather than a set, because
  -- each is a separate decision with a separate consent rule behind it.
  by_email      TINYINT(1)   NOT NULL DEFAULT 1,
  by_sms        TINYINT(1)   NOT NULL DEFAULT 0,
  by_push       TINYINT(1)   NOT NULL DEFAULT 0,

  -- 'draft' | 'sending' | 'sent' | 'stopped'.
  --
  -- 'sending' is entered when the rows are resolved, so a broadcast whose run
  -- was interrupted is visibly mid-flight rather than looking like a draft
  -- somebody could edit and send again.
  state         VARCHAR(16)  NOT NULL DEFAULT 'draft',

  created_by    VARCHAR(190) NULL,
  started_at    DATETIME     NULL,
  finished_at   DATETIME     NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_state (state, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {broadcast_recipients} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  broadcast_id  INT UNSIGNED NOT NULL,

  channel       VARCHAR(8)   NOT NULL,

  -- Where it goes: an email address, an E.164 number, or a push endpoint.
  -- Stored resolved, so the send does not re-derive it and cannot disagree
  -- with what the preview counted.
  address       VARCHAR(500) NOT NULL,

  -- Nullable: an event's sign-ups include people with no account, and they are
  -- exactly the people a church most wants to reach.
  user_id       INT UNSIGNED NULL,
  person_name   VARCHAR(190) NULL,

  -- 'pending' | 'sent' | 'failed'.
  state         VARCHAR(12)  NOT NULL DEFAULT 'pending',

  -- The provider's own words. "Connection lost" for everything is how an
  -- afternoon gets spent looking in the wrong place.
  error         VARCHAR(500) NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at       DATETIME     NULL,

  created_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),

  -- THE KEY THAT MAKES A RESUMED RUN UNABLE TO SEND TWICE. See the note above.
  -- The address is part of it rather than the user id, because a recipient may
  -- have no account at all.
  UNIQUE KEY uniq_recipient (broadcast_id, channel, address),

  KEY idx_pending (broadcast_id, state, id),

  CONSTRAINT fk_recipient_broadcast FOREIGN KEY (broadcast_id)
    REFERENCES {broadcasts} (id) ON DELETE CASCADE,
  CONSTRAINT fk_recipient_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('send_broadcasts', 'Write and send broadcasts by email, text and push');

INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('broadcasts.send', 60, NOW(), 1);
