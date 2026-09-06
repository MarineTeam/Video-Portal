-- The running order for one service.
--
-- EACH ROW IS NAMED BY THE HYMN, NOT BY THE BOOK IT IS IN.
--
-- That is the modelling rule for this table and it is worth stating because the
-- obvious schema gets it backwards. A plan row that says "Ancient & Modern 245"
-- is meaningless to anybody holding a different book, and it is meaningless to
-- this site the day the church buys new hymnals: the number is a property of
-- the hymn IN A BOOK, not of the hymn, and not of the service.
--
-- So `title` is the hymn's own name — "Be Thou My Vision" — and `reference` is
-- whatever the person building the plan typed for the congregation to read off
-- the board. When hymnals land (they are a later section) a row can gain a
-- pointer to the hymn itself and the number becomes a lookup. What is NOT here
-- is a hymn_id column that nothing reads: the spec warns specifically against
-- porting a promise nothing keeps, and a nullable foreign key to a table that
-- does not exist yet is exactly that.
--
-- WHY NOT ONLY HYMNS
--
-- The feature is described as a running order of hymns, and a running order
-- with only hymns is not a running order — the person holding it needs the
-- reading and the notices in their places or they cannot follow it. `kind`
-- carries that, and 'hymn' is the one the later hymnal work will care about.
--
-- WHY THERE IS NO is_published HERE
--
-- The service already has one. A plan that could be published separately from
-- its service would be two switches for one question, and the failure is a
-- published order for a service nobody has been told about.

CREATE TABLE IF NOT EXISTS {service_plan_items} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id    INT UNSIGNED NOT NULL,

  -- hymn | reading | item. Widened rather than an ENUM: adding a kind should
  -- not need a migration on a table this small, and an unrecognised value
  -- renders as a plain item rather than breaking the order.
  kind          VARCHAR(16)  NOT NULL DEFAULT 'item',

  -- The hymn's own name, or what the item is called.
  title         VARCHAR(190) NOT NULL,

  -- What the congregation reads off the board, or the passage. Free text on
  -- purpose: "245", "H&M 245", "Romans 8:1-11" and "insert" are all things
  -- people write here, and refusing any of them would send somebody to a
  -- different piece of paper.
  reference     VARCHAR(120) NULL,

  -- For whoever is leading. Not printed on the congregation's copy.
  note          VARCHAR(300) NULL,

  position      INT          NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_order (service_id, position, id),
  CONSTRAINT fk_plan_item_service FOREIGN KEY (service_id)
    REFERENCES {rota_services} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
