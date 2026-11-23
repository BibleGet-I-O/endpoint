-- 068: the Vulgate's garbled "œ". VGCL's import read Windows-1252 text as
-- Latin-1, leaving the control character U+009C (byte 0x9C) where "œ" stands:
-- 925 times in 879 verses, always inside a word ("fœderis", "cœpit",
-- "pœnitentiam"). Being no letter, it also split those words for the search.
-- One character for one: positions do not move.
--
-- The text_plain trigger follows; the vocabulary is refreshed. Those rows'
-- embeddings become stale: run scripts/compute_embeddings.py. Idempotent.
-- Run by Doctrine (src/Migrations/Version068VgclOe.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    n INTEGER;
BEGIN
    IF to_regclass('public."VGCL"') IS NULL THEN
        RETURN;
    END IF;
    UPDATE "VGCL" SET text = replace(text, chr(156), 'œ') WHERE strpos(text, chr(156)) > 0;
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n > 0 AND EXISTS (SELECT 1 FROM versions_available WHERE sigla = 'VGCL') THEN
        PERFORM refresh_search_vocabulary('VGCL');
    END IF;
END
$mig$;
