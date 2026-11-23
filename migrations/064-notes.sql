-- 064: the editions' notes: footnotes and cross-references, where each marker
-- goes, and the passages a note cites (docs/superpowers/specs/
-- 2026-11-23-footnotes-design.md, Part 2; the wiki's "Design: Footnotes").
--
-- note:         one row per note, as printed: its kind ('footnote' or
--               'crossref'), its marker ('*', 'a', 'aa'; NULL where the
--               edition prints none in the line, as CEI 2008), the lemma or
--               range it is printed under ('3:6', '8,1-4'), CEI's title, the
--               body (inline tags <i>, <b>, <sm> only), and the source's own id,
--               unique per edition, which keeps a note's identity across
--               re-imports.
-- note_anchor:  where its marker goes: the verse by identity (book, chapter,
--               verse, verse_segment, text_tradition, printed_chapter: the
--               columns of <T>_identity, migration 046; never sort_order), the
--               field ('text', 'title1'..'title3', or 'chapter' for a chapter
--               heading such as "PSALM 23"), and the position in code points
--               into that field, formatting tags included, on a token boundary
--               (as title_position). NULL position = the verse as a whole (its
--               number). A note covering a range records its last verse. A
--               note may have several anchors.
-- note_ref:     each passage a note cites, resolved at import to the
--               edition's stored verse, with the span of the body (code
--               points) that prints it.
--
-- The tables are empty here: the notes are copyrighted, and come from private
-- data halves (NABRE-source/migrations/064-nabre-notes.sql, ...), applied with
-- psql after this migration. Notes are served only to reader-scope keys.
-- Idempotent: CREATE ... IF NOT EXISTS.
--
-- Run by Doctrine (src/Migrations/Version064Notes.php): no BEGIN/COMMIT.

CREATE TABLE IF NOT EXISTS note (
    id         SERIAL PRIMARY KEY,
    edition    VARCHAR(10) NOT NULL,
    kind       VARCHAR(8)  NOT NULL CHECK (kind IN ('footnote', 'crossref')),
    marker     VARCHAR(8),
    label      VARCHAR(40),
    title      TEXT,
    body       TEXT        NOT NULL,
    source_id  VARCHAR(40) NOT NULL,
    UNIQUE (edition, source_id)
);

CREATE TABLE IF NOT EXISTS note_anchor (
    id               SERIAL PRIMARY KEY,
    note_id          INT NOT NULL REFERENCES note (id) ON DELETE CASCADE,
    book             INT NOT NULL,
    chapter          INT NOT NULL,
    verse            INT NOT NULL,
    verse_segment    VARCHAR(4),
    text_tradition   VARCHAR(10),
    printed_chapter  INT,
    field            VARCHAR(8) NOT NULL CHECK (field IN ('text', 'title1', 'title2', 'title3', 'chapter')),
    position         INT CHECK (position >= 0),
    last_book        INT,
    last_chapter     INT,
    last_verse       INT
);
CREATE INDEX IF NOT EXISTS note_anchor_verse ON note_anchor (book, chapter, verse);
CREATE INDEX IF NOT EXISTS note_anchor_note ON note_anchor (note_id);

CREATE TABLE IF NOT EXISTS note_ref (
    id               SERIAL PRIMARY KEY,
    note_id          INT NOT NULL REFERENCES note (id) ON DELETE CASCADE,
    ord              SMALLINT NOT NULL,
    book             INT NOT NULL,
    chapter          INT NOT NULL,
    verse            INT,
    verse_segment    VARCHAR(4),
    printed_chapter  INT,
    end_book         INT,
    end_chapter      INT,
    end_verse        INT,
    span_start       INT NOT NULL CHECK (span_start >= 0),
    span_end         INT NOT NULL CHECK (span_end >= span_start),
    UNIQUE (note_id, ord)
);
