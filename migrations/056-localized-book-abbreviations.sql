-- 056: the abbreviations French, German, Portuguese and Spanish citations use.
--
-- biblebooks_abbr held the English abbreviations, copied unchanged, for French,
-- German and Portuguese (and Romanian, Czech, Albanian and Vietnamese, left for
-- later), and Spanish wrote Samuel and Kings "1Sa", "1Re". So "Joh 3,16",
-- "1 Kön 19,12", "1 R 19,12" or "1 Pd 3,15" were rejected, and "Jó 15,35"
-- (Job) fell through to the diacritics-folded pass and landed on Hungarian
-- "Jóel". This adds, next to the abbreviations already there, the ones of the
-- Einheitsübersetzung (Loccum), the Bible de Jérusalem, the Biblia de
-- Jerusalén and the CNBB / Ave-Maria editions.
--
-- Book tokens are matched across every language, so no language may use a
-- token another language holds for another book. Eight standard ones collide
-- and are left out, with what is added instead:
--   pt Gl   (Galatians)  is Italian Joel          -> Gál
--   pt Jo   (John)       is Hungarian Joel        -> none ("1 Jo" etc. are free)
--   pt Jn   (Jonah)      is John everywhere       -> Jon (already there)
--   fr He   (Hebrews)    is Spanish Acts          -> Hé (Heb already there)
--   fr Jc   (James)      is Spanish Judges        -> Jac
--   fr, pt Ab (Obadiah)  is Italian Habakkuk      -> Abd
--   pt Br   (Baruch)     is Croatian Numbers      -> Bar (already there)
--   fr Sg   (Wisdom)     is Song of Songs         -> Sag
-- tests/Integration/Pipeline/BibleBooksIntegrityTest guards that no stored
-- token resolves to another book; LocalizedBookAbbreviationsTest pins these.
--
-- Each token is appended only when the cell does not hold it yet (compared
-- without spaces, case-insensitively), so rerunning changes nothing.
--
-- Run by Doctrine (src/Migrations/Version056LocalizedBookAbbreviations.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    r       RECORD;
    token   TEXT;
    cell    TEXT;
    have    TEXT[];
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            -- German
            ('GERMAN',  5, 'Dtn'),  -- Deuteronomy
            ('GERMAN',  6, 'Jos'),  -- Joshua
            ('GERMAN',  7, 'Ri'),  -- Judges
            ('GERMAN',  8, 'Rut'),  -- Ruth
            ('GERMAN', 11, '1Kön'),  -- 1 Kings
            ('GERMAN', 12, '2Kön'),  -- 2 Kings
            ('GERMAN', 15, 'Esra'),  -- Ezra
            ('GERMAN', 20, '1Makk'),  -- 1 Maccabees
            ('GERMAN', 21, '2Makk'),  -- 2 Maccabees
            ('GERMAN', 22, 'Ijob | Hi'),  -- Job
            ('GERMAN', 24, 'Spr'),  -- Proverbs
            ('GERMAN', 25, 'Koh'),  -- Ecclesiastes
            ('GERMAN', 26, 'Hld'),  -- Song of Songs
            ('GERMAN', 27, 'Weish'),  -- Wisdom
            ('GERMAN', 29, 'Jes'),  -- Isaiah
            ('GERMAN', 31, 'Klgl'),  -- Lamentations
            ('GERMAN', 36, 'Joël'),  -- Joel
            ('GERMAN', 38, 'Obd'),  -- Obadiah
            ('GERMAN', 39, 'Jona'),  -- Jonah
            ('GERMAN', 43, 'Zef'),  -- Zephaniah
            ('GERMAN', 45, 'Sach'),  -- Zechariah
            ('GERMAN', 50, 'Joh'),  -- John
            ('GERMAN', 51, 'Apg'),  -- Acts
            ('GERMAN', 52, 'Röm'),  -- Romans
            ('GERMAN', 53, '1Kor'),  -- 1 Corinthians
            ('GERMAN', 54, '2Kor'),  -- 2 Corinthians
            ('GERMAN', 58, 'Kol'),  -- Colossians
            ('GERMAN', 63, 'Tit'),  -- Titus
            ('GERMAN', 65, 'Hebr'),  -- Hebrews
            ('GERMAN', 66, 'Jak'),  -- James
            ('GERMAN', 67, '1Petr'),  -- 1 Peter
            ('GERMAN', 68, '2Petr'),  -- 2 Peter
            ('GERMAN', 69, '1Joh'),  -- 1 John
            ('GERMAN', 70, '2Joh'),  -- 2 John
            ('GERMAN', 71, '3Joh'),  -- 3 John
            ('GERMAN', 73, 'Offb'),  -- Revelation
            -- French
            ('FRENCH',  1, 'Gn'),  -- Genesis
            ('FRENCH',  4, 'Nb'),  -- Numbers
            ('FRENCH',  6, 'Jos'),  -- Joshua
            ('FRENCH',  7, 'Jg'),  -- Judges
            ('FRENCH',  8, 'Rt'),  -- Ruth
            ('FRENCH',  9, '1S'),  -- 1 Samuel
            ('FRENCH', 10, '2S'),  -- 2 Samuel
            ('FRENCH', 11, '1R'),  -- 1 Kings
            ('FRENCH', 12, '2R'),  -- 2 Kings
            ('FRENCH', 13, '1Ch'),  -- 1 Chronicles
            ('FRENCH', 14, '2Ch'),  -- 2 Chronicles
            ('FRENCH', 15, 'Esd'),  -- Ezra
            ('FRENCH', 16, 'Ne'),  -- Nehemiah
            ('FRENCH', 17, 'Tb'),  -- Tobit
            ('FRENCH', 20, '1M'),  -- 1 Maccabees
            ('FRENCH', 21, '2M'),  -- 2 Maccabees
            ('FRENCH', 26, 'Ct'),  -- Song of Songs
            ('FRENCH', 27, 'Sag'),  -- Wisdom
            ('FRENCH', 28, 'Si'),  -- Ecclesiasticus
            ('FRENCH', 30, 'Jr'),  -- Jeremiah
            ('FRENCH', 31, 'Lm'),  -- Lamentations
            ('FRENCH', 32, 'Ba'),  -- Baruch
            ('FRENCH', 35, 'Os'),  -- Hosea
            ('FRENCH', 38, 'Abd'),  -- Obadiah
            ('FRENCH', 42, 'Ha'),  -- Habakkuk
            ('FRENCH', 43, 'So'),  -- Zephaniah
            ('FRENCH', 44, 'Ag'),  -- Haggai
            ('FRENCH', 45, 'Za'),  -- Zechariah
            ('FRENCH', 48, 'Mc'),  -- Mark
            ('FRENCH', 49, 'Lc'),  -- Luke
            ('FRENCH', 53, '1Co'),  -- 1 Corinthians
            ('FRENCH', 54, '2Co'),  -- 2 Corinthians
            ('FRENCH', 55, 'Ga'),  -- Galatians
            ('FRENCH', 56, 'Ep'),  -- Ephesians
            ('FRENCH', 57, 'Ph'),  -- Philippians
            ('FRENCH', 64, 'Phm'),  -- Philemon
            ('FRENCH', 65, 'Hé'),  -- Hebrews
            ('FRENCH', 66, 'Jac'),  -- James
            ('FRENCH', 67, '1P'),  -- 1 Peter
            ('FRENCH', 68, '2P'),  -- 2 Peter
            ('FRENCH', 72, 'Jude'),  -- Jude
            ('FRENCH', 73, 'Ap'),  -- Revelation
            -- Spanish
            ('SPANISH',  8, 'Rut'),  -- Ruth
            ('SPANISH',  9, '1S'),  -- 1 Samuel
            ('SPANISH', 10, '2S'),  -- 2 Samuel
            ('SPANISH', 11, '1R'),  -- 1 Kings
            ('SPANISH', 12, '2R'),  -- 2 Kings
            ('SPANISH', 22, 'Job'),  -- Job
            ('SPANISH', 28, 'Eclo'),  -- Ecclesiasticus
            ('SPANISH', 55, 'Gal'),  -- Galatians
            ('SPANISH', 66, 'Sant'),  -- James
            ('SPANISH', 72, 'Jds'),  -- Jude
            -- Portuguese
            ('PORTUGUESE',  1, 'Gn'),  -- Genesis
            ('PORTUGUESE',  6, 'Js'),  -- Joshua
            ('PORTUGUESE',  7, 'Jz'),  -- Judges
            ('PORTUGUESE',  8, 'Rt'),  -- Ruth
            ('PORTUGUESE',  9, '1Sm'),  -- 1 Samuel
            ('PORTUGUESE', 10, '2Sm'),  -- 2 Samuel
            ('PORTUGUESE', 11, '1Rs'),  -- 1 Kings
            ('PORTUGUESE', 12, '2Rs'),  -- 2 Kings
            ('PORTUGUESE', 13, '1Cr'),  -- 1 Chronicles
            ('PORTUGUESE', 14, '2Cr'),  -- 2 Chronicles
            ('PORTUGUESE', 15, 'Esd'),  -- Ezra
            ('PORTUGUESE', 16, 'Ne'),  -- Nehemiah
            ('PORTUGUESE', 17, 'Tb'),  -- Tobit
            ('PORTUGUESE', 18, 'Jt'),  -- Judith
            ('PORTUGUESE', 22, 'Jó'),  -- Job
            ('PORTUGUESE', 23, 'Sl'),  -- Psalms
            ('PORTUGUESE', 25, 'Ecl'),  -- Ecclesiastes
            ('PORTUGUESE', 26, 'Ct'),  -- Song of Songs
            ('PORTUGUESE', 27, 'Sb'),  -- Wisdom
            ('PORTUGUESE', 28, 'Eclo'),  -- Ecclesiasticus
            ('PORTUGUESE', 30, 'Jr'),  -- Jeremiah
            ('PORTUGUESE', 31, 'Lm'),  -- Lamentations
            ('PORTUGUESE', 35, 'Os'),  -- Hosea
            ('PORTUGUESE', 38, 'Abd'),  -- Obadiah
            ('PORTUGUESE', 40, 'Mq'),  -- Micah
            ('PORTUGUESE', 43, 'Sf'),  -- Zephaniah
            ('PORTUGUESE', 44, 'Ag'),  -- Haggai
            ('PORTUGUESE', 45, 'Zc'),  -- Zechariah
            ('PORTUGUESE', 48, 'Mc'),  -- Mark
            ('PORTUGUESE', 49, 'Lc'),  -- Luke
            ('PORTUGUESE', 51, 'At'),  -- Acts
            ('PORTUGUESE', 55, 'Gál'),  -- Galatians
            ('PORTUGUESE', 56, 'Ef'),  -- Ephesians
            ('PORTUGUESE', 57, 'Fl | Fp'),  -- Philippians
            ('PORTUGUESE', 58, 'Cl'),  -- Colossians
            ('PORTUGUESE', 59, '1Ts'),  -- 1 Thessalonians
            ('PORTUGUESE', 60, '2Ts'),  -- 2 Thessalonians
            ('PORTUGUESE', 64, 'Fm'),  -- Philemon
            ('PORTUGUESE', 65, 'Hb'),  -- Hebrews
            ('PORTUGUESE', 66, 'Tg'),  -- James
            ('PORTUGUESE', 67, '1Pd'),  -- 1 Peter
            ('PORTUGUESE', 68, '2Pd'),  -- 2 Peter
            ('PORTUGUESE', 69, '1Jo'),  -- 1 John
            ('PORTUGUESE', 70, '2Jo'),  -- 2 John
            ('PORTUGUESE', 71, '3Jo'),  -- 3 John
            ('PORTUGUESE', 73, 'Ap')   -- Revelation
        ) AS t(lang, book, tokens)
    LOOP
        EXECUTE format('SELECT %I FROM biblebooks_abbr WHERE "BOOK" = $1', r.lang) INTO cell USING r.book;
        IF cell IS NULL THEN
            RAISE EXCEPTION '056: biblebooks_abbr has no % cell for book %', r.lang, r.book;
        END IF;
        FOREACH token IN ARRAY string_to_array(r.tokens, ' | ') LOOP
            SELECT array_agg(lower(replace(x, ' ', ''))) INTO have
              FROM unnest(string_to_array(cell, ' | ')) AS x;
            IF NOT lower(replace(token, ' ', '')) = ANY (coalesce(have, '{}')) THEN
                cell := CASE WHEN cell = '' THEN token ELSE cell || ' | ' || token END;
            END IF;
        END LOOP;
        EXECUTE format('UPDATE biblebooks_abbr SET %I = $1 WHERE "BOOK" = $2', r.lang) USING cell, r.book;
    END LOOP;
END
$mig$;
