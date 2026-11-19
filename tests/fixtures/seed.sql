-- Seed data for integration tests (PostgreSQL)

-- Test Bible versions
INSERT INTO versions_available (sigla, fullname, year, language, imprimatur, canon, copyright_holder, notes, copyright, type, ts_language) VALUES
('TEST1', 'Test Bible Version 1', '2020', 'English', 'Yes', 'CATHOLIC', 'Test Publisher', 'Test notes', 0, 'BIBLE', 'english'),
('TEST2', 'Test Bible Version 2', '2021', 'English', 'No', 'PROTESTANT', 'Other Publisher', '', 1, 'BIBLE', 'english'),
('VGCL', 'Vulgata Clementina', '1592', 'Latin', 'Yes', 'CATHOLIC', '', 'Test subset', 0, 'BIBLE', 'simple'),
('DRB', 'Douay-Rheims Bible', '1752', 'English', 'Yes', 'CATHOLIC', '', 'Test subset', 0, 'BIBLE', 'english')
ON CONFLICT DO NOTHING;

-- Bible book names/abbreviations live in biblebooks.sql (real 25-language data)

-- Index tables for TEST1 (3 books)
CREATE TABLE IF NOT EXISTS "TEST1_idx" (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
);

INSERT INTO "TEST1_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Exod', 'Exodus', 2, '7,5', 2),
('Lev', 'Leviticus', 2, '6,4', 3),
('Ps', 'Psalms', 55, '6,12,8,8,12,10,17,9,21,18,7,8,6,7,5,11,15,10,21,18,7,8,6,10,12,12,10,17,9,3,5,16,8,8,10,13,18,7,14,11,19,17,8,4,3,13,14,7,13,12,19,6,12,8,6,12,5,4,8,3,11,11,8,10,13,6,5,17,9,14,10,17,7,13,20,6,10,7,13,7,18,6', 23),
('Mk', 'Mark', 9, '1,1,1,1,1,1,1,1,48', 48)
ON CONFLICT DO NOTHING;

-- Index tables for TEST2 (3 books)
CREATE TABLE IF NOT EXISTS "TEST2_idx" (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
);

INSERT INTO "TEST2_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,35', 1),
('Exod', 'Exodus', 2, '7,5', 2),
('Lev', 'Leviticus', 2, '6,4', 3),
('Ps', 'Psalms', 55, '6,12,8,8,12,10,17,9,21,18,7,8,6,7,5,11,15,10,21,18,7,8,6,10,12,12,10,17,9,3,5,16,8,8,10,13,18,7,14,11,19,17,8,4,3,13,14,7,13,12,19,6,12,8,6,12,5,4,8,3,11,11,8,10,13,6,5,17,9,14,10,17,7,13,20,6,10,7,13,7,18,6', 23)
ON CONFLICT DO NOTHING;

-- Bible text tables for TEST1 (sample verses)
CREATE TABLE IF NOT EXISTS "TEST1" (
    "verseID"     SERIAL PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    text_plain    TEXT NOT NULL DEFAULT '',
    title_position SMALLINT,
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) DEFAULT NULL CHECK (verseorigin IN ('GREEK', 'HEBREW')),  -- as in production-schema.sql
    printed_chapter INT,                                     -- migration 044
    verse_label     VARCHAR(16),
    omitted         BOOLEAN NOT NULL DEFAULT false,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    embedding           vector(384),
    embedding_labse     vector(768),
    text_hash           BYTEA,
    embedded_text_hash  BYTEA
);
ALTER TABLE "TEST1" ADD COLUMN IF NOT EXISTS title1 VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS title2 VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS title3 VARCHAR(255) NOT NULL DEFAULT '';
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'TEST1_title_position_check') THEN
        ALTER TABLE "TEST1" ADD CONSTRAINT "TEST1_title_position_check"
            CHECK (bibleget_title_position_ok(text, title_position));
        ALTER TABLE "TEST1" ADD CONSTRAINT "TEST1_markup_check" CHECK (bibleget_markup_ok(text));
    END IF;
END $$;
CREATE INDEX IF NOT EXISTS "TEST1_text_fts" ON "TEST1" USING gin(to_tsvector('english', text));
CREATE INDEX IF NOT EXISTS "TEST1_text_plain_fts" ON "TEST1" USING gin(to_tsvector('english', text_plain));
CREATE INDEX IF NOT EXISTS "TEST1_embedding_hnsw" ON "TEST1" USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS "TEST1_embedding_labse_hnsw" ON "TEST1" USING hnsw (embedding_labse vector_cosine_ops);
DO $$ BEGIN
    CREATE OR REPLACE TRIGGER "TEST1_text_hash_trigger" BEFORE INSERT OR UPDATE OF text, text_plain, text_hash ON "TEST1"
        FOR EACH ROW EXECUTE FUNCTION update_text_hash();
END $$;

INSERT INTO "TEST1" (book, chapter, verse, text, testament, section) VALUES
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
(23, 51, 6, 'Yet you desired faithfulness even in the womb; you taught me wisdom in that secret place.', 1, 7)
ON CONFLICT DO NOTHING;

