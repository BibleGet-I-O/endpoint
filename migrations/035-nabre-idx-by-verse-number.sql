-- 035: NABRE_idx chapter bounds and counts by verse number.
--
-- A reference now selects verses by the number the edition prints (see
-- src/Util/PrintedLabel.php): NABRE prints Wis 12:1 at the end of chapter 11
-- (stored as chapter 11, verse 26, verseequiv '12:1') and the first words of
-- Job 10:1 at the end of chapter 9 (chapter 9, verse 35, verseequiv '10:1a').
-- The index must count those verses in the chapter they belong to. It didn't:
-- Wis 11's last verse was stored as '12:1', which the validator read as 12,
-- so "Wis11:26" failed with "the last possible verse is 12"; and John 7:53,
-- which NABRE prints at the start of chapter 8, was counted there, so
-- "John7:53" failed with "the last possible verse is 52".
--
-- Recomputed from the rows, by cited chapter and verse, with 031's rule:
-- verses_count = distinct verse numbers per chapter, verses_last = highest.
-- Esther (19) is left alone: its index has its own layout (Greek additions).
-- A dry run on staging (2026-11-15) changes exactly these chapters:
--
--   book  chapter     count old -> new   last old -> new   why
--   17    6           17 -> 18           18                stale count
--   22    9           36 -> 35           35                the 10:1a row was counted
--   22    10          21 -> 22           22                stale count
--   27    11          27 -> 26           12:1 -> 26        the 12:1 row; Wis11:26
--   27    12          26 -> 27           27                12:1 now counted here
--   28    33/41/47    34/26/27 -> 33/22/25  unchanged      lettered rows were counted
--   50    7           52 -> 53           52 -> 53          John7:53
--   50    8           60 -> 59           59                7:53 was counted here
--
-- Other editions' counts that don't match their rows (BLPD, NVBSE) are #187.
-- Finally every *_idx table is checked: a non-integer token in verses_last or
-- verses_count aborts the migration, an empty field ('26,,27') included. A
-- wholly empty or NULL list yields no tokens (none exist on staging).

BEGIN;

DO $$
DECLARE
    r RECORD;
    n INT := 0;
BEGIN
    FOR r IN
        WITH cited AS (
            SELECT book,
                   CASE WHEN coalesce(verseequiv, '') ~ '^[0-9]+:[0-9]+[a-z]*$'
                        THEN split_part(verseequiv, ':', 1)::int ELSE chapter END AS c,
                   CASE WHEN coalesce(verseequiv, '') ~ '^[0-9]+:[0-9]+[a-z]*$'
                        THEN substring(split_part(verseequiv, ':', 2) from '^[0-9]+')::int ELSE verse END AS v
            FROM "NABRE"
            WHERE book <> 19
        ),
        per_chapter AS (
            SELECT book, c, count(DISTINCT v) AS cnt, max(v) AS last FROM cited GROUP BY book, c
        ),
        per_book AS (
            SELECT book, count(*) AS chapters, max(c) AS max_c,
                   string_agg(cnt::text, ',' ORDER BY c) AS cnt,
                   string_agg(last::text, ',' ORDER BY c) AS last
            FROM per_chapter GROUP BY book
        )
        SELECT p.*, i.verses_count AS old_cnt, i.verses_last AS old_last
        FROM per_book p JOIN "NABRE_idx" i USING (book)
    LOOP
        IF r.chapters <> r.max_c THEN
            RAISE EXCEPTION 'NABRE book %: chapters are not 1..% (% present)', r.book, r.max_c, r.chapters;
        END IF;
        IF r.cnt IS DISTINCT FROM r.old_cnt OR r.last IS DISTINCT FROM r.old_last THEN
            UPDATE "NABRE_idx" SET verses_count = r.cnt, verses_last = r.last WHERE book = r.book;
            RAISE NOTICE 'NABRE_idx book %: verses_count % -> %; verses_last % -> %',
                r.book, r.old_cnt, r.cnt, r.old_last, r.last;
            n := n + 1;
        END IF;
    END LOOP;
    RAISE NOTICE 'NABRE_idx: % books updated', n;
END $$;

DO $$
DECLARE
    t TEXT;
    bad TEXT;
BEGIN
    FOR t IN SELECT sigla FROM versions_available LOOP
        IF to_regclass(format('%I', t || '_idx')) IS NULL THEN
            CONTINUE;
        END IF;
        EXECUTE format(
            'SELECT string_agg(DISTINCT book || '':'' || tok, '', '') FROM %I, '
            || 'unnest(string_to_array(verses_last, '','') || string_to_array(coalesce(verses_count, ''''), '','')) AS tok '
            || 'WHERE tok !~ ''^[0-9]+$''',
            t || '_idx') INTO bad;
        IF bad IS NOT NULL THEN
            RAISE EXCEPTION '%_idx has non-integer index tokens (book:token): %', t, bad;
        END IF;
    END LOOP;
END $$;

COMMIT;
