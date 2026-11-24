-- 069: each edition's titles with their level, book by book: the index the
-- reader opens from the chapter's title line (docs/superpowers/specs/
-- 2026-11-24-outline-introductions-design.md). title1-title3 hold the printed
-- headings but no level, and CEI 2008 prints headings in few books; there, the
-- titles of its commentary serve, for reader keys only (reader_only).
--
-- A title is keyed to its verse by identity (migration 046). The table is
-- empty here: the rows come from private data halves (scripts/outline.py),
-- applied with psql. Idempotent. Run by Doctrine: no BEGIN/COMMIT.

CREATE TABLE IF NOT EXISTS book_outline (
    edition          VARCHAR(10) NOT NULL,
    book             INT NOT NULL,
    ord              INT NOT NULL,
    level            SMALLINT NOT NULL CHECK (level BETWEEN 1 AND 6),
    title            TEXT NOT NULL,
    chapter          INT NOT NULL,
    verse            INT NOT NULL,
    verse_segment    VARCHAR(4),
    text_tradition   VARCHAR(10),
    printed_chapter  INT,
    reader_only      BOOLEAN NOT NULL DEFAULT false,
    PRIMARY KEY (edition, book, ord)
);
