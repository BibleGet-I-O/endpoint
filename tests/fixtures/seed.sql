-- Seed data for integration tests

-- Test Bible versions
INSERT IGNORE INTO versions_available (sigla, fullname, year, language, imprimatur, canon, copyright_holder, notes, copyright, type) VALUES
('TEST1', 'Test Bible Version 1', '2020', 'English', 'Yes', 'CATHOLIC', 'Test Publisher', 'Test notes', 0, 'BIBLE'),
('TEST2', 'Test Bible Version 2', '2021', 'English', 'No', 'PROTESTANT', 'Other Publisher', '', 1, 'BIBLE'),
('VGCL', 'Vulgata Clementina', '1592', 'Latin', 'Yes', 'CATHOLIC', '', 'Test subset', 0, 'BIBLE'),
('DRB', 'Douay-Rheims Bible', '1752', 'English', 'Yes', 'CATHOLIC', '', 'Test subset', 0, 'BIBLE');

-- Bible book names: books 1-3 (Genesis, Exodus, Leviticus) + 4-22 (fillers) + 23 (Psalms)
INSERT IGNORE INTO biblebooks_fullname (BOOK, ENGLISH, ITALIAN) VALUES
(1, 'Genesis', 'Genesi'),
(2, 'Exodus', 'Esodo'),
(3, 'Leviticus', 'Levitico'),
(4, 'Numbers', 'Numeri'),
(5, 'Deuteronomy', 'Deuteronomio'),
(6, 'Joshua', 'Giosue'),
(7, 'Judges', 'Giudici'),
(8, 'Ruth', 'Rut'),
(9, '1 Samuel', '1Samuele'),
(10, '2 Samuel', '2Samuele'),
(11, '1 Kings', '1Re'),
(12, '2 Kings', '2Re'),
(13, '1 Chronicles', '1Cronache'),
(14, '2 Chronicles', '2Cronache'),
(15, 'Ezra', 'Esdra'),
(16, 'Nehemiah', 'Neemia'),
(17, 'Tobit | Tobias', 'Tobia'),
(18, 'Judith', 'Giuditta'),
(19, 'Esther', 'Ester'),
(20, '1 Maccabees', '1Maccabei'),
(21, '2 Maccabees', '2Maccabei'),
(22, 'Job', 'Giobbe'),
(23, 'Psalms | Psalm', 'Salmi | Salmo');

INSERT IGNORE INTO biblebooks_abbr (BOOK, ENGLISH, ITALIAN) VALUES
(1, 'Gen | Gn', 'Gen | Gn'),
(2, 'Exod | Ex', 'Es | Esod'),
(3, 'Lev | Lv', 'Lv | Lev'),
(4, 'Num | Nm', 'Nm'),
(5, 'Deut | Dt', 'Dt'),
(6, 'Josh', 'Gs'),
(7, 'Jdg | Jgs', 'Gdc'),
(8, 'Ru', 'Rt'),
(9, '1Sam', '1Sam'),
(10, '2Sam', '2Sam'),
(11, '1Kgs', '1Re'),
(12, '2Kgs', '2Re'),
(13, '1Chron | 1Chr', '1Cr'),
(14, '2Chron | 2Chr', '2Cr'),
(15, 'Ezr', 'Esd'),
(16, 'Neh', 'Ne'),
(17, 'Tob', 'Tb'),
(18, 'Jdt', 'Gdt'),
(19, 'Est', 'Est'),
(20, '1Macc | 1Mc', '1Mac'),
(21, '2Macc | 2Mc', '2Mac'),
(22, 'Jb', 'Gb'),
(23, 'Ps | Pss', 'Sal | Salm');

