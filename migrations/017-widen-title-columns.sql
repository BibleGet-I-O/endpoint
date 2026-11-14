-- Migration: Widen title1/title2/title3 to VARCHAR(255) on every edition table
--
-- title1-title3 hold the section headings that precede a verse (first,
-- second and third level). VARCHAR(100) is too short for real editions:
-- 26 LUZZI headings exceed it, the longest at 203 characters (Deut 27:1).
-- Widen every edition table, not only LUZZI, so the idx/edition schema stays
-- uniform for when other editions get their headings.
--
-- VARCHAR(n) -> VARCHAR(m > n) is binary-coercible in PostgreSQL: no table
-- rewrite, and the *_title*_fts expression indexes stay valid.
-- Nullability and defaults are unchanged.
--
-- Idempotent: tables that are absent are skipped, and re-applying the same
-- type is a no-op. The companion MariaDB change for the legacy v3 database
-- is a one-shot ALTER on live and is not scripted here.

DO $$
DECLARE
    tbl TEXT;
    col TEXT;
BEGIN
    FOREACH tbl IN ARRAY ARRAY['BLPD', 'CEI2008', 'DIVCOM', 'DRB', 'LUZZI', 'NABRE', 'NVBSE', 'VGCL'] LOOP
        IF to_regclass(format('public.%I', tbl)) IS NULL THEN
            CONTINUE;
        END IF;
        FOREACH col IN ARRAY ARRAY['title1', 'title2', 'title3'] LOOP
            EXECUTE format('ALTER TABLE %I ALTER COLUMN %I TYPE VARCHAR(255)', tbl, col);
        END LOOP;
    END LOOP;
END
$$;