-- Formatted text (NABRE tag set) and a mid-verse heading, for the
-- text_plain / title_position tests. text_plain is derived by the trigger.
INSERT INTO "TEST1" (book, chapter, verse, text, title1, title_position, testament, section) VALUES
(1, 3, 1, E'<pof>Now the serpent was the most cunning</pof>\n<poi>of all the animals that the L<sm>ORD</sm> God had made.</poi>', '', NULL, 1, 1),
(1, 3, 2, 'The woman answered the serpent. We may eat of the fruit of the trees in the garden.', 'The woman replies', 32, 1, 1)
ON CONFLICT DO NOTHING;

-- Mk 9:44 with its text (an edition that prints it), next to TEST3's omitted one.
INSERT INTO "TEST1" (book, chapter, verse, text, testament, section) VALUES
(48, 9, 44, 'Where their worm dieth not, and the fire is not quenched.', 2, 7)
ON CONFLICT DO NOTHING;

-- Synthetic Psalm 23 (Hebrew-numbered, as TEST1_idx counts it) for numbering=edition.
INSERT INTO "TEST1" (book, chapter, verse, text, testament, section) VALUES
(23, 23, 1, 'TEST1 Ps 23:1', 1, 7),
(23, 23, 2, 'TEST1 Ps 23:2', 1, 7),
(23, 23, 3, 'TEST1 Ps 23:3', 1, 7),
(23, 23, 4, 'TEST1 Ps 23:4', 1, 7),
(23, 23, 5, 'TEST1 Ps 23:5', 1, 7),
(23, 23, 6, 'TEST1 Ps 23:6', 1, 7)
ON CONFLICT DO NOTHING;

-- Bible text tables for TEST2 (same sample verses)
CREATE TABLE IF NOT EXISTS "TEST2" (
    "verseID"     SERIAL PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    text_plain    TEXT NOT NULL DEFAULT '',
    title_position SMALLINT,
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) DEFAULT NULL CHECK (verseorigin IN ('GREEK', 'HEBREW')),  -- as in production-schema.sql
    printed_chapter INT,                                     -- migration 044
    verse_label     VARCHAR(16),
    omitted         BOOLEAN NOT NULL DEFAULT false,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    embedding           vector(384),
    embedding_labse     vector(768),
    text_hash           BYTEA,
    embedded_text_hash  BYTEA
);
CREATE INDEX IF NOT EXISTS "TEST2_text_fts" ON "TEST2" USING gin(to_tsvector('english', text));
CREATE INDEX IF NOT EXISTS "TEST2_embedding_hnsw" ON "TEST2" USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS "TEST2_embedding_labse_hnsw" ON "TEST2" USING hnsw (embedding_labse vector_cosine_ops);
DO $$ BEGIN
    CREATE OR REPLACE TRIGGER "TEST2_text_hash_trigger" BEFORE INSERT OR UPDATE OF text, text_plain, text_hash ON "TEST2"
        FOR EACH ROW EXECUTE FUNCTION update_text_hash();
END $$;

INSERT INTO "TEST2" (book, chapter, verse, text, testament, section) VALUES
(1, 1, 1, 'In the beginning God created the heaven and the earth.', 1, 1),
(1, 1, 2, 'And the earth was without form, and void.', 1, 1),
(1, 1, 3, 'And God said, Let there be light: and there was light.', 1, 1),
(1, 1, 4, 'And God saw the light, that it was good.', 1, 1),
(1, 1, 5, 'And God called the light Day, and the darkness he called Night.', 1, 1),
(1, 2, 1, 'Thus the heavens and the earth were finished, and all the host of them.', 1, 1),
(2, 1, 1, 'Now these are the names of the children of Israel.', 1, 2)
ON CONFLICT DO NOTHING;

