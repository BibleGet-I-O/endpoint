-- The persons, places and events of the Bible from CBPPEDR (CatholicOS/cbppedr): each entry, the CBTUDR units where it
-- is attested, and for a labelled unit the editions whose text names the entry there (the label's words are not
-- loaded). Loaded by scripts/load_cbppedr.php, which replaces every row; served by /v3/metadata/entities.
-- Design: the website's docs/superpowers/specs/2026-11-27-persons-places-events-design.md.

CREATE TABLE IF NOT EXISTS bpe_entry (
    id          text PRIMARY KEY,
    type        text    NOT NULL CHECK (type IN ('person', 'place', 'event')),
    kind        text    NOT NULL,
    names       jsonb   NOT NULL,
    description jsonb   NOT NULL,
    qid         text    NULL,
    qid_status  text    NULL,
    uncertain   boolean NOT NULL DEFAULT false
);

CREATE TABLE IF NOT EXISTS bpe_attested (
    entry text    NOT NULL REFERENCES bpe_entry (id) ON DELETE CASCADE,
    unit  text    NOT NULL,
    ord   integer NOT NULL,
    PRIMARY KEY (entry, unit)
);

CREATE INDEX IF NOT EXISTS bpe_attested_unit ON bpe_attested (unit);

CREATE TABLE IF NOT EXISTS bpe_label (
    entry    text   NOT NULL REFERENCES bpe_entry (id) ON DELETE CASCADE,
    unit     text   NOT NULL,
    editions text[] NOT NULL,
    PRIMARY KEY (entry, unit)
);

CREATE TABLE IF NOT EXISTS bpe_release (
    id        bigserial PRIMARY KEY,
    loaded_at timestamptz NOT NULL DEFAULT now(),
    commit    text        NOT NULL,
    counts    jsonb       NOT NULL
);
