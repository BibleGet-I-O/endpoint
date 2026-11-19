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
-- Idempotent: the initial flags are set only when the column is first added,
-- so rerunning this changes nothing, and editions an admin has since switched
-- on or off keep their value.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'versions_available'
           AND column_name = 'liturgical'
    ) THEN
        ALTER TABLE versions_available
            ADD COLUMN liturgical BOOLEAN NOT NULL DEFAULT FALSE;

        UPDATE versions_available
           SET liturgical = TRUE
         WHERE sigla IN ('NABRE', 'CEI2008', 'NVBSE')
           AND type = 'BIBLE';
    END IF;
END
$$;

COMMIT;