-- Synthetic text (no real copyrighted wording): one TEST2 chapter longer than the
-- 30-verse limit, for the reader full-text switch (AdminHttpTest).
INSERT INTO "TEST2" (book, chapter, verse, text, testament, section) VALUES
(1, 3, 1, 'TEST2 Gen 3:1', 1, 1),
(1, 3, 2, 'TEST2 Gen 3:2', 1, 1),
(1, 3, 3, 'TEST2 Gen 3:3', 1, 1),
(1, 3, 4, 'TEST2 Gen 3:4', 1, 1),
(1, 3, 5, 'TEST2 Gen 3:5', 1, 1),
(1, 3, 6, 'TEST2 Gen 3:6', 1, 1),
(1, 3, 7, 'TEST2 Gen 3:7', 1, 1),
(1, 3, 8, 'TEST2 Gen 3:8', 1, 1),
(1, 3, 9, 'TEST2 Gen 3:9', 1, 1),
(1, 3, 10, 'TEST2 Gen 3:10', 1, 1),
(1, 3, 11, 'TEST2 Gen 3:11', 1, 1),
(1, 3, 12, 'TEST2 Gen 3:12', 1, 1),
(1, 3, 13, 'TEST2 Gen 3:13', 1, 1),
(1, 3, 14, 'TEST2 Gen 3:14', 1, 1),
(1, 3, 15, 'TEST2 Gen 3:15', 1, 1),
(1, 3, 16, 'TEST2 Gen 3:16', 1, 1),
(1, 3, 17, 'TEST2 Gen 3:17', 1, 1),
(1, 3, 18, 'TEST2 Gen 3:18', 1, 1),
(1, 3, 19, 'TEST2 Gen 3:19', 1, 1),
(1, 3, 20, 'TEST2 Gen 3:20', 1, 1),
(1, 3, 21, 'TEST2 Gen 3:21', 1, 1),
(1, 3, 22, 'TEST2 Gen 3:22', 1, 1),
(1, 3, 23, 'TEST2 Gen 3:23', 1, 1),
(1, 3, 24, 'TEST2 Gen 3:24', 1, 1),
(1, 3, 25, 'TEST2 Gen 3:25', 1, 1),
(1, 3, 26, 'TEST2 Gen 3:26', 1, 1),
(1, 3, 27, 'TEST2 Gen 3:27', 1, 1),
(1, 3, 28, 'TEST2 Gen 3:28', 1, 1),
(1, 3, 29, 'TEST2 Gen 3:29', 1, 1),
(1, 3, 30, 'TEST2 Gen 3:30', 1, 1),
(1, 3, 31, 'TEST2 Gen 3:31', 1, 1),
(1, 3, 32, 'TEST2 Gen 3:32', 1, 1),
(1, 3, 33, 'TEST2 Gen 3:33', 1, 1),
(1, 3, 34, 'TEST2 Gen 3:34', 1, 1),
(1, 3, 35, 'TEST2 Gen 3:35', 1, 1)
ON CONFLICT DO NOTHING;

-- ── VGCL (Vulgata Clementina) ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS "VGCL_idx" (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
);

-- Vulgate numbering: 150 Psalms. Hebrew Psalm 51 = VGCL chapter 50.
INSERT INTO "VGCL_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Exod', 'Exodus', 4, '22,25,22,31', 2),
('Psal', 'Psalmorum', 55, '6,13,9,10,13,11,18,10,39,8,9,6,7,5,10,15,51,15,10,14,32,6,10,22,12,14,9,11,13,25,11,22,23,28,13,40,23,14,18,14,12,5,26,18,12,10,15,21,23,21,11,7,9,24,13', 23)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS "VGCL" (
    "verseID"     SERIAL PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    text_plain    TEXT NOT NULL DEFAULT '',
    title_position SMALLINT,
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) DEFAULT NULL CHECK (verseorigin IN ('GREEK', 'HEBREW')),  -- as in production-schema.sql
    printed_chapter INT,                                     -- migration 044
    verse_label     VARCHAR(16),
    omitted         BOOLEAN NOT NULL DEFAULT false,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    embedding           vector(384),
    embedding_labse     vector(768),
    text_hash           BYTEA,
    embedded_text_hash  BYTEA
);
CREATE INDEX IF NOT EXISTS "VGCL_text_fts" ON "VGCL" USING gin(to_tsvector('simple', text));
CREATE INDEX IF NOT EXISTS "VGCL_embedding_hnsw" ON "VGCL" USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS "VGCL_embedding_labse_hnsw" ON "VGCL" USING hnsw (embedding_labse vector_cosine_ops);
DO $$ BEGIN
    CREATE OR REPLACE TRIGGER "VGCL_text_hash_trigger" BEFORE INSERT OR UPDATE OF text, text_plain, text_hash ON "VGCL"
        FOR EACH ROW EXECUTE FUNCTION update_text_hash();
END $$;

-- VGCL Psalm 50 (= Hebrew Psalm 51, "Miserere mei")
INSERT INTO "VGCL" (book, chapter, verse, text, testament, section) VALUES
(23, 50, 1, 'In finem. Psalmus David,', 1, 7),
(23, 50, 2, 'cum venit ad eum Nathan propheta, quando intravit ad Bethsabee.', 1, 7),
(23, 50, 3, 'Miserere mei, Deus, secundum magnam misericordiam tuam.', 1, 7),
(23, 50, 4, 'Amplius lava me ab iniquitate mea, et a peccato meo munda me.', 1, 7),
(23, 50, 5, 'Quoniam iniquitatem meam ego cognosco, et peccatum meum contra me est semper.', 1, 7),
(23, 50, 6, 'Tibi soli peccavi, et malum coram te feci.', 1, 7),
-- VGCL Psalm 51 (= Hebrew Psalm 52, a different Psalm)
(23, 51, 1, 'In finem. Intellectus David,', 1, 7),
(23, 51, 2, 'cum venit Doeg Idumaeus, et nuntiavit Sauli.', 1, 7),
(23, 51, 3, 'Quid gloriaris in malitia, qui potens es in iniquitate?', 1, 7),
-- The Clementine's own spellings, for accent-insensitive keyword search (#196)
(1, 1, 1, 'In principio creavit Deus cælum et terram.', 1, 1),
(2, 4, 22, 'dicesque ad eum: Hæc dicit Dominus: Filius meus primogenitus Israël.', 1, 1)
ON CONFLICT DO NOTHING;

