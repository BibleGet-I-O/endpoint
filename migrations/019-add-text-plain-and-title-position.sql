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
-- whose markup changes the text become stale. Only embedding_labse (the
-- default column) tracks text_hash; MiniLM `embedding` is only filled where
-- NULL. So first NULL `embedding` where text_hash != embedded_text_hash
-- (before the LaBSE run resets the hash), then run compute_embeddings.py
-- and compute_embeddings.py --column embedding (the column selects its model:
-- MiniLM at its pinned revision, unless EMBEDDING_MODEL overrides it).
--
-- Idempotent: columns, index and trigger are created only if missing, the
-- title_position constraint is recreated, and the backfill recomputes the
-- same values. It rewrites every row, so run `VACUUM ANALYZE` on the edition
-- tables afterwards.

CREATE OR REPLACE FUNCTION bibleget_plain_text(src TEXT) RETURNS TEXT
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
    SELECT btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(src,
        '</?(sm|i|sup)>', '', 'g'),
        '</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>', ' ', 'g'),
        '<br\s*/?>', ' ', 'gi'),
        '\s+', ' ', 'g'))
$$;

-- A heading may only be inserted between tokens: never splitting a word
-- (judged on the *visible* characters either side, so an offset inside
-- L<sm>|ORD</sm> is rejected even though the raw neighbour is '>'), never
-- inside a tag, never at either end of the text. Inline tags (sm, i, sup)
-- vanish in the visible text; any other tag counts as a space, as in
-- bibleget_plain_text().
CREATE OR REPLACE FUNCTION bibleget_title_position_ok(src TEXT, pos INT) RETURNS BOOLEAN
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
    SELECT pos IS NULL OR (
        pos > 0 AND pos < char_length(src)
        AND left(src, pos) !~ '<[^>]*$'
        AND NOT (
            right(regexp_replace(regexp_replace(left(src, pos), '</?(sm|i|sup)>', '', 'g'), '<[^>]*>', ' ', 'g'), 1) ~ '[[:alnum:]]'
            AND left(regexp_replace(regexp_replace(substr(src, pos + 1), '</?(sm|i|sup)>', '', 'g'), '<[^>]*>', ' ', 'g'), 1) ~ '[[:alnum:]]'
        )
    )
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

        -- Guard (see bibleget_title_position_ok above). Recreated on every
        -- run so databases that got an earlier version of the rule converge.
        EXECUTE format('ALTER TABLE %I DROP CONSTRAINT IF EXISTS %I', tbl, tbl || '_title_position_check');
        EXECUTE format(
            'ALTER TABLE %I ADD CONSTRAINT %I CHECK (bibleget_title_position_ok(text, title_position))',
            tbl, tbl || '_title_position_check'
        );

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
    pre   BYTEA;
BEGIN
    FOR rec IN SELECT sigla, ts_language FROM versions_available LOOP
        IF to_regclass(format('public.%I', rec.sigla)) IS NULL THEN
            CONTINUE;
        END IF;
        -- Content fingerprint before the backfill (text_hash changes for every
        -- row whose text_plain differs from text).
        EXECUTE format('SELECT bytea_xor_agg(text_hash) FROM %I', rec.sigla) INTO pre;
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
        -- so EmbeddingModelValidator does not report them stale. Every
        -- embedding column's metadata row is refreshed (embedding_labse is
        -- the hash-tracked one, `embedding` the MiniLM one), but only where
        -- it was current before the backfill: a row that was already stale
        -- must stay stale.
        EXECUTE format('SELECT count(*) FROM %I WHERE text_hash IS DISTINCT FROM embedded_text_hash', rec.sigla)
            INTO stale;
        IF stale = 0 THEN
            EXECUTE format(
                'UPDATE embedding_metadata SET content_xor = (SELECT bytea_xor_agg(text_hash) FROM %I) '
                || 'WHERE version_sigla = %L AND content_xor IS NOT DISTINCT FROM %L::bytea',
                rec.sigla, rec.sigla, pre
            );
        END IF;
    END LOOP;
END
$$;
