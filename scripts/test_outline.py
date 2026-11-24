"""Tests for outline.py, on synthetic titles only. Run: python3 -m unittest scripts/test_outline.py"""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import outline as o  # noqa: E402


def row(b, c, v, *titles, seg="", trad="", pc=""):
    return o.Row(b, c, v, seg, trad, pc, [t for t in titles if t])


class OutlineTest(unittest.TestCase):
    def test_nabreLevelsComeFromTheTags(self):
        scml = ('<bksect>I. PART<fnref id="x">*</fnref></bksect><ch id="01001000">CHAPTER 1</ch><bksubsect>A. Sub</bksubsect>'
                '<p><b>Run in.</b></p><chsect>Section</chsect><chsubsect>Lower</chsubsect><cs>Psalm title</cs>')
        self.assertEqual(o.nabre_levels(scml), {"I. PART": [1], "A. Sub": [2], "Run in": [3], "Section": [3], "Lower": [4], "Psalm title": [3]})

    def test_storedTitlesTakeTheirLevelInOrder(self):
        rows = [row(1, 1, 1, "PART", "Same"), row(1, 2, 1, "Same"), row(1, 3, 1, "Unknown")]
        problems = []
        got = o.printed(rows, {1: {"PART": [1], "Same": [3, 2]}}, 3, problems)
        self.assertEqual([(e.level, e.title) for e in got[1]], [(1, "PART"), (3, "Same"), (2, "Same"), (3, "Unknown")])
        self.assertEqual(problems, [(1, "Unknown", "no-level")])

    def test_numeralsAndLettersAreNotTitles(self):
        rows = [row(23, 2, 1, "A Psalm", "I"), row(23, 2, 7, "II"), row(23, 119, 1, "Aleph"), row(31, 1, 1, "Alef", "A"), row(1, 1, 1, "I. PART")]
        got = o.printed(rows, {}, 3, [])
        self.assertEqual([e.title for b in sorted(got) for e in got[b]], ["I. PART", "A Psalm"])

    def test_estherIsReadFromItsGreekTextOnce(self):
        rows = [row(19, 1, 1, "Feast", trad="HEBREW"), row(19, 1, 1, "Dream", seg="a", trad="GREEK"), row(19, 1, 1, "Feast", trad="GREEK")]
        self.assertEqual([(e.title, e.ident[3:5]) for e in o.printed(rows, {}, 3, [])[19]], [("Dream", ("a", "GREEK")), ("Feast", ("", "GREEK"))])

    def test_ceiPrintedLevels(self):
        page = '<div class="usfm usfm_titlesheadingsandlabel s">LE ORIGINI</div><div class="usfm usfm_titlesheadingsandlabel s s2"> La <i>creazione</i> </div>'
        self.assertEqual(o.cei_levels(page), {"LE ORIGINI": [1], "La creazione": [2]})

    def test_ceiCommentaryTitlesAreReaderOnlyInBooksWithoutPrintedOnes(self):
        anchored = [((9, 1, 1, "", "", ""), "SAMUELE"), ((9, 4, 1, "", "", ""), "a L’arca"), ((9, 2, 1, "", "", ""), "Cantico"), ((9, 1, 1, "", "", ""), "Nascita"), ((1, 1, 1, "", "", ""), "Nota")]
        printed = {1: [o.Entry(1, f"T{i}", (1, i, 1, "", "", ""), False) for i in range(12)]}
        got = o.with_commentary(printed, anchored)
        self.assertEqual([(e.level, e.title, e.reader_only) for e in got[9]],
                         [(1, "SAMUELE", True), (2, "Nascita", True), (2, "Cantico", True), (2, "L’arca", True)])
        self.assertEqual(len(got[1]), 12)

    def test_aPrologueIsTopLevelAndCeiPsalmTitlesSitUnderTheirNumber(self):
        self.assertEqual(o.printed([row(28, 0, 1, "Foreword")], {28: {}}, 3, [])[28][0].level, 1)
        page = "".join(f'<div class="usfm usfm_titlesheadingsandlabel s">{t}</div>' for t in ("SALMO 2 (2)", "IL SIGNORE"))
        levels = {23: o.cei_levels(page)}
        got = o.psalm_levels(o.printed([row(23, 2, 1, "SALMO 2 (2)", "IL SIGNORE")], levels, 2, []))
        self.assertEqual([(e.level, e.title) for e in got[23]], [(1, "SALMO 2 (2)"), (2, "IL SIGNORE")])

    def test_dataHalf(self):
        sql = o.to_sql("CEI2008", {9: [o.Entry(1, "L'arca", (9, 4, 1, "b", "", ""), True)]})
        self.assertIn("DELETE FROM book_outline WHERE edition = 'CEI2008';", sql)
        self.assertIn("('CEI2008', 9, 1, 1, 'L''arca', 4, 1, 'b', NULL::text, NULL::int, true)", sql)

    def test_aSourceWithNoFilesStopsBeforeAnythingIsWritten(self):
        for read in (o.nabre, o.cei2008):
            with self.assertRaises(SystemExit):
                read("/nonexistent", [row(1, 1, 1, "Title")], [])

    def test_nothingIsWrittenWithoutTitles(self):
        with self.assertRaises(SystemExit):
            o.write("NABRE", {}, [], "/nonexistent/out", "/nonexistent/report")


if __name__ == "__main__":
    unittest.main()