-- Index tables for TEST1 (3 books)
CREATE TABLE IF NOT EXISTS TEST1_idx (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
) DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO TEST1_idx (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Exod', 'Exodus', 2, '7,5', 2),
('Lev', 'Leviticus', 2, '6,4', 3),
('Ps', 'Psalms', 55, '6,12,8,8,12,10,17,9,21,18,7,8,6,7,5,11,15,10,21,18,7,8,6,10,12,12,10,17,9,3,5,16,8,8,10,13,18,7,14,11,19,17,8,4,3,13,14,7,13,12,19,6,12,8,6,12,5,4,8,3,11,11,8,10,13,6,5,17,9,14,10,17,7,13,20,6,10,7,13,7,18,6', 23);

-- Index tables for TEST2 (3 books)
CREATE TABLE IF NOT EXISTS TEST2_idx (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
) DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO TEST2_idx (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Exod', 'Exodus', 2, '7,5', 2),
('Lev', 'Leviticus', 2, '6,4', 3),
('Ps', 'Psalms', 55, '6,12,8,8,12,10,17,9,21,18,7,8,6,7,5,11,15,10,21,18,7,8,6,10,12,12,10,17,9,3,5,16,8,8,10,13,18,7,14,11,19,17,8,4,3,13,14,7,13,12,19,6,12,8,6,12,5,4,8,3,11,11,8,10,13,6,5,17,9,14,10,17,7,13,20,6,10,7,13,7,18,6', 23);

-- Bible text tables for TEST1 (sample verses)
CREATE TABLE IF NOT EXISTS TEST1 (
    verseID     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    testament   TINYINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) NOT NULL DEFAULT '',
    FULLTEXT(text)
) DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO TEST1 (book, chapter, verse, text, testament, section) VALUES
(1, 1, 1, 'In the beginning God created the heavens and the earth.', 1, 1),
(1, 1, 2, 'The earth was formless and void, and darkness was over the surface of the deep.', 1, 1),
(1, 1, 3, 'Then God said, Let there be light; and there was light.', 1, 1),
(1, 1, 4, 'God saw that the light was good; and God separated the light from the darkness.', 1, 1),
(1, 1, 5, 'God called the light day, and the darkness He called night.', 1, 1),
(1, 1, 6, 'Then God said, Let there be an expanse in the midst of the waters.', 1, 1),
(1, 1, 7, 'God made the expanse, and separated the waters.', 1, 1),
(1, 1, 8, 'God called the expanse heaven. And there was evening and there was morning, a second day.', 1, 1),
(1, 1, 9, 'Then God said, Let the waters below the heavens be gathered into one place.', 1, 1),
(1, 1, 10, 'God called the dry land earth, and the gathering of the waters He called seas.', 1, 1),
(1, 2, 1, 'Thus the heavens and the earth were completed, and all their hosts.', 1, 1),
(1, 2, 2, 'By the seventh day God completed His work which He had done.', 1, 1),
(1, 2, 3, 'Then God blessed the seventh day and sanctified it.', 1, 1),
(1, 2, 4, 'This is the account of the heavens and the earth when they were created.', 1, 1),
(1, 2, 5, 'Now no shrub of the field was yet in the earth.', 1, 1),
(1, 2, 6, 'But a mist used to rise from the earth and water the whole surface of the ground.', 1, 1),
(1, 2, 7, 'Then the Lord God formed man of dust from the ground.', 1, 1),
(1, 2, 8, 'The Lord God planted a garden toward the east, in Eden.', 1, 1),
(2, 1, 1, 'Now these are the names of the sons of Israel who came to Egypt.', 1, 2),
(2, 1, 2, 'Reuben, Simeon, Levi and Judah.', 1, 2),
(2, 1, 3, 'Issachar, Zebulun and Benjamin.', 1, 2),
-- Psalms (book 23) — Hebrew numbering: chapter 51 = "Miserere"
(23, 51, 1, 'Have mercy on me, O God, according to your unfailing love.', 1, 7),
(23, 51, 2, 'Wash away all my iniquity and cleanse me from my sin.', 1, 7),
(23, 51, 3, 'For I know my transgressions, and my sin is always before me.', 1, 7),
(23, 51, 4, 'Against you, you only, have I sinned and done what is evil in your sight.', 1, 7),
(23, 51, 5, 'Surely I was sinful at birth, sinful from the time my mother conceived me.', 1, 7),
(23, 51, 6, 'Yet you desired faithfulness even in the womb; you taught me wisdom in that secret place.', 1, 7);

-- Bible text tables for TEST2 (same sample verses)
CREATE TABLE IF NOT EXISTS TEST2 (
    verseID     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    testament   TINYINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) NOT NULL DEFAULT '',
    FULLTEXT(text)
) DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO TEST2 (book, chapter, verse, text, testament, section) VALUES
(1, 1, 1, 'In the beginning God created the heaven and the earth.', 1, 1),
(1, 1, 2, 'And the earth was without form, and void.', 1, 1),
(1, 1, 3, 'And God said, Let there be light: and there was light.', 1, 1),
(1, 1, 4, 'And God saw the light, that it was good.', 1, 1),
(1, 1, 5, 'And God called the light Day, and the darkness he called Night.', 1, 1),
(1, 2, 1, 'Thus the heavens and the earth were finished, and all the host of them.', 1, 1),
(2, 1, 1, 'Now these are the names of the children of Israel.', 1, 2);

