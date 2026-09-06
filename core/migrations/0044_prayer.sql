-- The prayer wall.
--
-- NOTHING APPEARS UNTIL A HUMAN HAS READ IT, and there is deliberately no
-- setting to switch that off. An unmoderated prayer wall on a church website is
-- a liability with a "post" button on it, and the only reliable way to stop
-- somebody turning moderation off "just for a bit" is not to build the switch.
-- So `status` starts at 'pending' and only a person moves it.
--
-- ANONYMOUS MEANS ANONYMOUS, INCLUDING TO MODERATORS
--
-- `user_id` is here so that somebody can find and withdraw their own request,
-- and for NOTHING else. It is never selected into anything a page renders:
-- Portal\Prayer\PrayerRequest — the type every screen receives, moderation
-- included — has no user id on it at all, so a page cannot leak one by
-- forgetting. Exactly one function produces a display name, and it answers
-- "Anonymous" for an anonymous request whoever is asking.
--
-- The cost is real and is stated on the moderation screen: an anonymous request
-- cannot be followed up and its author cannot be blocked. That is the trade
-- being made on purpose — a wall where anonymity quietly means "anonymous to
-- everyone except the people who run the church" is worse than no wall, because
-- it is a promise the software breaks.
--
-- "I PRAYED FOR THIS" IS A COUNT, AND THERE IS NO TABLE
--
-- Not a table of who pressed it, not even hashed. The only way to be certain a
-- list cannot leak is not to keep one, so the server holds an integer and the
-- "you already did" memory is a cookie on the device. Somebody who clears their
-- cookies can press it twice; that is a prayer counter, and inflating it is not
-- an attack anybody needs defending from.

CREATE TABLE IF NOT EXISTS {prayer_requests} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,

  body          TEXT         NOT NULL,

  -- The name to show, when there is one. Kept separate from the account so
  -- somebody signed in can still ask anonymously, and so a request keeps the
  -- name it was posted under after the account is renamed or deleted.
  requester_name VARCHAR(120) NULL,
  is_anonymous  TINYINT(1)   NOT NULL DEFAULT 0,

  -- For "withdraw mine", and nothing else. See the note above.
  user_id       INT UNSIGNED NULL,

  -- 'everyone' | 'members' | 'leaders'.
  visibility    VARCHAR(16)  NOT NULL DEFAULT 'members',

  -- 'pending' | 'approved' | 'removed'. There is no fourth state and no
  -- setting that lets a request skip the first one.
  status        VARCHAR(16)  NOT NULL DEFAULT 'pending',

  -- An answered request STAYS UP with a note. Taking it down when it is
  -- answered removes the half of the wall worth reading.
  answered_at   DATETIME     NULL,
  answer_note   VARCHAR(1000) NULL,

  -- An integer, not a list. See the note above.
  prayed_count  INT UNSIGNED NOT NULL DEFAULT 0,

  approved_at   DATETIME     NULL,
  approved_by   VARCHAR(190) NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_wall (status, visibility, created_at),
  KEY idx_queue (status, created_at),
  KEY idx_mine (user_id, created_at),

  CONSTRAINT fk_prayer_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('moderate_prayer', 'Read the prayer queue and decide what goes on the wall');
