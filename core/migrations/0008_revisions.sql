-- Revision history.
--
-- A snapshot of the editable fields taken BEFORE a person changes them, so the
-- paste that wiped a description can be undone. Not a version-control system:
-- there is no branching, no merge, and restoring is an ordinary edit that
-- itself gets recorded.
--
-- Only human edits are captured, which is why nothing here hooks the
-- repositories. The provider sync rewrites titles and durations on a schedule;
-- recording those would bury the one edit somebody wants to find under a
-- hundred machine writes, and the sync is not something anybody wants to
-- "restore" from.

CREATE TABLE IF NOT EXISTS {revisions} (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,

  --   video | category | series | playlist
  --
  -- Deliberately a string plus an id rather than four nullable foreign keys.
  -- The alternative is a table that grows a column every time something else
  -- becomes editable, and rows where three of the four are always NULL.
  --
  -- The cost is that a deleted subject leaves its revisions behind, which
  -- prune() clears. That is the right trade here: a revision is a record of
  -- what somebody did, and a foreign key deleting the evidence when the
  -- content goes is not obviously what anybody wants.
  subject_type VARCHAR(32)  NOT NULL,
  subject_id   INT UNSIGNED NOT NULL,

  -- The fields as they were, JSON-encoded. TEXT rather than the JSON column
  -- type: MariaDB aliases JSON to LONGTEXT anyway, and nothing here queries
  -- inside the document, so the type would buy validation this code already
  -- does and cost portability across the MySQL/MariaDB versions this ships to.
  data         TEXT         NOT NULL,

  -- Who, as an address rather than a user id. The account may be deleted later
  -- and the record of who made a change should outlive it.
  changed_by   VARCHAR(254) NOT NULL DEFAULT '',

  created_at   DATETIME     NOT NULL,

  PRIMARY KEY (id),
  -- The one query this table serves: the history of one thing, newest first.
  KEY idx_subject (subject_type, subject_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