-- Synthetic VGCL Psalms 9 (= Hebrew 9 + 10), 21 and 22 (= Hebrew 22, 23), as VGCL_idx counts
-- them, for numbering=edition and psalm_hebrew (QuoteHandlerHttpTest).
INSERT INTO "VGCL" (book, chapter, verse, text, testament, section) VALUES
(23, 9, 1, 'VGCL Ps 9:1', 1, 7),
(23, 9, 2, 'VGCL Ps 9:2', 1, 7),
(23, 9, 3, 'VGCL Ps 9:3', 1, 7),
(23, 9, 4, 'VGCL Ps 9:4', 1, 7),
(23, 9, 5, 'VGCL Ps 9:5', 1, 7),
(23, 9, 6, 'VGCL Ps 9:6', 1, 7),
(23, 9, 7, 'VGCL Ps 9:7', 1, 7),
(23, 9, 8, 'VGCL Ps 9:8', 1, 7),
(23, 9, 9, 'VGCL Ps 9:9', 1, 7),
(23, 9, 10, 'VGCL Ps 9:10', 1, 7),
(23, 9, 11, 'VGCL Ps 9:11', 1, 7),
(23, 9, 12, 'VGCL Ps 9:12', 1, 7),
(23, 9, 13, 'VGCL Ps 9:13', 1, 7),
(23, 9, 14, 'VGCL Ps 9:14', 1, 7),
(23, 9, 15, 'VGCL Ps 9:15', 1, 7),
(23, 9, 16, 'VGCL Ps 9:16', 1, 7),
(23, 9, 17, 'VGCL Ps 9:17', 1, 7),
(23, 9, 18, 'VGCL Ps 9:18', 1, 7),
(23, 9, 19, 'VGCL Ps 9:19', 1, 7),
(23, 9, 20, 'VGCL Ps 9:20', 1, 7),
(23, 9, 21, 'VGCL Ps 9:21', 1, 7),
(23, 9, 22, 'VGCL Ps 9:22', 1, 7),
(23, 9, 23, 'VGCL Ps 9:23', 1, 7),
(23, 9, 24, 'VGCL Ps 9:24', 1, 7),
(23, 9, 25, 'VGCL Ps 9:25', 1, 7),
(23, 9, 26, 'VGCL Ps 9:26', 1, 7),
(23, 9, 27, 'VGCL Ps 9:27', 1, 7),
(23, 9, 28, 'VGCL Ps 9:28', 1, 7),
(23, 9, 29, 'VGCL Ps 9:29', 1, 7),
(23, 9, 30, 'VGCL Ps 9:30', 1, 7),
(23, 9, 31, 'VGCL Ps 9:31', 1, 7),
(23, 9, 32, 'VGCL Ps 9:32', 1, 7),
(23, 9, 33, 'VGCL Ps 9:33', 1, 7),
(23, 9, 34, 'VGCL Ps 9:34', 1, 7),
(23, 9, 35, 'VGCL Ps 9:35', 1, 7),
(23, 9, 36, 'VGCL Ps 9:36', 1, 7),
(23, 9, 37, 'VGCL Ps 9:37', 1, 7),
(23, 9, 38, 'VGCL Ps 9:38', 1, 7),
(23, 9, 39, 'VGCL Ps 9:39', 1, 7),
(23, 21, 1, 'VGCL Ps 21:1', 1, 7),
(23, 21, 2, 'VGCL Ps 21:2', 1, 7),
(23, 21, 3, 'VGCL Ps 21:3', 1, 7),
(23, 21, 4, 'VGCL Ps 21:4', 1, 7),
(23, 21, 5, 'VGCL Ps 21:5', 1, 7),
(23, 21, 6, 'VGCL Ps 21:6', 1, 7),
(23, 21, 7, 'VGCL Ps 21:7', 1, 7),
(23, 21, 8, 'VGCL Ps 21:8', 1, 7),
(23, 21, 9, 'VGCL Ps 21:9', 1, 7),
(23, 21, 10, 'VGCL Ps 21:10', 1, 7),
(23, 21, 11, 'VGCL Ps 21:11', 1, 7),
(23, 21, 12, 'VGCL Ps 21:12', 1, 7),
(23, 21, 13, 'VGCL Ps 21:13', 1, 7),
(23, 21, 14, 'VGCL Ps 21:14', 1, 7),
(23, 21, 15, 'VGCL Ps 21:15', 1, 7),
(23, 21, 16, 'VGCL Ps 21:16', 1, 7),
(23, 21, 17, 'VGCL Ps 21:17', 1, 7),
(23, 21, 18, 'VGCL Ps 21:18', 1, 7),
(23, 21, 19, 'VGCL Ps 21:19', 1, 7),
(23, 21, 20, 'VGCL Ps 21:20', 1, 7),
(23, 21, 21, 'VGCL Ps 21:21', 1, 7),
(23, 21, 22, 'VGCL Ps 21:22', 1, 7),
(23, 21, 23, 'VGCL Ps 21:23', 1, 7),
(23, 21, 24, 'VGCL Ps 21:24', 1, 7),
(23, 21, 25, 'VGCL Ps 21:25', 1, 7),
(23, 21, 26, 'VGCL Ps 21:26', 1, 7),
(23, 21, 27, 'VGCL Ps 21:27', 1, 7),
(23, 21, 28, 'VGCL Ps 21:28', 1, 7),
(23, 21, 29, 'VGCL Ps 21:29', 1, 7),
(23, 21, 30, 'VGCL Ps 21:30', 1, 7),
(23, 21, 31, 'VGCL Ps 21:31', 1, 7),
(23, 21, 32, 'VGCL Ps 21:32', 1, 7),
(23, 22, 1, 'VGCL Ps 22:1', 1, 7),
(23, 22, 2, 'VGCL Ps 22:2', 1, 7),
(23, 22, 3, 'VGCL Ps 22:3', 1, 7),
(23, 22, 4, 'VGCL Ps 22:4', 1, 7),
(23, 22, 5, 'VGCL Ps 22:5', 1, 7),
(23, 22, 6, 'VGCL Ps 22:6', 1, 7)
ON CONFLICT DO NOTHING;

