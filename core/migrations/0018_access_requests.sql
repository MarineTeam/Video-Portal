-- A way to ask.
--
-- Approval has worked since Phase 1 and asking for it never has. Somebody
-- signs in, lands on "your account is not approved yet", and the page tells
-- them to go find whoever invited them — a dead end the site creates and then
-- refuses to help with. Meanwhile nobody is told that anyone is waiting: the
-- dashboard has counted pending accounts all along, and counting is not
-- telling.
--
-- One row per person, not per request. The PRIMARY KEY on user_id is the whole
-- design:
--
--   * It caps the table at one row per account, so a stranger who can sign in
--     cannot fill a table by clicking a button repeatedly.
--   * It is the fire-once guard for the notification. INSERT IGNORE either
--     creates the row or does nothing, and only the create sends mail. Asking
--     again edits the note and emails nobody, which is what stops the request
--     button from being an outbound mail relay pointed at the administrators.
--
-- Deliberately NOT a status column. A request is answered by authorizing the
-- account, which is a fact about {users}; duplicating it here would create two
-- places that can disagree about whether somebody has access. The row is
-- deleted when the account is approved, so this table only ever holds
-- questions nobody has answered yet.

CREATE TABLE IF NOT EXISTS {access_requests} (
  user_id     INT UNSIGNED NOT NULL,

  -- What they said about themselves. Optional: a request with no note is still
  -- a request, and demanding a reason from somebody who may simply have been
  -- invited would turn a button into a form nobody completes.
  note        VARCHAR(500) NOT NULL DEFAULT '',

  created_at  DATETIME     NOT NULL,

  -- When the administrators were told. NULL means the notification has not
  -- gone out — usually because no mail provider is configured, which is a
  -- normal state on a fresh install and must not lose the request.
  notified_at DATETIME     NULL,

  PRIMARY KEY (user_id),

  -- Oldest first is the only ordering this is ever read in.
  KEY idx_created (created_at),

  CONSTRAINT fk_access_request_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
