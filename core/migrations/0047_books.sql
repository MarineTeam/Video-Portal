-- The book reader and hymnals.
--
-- EVERY STORED POSITION IS A PDF PAGE
--
-- Contents entries, bookmarks, highlights and reading positions all record
-- `pdf_page`. The printed number is worked out for display from `page_offset`
-- and is never written down anywhere.
--
-- The reason is what happens when the offset turns out to be wrong by two,
-- which it will: somebody scanned a cover and a blank leaf and nobody counted.
-- With the page stored, correcting it is ONE COLUMN EDIT that relabels the
-- whole book at once. Store the printed number instead and the same correction
-- is a migration over four tables, against live data, that has to be right
-- first time.
--
-- A FILE CAN BE REPLACED WITHOUT BECOMING A DIFFERENT BOOK
--
-- `file_revision` goes up whenever the file behind a book changes. A device
-- holding a saved copy compares it and knows its copy is stale — without it a
-- re-scanned hymnal would keep serving the old pages to everybody who had ever
-- opened it, with every page number silently one out.

CREATE TABLE IF NOT EXISTS {books} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug          VARCHAR(191) NOT NULL,
  title         VARCHAR(300) NOT NULL,
  subtitle      VARCHAR(300) NULL,
  author        VARCHAR(190) NULL,

  -- 'pdf' or 'epub'. What the browser has to render decides almost everything
  -- about the reader, including which features are possible at all — an EPUB's
  -- selection lives inside an iframe its renderer owns, so highlighting is
  -- PDF-only and the screen says so rather than offering a button that fails.
  kind          VARCHAR(8)   NOT NULL DEFAULT 'pdf',

  -- Where the file is. Through {file_assets} rather than a path of its own, so
  -- a book is stored, served and access-checked the same way every other
  -- uploaded file on this site is.
  asset_id      INT UNSIGNED NULL,

  -- Bumped when the file is replaced. See the note above.
  file_revision INT UNSIGNED NOT NULL DEFAULT 1,

  page_count    INT UNSIGNED NOT NULL DEFAULT 0,

  /*
   * How many leaves come before printed page 1 — a cover, a title page, a
   * blank. printed = pdf_page - page_offset, and a page inside that run has no
   * printed number at all.
   */
  page_offset   INT          NOT NULL DEFAULT 0,

  -- A hymnal is read by number; a book is read by page. It changes what the
  -- reader offers rather than how anything is stored.
  is_hymnal     TINYINT(1)   NOT NULL DEFAULT 0,

  category_id   INT UNSIGNED NULL,
  position      INT          NOT NULL DEFAULT 0,

  is_published  TINYINT(1)   NOT NULL DEFAULT 0,

  -- A members-only book is INVISIBLE to a stranger rather than refused, the
  -- rule events, forms and small groups all keep. The title is a leak too.
  member_only   TINYINT(1)   NOT NULL DEFAULT 0,

  -- Whether an admin's browser has finished indexing the contents and the page
  -- text. Held so the screen can say "not indexed yet" rather than showing an
  -- empty search box that silently finds nothing.
  indexed_at    DATETIME     NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_slug (slug),
  KEY idx_shelf (is_published, member_only, category_id, position),
  CONSTRAINT fk_book_asset FOREIGN KEY (asset_id)
    REFERENCES {file_assets} (id) ON DELETE SET NULL,
  CONSTRAINT fk_book_category FOREIGN KEY (category_id)
    REFERENCES {categories} (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What is in a book: a hymn, a chapter, a reading.
--
-- From the PDF's own bookmarks, an EPUB's chapters, or typed by hand when a
-- book has neither — resolved ONCE by an admin's browser and stored here, so
-- searching a whole hymnal category does not mean opening six PDFs.
CREATE TABLE IF NOT EXISTS {book_contents} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,

  -- The hymn number, where there is one. NULL for a chapter, which is ordered
  -- by page and referred to by name.
  number        INT UNSIGNED NULL,

  title         VARCHAR(300) NOT NULL,

  -- THE PAGE, not the printed number. See the note at the top.
  pdf_page      INT UNSIGNED NOT NULL DEFAULT 1,

  -- An EPUB has no pages, so its position is a string only its own renderer
  -- understands. Kept opaque here rather than parsed.
  epub_href     VARCHAR(500) NULL,

  depth         TINYINT UNSIGNED NOT NULL DEFAULT 0,

  created_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_reading_order (book_id, pdf_page, number),

  -- One number per book. A hymnal with two hymn 214s is a scanning mistake,
  -- and letting it in means "go to 214" quietly picks one.
  UNIQUE KEY uniq_number (book_id, number),

  CONSTRAINT fk_contents_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The text of a page, so a section can be searched without opening anything.
--
-- Extracted in an ADMIN'S BROWSER — from the PDF's text layer where there is
-- one, by OCR where there is not — and posted here. Deliberately not on the
-- server: OCR on shared hosting is not available at any price, and the browser
-- doing it is idle anyway.
CREATE TABLE IF NOT EXISTS {book_pages} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,
  pdf_page      INT UNSIGNED NOT NULL,

  body          MEDIUMTEXT   NULL,

  -- 'text' (the file had a text layer) or 'ocr' (it did not). Worth keeping:
  -- OCR text is good enough to search and not good enough to quote, and a
  -- screen that shows a snippet needs to know which it has.
  source        VARCHAR(8)   NOT NULL DEFAULT 'text',

  created_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_page (book_id, pdf_page),
  FULLTEXT KEY ft_body (body),
  CONSTRAINT fk_page_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where somebody had got to, one row per person per book.
CREATE TABLE IF NOT EXISTS {reading_positions} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,

  pdf_page      INT UNSIGNED NOT NULL DEFAULT 1,

  -- An EPUB's position, which only its renderer understands. Stored opaque
  -- alongside the percentage rather than parsed — nothing here could work a
  -- progress figure out of one, and a bar that only worked for PDFs would be
  -- worse than none.
  epub_cfi      VARCHAR(500) NULL,
  percent       TINYINT UNSIGNED NOT NULL DEFAULT 0,

  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_reader (book_id, user_id),
  CONSTRAINT fk_position_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE,
  CONSTRAINT fk_position_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A bookmark, a highlight or a note.
CREATE TABLE IF NOT EXISTS {book_marks} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,

  -- 'bookmark' | 'highlight' | 'note'
  kind          VARCHAR(10)  NOT NULL DEFAULT 'bookmark',

  pdf_page      INT UNSIGNED NOT NULL DEFAULT 1,

  -- Where on the page, for a highlight. Opaque: rectangles in the PDF's own
  -- coordinates, which mean nothing outside the renderer that produced them.
  anchor        VARCHAR(1000) NULL,

  -- What was highlighted, and what somebody wrote about it.
  quote         VARCHAR(1000) NULL,
  body          VARCHAR(2000) NULL,
  colour        VARCHAR(7)   NULL,

  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,

  PRIMARY KEY (id),
  KEY idx_mine (user_id, book_id, pdf_page),
  CONSTRAINT fk_mark_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE,
  CONSTRAINT fk_mark_user FOREIGN KEY (user_id)
    REFERENCES {users} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which hymns get looked up, for the analytics screen and a licence return.
--
-- Counted rather than logged: a row per lookup would be a record of what each
-- person searched for in a hymnal, which is nobody's business and grows without
-- limit. This answers "what do we sing" and nothing about who.
CREATE TABLE IF NOT EXISTS {hymn_lookups} (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id       INT UNSIGNED NOT NULL,
  number        INT UNSIGNED NOT NULL,
  on_date       DATE         NOT NULL,
  lookups       INT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_hymn_day (book_id, number, on_date),
  CONSTRAINT fk_lookup_book FOREIGN KEY (book_id)
    REFERENCES {books} (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO {capabilities} (slug, description)
VALUES ('manage_books', 'Add books and hymnals, index them, and correct their page numbering');