-- ── DRB (Douay-Rheims Bible) ───────────────────────────────────────────

CREATE TABLE IF NOT EXISTS "DRB_idx" (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
);

-- DRB uses same Vulgate numbering as VGCL.
INSERT INTO "DRB_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Gen', 'Genesis', 3, '10,8,5', 1),
('Ps', 'Psalms', 55, '6,13,9,10,13,11,18,10,39,8,9,6,7,5,11,15,51,15,9,14,32,6,10,22,12,14,9,10,13,25,11,22,23,28,13,40,23,14,18,14,12,6,26,18,12,10,15,21,23,21,11,7,9,24,13', 23)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS "DRB" (
    "verseID"     SERIAL PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    text_plain    TEXT NOT NULL DEFAULT '',
    title_position SMALLINT,
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) DEFAULT NULL CHECK (verseorigin IN ('GREEK', 'HEBREW')),  -- as in production-schema.sql
    printed_chapter INT,                                     -- migration 044
    verse_label     VARCHAR(16),
    omitted         BOOLEAN NOT NULL DEFAULT false,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    embedding           vector(384),
    embedding_labse     vector(768),
    text_hash           BYTEA,
    embedded_text_hash  BYTEA
);
CREATE INDEX IF NOT EXISTS "DRB_text_fts" ON "DRB" USING gin(to_tsvector('english', text));
CREATE INDEX IF NOT EXISTS "DRB_embedding_hnsw" ON "DRB" USING hnsw (embedding vector_cosine_ops);
CREATE INDEX IF NOT EXISTS "DRB_embedding_labse_hnsw" ON "DRB" USING hnsw (embedding_labse vector_cosine_ops);
DO $$ BEGIN
    CREATE OR REPLACE TRIGGER "DRB_text_hash_trigger" BEFORE INSERT OR UPDATE OF text, text_plain, text_hash ON "DRB"
        FOR EACH ROW EXECUTE FUNCTION update_text_hash();
END $$;

-- DRB Psalm 50 (= Hebrew Psalm 51, "Have mercy")
INSERT INTO "DRB" (book, chapter, verse, text, testament, section) VALUES
(23, 50, 1, 'Unto the end, a psalm of David,', 1, 7),
(23, 50, 2, 'When Nathan the prophet came to him, after he had sinned with Bethsabee.', 1, 7),
(23, 50, 3, 'Have mercy on me, O God, according to thy great mercy.', 1, 7),
(23, 50, 4, 'Wash me yet more from my iniquity, and cleanse me from my sin.', 1, 7),
(23, 50, 5, 'For I know my iniquity, and my sin is always before me.', 1, 7),
(23, 50, 6, 'To thee only have I sinned, and have done evil before thee.', 1, 7)
ON CONFLICT DO NOTHING;

-- Databases seeded before verseequiv was added to the test tables.
ALTER TABLE "TEST1" ADD COLUMN IF NOT EXISTS verseequiv VARCHAR(10) DEFAULT NULL;
ALTER TABLE "TEST2" ADD COLUMN IF NOT EXISTS verseequiv VARCHAR(10) DEFAULT NULL;
ALTER TABLE "VGCL" ADD COLUMN IF NOT EXISTS verseequiv VARCHAR(10) DEFAULT NULL;
ALTER TABLE "DRB" ADD COLUMN IF NOT EXISTS verseequiv VARCHAR(10) DEFAULT NULL;

