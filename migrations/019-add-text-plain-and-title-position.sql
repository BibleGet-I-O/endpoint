-- Migration: Add text_plain and title_position to every edition table (#123)
--
-- `text` carries typographic markup (NABRE's <po>/<poi>/<sm>/…, and from
-- migration 020 LUZZI's poetic lines and italics). It is right for
-- rendering a quote but wrong for search: markup tokens leak into the
-- keyword index and into the embedding input. This migration splits the two
-- jobs:
--
--   text            formatted, for rendering (unchanged meaning)
--   text_plain      derived from `text` by bibleget_plain_text(); read by
--                   keyword search, by the embedding pipeline, and hashed
--                   into text_hash
--   title_position  where the verse's headings (title1-title3) go inside the
--                   verse: a 0-based character offset into `text` (tags
--                   count as characters, as in the footnote design), or
--                   NULL = before the verse (the usual case). A renderer
--                   emits text[:pos] + headings + text[pos:].
--
-- text_plain is derived, never edited: the existing text_hash trigger now
-- also sets it, so the two columns cannot drift.
--
-- Normalization (bibleget_plain_text): a whitelist, so unknown angle-bracket
-- content (e.g. VGCL's <Aleph>, <Sponsa>) is left untouched.
--   inline tags   <sm> <i> <sup>          removed, inner text joined:
--                                          L<sm>ORD</sm> -> LORD
--   line/block    <po> <pof> <pol> <poi> <poil> <poif> <pos> <po3> <po3l>
--                 <pr> <bq> <bqf> <bql> <speaker> <br/>   -> a space
--   then whitespace runs collapse to one space and the result is trimmed.
--
-- Embeddings: text_hash now hashes text_plain. Where text and text_plain
-- differ only in whitespace, the stored vectors are still exact (MiniLM and
-- LaBSE produce bit-identical vectors for whitespace variants; verified
-- 2026-11-14), so embedded_text_hash is carried forward for those rows. Rows
-- whose markup changes the text become stale and need
-- `compute_embeddings.py` (for embedding_labse: NULL it where
-- text_hash != embedded_text_hash first).
--
-- Idempotent: columns, constraint, index and trigger are created only if
-- missing; the backfill recomputes the same values. It rewrites every row,
-- so run `VACUUM ANALYZE` on the edition tables afterwards.

CREATE OR REPLACE FUNCTION bibleget_plain_text(src TEXT) RETURNS TEXT
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(src,
        '</?(sm|i|sup)>', '', 'g'),
        '</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>', ' ', 'g'),
        '<br\s*/?>', ' ', 'gi'),
        '\s+', ' ', 'g'))
$$;

DO $$
DECLARE
    rec  RECORD;
    tbl  TEXT;
BEGIN
    FOR rec IN SELECT sigla, ts_language FROM versions_available LOOP
        tbl := rec.sigla;
        IF to_regclass(format('public.%I', tbl)) IS NULL THEN
            CONTINUE;
        END IF;

        EXECUTE format('ALTER TABLE %I ADD COLUMN IF NOT EXISTS text_plain VARCHAR(900) NOT NULL DEFAULT %L', tbl, '');
        EXECUTE format('ALTER TABLE %I ADD COLUMN IF NOT EXISTS title_position SMALLINT', tbl);

        -- Guard: a heading may only be inserted between tokens -- never
        -- splitting a word, never inside a tag, never at either end.
        IF NOT EXISTS (SELECT 1 FROM pg_constraint
                       WHERE conname = tbl || '_title_position_check'
                         AND conrelid = format('public.%I', tbl)::regclass) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD CONSTRAINT %I CHECK (title_position IS NULL OR ('
                || 'title_position > 0 AND title_position < char_length(text) '
                || 'AND NOT (substr(text, title_position, 1) ~ %L AND substr(text, title_position + 1, 1) ~ %L) '
                || 'AND left(text, title_position) !~ %L))',
                tbl, tbl || '_title_position_check', '[[:alnum:]]', '[[:alnum:]]', '<[^>]*$'
            );
        END IF;

        IF NOT EXISTS (SELECT 1 FROM pg_trigger
                       WHERE tgname = tbl || '_text_hash_trigger'
                         AND tgrelid = format('public.%I', tbl)::regclass) THEN
            EXECUTE format(
                'CREATE TRIGGER %I BEFORE INSERT OR UPDATE OF text ON %I '
                || 'FOR EACH ROW EXECUTE FUNCTION update_text_hash()',
                tbl || '_text_hash_trigger', tbl
            );
        END IF;
    END LOOP;
END
$$;

-- The trigger function now derives text_plain and hashes it. Replaced after
-- the columns exist on every table it is attached to.
CREATE OR REPLACE FUNCTION update_text_hash()
RETURNS TRIGGER AS $$
BEGIN
    NEW.text_plain := bibleget_plain_text(NEW.text);
    NEW.text_hash  := decode(md5(NEW.text_plain), 'hex');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Backfill (fires the trigger) and carry embedding freshness forward for
-- whitespace-only differences. Right-hand expressions see the OLD row.
DO $$
DECLARE
    rec   RECORD;
    stale BIGINT;
BEGIN
    FOR rec IN SELECT sigla, ts_language FROM versions_available LOOP
        IF to_regclass(format('public.%I', rec.sigla)) IS NULL THEN
            CONTINUE;
        END IF;
        EXECUTE format(
            'UPDATE %I SET text = text, embedded_text_hash = CASE '
            || 'WHEN embedded_text_hash = text_hash '
            || 'AND btrim(regexp_replace(text, %L, %L, %L)) = bibleget_plain_text(text) '
            || 'THEN decode(md5(bibleget_plain_text(text)), %L) ELSE embedded_text_hash END',
            rec.sigla, '\s+', ' ', 'g', 'hex'
        );
        -- Index text_plain only after the backfill: an index on the column
        -- being filled would make every row update non-HOT and add entries
        -- to every other index (including the HNSW vector indexes).
        EXECUTE format(
            'CREATE INDEX IF NOT EXISTS %I ON %I USING gin (to_tsvector(%L, text_plain))',
            rec.sigla || '_text_plain_fts', rec.sigla, rec.ts_language
        );
        -- Versions left fully fresh get their content fingerprint refreshed,
        -- so EmbeddingModelValidator does not report them stale.
        EXECUTE format('SELECT count(*) FROM %I WHERE text_hash IS DISTINCT FROM embedded_text_hash', rec.sigla)
            INTO stale;
        IF stale = 0 THEN
            EXECUTE format(
                'UPDATE embedding_metadata SET content_xor = (SELECT bytea_xor_agg(text_hash) FROM %I) '
                || 'WHERE version_sigla = %L AND column_name = %L',
                rec.sigla, rec.sigla, 'embedding'
            );
        END IF;
    END LOOP;
END
$$;
