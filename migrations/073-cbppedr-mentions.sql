-- Where the entries of CBPPEDR are named in an edition's text, so the reader can mark them, and what a card shows of
-- an entry. Loaded by scripts/load_cbppedr.php, which replaces every row. The positions in bpe_mention are already
-- converted to code points of the row's formatted text, and are valid only against plain_hash (the SHA-256 hex of the
-- row's text_plain when it was loaded). A mention names either its entry or, while the identification is under
-- review, the candidate entries. bpe_verse_count is filled after the load, from the entity index.
-- Design: the website's docs/superpowers/specs/2026-11-27-mentions-and-cards-design.md.

ALTER TABLE bpe_attested ADD COLUMN IF NOT EXISTS mentioned boolean NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS bpe_mention (
    edition    varchar(16) NOT NULL,
    book       smallint    NOT NULL,
    chapter    integer     NOT NULL,
    verse      integer     NOT NULL,
    segment    text        NULL,
    tradition  text        NULL,
    unit       text        NOT NULL,
    plain_hash text        NOT NULL,
    start_cp   integer     NOT NULL CHECK (start_cp >= 0),
    end_cp     integer     NOT NULL,
    entry      text        NULL REFERENCES bpe_entry (id) ON DELETE CASCADE,
    candidates text[]      NULL CHECK (candidates IS NULL OR cardinality(candidates) >= 1),
    CHECK (end_cp > start_cp),
    CHECK ((entry IS NULL) <> (candidates IS NULL))
);

CREATE INDEX IF NOT EXISTS bpe_mention_chapter ON bpe_mention (edition, book, chapter);

CREATE TABLE IF NOT EXISTS bpe_detail (
    entry     text PRIMARY KEY REFERENCES bpe_entry (id) ON DELETE CASCADE,
    image     jsonb NULL,
    wikipedia jsonb NULL,
    coords    jsonb NULL
);

CREATE TABLE IF NOT EXISTS bpe_verse_count (
    edition varchar(16) NOT NULL,
    entry   text        NOT NULL REFERENCES bpe_entry (id) ON DELETE CASCADE,
    verses  integer     NOT NULL,
    PRIMARY KEY (edition, entry)
);
