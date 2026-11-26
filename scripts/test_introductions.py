import json
import tempfile
import unittest
from pathlib import Path

import introductions as intro
import notes_nabre as nn


def row(book, chapter, verse, seg="", pc=""):
    return {"ident": (book, chapter, verse, seg, "", pc), "key": (book, chapter, verse, seg), "label": str(verse),
            "fields": {"text": "x", "title1": "", "title2": "", "title3": ""}}


INDEX = nn.index_rows([row(1, 1, 1), row(1, 2, 4), row(3, 1, 1), row(3, 7, 38), row(47, 1, 1)])
BOOK = ('<link rel="stylesheet" href="nabscml.css" type="text/css" />\n<scml>\n<bk>THE BOOK OF TESTS</bk>\n'
        '<pf>Opening, cf. <xref id="01001001">Gn 1:1</xref>&#8211;<xref id="01002004">2:4</xref>; <ver>see</ver> chaps. <xref id="01002000">2</xref>.</pf>\n'
        '<ah>A Heading</ah>\n<p>Unknown <xref id="99001001">place</xref>.</p>\n'
        '<nlf>I. First (<xref id="03001001-03007038">1:1&#8211;7:38</xref>)</nlf>\n<nl1f>A. Sub</nl1f>\n'
        '<table><tr><td>One</td><td><i>Two</i></td></tr></table>\n'
        '<bksect>I. THE FIRST PART</bksect>\n<ch id="03001000">CHAPTER 1</ch><p>Not the introduction.</p>')


class SegmentTest(unittest.TestCase):
    def test_aBooksIntroductionRunsFromItsTitleToTheFirstSection(self):
        title, inner, joint = intro.book_segment(BOOK)
        self.assertEqual(title, "THE BOOK OF TESTS")
        self.assertTrue(inner.lstrip().startswith("<pf>Opening"))
        self.assertNotIn("bksect", inner)
        self.assertFalse(joint)

    def test_itEndsAtAChapterAVerseOrAHeading(self):
        for stop in ('<ch id="40001000">CHAPTER 1</ch>', '<p id="65001001">v</p>', '<v id="65001001">v</v>', "<chsect>S</chsect>", "<cs>S</cs>"):
            self.assertEqual(intro.book_segment(f"<bk>T</bk><p>One.</p>{stop}<p>Two.</p>")[1], "<p>One.</p>")

    def test_aCitationWithAnIdIsNotAVerse(self):
        self.assertIn("Two", intro.book_segment('<bk>T</bk><p>One <xref id="01001001">1</xref>.</p><p>Two.</p>')[1])

    def test_aParagraphOpenedByTheFirstVerseIsNotTheIntroductions(self):
        scml = '<bk>T</bk><p>One.</p>\n<pf><b>Address.</b> <v id="65001001"><bcv>1</bcv>Paul</v></pf>'
        self.assertEqual(intro.book_segment(scml)[1], "<p>One.</p>")

    def test_aSecondBookTitleEndsAJointIntroduction(self):
        title, inner, joint = intro.book_segment("<bk>THE BOOKS OF TESTS</bk><p>Both.</p><bk>THE FIRST BOOK</bk><bksect>I.</bksect>")
        self.assertEqual((title, inner, joint), ("THE BOOKS OF TESTS", "<p>Both.</p>", True))

    def test_aSubtitleJoinsTheTitle(self):
        self.assertEqual(intro.book_segment("<bk>THE WISDOM</bk>\n<bks>(SUBTITLE)</bks><p>x</p><ch id=\"28001000\">")[0],
                         "THE WISDOM (SUBTITLE)")

    def test_aBookWithoutAnIntroductionHasAnEmptySegment(self):
        self.assertEqual(intro.book_segment('<bk>THE SECOND BOOK</bk>\n<ch id="10001000">CHAPTER 1</ch>')[1].strip(), "")