-- ── VGCL (Vulgata Clementina) ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS VGCL_idx (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
) DEFAULT CHARSET=utf8mb4;

-- Vulgate numbering: 150 Psalms. Hebrew Psalm 51 = VGCL chapter 50.
INSERT IGNORE INTO VGCL_idx (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Psal', 'Psalmorum', 55, '6,13,9,10,13,11,18,10,39,8,9,6,7,5,10,15,51,15,10,14,32,6,10,22,12,14,9,11,13,25,11,22,23,28,13,40,23,14,18,14,12,5,26,18,12,10,15,21,23,21,11,7,9,24,13', 23);

CREATE TABLE IF NOT EXISTS VGCL (
    verseID     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    testament   TINYINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) NOT NULL DEFAULT '',
    FULLTEXT(text)
) DEFAULT CHARSET=utf8mb4;

-- VGCL Psalm 50 (= Hebrew Psalm 51, "Miserere mei")
INSERT IGNORE INTO VGCL (book, chapter, verse, text, testament, section) VALUES
(23, 50, 1, 'In finem. Psalmus David,', 1, 7),
(23, 50, 2, 'cum venit ad eum Nathan propheta, quando intravit ad Bethsabee.', 1, 7),
(23, 50, 3, 'Miserere mei, Deus, secundum magnam misericordiam tuam.', 1, 7),
(23, 50, 4, 'Amplius lava me ab iniquitate mea, et a peccato meo munda me.', 1, 7),
(23, 50, 5, 'Quoniam iniquitatem meam ego cognosco, et peccatum meum contra me est semper.', 1, 7),
(23, 50, 6, 'Tibi soli peccavi, et malum coram te feci.', 1, 7),
-- VGCL Psalm 51 (= Hebrew Psalm 52, a different Psalm)
(23, 51, 1, 'In finem. Intellectus David,', 1, 7),
(23, 51, 2, 'cum venit Doeg Idumaeus, et nuntiavit Sauli.', 1, 7),
(23, 51, 3, 'Quid gloriaris in malitia, qui potens es in iniquitate?', 1, 7);

-- ── DRB (Douay-Rheims Bible) ───────────────────────────────────────────

CREATE TABLE IF NOT EXISTS DRB_idx (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
) DEFAULT CHARSET=utf8mb4;

-- DRB uses same Vulgate numbering as VGCL.
INSERT IGNORE INTO DRB_idx (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Ps', 'Psalms', 55, '6,13,9,10,13,11,18,10,39,8,9,6,7,5,11,15,51,15,9,14,32,6,10,22,12,14,9,10,13,25,11,22,23,28,13,40,23,14,18,14,12,6,26,18,12,10,15,21,23,21,11,7,9,24,13', 23);

CREATE TABLE IF NOT EXISTS DRB (
    verseID     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    testament   TINYINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) NOT NULL DEFAULT '',
    FULLTEXT(text)
) DEFAULT CHARSET=utf8mb4;

-- DRB Psalm 50 (= Hebrew Psalm 51, "Have mercy")
INSERT IGNORE INTO DRB (book, chapter, verse, text, testament, section) VALUES
(23, 50, 1, 'Unto the end, a psalm of David,', 1, 7),
(23, 50, 2, 'When Nathan the prophet came to him, after he had sinned with Bethsabee.', 1, 7),
(23, 50, 3, 'Have mercy on me, O God, according to thy great mercy.', 1, 7),
(23, 50, 4, 'Wash me yet more from my iniquity, and cleanse me from my sin.', 1, 7),
(23, 50, 5, 'For I know my iniquity, and my sin is always before me.', 1, 7),
(23, 50, 6, 'To thee only have I sinned, and have done evil before thee.', 1, 7);