-- Esther in the supplementary (Vulgate) pattern, synthetic: the index has 16
-- chapters; chapters 1-10 store HEBREW and GREEK rows, the GREEK ones with the
-- additions as lettered sub-verses (verseequiv '1a', which migration 046 turns
-- into verse_segment 'a'); chapters 11-16 are GREEK only (the default numbering
-- reads 16 as the Greek 8:12a ff.). DRB prints 10:1-13 in both traditions;
-- VGCL's HEBREW chapter 10 has 1-3 only, 10:4-13 being GREEK continuation.
-- "verseID" (sort_order) mirrors staging's storage order, which is not reading
-- order: DRB stores HEBREW 1-10, GREEK 11-16, the lettered rows, GREEK 1-9,
-- then GREEK 10; VGCL stores GREEK 10:4-13 before GREEK 10:1-3.
INSERT INTO "DRB_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Esth', 'Esther', 16, '3,1,1,1,1,1,1,12,1,5,2,1,1,1,1,4', 19)
ON CONFLICT DO NOTHING;
INSERT INTO "DRB" ("verseID", book, chapter, verse, verseorigin, verseequiv, text, testament, section)
SELECT * FROM (VALUES
    (13001, 19, 1, 1, 'HEBREW', NULL, 'DRB Est 1:1 hebrew', 1, 4),
    (13002, 19, 1, 2, 'HEBREW', NULL, 'DRB Est 1:2 hebrew', 1, 4),
    (13003, 19, 1, 3, 'HEBREW', NULL, 'DRB Est 1:3 hebrew', 1, 4),
    (13004, 19, 8, 12, 'HEBREW', NULL, 'DRB Est 8:12 hebrew', 1, 4),
    (13005, 19, 10, 1, 'HEBREW', NULL, 'DRB Est 10:1 hebrew', 1, 4),
    (13006, 19, 10, 2, 'HEBREW', NULL, 'DRB Est 10:2 hebrew', 1, 4),
    (13007, 19, 10, 3, 'HEBREW', NULL, 'DRB Est 10:3 hebrew', 1, 4),
    (13008, 19, 10, 4, 'HEBREW', NULL, 'DRB Est 10:4 hebrew', 1, 4),
    (13009, 19, 10, 5, 'HEBREW', NULL, 'DRB Est 10:5 hebrew', 1, 4),
    (13010, 19, 11, 1, 'GREEK', NULL, 'DRB Est 11:1 greek', 1, 4),
    (13011, 19, 11, 2, 'GREEK', NULL, 'DRB Est 11:2 greek', 1, 4),
    (13012, 19, 16, 1, 'GREEK', NULL, 'DRB Est 16:1 greek', 1, 4),
    (13013, 19, 16, 2, 'GREEK', NULL, 'DRB Est 16:2 greek', 1, 4),
    (13014, 19, 16, 3, 'GREEK', NULL, 'DRB Est 16:3 greek', 1, 4),
    (13015, 19, 16, 4, 'GREEK', NULL, 'DRB Est 16:4 greek', 1, 4),
    (13016, 19, 1, 1, 'GREEK', '1a', 'DRB Est 1:1a greek', 1, 4),
    (13017, 19, 1, 1, 'GREEK', '1b', 'DRB Est 1:1b greek', 1, 4),
    (13018, 19, 8, 12, 'GREEK', '12a', 'DRB Est 8:12a greek', 1, 4),
    (13019, 19, 8, 12, 'GREEK', '12b', 'DRB Est 8:12b greek', 1, 4),
    (13020, 19, 1, 1, 'GREEK', NULL, 'DRB Est 1:1 greek', 1, 4),
    (13021, 19, 1, 2, 'GREEK', NULL, 'DRB Est 1:2 greek', 1, 4),
    (13022, 19, 1, 3, 'GREEK', NULL, 'DRB Est 1:3 greek', 1, 4),
    (13023, 19, 8, 12, 'GREEK', NULL, 'DRB Est 8:12 greek', 1, 4),
    (13024, 19, 10, 1, 'GREEK', NULL, 'DRB Est 10:1 greek', 1, 4),
    (13025, 19, 10, 2, 'GREEK', NULL, 'DRB Est 10:2 greek', 1, 4),
    (13026, 19, 10, 3, 'GREEK', NULL, 'DRB Est 10:3 greek', 1, 4),
    (13027, 19, 10, 4, 'GREEK', NULL, 'DRB Est 10:4 greek', 1, 4),
    (13028, 19, 10, 5, 'GREEK', NULL, 'DRB Est 10:5 greek', 1, 4)
) AS v("verseID", book, chapter, verse, verseorigin, verseequiv, text, testament, section)
WHERE NOT EXISTS (SELECT 1 FROM "DRB" WHERE book = 19);
INSERT INTO "VGCL_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Esth', 'Esther', 16, '1,1,1,1,1,1,1,1,1,13,1,1,1,1,1,1', 19)
ON CONFLICT DO NOTHING;
INSERT INTO "VGCL" ("verseID", book, chapter, verse, verseorigin, verseequiv, text, testament, section)
SELECT * FROM (VALUES
    (13001, 19, 10, 1, 'HEBREW', NULL, 'VGCL Est 10:1 hebrew', 1, 4),
    (13002, 19, 10, 2, 'HEBREW', NULL, 'VGCL Est 10:2 hebrew', 1, 4),
    (13003, 19, 10, 3, 'HEBREW', NULL, 'VGCL Est 10:3 hebrew', 1, 4),
    (13004, 19, 10, 4, 'GREEK', NULL, 'VGCL Est 10:4 greek', 1, 4),
    (13005, 19, 10, 5, 'GREEK', NULL, 'VGCL Est 10:5 greek', 1, 4),
    (13006, 19, 10, 6, 'GREEK', NULL, 'VGCL Est 10:6 greek', 1, 4),
    (13007, 19, 10, 7, 'GREEK', NULL, 'VGCL Est 10:7 greek', 1, 4),
    (13008, 19, 10, 8, 'GREEK', NULL, 'VGCL Est 10:8 greek', 1, 4),
    (13009, 19, 10, 9, 'GREEK', NULL, 'VGCL Est 10:9 greek', 1, 4),
    (13010, 19, 10, 10, 'GREEK', NULL, 'VGCL Est 10:10 greek', 1, 4),
    (13011, 19, 10, 11, 'GREEK', NULL, 'VGCL Est 10:11 greek', 1, 4),
    (13012, 19, 10, 12, 'GREEK', NULL, 'VGCL Est 10:12 greek', 1, 4),
    (13013, 19, 10, 13, 'GREEK', NULL, 'VGCL Est 10:13 greek', 1, 4),
    (13014, 19, 10, 1, 'GREEK', NULL, 'VGCL Est 10:1 greek', 1, 4),
    (13015, 19, 10, 2, 'GREEK', NULL, 'VGCL Est 10:2 greek', 1, 4),
    (13016, 19, 10, 3, 'GREEK', NULL, 'VGCL Est 10:3 greek', 1, 4)
) AS v("verseID", book, chapter, verse, verseorigin, verseequiv, text, testament, section)
WHERE NOT EXISTS (SELECT 1 FROM "VGCL" WHERE book = 19);

