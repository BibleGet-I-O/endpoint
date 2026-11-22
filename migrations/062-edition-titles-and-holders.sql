-- 062: the titles and rights holders of the editions under agreement, as the agreements state them (2026-11-22).
--
--   NABRE    The licence (2015) is with the Confraternity of Christian Doctrine, Inc., which owns the copyright;
--            its notice names the edition "New American Bible, revised edition". The table held
--            "New American Bible - Revised Edition" and, as holder, the United States Conference of Catholic Bishops.
--   BLPD     The "Acuerdo de cesión de derechos" (2020) lists the edition as "La Biblia. Libro del Pueblo de Dios";
--            the table held "Libro del Pueblo de Dios".
--   CEI2008  The table held the publisher, "Conferenza Episcopale Italiana", as the edition's name. Its rights
--            holder is unchanged: Fondazione di Religione "Santi Francesco d'Assisi e Caterina da Siena".
--
-- Clients show the full name under each quote (the MediaWiki extension's source line), on the website's books and
-- in /metadata/bibleversions. An edition absent from a database is skipped. Rerunning changes nothing.
--
-- Run by Doctrine (src/Migrations/Version062EditionTitlesAndHolders.php): no BEGIN/COMMIT.

UPDATE versions_available
   SET fullname = 'New American Bible, Revised Edition', copyright_holder = 'Confraternity of Christian Doctrine, Inc.'
 WHERE sigla = 'NABRE';

UPDATE versions_available SET fullname = 'La Biblia. Libro del Pueblo de Dios' WHERE sigla = 'BLPD';

UPDATE versions_available SET fullname = 'La Sacra Bibbia (CEI 2008)' WHERE sigla = 'CEI2008';
