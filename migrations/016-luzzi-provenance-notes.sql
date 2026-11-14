-- Migration: Record the provenance of the LUZZI text in versions_available.notes
--
-- The LUZZI notes field was empty. The original import file, luzzi.txt
-- (2015), is a dump of LaParola.net's "Luzzi/Riveduta" text: same markup
-- (<br /> poetic breaks, <i> headings, HTML entities, Latin-1) and the same
-- wording, verse for verse. LaParola describes its text as:
--
--   La Versione Riveduta in testo originale dal Dott. GIOVANNI LUZZI, già
--   Prof. alla Facoltà Teologica Valdese di Roma. Stampato a cura della
--   Società Biblica Britannica e Forestiera (1925). Il testo segue
--   l'edizione © Libreria Sacre Scritture Roma 1990, ISBN 88-237-1051-0.
--   (https://www.laparola.net/versioni.php)
--
-- `year` stays 1924, the date by which the revision is commonly known;
-- the note records the 1925 printing that the digital text follows.
-- Format follows DIVCOM's notes ("Campo:<TAB>valore" lines) plus the
-- "source: <url>" line used by other editions.
--
-- Idempotent: only fills the field while it is still empty.

UPDATE versions_available
SET notes = E'Titolo:\tLa Versione Riveduta in testo originale dal Dott. Giovanni Luzzi, già Prof. alla Facoltà Teologica Valdese di Roma\n'
         || E'Editore:\tSocietà Biblica Britannica e Forestiera\n'
         || E'Anno:\t1925 (revisione nota come "Riveduta 1924")\n'
         || E'Edizione seguita:\tLibreria Sacre Scritture, Roma 1990, ISBN 88-237-1051-0\n'
         || E'Testo digitale:\tLaParola.net, versione "Luzzi/Riveduta" (importato nel 2015)\n'
         || 'source: https://www.laparola.net/versioni.php'
WHERE sigla = 'LUZZI' AND notes = '';
