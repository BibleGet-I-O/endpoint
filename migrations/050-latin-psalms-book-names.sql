-- 050: Latin names for the Psalms that liturgical books actually write (#212).
--
-- biblebooks_fullname.LATIN held only "Psalmorum" (the genitive of "Liber
-- Psalmorum") for book 23, and book names are matched exactly, so a Latin
-- citation such as "Psalmo 15 (16), 1-2a" (the ablative, as in "ex Psalmo")
-- was rejected before any chapter or verse was looked at. This adds the other
-- forms Latin citations use: "Psalmi", "Psalmus" (the nominative; the Nova
-- Vulgata heads each psalm "PSALMUS 51 (50)") and "Psalmo". "Psalmi" is
-- already a Croatian and a Romanian name of the same book, so nothing else is
-- shadowed.
--
-- Guarded on the current value, so it changes nothing once applied, or if the
-- cell was edited by hand since; rerunning is a no-op.

BEGIN;

UPDATE biblebooks_fullname
   SET "LATIN" = 'Psalmorum | Psalmi | Psalmus | Psalmo'
 WHERE "BOOK" = 23
   AND "LATIN" = 'Psalmorum';

COMMIT;