-- ── TEST3: verses printed under a label in another chapter ────────────
-- Mirrors NABRE's layout with placeholder text: NABRE prints Wis 12:1 after
-- 11:26 (chapter 12 starts at verse 2) and the first words of Job 10:1 after
-- 9:35 (chapter 10 opens with the rest of verse 1, unnumbered). The rows sit
-- where they are printed, with the printed label in verseequiv; insertion
-- order is reading order ("verseID"). The index is by verse number. Wis 12:3
-- and 12:4 carry other verseequiv shapes ('' and a lettered '4a'), which
-- must not be read as a label.
INSERT INTO versions_available (sigla, fullname, year, language, imprimatur, canon, copyright_holder, notes, copyright, type, ts_language) VALUES
('TEST3', 'Test Bible Version 3', '2026', 'English', 'Yes', 'CATHOLIC', 'Test Publisher', 'Printed labels', 0, 'BIBLE', 'english')
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS "TEST3_idx" (
    abbrev     VARCHAR(20) NOT NULL DEFAULT '',
    fullname   VARCHAR(100) NOT NULL DEFAULT '',
    chapters   INT NOT NULL DEFAULT 0,
    verses_last VARCHAR(500) NOT NULL DEFAULT '',
    book       INT NOT NULL DEFAULT 0,
    PRIMARY KEY (book)
);
INSERT INTO "TEST3_idx" (abbrev, fullname, chapters, verses_last, book) VALUES
('Jb', 'Job', 10, '1,1,1,1,1,1,1,1,35,2', 22),
('Wis', 'Wisdom', 12, '1,1,1,1,1,1,1,1,1,1,26,5', 27),
('Est', 'Esther', 9, '2,1,1,1,1,1,1,1,32', 19),
('Mk', 'Mark', 9, '1,1,1,1,1,1,1,1,47', 48),
('Jn', 'John', 8, '1,1,1,1,1,1,53,2', 50)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS "TEST3" (
    "verseID"     SERIAL PRIMARY KEY,
    book        INT NOT NULL,
    chapter     INT NOT NULL,
    verse       INT NOT NULL,
    text        TEXT NOT NULL,
    text_plain    TEXT NOT NULL DEFAULT '',
    title_position SMALLINT,
    title1      VARCHAR(255) NOT NULL DEFAULT '',
    title2      VARCHAR(255) NOT NULL DEFAULT '',
    title3      VARCHAR(255) NOT NULL DEFAULT '',
    testament   SMALLINT NOT NULL DEFAULT 1,
    section     INT NOT NULL DEFAULT 0,
    verseorigin VARCHAR(10) DEFAULT NULL CHECK (verseorigin IN ('GREEK', 'HEBREW')),  -- as in production-schema.sql
    printed_chapter INT,                                     -- migration 044
    verse_label     VARCHAR(16),
    omitted         BOOLEAN NOT NULL DEFAULT false,
    verseequiv  VARCHAR(10) DEFAULT NULL,
    versedescr  VARCHAR(10) DEFAULT NULL,
    embedding           vector(384),
    embedding_labse     vector(768),
    text_hash           BYTEA,
    embedded_text_hash  BYTEA
);
CREATE INDEX IF NOT EXISTS "TEST3_text_fts" ON "TEST3" USING gin(to_tsvector('english', text));
CREATE INDEX IF NOT EXISTS "TEST3_text_plain_fts" ON "TEST3" USING gin(to_tsvector('english', text_plain));
DO $$ BEGIN
    CREATE OR REPLACE TRIGGER "TEST3_text_hash_trigger" BEFORE INSERT OR UPDATE OF text, text_plain, text_hash ON "TEST3"
        FOR EACH ROW EXECUTE FUNCTION update_text_hash();
