-- Seed data for integration tests

-- Two test Bible versions: TEST1 (Catholic, not copyrighted), TEST2 (Protestant, copyrighted)
INSERT IGNORE INTO versions_available (sigla, fullname, year, language, imprimatur, canon, copyright_holder, notes, copyright, type) VALUES
('TEST1', 'Test Bible Version 1', '2020', 'English', 'Yes', 'CATHOLIC', 'Test Publisher', 'Test notes', 0, 'BIBLE'),
('TEST2', 'Test Bible Version 2', '2021', 'English', 'No', 'PROTESTANT', 'Other Publisher', '', 1, 'BIBLE');

-- Bible book names: just populate first 3 books (Genesis, Exodus, Leviticus) for testing
INSERT IGNORE INTO biblebooks_fullname (ENGLISH, ITALIAN) VALUES
('Genesis', 'Genesi'),
('Exodus', 'Esodo'),
('Leviticus', 'Levitico');

INSERT IGNORE INTO biblebooks_abbr (ENGLISH, ITALIAN) VALUES
('Gen | Gn', 'Gen | Gn'),
('Exod | Ex', 'Es | Esod'),
('Lev | Lv', 'Lv | Lev');

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
('Lev', 'Leviticus', 2, '6,4', 3);

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
('Lev', 'Leviticus', 2, '6,4', 3);

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
(2, 1, 3, 'Issachar, Zebulun and Benjamin.', 1, 2);

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