class BlocksTest(unittest.TestCase):
    def setUp(self):
        self.problems = []
        self.blocks = intro.blocks(intro.book_segment(BOOK)[1], INDEX, "nab-03-lv.scml", self.problems)

    def test_blocksInOrderWithTheirTypesAndLevels(self):
        self.assertEqual([(b["type"], b.get("level")) for b in self.blocks],
                         [("paragraph", None), ("heading", 1), ("paragraph", None), ("item", 1), ("item", 2), ("row", None)])

    def test_textKeepsTheAllowedTagsAndEntities(self):
        self.assertEqual(self.blocks[0]["text"], "Opening, cf. Gn 1:1–2:4; <i>see</i> chaps. 2.")
        self.assertEqual(self.blocks[5]["cells"], [{"text": "One", "refs": []}, {"text": "<i>Two</i>", "refs": []}])

    def test_citationsAreSpansWithTheirVerses(self):
        text, refs = self.blocks[0]["text"], self.blocks[0]["refs"]
        self.assertEqual([text[r["span"][0]:r["span"][1]] for r in refs], ["Gn 1:1", "2:4", "2"])
        self.assertEqual(refs[0]["start"], {"book": 1, "chapter": 1, "verse": 1, "segment": None, "printed_chapter": None})
        self.assertIsNone(refs[0]["end"])
        self.assertEqual(refs[2]["start"]["verse"], None)  # a whole chapter

    def test_aRangeHasAnEnd(self):
        (r,) = self.blocks[3]["refs"]
        self.assertEqual((r["start"]["chapter"], r["end"]), (1, {"book": 3, "chapter": 7, "verse": 38}))

    def test_anUnresolvedCitationIsPlainTextAndReported(self):
        self.assertEqual((self.blocks[2]["text"], self.blocks[2]["refs"]), ("Unknown place.", []))
        self.assertEqual(self.problems, [("nab-03-lv.scml", "unresolved-citation", "99001001 place")])

    def test_aRangeWhoseEndIsNoStoredVerseIsUnresolved(self):
        problems = []
        (b,) = intro.blocks('<p>See <xref id="03001001-03009099">1:1&#8211;9:99</xref>.</p>', INDEX, "f", problems)
        self.assertEqual(b["refs"], [])
        self.assertEqual(problems, [("f", "unresolved-citation", "03001001-03009099 1:1\u20139:99")])

    def test_anUnknownBlockIsReported(self):
        problems = []
        self.assertEqual(intro.blocks("<p>a</p><weird>b</weird>", INDEX, "f", problems), [{"type": "paragraph", "text": "a", "refs": []}])
        self.assertEqual(problems, [("f", "unknown-block", "weird")])


class CollectTest(unittest.TestCase):
    def collect(self, files):
        with tempfile.TemporaryDirectory() as d:
            for name, text in files.items():
                (Path(d) / name).write_bytes(text.encode("cp1252"))
            problems = []
            return intro.nabre(d, INDEX, problems), problems

    def test_booksJointAndGeneralIntroductionsInPrintOrder(self):
        got, problems = self.collect({
            "nab-01-gn.scml": "<bk>GENESIS</bk><p>G.</p><bksect>I.</bksect>",
            "nab-01b-pentintro.scml": "<scml><pt>THE PENTATEUCH</pt><pf>P.</pf></scml>",
            "nab-09-1sm.scml": "<bk>THE BOOKS OF SAMUEL</bk><p>S.</p><bk>THE FIRST BOOK OF SAMUEL</bk><bksect>I.</bksect>",
            "nab-10-2sm.scml": '<bk>THE SECOND BOOK OF SAMUEL</bk><ch id="10001000">CHAPTER 1</ch>',
            "nab-48-mt.scml": "<bk>MATTHEW</bk><p>M.</p><bksect>I.</bksect>",
            "nab-48a-ntintro.scml": "<pt>PREFACE</pt><p>N.</p>",
            "nab-48b-gospintro.scml": "<pt>THE GOSPELS</pt><p>E.</p>",
            "nab-00-fm.scml": "<p>Front matter.</p>",
        })
        self.assertEqual([(i.id, i.title, i.books, i.general) for i in got], [
            ("pentateuch", "THE PENTATEUCH", [1, 2, 3, 4, 5], True),
            ("1", "GENESIS", [1], False),
            ("9", "THE BOOKS OF SAMUEL", [9, 10], False),
            ("new-testament", "PREFACE", list(range(47, 74)), True),
            ("gospels", "THE GOSPELS", [47, 48, 49, 50], True),
            ("47", "MATTHEW", [47], False),
        ])
        self.assertEqual(got[0].blocks, [{"type": "paragraph", "text": "P.", "refs": []}])
        self.assertEqual(problems, [])

    def test_aBookWithoutAnIntroductionNotSharedIsReported(self):
        _, problems = self.collect({"nab-02-ex.scml": '<bk>EXODUS</bk><ch id="02001000">CHAPTER 1</ch>'})
        self.assertEqual(problems, [("nab-02-ex.scml", "no-introduction", "")])

    def test_anUnknownGeneralFileStopsTheImport(self):
        with self.assertRaises(SystemExit):
            self.collect({"nab-05a-other.scml": "<pt>OTHER</pt><p>x</p>"})