END $$;

INSERT INTO "TEST3" (book, chapter, verse, verseequiv, versedescr, printed_chapter, verse_label, omitted, text, testament, section)
SELECT * FROM (VALUES
    -- As NABRE stores them since migration 045: at their identity, with where and
    -- how the edition prints them in printed_chapter / verse_label.
    (22, 9, 34, NULL, NULL, NULL, NULL, false, 'Job 9:34 placeholder', 1, 5),
    (22, 9, 35, NULL, NULL, NULL, NULL, false, 'Job 9:35 placeholder', 1, 5),
    (22, 10, 1, NULL, NULL, 9, '10:1', false, 'Job 10:1 first part labelmark placeholder', 1, 5),
    (22, 10, 1, NULL, NULL, NULL, '', false, 'Job 10:1 rest placeholder', 1, 5),
    (22, 10, 2, NULL, NULL, NULL, NULL, false, 'Job 10:2 placeholder', 1, 5),
    (27, 11, 25, NULL, NULL, NULL, NULL, false, 'Wis 11:25 placeholder', 1, 5),
    (27, 11, 26, NULL, NULL, NULL, NULL, false, 'Wis 11:26 placeholder', 1, 5),
    (27, 12, 1, NULL, NULL, 11, '12:1', false, 'Wis 12:1 labelmark placeholder', 1, 5),
    (27, 12, 2, NULL, NULL, NULL, NULL, false, 'Wis 12:2 placeholder', 1, 5),
    (27, 12, 3, '', NULL, NULL, NULL, false, 'Wis 12:3 placeholder', 1, 5),
    (27, 12, 4, '4a', NULL, NULL, NULL, false, 'Wis 12:4 placeholder', 1, 5),
    (27, 12, 5, NULL, NULL, NULL, NULL, false, 'Wis 12:5 placeholder', 1, 5),
    -- Omitted verses: the number is printed, the text is not (#180).
    (48, 9, 43, NULL, NULL, NULL, NULL, false, 'Mk 9:43 placeholder', 2, 7),
    (48, 9, 44, NULL, NULL, NULL, NULL, true, '', 2, 7),
    (48, 9, 45, NULL, NULL, NULL, NULL, false, 'Mk 9:45 placeholder', 2, 7),
    (48, 9, 46, NULL, NULL, NULL, NULL, true, '', 2, 7),
    (48, 9, 47, NULL, NULL, NULL, NULL, false, 'Mk 9:47 placeholder', 2, 7),
    -- John 7:53, printed under chapter 8 before 8:1.
    (50, 7, 52, NULL, NULL, NULL, NULL, false, 'Jn 7:52 placeholder', 2, 7),
    (50, 7, 53, NULL, NULL, 8, '7:53', false, 'Jn 7:53 placeholder', 2, 7),
    (50, 8, 1, NULL, NULL, NULL, NULL, false, 'Jn 8:1 placeholder', 2, 7),
    (50, 8, 2, NULL, NULL, NULL, NULL, false, 'Jn 8:2 placeholder', 2, 7)
) AS v(book, chapter, verse, verseequiv, versedescr, printed_chapter, verse_label, omitted, text, testament, section)
WHERE NOT EXISTS (SELECT 1 FROM "TEST3");

-- Esther: the Hebrew and the Greek text share chapter:verse, told apart by
-- verseorigin (similar search must start from one of them, not a blend).
-- 9:29-31 as in CEI 2008: the Greek text has no 9:30.
INSERT INTO "TEST3" (book, chapter, verse, verseorigin, text, testament, section)
SELECT * FROM (VALUES
    (19, 1, 1, 'HEBREW', 'Est 1:1 hebrew placeholder', 1, 4),
    (19, 1, 1, 'GREEK', 'Est 1:1 greek placeholder', 1, 4),
    (19, 1, 2, 'HEBREW', 'Est 1:2 hebrew placeholder', 1, 4),
    (19, 1, 2, 'GREEK', 'Est 1:2 greek placeholder', 1, 4),
    (19, 9, 29, 'HEBREW', 'Est 9:29 hebrew placeholder', 1, 4),
    (19, 9, 30, 'HEBREW', 'Est 9:30 hebrew placeholder', 1, 4),
    (19, 9, 31, 'HEBREW', 'Est 9:31 hebrew placeholder', 1, 4),
    (19, 9, 29, 'GREEK', 'Est 9:29 greek placeholder', 1, 4),
    (19, 9, 31, 'GREEK', 'Est 9:31 greek placeholder', 1, 4)
) AS v(book, chapter, verse, verseorigin, text, testament, section)
WHERE NOT EXISTS (SELECT 1 FROM "TEST3" WHERE book = 19);
