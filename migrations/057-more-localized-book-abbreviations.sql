-- 057: the abbreviations Romanian, Czech, Albanian and Vietnamese citations use.
--
-- After 056, these four languages still held the English abbreviations, copied
-- unchanged, so "In 3,16", "J 3,16", "Gjn 3,16" or "Tv 23,1" were rejected.
-- This adds, next to the abbreviations already there:
--   ro  the Catholic (ARCB) forms, then the Cornilescu forms; the Orthodox
--       1-4 Regi are left out (1 Regi is 1 Samuel there, 1 Kings elsewhere);
--   cs  the Český ekumenický překlad forms, then the Catholic liturgical ones;
--   sq  forms from the book names (Catholic Dalja, Urtia; Protestant Eksodi,
--       Fjalët e urta);
--   vi  the Catholic liturgical translation (Nhóm Phiên Dịch Các Giờ Kinh
--       Phụng Vụ).
--
-- Book tokens are matched across every language, so no language may use a
-- token another language holds for another book. These collide and are left
-- out (the book keeps its other forms and its full names):
--   vi St (Genesis)       is Spanish James       vi Gn (Jonah)    is Genesis
--   vi Dt (Hebrews)       is Deuteronomy         vi Ac (Lam)      is Acts
--   vi Br (Baruch)        is Croatian Numbers    vi Gv (Eccl)     is Italian John
--   vi Ge (Joel)          is Italian Genesis     vi Mk (Micah)    is Mark
--   vi Ga (John)          is Galatians           vi Gl (Gal)      is Italian Joel
--   vi Pl (Philippians)   is Afrikaans Philemon  vi 1Cr, 2Cr (Cor) are Chronicles
--   cs Pl (Lamentations)  is Afrikaans Philemon  ro Jud (Judges)  is Jude
--   sq 1Kr, 2Kr (Chronicles) are Czech and Croatian Kings
-- Vietnamese loses the most; an optional language hint (#244) would let it
-- keep them.
--
-- tests/Integration/Pipeline/BibleBooksIntegrityTest guards that no stored
-- token resolves to another book; LocalizedBookAbbreviationsTest pins these.
-- Each token is appended only when the cell does not hold it yet (compared
-- without spaces, case-insensitively), so rerunning changes nothing.
--
-- Run by Doctrine (src/Migrations/Version057MoreLocalizedBookAbbreviations.php): no BEGIN/COMMIT.

DO $mig$
DECLARE
    r       RECORD;
    token   TEXT;
    cell    TEXT;
    have    TEXT[];
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
        -- Romanian
        ('ROMANIAN',  1, 'Fac'),  -- Genesis
        ('ROMANIAN',  2, 'Ieș | Exod'),  -- Exodus
        ('ROMANIAN',  6, 'Ios'),  -- Joshua
        ('ROMANIAN',  8, 'Rut'),  -- Ruth
        ('ROMANIAN', 11, '1Rg | 1Împ'),  -- 1 Kings
        ('ROMANIAN', 12, '2Rg | 2Împ'),  -- 2 Kings
        ('ROMANIAN', 13, '1Cr | 1Cron'),  -- 1 Chronicles
        ('ROMANIAN', 14, '2Cr | 2Cron'),  -- 2 Chronicles
        ('ROMANIAN', 15, 'Esd | Ezra'),  -- Ezra
        ('ROMANIAN', 16, 'Ne | Neem'),  -- Nehemiah
        ('ROMANIAN', 18, 'Idt'),  -- Judith
        ('ROMANIAN', 20, '1Mac'),  -- 1 Maccabees
        ('ROMANIAN', 21, '2Mac'),  -- 2 Maccabees
        ('ROMANIAN', 22, 'Iob'),  -- Job
        ('ROMANIAN', 25, 'Ecl'),  -- Ecclesiastes
        ('ROMANIAN', 26, 'Ct | Cânt'),  -- Song of Songs
        ('ROMANIAN', 27, 'Înț'),  -- Wisdom
        ('ROMANIAN', 30, 'Ier'),  -- Jeremiah
        ('ROMANIAN', 31, 'Plâng'),  -- Lamentations
        ('ROMANIAN', 33, 'Ezec'),  -- Ezekiel
        ('ROMANIAN', 35, 'Os | Osea'),  -- Hosea
        ('ROMANIAN', 36, 'Il | Ioel'),  -- Joel
        ('ROMANIAN', 37, 'Amos'),  -- Amos
        ('ROMANIAN', 38, 'Abd | Obad'),  -- Obadiah
        ('ROMANIAN', 39, 'Iona'),  -- Jonah
        ('ROMANIAN', 40, 'Mih | Mica'),  -- Micah
        ('ROMANIAN', 41, 'Naum'),  -- Nahum
        ('ROMANIAN', 43, 'Sof | Țef'),  -- Zephaniah
        ('ROMANIAN', 44, 'Ag'),  -- Haggai
        ('ROMANIAN', 45, 'Zah'),  -- Zechariah
        ('ROMANIAN', 47, 'Mat'),  -- Matthew
        ('ROMANIAN', 48, 'Mc | Marc'),  -- Mark
        ('ROMANIAN', 49, 'Lc | Luca'),  -- Luke
        ('ROMANIAN', 50, 'In | Ioan'),  -- John
        ('ROMANIAN', 51, 'Fap | Fapte'),  -- Acts
        ('ROMANIAN', 56, 'Ef | Efes'),  -- Ephesians
        ('ROMANIAN', 57, 'Fil | Filip'),  -- Philippians
        ('ROMANIAN', 59, '1Tes'),  -- 1 Thessalonians
        ('ROMANIAN', 60, '2Tes'),  -- 2 Thessalonians
        ('ROMANIAN', 63, 'Tit'),  -- Titus
        ('ROMANIAN', 64, 'Flm | Filim'),  -- Philemon
        ('ROMANIAN', 65, 'Evr'),  -- Hebrews
        ('ROMANIAN', 66, 'Iac'),  -- James
        ('ROMANIAN', 69, '1In | 1Ioan'),  -- 1 John
        ('ROMANIAN', 70, '2In | 2Ioan'),  -- 2 John
        ('ROMANIAN', 71, '3In | 3Ioan'),  -- 3 John
        ('ROMANIAN', 72, 'Iuda'),  -- Jude
        ('ROMANIAN', 73, 'Ap'),  -- Revelation
        -- Czech
        ('CZECH',  1, 'Gn'),  -- Genesis
        ('CZECH',  4, 'Nu'),  -- Numbers
        ('CZECH',  6, 'Joz'),  -- Joshua
        ('CZECH',  7, 'Sd'),  -- Judges
        ('CZECH',  8, 'Rt'),  -- Ruth
        ('CZECH',  9, '1S'),  -- 1 Samuel
        ('CZECH', 10, '2S'),  -- 2 Samuel
        ('CZECH', 11, '1Kr | 1Král'),  -- 1 Kings
        ('CZECH', 12, '2Kr | 2Král'),  -- 2 Kings
        ('CZECH', 13, '1Pa | 1Kron'),  -- 1 Chronicles
        ('CZECH', 14, '2Pa | 2Kron'),  -- 2 Chronicles
        ('CZECH', 15, 'Ezd'),  -- Ezra
        ('CZECH', 20, '1Mak'),  -- 1 Maccabees
        ('CZECH', 21, '2Mak'),  -- 2 Maccabees
        ('CZECH', 22, 'Job'),  -- Job
        ('CZECH', 23, 'Ž | Žl'),  -- Psalms
        ('CZECH', 24, 'Př | Přís'),  -- Proverbs
        ('CZECH', 25, 'Kaz'),  -- Ecclesiastes
        ('CZECH', 26, 'Pís'),  -- Song of Songs
        ('CZECH', 27, 'Mdr'),  -- Wisdom
        ('CZECH', 28, 'Sír'),  -- Ecclesiasticus
        ('CZECH', 29, 'Iz'),  -- Isaiah
        ('CZECH', 30, 'Jr'),  -- Jeremiah
        ('CZECH', 31, 'Pláč'),  -- Lamentations
        ('CZECH', 32, 'Bár'),  -- Baruch
        ('CZECH', 34, 'Da'),  -- Daniel
        ('CZECH', 35, 'Oz'),  -- Hosea
        ('CZECH', 38, 'Abd'),  -- Obadiah
        ('CZECH', 40, 'Mich'),  -- Micah
        ('CZECH', 42, 'Abk'),  -- Habakkuk
        ('CZECH', 43, 'Sf | Sof'),  -- Zephaniah
        ('CZECH', 44, 'Ag'),  -- Haggai
        ('CZECH', 45, 'Za | Zach'),  -- Zechariah
        ('CZECH', 49, 'L'),  -- Luke
        ('CZECH', 50, 'J | Jan'),  -- John
        ('CZECH', 51, 'Sk'),  -- Acts
        ('CZECH', 52, 'Ř | Řím'),  -- Romans
        ('CZECH', 53, '1K | 1Kor'),  -- 1 Corinthians
        ('CZECH', 54, '2K | 2Kor'),  -- 2 Corinthians
        ('CZECH', 55, 'Ga'),  -- Galatians
        ('CZECH', 56, 'Ef'),  -- Ephesians
        ('CZECH', 57, 'Fp | Flp'),  -- Philippians
        ('CZECH', 58, 'Ko | Kol'),  -- Colossians
        ('CZECH', 59, '1Te | 1Sol'),  -- 1 Thessalonians
        ('CZECH', 60, '2Te | 2Sol'),  -- 2 Thessalonians
        ('CZECH', 63, 'Tit'),  -- Titus
        ('CZECH', 64, 'Fm | Flm'),  -- Philemon
        ('CZECH', 65, 'Žd | Žid'),  -- Hebrews
        ('CZECH', 66, 'Jk | Jak'),  -- James
        ('CZECH', 67, '1P | 1Petr'),  -- 1 Peter
        ('CZECH', 68, '2P | 2Petr'),  -- 2 Peter
        ('CZECH', 69, '1J | 1Jan'),  -- 1 John
        ('CZECH', 70, '2J | 2Jan'),  -- 2 John
        ('CZECH', 71, '3J | 3Jan'),  -- 3 John
        ('CZECH', 72, 'Ju'),  -- Jude
        ('CZECH', 73, 'Zj'),  -- Revelation
        -- Albanian
        ('ALBANIAN',  1, 'Zan'),  -- Genesis
        ('ALBANIAN',  2, 'Dal | Eks'),  -- Exodus
        ('ALBANIAN',  5, 'Ligj'),  -- Deuteronomy
        ('ALBANIAN',  6, 'Joz'),  -- Joshua
        ('ALBANIAN',  7, 'Gjyq'),  -- Judges
        ('ALBANIAN',  8, 'Rut'),  -- Ruth
        ('ALBANIAN', 11, '1Mb | 1Mbr'),  -- 1 Kings
        ('ALBANIAN', 12, '2Mb | 2Mbr'),  -- 2 Kings
        ('ALBANIAN', 13, '1Kron'),  -- 1 Chronicles
        ('ALBANIAN', 14, '2Kron'),  -- 2 Chronicles
        ('ALBANIAN', 15, 'Esd'),  -- Ezra
        ('ALBANIAN', 20, '1Mak'),  -- 1 Maccabees
        ('ALBANIAN', 21, '2Mak'),  -- 2 Maccabees
        ('ALBANIAN', 22, 'Job'),  -- Job
        ('ALBANIAN', 24, 'Fja'),  -- Proverbs
        ('ALBANIAN', 25, 'Pred'),  -- Ecclesiastes
        ('ALBANIAN', 26, 'Kant'),  -- Song of Songs
        ('ALBANIAN', 27, 'Urt'),  -- Wisdom
        ('ALBANIAN', 31, 'Vaj'),  -- Lamentations
        ('ALBANIAN', 35, 'Os'),  -- Hosea
        ('ALBANIAN', 36, 'Joe'),  -- Joel
        ('ALBANIAN', 38, 'Abd'),  -- Obadiah
        ('ALBANIAN', 40, 'Mik'),  -- Micah
        ('ALBANIAN', 43, 'Sof'),  -- Zephaniah
        ('ALBANIAN', 45, 'Zak'),  -- Zechariah
        ('ALBANIAN', 48, 'Mr'),  -- Mark
        ('ALBANIAN', 49, 'Llu'),  -- Luke
        ('ALBANIAN', 50, 'Gjn | Gjo'),  -- John
        ('ALBANIAN', 51, 'Vep'),  -- Acts
        ('ALBANIAN', 53, '1Kor'),  -- 1 Corinthians
        ('ALBANIAN', 54, '2Kor'),  -- 2 Corinthians
        ('ALBANIAN', 56, 'Ef'),  -- Ephesians
        ('ALBANIAN', 57, 'Fil'),  -- Philippians
        ('ALBANIAN', 58, 'Kol'),  -- Colossians
        ('ALBANIAN', 59, '1Sel'),  -- 1 Thessalonians
        ('ALBANIAN', 60, '2Sel'),  -- 2 Thessalonians
        ('ALBANIAN', 63, 'Tit'),  -- Titus
        ('ALBANIAN', 64, 'Flm'),  -- Philemon
        ('ALBANIAN', 66, 'Jak'),  -- James
        ('ALBANIAN', 67, '1Pjt | 1Pj'),  -- 1 Peter
        ('ALBANIAN', 68, '2Pjt | 2Pj'),  -- 2 Peter
        ('ALBANIAN', 69, '1Gjn | 1Gjo'),  -- 1 John
        ('ALBANIAN', 70, '2Gjn | 2Gjo'),  -- 2 John
        ('ALBANIAN', 71, '3Gjn | 3Gjo'),  -- 3 John
        ('ALBANIAN', 73, 'Zb'),  -- Revelation
        -- Vietnamese
        ('VIETNAMESE',  2, 'Xh'),  -- Exodus
        ('VIETNAMESE',  4, 'Ds'),  -- Numbers
        ('VIETNAMESE',  5, 'Đnl'),  -- Deuteronomy
        ('VIETNAMESE',  6, 'Gs'),  -- Joshua
        ('VIETNAMESE',  7, 'Tl'),  -- Judges
        ('VIETNAMESE',  8, 'R'),  -- Ruth
        ('VIETNAMESE',  9, '1Sm'),  -- 1 Samuel
        ('VIETNAMESE', 10, '2Sm'),  -- 2 Samuel
        ('VIETNAMESE', 11, '1V'),  -- 1 Kings
        ('VIETNAMESE', 12, '2V'),  -- 2 Kings
        ('VIETNAMESE', 13, '1Sb'),  -- 1 Chronicles
        ('VIETNAMESE', 14, '2Sb'),  -- 2 Chronicles
        ('VIETNAMESE', 15, 'Er'),  -- Ezra
        ('VIETNAMESE', 16, 'Nkm'),  -- Nehemiah
        ('VIETNAMESE', 17, 'Tb'),  -- Tobit
        ('VIETNAMESE', 18, 'Gđt'),  -- Judith
        ('VIETNAMESE', 19, 'Et'),  -- Esther
        ('VIETNAMESE', 20, '1Mcb'),  -- 1 Maccabees
        ('VIETNAMESE', 21, '2Mcb'),  -- 2 Maccabees
        ('VIETNAMESE', 22, 'G'),  -- Job
        ('VIETNAMESE', 23, 'Tv'),  -- Psalms
        ('VIETNAMESE', 24, 'Cn'),  -- Proverbs
        ('VIETNAMESE', 26, 'Dc'),  -- Song of Songs
        ('VIETNAMESE', 27, 'Kn'),  -- Wisdom
        ('VIETNAMESE', 28, 'Hc'),  -- Ecclesiasticus
        ('VIETNAMESE', 30, 'Gr'),  -- Jeremiah
        ('VIETNAMESE', 33, 'Ed'),  -- Ezekiel
        ('VIETNAMESE', 34, 'Đn'),  -- Daniel
        ('VIETNAMESE', 35, 'Hs'),  -- Hosea
        ('VIETNAMESE', 38, 'Ov'),  -- Obadiah
        ('VIETNAMESE', 41, 'Nk'),  -- Nahum
        ('VIETNAMESE', 42, 'Kb'),  -- Habakkuk
        ('VIETNAMESE', 43, 'Xp'),  -- Zephaniah
        ('VIETNAMESE', 44, 'Kg'),  -- Haggai
        ('VIETNAMESE', 45, 'Dcr'),  -- Zechariah
        ('VIETNAMESE', 48, 'Mc'),  -- Mark
        ('VIETNAMESE', 49, 'Lc'),  -- Luke
        ('VIETNAMESE', 51, 'Cv'),  -- Acts
        ('VIETNAMESE', 56, 'Ep'),  -- Ephesians
        ('VIETNAMESE', 58, 'Cl'),  -- Colossians
        ('VIETNAMESE', 59, '1Tx'),  -- 1 Thessalonians
        ('VIETNAMESE', 60, '2Tx'),  -- 2 Thessalonians
        ('VIETNAMESE', 64, 'Plm'),  -- Philemon
        ('VIETNAMESE', 66, 'Gc'),  -- James
        ('VIETNAMESE', 67, '1Pr'),  -- 1 Peter
        ('VIETNAMESE', 68, '2Pr'),  -- 2 Peter
        ('VIETNAMESE', 69, '1Ga'),  -- 1 John
        ('VIETNAMESE', 70, '2Ga'),  -- 2 John
        ('VIETNAMESE', 71, '3Ga'),  -- 3 John
        ('VIETNAMESE', 72, 'Gđ'),  -- Jude
        ('VIETNAMESE', 73, 'Kh')   -- Revelation
        ) AS t(lang, book, tokens)
    LOOP
        EXECUTE format('SELECT %I FROM biblebooks_abbr WHERE "BOOK" = $1', r.lang) INTO cell USING r.book;
        IF cell IS NULL THEN
            RAISE EXCEPTION '057: biblebooks_abbr has no % cell for book %', r.lang, r.book;
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