class MisprintTest(unittest.TestCase):
    SCML = "<bk>T</bk><p>Gn 25:19\u2013<xref id=\"01035043\">35:43</xref> and <xref id=\"47005014\">5:14</xref>.</p><bksect>I.</bksect>"

    def collect(self, misprints=(), citations=""):
        with tempfile.TemporaryDirectory() as d:
            (Path(d) / "nab-03-lv.scml").write_text(self.SCML, encoding="cp1252")
            problems = []
            fixes = intro.Fixes(list(misprints), intro.load_citation_fixes(citations))
            return intro.nabre(d, INDEX, problems, fixes), problems

    def test_aRecordedMisprintIsNotedAfterThePrintedTextWithItsCitation(self):
        (i,), problems = self.collect([{"introduction": "3", "printed": "35:43", "intended": "2:4", "cite": "01002004",
                                        "verified": "curator"}])
        b = i.blocks[0]
        self.assertEqual(b["text"], "Gn 25:19\u201335:43 and 5:14.")  # as printed
        (sic,) = b["sic"]
        self.assertEqual(b["text"][sic["span"][0]:sic["span"][1]], "35:43")
        self.assertEqual(sic["intended"], "2:4")
        self.assertEqual(sic["ref"]["start"], {"book": 1, "chapter": 2, "verse": 4, "segment": None, "printed_chapter": None})
        # The misprinted citation is not reported again; the other one is.
        self.assertEqual([p[2] for p in problems], ["47005014 5:14"])

    def test_aBlockWithoutMisprintsHasNoSicKey(self):
        (i,), _ = self.collect()
        self.assertNotIn("sic", i.blocks[0])

    def test_aMisprintWhosePrintedTextIsNotFoundOnceStopsTheImport(self):
        for printed in ("nowhere", "4"):  # absent; "4" not a whole word ("5:14" holds it inside a number)
            with self.assertRaises(SystemExit):
                self.collect([{"introduction": "3", "printed": printed, "intended": "x", "verified": "curator"}])
        with self.assertRaises(SystemExit):  # a misprint in an introduction that does not exist
            self.collect([{"introduction": "99", "printed": "35:43", "intended": "x", "verified": "curator"}])

    def test_aCorrectedCitationLinksToItsVerse(self):
        (i,), problems = self.collect(citations="3\t47005014\t03007038\twrong book number in the link\n"
                                                "3\t01035043\t-\tno such verse\n")
        (r,) = i.blocks[0]["refs"]
        self.assertEqual((i.blocks[0]["text"][r["span"][0]:r["span"][1]], r["start"]["chapter"], r["start"]["verse"]), ("5:14", 7, 38))
        self.assertEqual(problems, [])  # "-": not a link, on purpose

    def test_anUnusedCorrectionStopsTheImport(self):
        with self.assertRaises(SystemExit):
            self.collect(citations="3\t99999999\t03007038\tstale\n")


class SqlTest(unittest.TestCase):
    def test_dataHalfReplacesTheEditionsRows(self):
        rows = [intro.Introduction("9", "THE BOOKS OF SAMUEL", [9, 10], False, [{"type": "paragraph", "text": "It's", "refs": []}])]
        sql = intro.to_sql("NABRE", rows)
        self.assertTrue(sql.startswith("BEGIN;"))
        self.assertIn("DELETE FROM book_introduction WHERE edition = 'NABRE';", sql)
        self.assertIn("('NABRE', '9', 1, 'THE BOOKS OF SAMUEL', '{9,10}'::int[], false, ", sql)
        body = sql.split("false, ", 1)[1].rsplit("::jsonb", 1)[0]
        self.assertEqual(json.loads(body[1:-1].replace("''", "'")), rows[0].blocks)
        self.assertTrue(sql.rstrip().endswith("COMMIT;"))

    def test_noSourceFilesWritesNothing(self):
        with tempfile.TemporaryDirectory() as d, self.assertRaises(SystemExit):
            intro.main(["nabre", "--source", d, "--dump", "/dev/null", "--out", str(Path(d) / "o.sql"), "--report", str(Path(d) / "r.tsv")])

    def test_cei2008ReadsItsPagesAndWritesItsDataHalf(self):
        page = ('<main><h2 class="book-title text-to-speech">Genesi</h2>\n<em>Il libro.</em>\n</div>\n'
                '<div class="container d-none d-md-block"></div></main>')
        with tempfile.TemporaryDirectory() as d:
            (Path(d) / "at").mkdir()
            (Path(d) / "at" / "Gen.html").write_text(page, encoding="utf-8")
            (Path(d) / "cei.copy").write_text("1\t1\t1\t1\t\t\t\t\tx\t\t\t\n", encoding="utf-8")
            out = Path(d) / "o.sql"
            intro.main(["cei2008", "--source", d, "--dump", str(Path(d) / "cei.copy"), "--out", str(out), "--report", str(Path(d) / "r.tsv")])
            sql = out.read_text(encoding="utf-8")
        self.assertIn("DELETE FROM book_introduction WHERE edition = 'CEI2008';", sql)
        self.assertIn("('CEI2008', '1', 1, 'Genesi', '{1}'::int[], false, ", sql)


if __name__ == "__main__":
    unittest.main()
