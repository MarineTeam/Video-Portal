-- Telling somebody what they are on for.
--
-- ONLY PEOPLE WITH AN ACCOUNT GET ONE. That is the limitation this whole
-- section is built around and it is not a bug to be fixed here: a name on a
-- spreadsheet has no address, and inventing a notification for one would be a
-- promise the site cannot keep. Linking a name to an account is what turns
-- reminders on, and the admin screen says so in those words.
--
-- ONE PREFERENCE ROW PER ACCOUNT, not per schedule. Somebody on the coffee rota
-- and the reading rota wants reminding at the same hour about both; a setting
-- per schedule would be a screen nobody finishes filling in.
--
-- ABSENT MEANS THE DEFAULT, not "off". A row is written only when somebody
-- changes something, so linking an account really does turn reminders on rather
-- than turning on the possibility of somebody visiting a settings page.

CREATE TABLE IF NOT EXISTS {schedule_reminder_prefs} (
  user_id       INT UNSIGNED NOT NULL,

  day_before    TINYINT(1)   NOT NULL DEFAULT 1,
  day_of        TINYINT(1)   NOT NULL DEFAULT 0,

  -- Wall clock in `timezone`, not on the server. Six in the evening is six
  -- where the person is; a rota reminder that arrives at two in the morning is
  -- one people turn off.
  send_hour     TINYINT UNSIGNED NOT NULL DEFAULT 18,
  timezone      VARCHAR(64)  NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (user_id),
  CONSTRAINT fk_reminder_pref_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- THE FIRE-ONCE GUARD, and the reason this table has no status column.
--
-- The PRIMARY KEY is the whole design: INSERT IGNORE either creates the row or
-- does nothing, and only the create sends. Two overlapping pseudo-cron runs, a
-- job killed halfway, a host that fires the same request twice — none of them
-- can produce a second email, because the row either inserts or it does not.
-- The same shape as {announced_videos} and the access-request table.
--
-- Claimed BEFORE the send, deliberately. Losing one reminder is something a
-- person recovers from by looking at the calendar; sending four is what makes
-- somebody turn the feature off.
CREATE TABLE IF NOT EXISTS {schedule_reminders_sent} (
  entry_id      INT UNSIGNED NOT NULL,
  kind          VARCHAR(8)   NOT NULL,
  sent_at       DATETIME     NOT NULL,
  PRIMARY KEY (entry_id, kind),
  CONSTRAINT fk_reminder_sent_entry FOREIGN KEY (entry_id)
    REFERENCES {schedule_entries} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {cron_jobs} (slug, interval_seconds, next_run_at, is_enabled)
VALUES ('schedules.reminders', 900, NOW(), 1);
