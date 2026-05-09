-- Migration: Normalize NABRE.verse to INT
-- Purpose: NABRE.verse is the only edition that uses VARCHAR for the verse
--          column. The PHP API's SqlCompiler emits `... AND verse = N` as a
--          bare integer literal; PostgreSQL refuses
--          `character varying = integer` (no implicit cast), which means
--          NABRE quotes do not work at all on the PG backend today. The
--          column is VARCHAR because of 8 anomalous rows whose verse value
--          carries either a sub-verse letter ('16a', '16b', …) or a
--          cross-chapter bridge marker ('12:1' in Wisdom 11). All 8 rows
--          can be normalized into the (verse, verseequiv) / (chapter, verse)
--          conventions used by every other edition.
--
-- Audit:   See the "NABRE Verse Anomaly Audit" wiki page. Verified that the
--          8 NABRE anomalies all have identical text in NABRE_old (nothing
--          changed in transition), and that the 2 NABRE_old-only anomalies
--          were correctly relocated to canonical numeric positions in NABRE.
--
-- Scope:   Only NABRE is normalized. NABRE_old is a legacy backup and is
--          left as VARCHAR.
--
-- Idempotent: the entire migration body runs inside a single DO block that
--             returns early when NABRE.verse is already INT. Re-running
--             after a successful apply is a clean no-op.

DO $$
DECLARE
    col_type TEXT;
    n_anom   INT;
BEGIN
    SELECT data_type INTO col_type
      FROM information_schema.columns
     WHERE table_schema = current_schema()
       AND table_name   = 'NABRE'
       AND column_name  = 'verse';

    IF col_type = 'integer' THEN
        RAISE NOTICE 'NABRE.verse is already integer — migration already applied. Skipping.';
        RETURN;
    END IF;

    -- Pre-flight: must find exactly 8 non-numeric rows.
    SELECT COUNT(*) INTO n_anom FROM "NABRE" WHERE verse !~ '^[0-9]+$';
    IF n_anom <> 8 THEN
        RAISE EXCEPTION 'Pre-flight: expected 8 non-numeric NABRE.verse rows, got %', n_anom;
    END IF;

    -- 7 sub-verse rows: '9b', '10b', '16a', '16b', '20a', '20b', '21c'
    -- → verse = numeric prefix, verseequiv = full label.
    UPDATE "NABRE"
       SET verseequiv = verse,
           verse      = regexp_replace(verse, '[a-z]+$', '')
     WHERE verse ~ '^[0-9]+[a-z]+$';

    -- 1 bridge row: Wisdom 11 v'12:1'. NABRE editorially places the text
    -- "for your imperishable spirit is in all things!" at the end of
    -- chapter 11 (canonically Wis 12:1 in the Vulgate / Bible Gateway
    -- NABRE rendering). We follow the same convention NABRE itself uses
    -- for Job 9:35-vs-10:1a: keep `verse` at the previous numeric value
    -- (here, 26 — the last canonical verse of Wis 11) and record the
    -- canonical citation in versedescr + verseequiv. This produces a
    -- second row at Wis 11:26, distinguished by versedescr/verseequiv.
    UPDATE "NABRE"
       SET chapter    = 11,
           verse      = '26',
           versedescr = '12:1',
           verseequiv = '12:1'
     WHERE book = 27 AND chapter = 11 AND verse = '12:1';

    -- Post-flight: every NABRE.verse must now be purely numeric.
    SELECT COUNT(*) INTO n_anom FROM "NABRE" WHERE verse !~ '^[0-9]+$';
    IF n_anom <> 0 THEN
        RAISE EXCEPTION 'Post-flight: still % non-numeric NABRE.verse rows', n_anom;
    END IF;

    -- Convert column type. PG rebuilds the dependent indexes automatically.
    EXECUTE 'ALTER TABLE "NABRE" ALTER COLUMN verse TYPE INT USING verse::int';

    RAISE NOTICE 'NABRE.verse normalized: 7 sub-verse rows split, 1 bridge row relocated, column converted to INT.';
END $$;
