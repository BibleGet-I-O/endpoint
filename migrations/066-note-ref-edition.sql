-- 066: a note's citation may point into another edition. CEI 2008's notes give
-- the Nova Vulgata's readings of Sirach, Esther and Tobit at the Nova Vulgata's
-- own verse numbers ("24,1a NVg (24,1b) aggiunge: ..."): those citations are
-- verses of NVBSE, not of CEI2008. note_ref.edition names that edition; NULL
-- (every other citation) means the note's own edition.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS. Run by Doctrine
-- (src/Migrations/Version066NoteRefEdition.php): no BEGIN/COMMIT.

ALTER TABLE note_ref ADD COLUMN IF NOT EXISTS edition VARCHAR(10);
