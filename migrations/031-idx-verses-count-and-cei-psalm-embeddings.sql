-- 031: VGCL/DRB verses_count, and stale CEI2008 psalm embeddings.
--
-- 1. VGCL_idx and DRB_idx had verses_count NULL for every book on staging:
--    migrate-mariadb-to-pgsql.sh copied these two idx tables with a column
--    list that left verses_count out (fixed there too). MariaDB has the
--    values; recomputed here from the verse rows as in 025/028 (number of
--    distinct verse numbers per chapter, comma-separated). The result was
--    checked against MariaDB's VGCL_idx and DRB_idx on 2026-11-15: all 146
--    books identical.
--
-- 2. 023 put CEI2008's psalm superscriptions on their own untagged line, so
--    under 022's rule bibleget_embedding_text() leaves them out of what is
--    embedded. But 023 changed neither text_plain nor text_hash, so the 53
--    rows were never marked stale and still carry embeddings of the full
--    text_plain (checked on staging: LaBSE cosine 1.00 to text_plain,
--    0.85-0.90 to the embedding text). As 022 describes, NULL
--    embedded_text_hash (LaBSE, hash-tracked) and the MiniLM `embedding`
--    (only filled where NULL), then run scripts/compute_embeddings.py for
--    CEI2008 with the default column and with --column embedding.
--    Only rows whose embedding text differs from text_plain are touched, so
--    re-applying it after re-embedding just marks them stale again.

BEGIN;

DO $$
DECLARE
    t TEXT;
    n INT;
BEGIN
    FOREACH t IN ARRAY ARRAY['VGCL', 'DRB'] LOOP
        EXECUTE format(
            'UPDATE %I i SET verses_count = x.cnt '
            || 'FROM (SELECT book, string_agg(d::text, '','' ORDER BY chapter) AS cnt '
            || '      FROM (SELECT book, chapter, count(DISTINCT verse) AS d FROM %I GROUP BY book, chapter) c '
            || '      GROUP BY book) x '
            || 'WHERE i.book = x.book AND i.verses_count IS DISTINCT FROM x.cnt',
            t || '_idx', t);
        GET DIAGNOSTICS n = ROW_COUNT;
        RAISE NOTICE '%_idx: verses_count set for % books', t, n;
        EXECUTE format('SELECT count(*) FROM %I WHERE verses_count IS NULL', t || '_idx') INTO n;
        IF n > 0 THEN
            RAISE EXCEPTION '%_idx: % books still have no verses_count', t, n;
        END IF;
    END LOOP;
END $$;

DO $$
DECLARE
    n INT;
BEGIN
    UPDATE "CEI2008" SET embedded_text_hash = NULL, embedding = NULL
    WHERE book = 23 AND bibleget_embedding_text(book, text) <> text_plain;
    GET DIAGNOSTICS n = ROW_COUNT;
    RAISE NOTICE 'CEI2008: % psalm verses marked for re-embedding', n;
END $$;

COMMIT;
