-- 067: NABRE's garbled ellipses. The publisher's SCML holds the ellipsis as the
-- Windows-1252 byte 0x85; the original import read it as UTF-8, leaving the
-- replacement character (U+FFFD) in 12 verses (Ex 2:25, Nm 33:40, 1 Sm 10:9,
-- 13:1, 2 Sm 8:1, 1 Chr 4:20, 7:11, Ezr 4:10, Neh 12:17, Ps 6:4, 60:1, Ez
-- 23:43), each checked against the source. One character for one: positions
-- (title_position, note_anchor.position) do not move.
--
-- The text_plain trigger follows; the vocabulary is refreshed. Those 12 rows'
-- embeddings become stale: run scripts/compute_embeddings.py. Idempotent.
-- Run by Doctrine (src/Migrations/Version067NabreEllipsis.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    n INTEGER;
BEGIN
    IF to_regclass('public."NABRE"') IS NULL THEN
        RETURN;
    END IF;
    UPDATE "NABRE" SET text = replace(text, chr(65533), '…') WHERE strpos(text, chr(65533)) > 0;
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n > 0 AND EXISTS (SELECT 1 FROM versions_available WHERE sigla = 'NABRE') THEN
        PERFORM refresh_search_vocabulary('NABRE');
    END IF;
END
$mig$;
