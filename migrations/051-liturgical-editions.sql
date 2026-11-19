-- 051: Flag the Bible editions whose text is the one used in the liturgy of
-- their language.
--
-- versions_available.liturgical is TRUE for an edition that is the liturgical
-- text of its language, so clients (the website's reader, for one) can offer it
-- first or mark it. /metadata/bibleversions lists the flagged editions under
-- `liturgicalversions`, and the admin API (/admin/versions) can switch the flag.
--
-- Flagged here:
--   NABRE    the basis of the Lectionary used in the United States
--   CEI2008  the text of the Italian Lectionary and Missal
--   NVBSE    the Nova Vulgata, the Latin typical edition
-- BLPD is deliberately not flagged: the Argentine bishops' conference
-- recognized it, but its use in the Lectionary is unconfirmed. An admin can
-- switch it on later.
--
-- Idempotent: the column is added only if missing, and rerunning the UPDATE
-- sets the same rows to the same value (editions an admin has since switched
-- on are untouched; ones switched off for these three would be flagged again,
-- so apply this once).

BEGIN;

ALTER TABLE versions_available
    ADD COLUMN IF NOT EXISTS liturgical BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE versions_available
   SET liturgical = TRUE
 WHERE sigla IN ('NABRE', 'CEI2008', 'NVBSE')
   AND type = 'BIBLE';

COMMIT;
