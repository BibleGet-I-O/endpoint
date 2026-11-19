-- Review store for CBTUDR unit links (CatholicOS/cbtudr): the units, and each edition's links to them with
-- their status, so that drafted links can be reviewed on staging and exported back to the registry.
-- docs/superpowers/specs/2026-11-19-unit-mapping-review-design.md. Loaded by scripts/load_cbtudr.php.

CREATE TABLE IF NOT EXISTS btu_unit (
    id         text PRIMARY KEY,
    book       smallint NOT NULL,
    ord        integer  NOT NULL,
    parent     text     NULL,
    after      text     NULL,
    nv_omitted boolean  NOT NULL DEFAULT false
);

-- A stretch: the verses and units between two rule links, within which a draft may be relinked.
CREATE TABLE IF NOT EXISTS btu_stretch (
    edition varchar(16) NOT NULL,
    book    smallint    NOT NULL,
    id      text        NOT NULL,
    units   text[]      NOT NULL,
    PRIMARY KEY (edition, book, id)
);

CREATE TABLE IF NOT EXISTS btu_link (
    id          bigserial PRIMARY KEY,
    edition     varchar(16) NOT NULL,
    book        smallint    NOT NULL,
    ord         integer     NOT NULL,
    ref         text        NOT NULL,
    units       text[]      NOT NULL,
    kind        text        NOT NULL CHECK (kind IN ('same', 'corresponding')),
    status      text        NOT NULL CHECK (status IN ('rule', 'draft', 'reviewed')),
    confidence  real        NULL,
    stretch     text        NULL,
    note        text        NULL,
    reviewed_by text        NULL,
    reviewed_at timestamptz NULL,
    UNIQUE (edition, book, ref)
);

CREATE INDEX IF NOT EXISTS btu_link_edition_status ON btu_link (edition, status);
CREATE INDEX IF NOT EXISTS btu_link_edition_book_ord ON btu_link (edition, book, ord);
