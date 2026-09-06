-- Forms and connect cards.
--
-- BUILT IN THE ADMIN, NOT IN CODE. The questions on a connect card change every
-- term — a new course, a different sign-up, one more box because of something
-- that happened in the summer — and a form whose questions live in a template
-- is a form that needs a deployment to change. Nobody on a shared host is going
-- to do that, so the card stops being edited and stops being true.
--
-- AN ANSWER BELONGS TO THE QUESTION, NOT TO THE WORDS IT WAS ASKED IN
--
-- This is the rule the whole shape here exists for. `form_answers` points at a
-- question ROW, never at a label, so renaming "Phone" to "Mobile number" does
-- not reach back and rewrite what March's responses were answering. And a
-- question is RETIRED rather than deleted, because deleting one would take its
-- answers with it and quietly change what a past response said.
--
-- The foreign key is deliberately RESTRICT rather than CASCADE. A question that
-- has been answered cannot be deleted at all — the database refuses, rather
-- than trusting every future caller to remember to retire instead. Nothing in
-- the application offers deletion, but the rule is worth more in the schema
-- than in a code path somebody can add a second of.

CREATE TABLE IF NOT EXISTS {forms} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  title         VARCHAR(190) NOT NULL,
  description   TEXT         NULL,

  -- A closed form still exists and still has its responses; it just does not
  -- take new ones. Deleting a form to stop it is how a term's answers vanish.
  is_open       TINYINT(1)   NOT NULL DEFAULT 1,

  -- INVISIBLE to a stranger rather than refused, the same rule events keep: a
  -- form nobody outside the church is meant to see must not announce its own
  -- existence by refusing. The title is a leak too.
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,

  -- Who hears about a response. NAMED BY THE FORM, because different forms
  -- reach different people — a prayer-ministry card and a car-park rota do not
  -- go to the same inbox, and one site-wide address means one person forwarding
  -- everything by hand.
  notify_emails VARCHAR(500) NULL,

  -- What the person sees after sending. Per form, because "thank you, we will
  -- be in touch" is wrong for half of them.
  thanks        VARCHAR(500) NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_open (is_open, member_only)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {form_questions} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id       INT UNSIGNED NOT NULL,

  label         VARCHAR(300) NOT NULL,
  help          VARCHAR(500) NULL,

  -- One of the ten in Portal\Forms\FieldType. Held as a string rather than an
  -- ENUM so a plugin can be given a new one without an ALTER on a live host.
  type          VARCHAR(24)  NOT NULL DEFAULT 'text',

  -- The permitted answers for a choice question, as a JSON list. The SERVER
  -- decides validity against this: a crafted request cannot invent a fourth
  -- answer to a three-way question, whatever the page it came from said.
  options       TEXT         NULL,

  is_required   TINYINT(1)   NOT NULL DEFAULT 0,
  position      INT          NOT NULL DEFAULT 0,

  -- Retired, not deleted. The answers stay and still say what they were
  -- answering; the question simply stops being asked.
  retired_at    DATETIME     NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_form (form_id, retired_at, position),
  CONSTRAINT fk_question_form FOREIGN KEY (form_id)
    REFERENCES {forms} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {form_responses} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id       INT UNSIGNED NOT NULL,

  -- Whoever sent it, if they happened to be signed in. NO ACCOUNT IS NEEDED,
  -- so this is null far more often than not and nothing may depend on it.
  user_id       INT UNSIGNED NULL,

  -- Pulled out of the answers when the form asks for them, so a list of
  -- responses can show who sent what without reading every answer row.
  from_name     VARCHAR(190) NULL,
  from_email    VARCHAR(190) NULL,

  /*
   * DEALT WITH, BY NAME.
   *
   * The way follow-up fails is not that nobody rings — it is that two people
   * each assume the other did, and the person who filled the card in hears
   * from nobody. A tick with no name beside it produces exactly that, because
   * it answers "has this been done" and not "by whom".
   */
  handled_by    VARCHAR(190) NULL,
  handled_at    DATETIME     NULL,
  handled_note  VARCHAR(500) NULL,

  created_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_form_when (form_id, created_at),
  KEY idx_outstanding (form_id, handled_at),
  CONSTRAINT fk_response_form FOREIGN KEY (form_id)
    REFERENCES {forms} (id) ON DELETE CASCADE,
  CONSTRAINT fk_response_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {form_answers} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_id   INT UNSIGNED NOT NULL,
  question_id   INT UNSIGNED NOT NULL,

  -- The answer as the server accepted it, after validating against the
  -- question's own options. A multi-choice answer is a JSON list.
  value         TEXT         NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_answer (response_id, question_id),
  KEY idx_question (question_id),

  CONSTRAINT fk_answer_response FOREIGN KEY (response_id)
    REFERENCES {form_responses} (id) ON DELETE CASCADE,

  -- RESTRICT. See the note at the top: an answered question cannot be deleted,
  -- because deleting it would rewrite what a past response said.
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id)
    REFERENCES {form_questions} (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('manage_forms', 'Build forms and connect cards, and read what people send');
