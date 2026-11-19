-- 052: The Nova Vulgata (NVBSE): copyrighted by the Libreria Editrice Vaticana
-- (LEV), and dated 1986.
--
-- Year: the text imported from vatican.va (migration 041) is the second typical
-- edition, "editio typica altera" on its title page, of 1986. The first typical
-- edition was promulgated in 1979 (John Paul II, "Scripturarum thesaurus"),
-- the year recorded until now.
--
-- Copyright: it was imported as public domain, so /quote served it whole to every caller
-- and /metadata/bibleversions left it out of `copyrightversions`. Flagged, it
-- gets the 30-verse cap of the other copyrighted editions; the website's
-- `reader` key still reads it whole while versions_available.reader_full_text
-- is on (an admin switch).
--
-- Idempotent: rerunning sets the same values.
--
-- The first migration applied by Doctrine (src/Migrations/Version052NvbseCopyright.php),
-- on deploy: Doctrine opens the transaction, so this file has no BEGIN/COMMIT.

UPDATE versions_available
   SET year = 1986,
       copyright = 1,
       copyright_holder = 'Libreria Editrice Vaticana'
 WHERE sigla = 'NVBSE';
