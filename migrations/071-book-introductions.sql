-- 071: the editions' introductions, as printed: each book's (one may serve two,
-- as NABRE's to 1-2 Samuel), and the general ones that open a group of books
-- (docs/superpowers/specs/2026-11-24-outline-introductions-design.md). They have
-- no chapter or verse; blocks holds their headings, paragraphs, outline items
-- and table rows in order, each with its citations. Given to the reader key only.
--
-- The table is empty here: the rows come from private data halves
-- (scripts/introductions.py), applied with psql. Idempotent. Run by Doctrine:
-- no BEGIN/COMMIT.

CREATE TABLE IF NOT EXISTS book_introduction (
    edition  VARCHAR(10) NOT NULL,
    id       VARCHAR(40) NOT NULL CHECK (id ~ '^[a-z0-9-]+$'),
    ord      INT NOT NULL,
    title    TEXT NOT NULL,
    books    INT[] NOT NULL CHECK (cardinality(books) > 0),
    general  BOOLEAN NOT NULL DEFAULT false,
    blocks   JSONB NOT NULL CHECK (jsonb_typeof(blocks) = 'array'),
    PRIMARY KEY (edition, id)
);

CREATE INDEX IF NOT EXISTS book_introduction_books ON book_introduction USING gin (books);
