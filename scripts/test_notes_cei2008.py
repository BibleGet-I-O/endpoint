"""Tests for notes_cei2008.py, on synthetic pages and rows only (no CEI 2008 text).

Run: python3 -m unittest scripts/test_notes_cei2008.py
"""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import notes_cei2008 as nc  # noqa: E402


def note(nid, label, body):
    return (f'<span data-notes-id="{nid}" class="footer_notes" id="footer_notes_{nid}">\n'
            f'<div class="usfm_note mb-1 mb-nope note-id-{nid} is-cross-0">\n'
            f'<span class="note-verse-identificative">{label}</span>\n        {body}\n</div>\n</span>\n')


def link(book, sel, text, part="at"):
    return (f'<a title="vai a {text}" href="/CEI2008/{part}/{book}/{sel.split(",")[0]}/?sel={sel}" target="_self" '
            f'data-target="x1">{text}</a><span class="verses target hide-if-no-js" id="x1" ></span>')


ROWS = [(1, 1, v, "", "") for v in range(1, 32)] + [(1, 2, v, "", "") for v in range(1, 26)] + [
    (23, 8, v, "", "") for v in range(1, 11)] + [
    (19, 1, 1, "", "HEBREW"), (19, 1, 1, "", "GREEK"), (19, 1, 1, "a", "GREEK"), (19, 1, 2, "", "HEBREW"),
    (22, 40, 4, "", ""), (38, 1, 8, "", ""), (38, 1, 9, "", ""), (64, 1, 24, "", "")]
INDEX = nc.index_rows(ROWS)
# The Nova Vulgata's Sirach 24 runs to verse 47; CEI 2008's to 34.
NV = nc.index_rows([(28, 24, v, "", "") for v in range(1, 48)] + [(28, 24, 1, "b", "")])
CEI_SIR = nc.index_rows([(28, 24, v, "", "") for v in range(1, 35)])


class ParseTest(unittest.TestCase):
    def test_noteLabelBodyAndId(self):
        [n] = nc.parse_page(note(31031, "1,2", "<i>spirito</i>: lett. <em>soffio</em> &egrave; <strong>vento</strong>.<br>Altro."), 1)
        self.assertEqual(n.source_id, "31031")
        self.assertEqual(n.label, "1,2")
        self.assertIsNone(n.title)
        self.assertEqual(n.body, "<i>spirito</i>: lett. <i>soffio</i> è <b>vento</b>.\nAltro.")
        self.assertEqual(n.dropped, [])

    def test_titleIsTheLeadingBold(self):
        [n] = nc.parse_page(note(41218, "1,1-31", "<b>GENEALOGIE</b> (" + link("Gen", "1,1-31", "Gen 1,1-31") + ")<br>Testo."), 1)
        self.assertEqual(n.title, "GENEALOGIE")
        self.assertEqual(n.body, "(Gen 1,1-31)\nTesto.")
        self.assertEqual(n.refs, [("Gen", "1,1-31", 1, 11, None)])

    def test_citationsKeepTheirSpanInCodePointsWithTags(self):
        body = "Cfr. <i>" + link("Sal", "8", "Sal 8") + "</i>; " + link("Pr", "8,22-31", "Pr 8,22-31") + "."
        [n] = nc.parse_page(note(1, "1,1", body), 1)
        self.assertEqual(n.body, "Cfr. <i>Sal 8</i>; Pr 8,22-31.")
        cps = list(n.body)
        self.assertEqual([("".join(cps[s:e])) for _, _, s, e, _ in n.refs], ["Sal 8", "Pr 8,22-31"])

    def test_otherTagsAreDroppedAndReported(self):
        [n] = nc.parse_page(note(2, "1,1", "<p>Uno<sup>1</sup></p><p>Due</p>"), 1)
        self.assertEqual(n.body, "Uno1\nDue")
        self.assertEqual(n.dropped, ["sup"])

    def test_commentsAreDroppedWithTheLinksInThem(self):
        hidden = '<!--UIBHG Tipo="BG"--><!-- ' + link("Is", "8,11-23", "8,11-23") + ' --><!--/UIBHG-->'
        [n] = nc.parse_page(note(53, "8,11", f"<b>{hidden}La testimonianza</b>"), 29)
        self.assertEqual(n.title, "La testimonianza")
        self.assertEqual((n.body, n.refs), ("", []))
        [m] = nc.parse_page(note(54, "8,11", f"Testo {hidden}fine."), 29)
        self.assertEqual((m.body, m.refs), ("Testo fine.", []))

    def test_aCitationEndingTheBodyStaysInsideIt(self):
        [n] = nc.parse_page(note(9, "1,1", "Cfr. " + link("Gen", "1,1", "Gen 1,1 ")), 1)
        self.assertEqual(n.body, "Cfr. Gen 1,1")
        self.assertEqual(n.refs, [("Gen", "1,1", 5, 12, None)])

    def test_aNoteOnTwoPagesIsKeptOnce(self):
        notes = nc.collect([(1, note(5, "1,1-2,4a", "A.")), (1, note(5, "1,1-2,4a", "A.")), (1, note(6, "2,5", "B."))])
        self.assertEqual(list(notes), ["5", "6"])


class ResolveTest(unittest.TestCase):
    def test_aRangeAnchorsToItsFirstVerseWithItsLast(self):
        self.assertEqual(nc.anchor_for(1, "1,1-2,4a", INDEX), ((1, 1, 1, "", "", ""), (1, 2, 4)))
        self.assertEqual(nc.anchor_for(1, "1,1-31", INDEX), ((1, 1, 1, "", "", ""), (1, 1, 31)))
        self.assertEqual(nc.anchor_for(1, "1,2", INDEX), ((1, 1, 2, "", "", ""), None))

    def test_aLetterTheTableDoesNotStoreFallsBackToTheVerse(self):
        self.assertEqual(nc.anchor_for(1, "1,4b", INDEX), ((1, 1, 4, "", "", ""), None))

    def test_estherAnchorsToTheGreekText(self):
        self.assertEqual(nc.anchor_for(19, "1,1", INDEX)[0], (19, 1, 1, "", "GREEK", ""))
        self.assertEqual(nc.anchor_for(19, "1,1a", INDEX)[0], (19, 1, 1, "a", "GREEK", ""))
        # A verse only the Hebrew text has.
        self.assertEqual(nc.anchor_for(19, "1,2", INDEX)[0], (19, 1, 2, "", "HEBREW", ""))

    def test_aRangeEndTheTableLacksIsNotRecorded(self):
        self.assertEqual(nc.anchor_for(1, "1,1-50,1", INDEX), ((1, 1, 1, "", "", ""), None))
        self.assertEqual(nc.resolve_ref("Gen", "1,1-50,1", INDEX), ((1, 1, 1, "", ""), None))

    def test_unusualLabels(self):
        self.assertEqual(nc.anchor_for(1, "1,2.5", INDEX)[0], (1, 1, 2, "", "", ""))
        self.assertEqual(nc.anchor_for(23, "8,2 (8,1)", INDEX)[0], (23, 8, 2, "", "", ""))
        self.assertIsNone(nc.anchor_for(1, "9,9", INDEX))

    def test_aOneChapterBookIsCitedByVerse(self):
        # Obadiah, Philemon, 2 and 3 John, Jude: "Abd 8-9", "Fm 24" are verses of chapter 1.
        self.assertEqual(nc.resolve_ref("Abd", "8-9", INDEX), ((38, 1, 8, "", ""), (38, 1, 9)))
        self.assertEqual(nc.resolve_ref("Fm", "24", INDEX), ((64, 1, 24, "", ""), None))
        self.assertEqual(nc.resolve_ref("Fm", "1,24", INDEX), ((64, 1, 24, "", ""), None))

    def test_citations(self):
        self.assertEqual(nc.resolve_ref("Sal", "8", INDEX), ((23, 8, None, "", ""), None))
        self.assertEqual(nc.resolve_ref("Gen", "1,22-2,3", INDEX), ((1, 1, 22, "", ""), (1, 2, 3)))
        self.assertEqual(nc.resolve_ref("Gen", "1,3.5", INDEX), ((1, 1, 3, "", ""), None))
        self.assertEqual(nc.resolve_ref("Gb", "40,4b", INDEX), ((22, 40, 4, "", ""), None))
        self.assertIsNone(nc.resolve_ref("Gen", "50,1", INDEX))
        self.assertIsNone(nc.resolve_ref("Xyz", "1,1", INDEX))


class NovaVulgataTest(unittest.TestCase):
    def test_aCitationAfterNVgIsANovaVulgataVerse(self):
        body = "24,1a NVg (" + link("Sir", "24,1", "24,1b") + ") aggiunge: x. 24,18 NVg (" + link("Sir", "24,40", "24,40") + ")"
        [n] = nc.parse_page(note(52098, "24,1", body), 28)
        self.assertEqual([r[4] for r in n.refs], ["NVBSE", "NVBSE"])
        rows = nc.ref_rows(n, CEI_SIR, [], NV)
        self.assertEqual([(r[2], r[6]) for r in rows], [((28, 24, 1, "b", ""), "NVBSE"), ((28, 24, 40, "", ""), "NVBSE")])

    def test_theNovaVulgataVerseIsReadFromThePrintedNumber(self):
        # The site's link says sel=24,1 for "24,1b": the printed number is the Nova Vulgata's.
        [n] = nc.parse_page(note(1, "24,1", "NVg (" + link("Sir", "24,1", "24,1b") + ")"), 28)
        self.assertEqual(n.refs[0][:2], ("Sir", "24,1b"))

    def test_anOrdinaryCitationStaysInTheNotesEdition(self):
        [n] = nc.parse_page(note(2, "24,1", "Cfr. " + link("Sir", "24,3", "Sir 24,3")), 28)
        self.assertIsNone(n.refs[0][4])

    def test_aNovaVulgataVerseItLacksIsReported(self):
        [n] = nc.parse_page(note(3, "24,1", "NVg (" + link("Sir", "24,60", "24,60") + ")"), 28)
        problems = []
        self.assertEqual(nc.ref_rows(n, CEI_SIR, problems, NV), [])
        self.assertEqual(problems[0][2], "unresolved-citation")

    def test_theDataHalfNamesTheEdition(self):
        notes = nc.collect([(28, note(4, "24,1", "NVg (" + link("Sir", "24,40", "24,40") + ")"))])
        sql = nc.to_sql(notes, CEI_SIR, [], NV)
        self.assertIn("span_start, span_end, edition)", sql)
        self.assertIn("'NVBSE')", sql)


class OutputTest(unittest.TestCase):
    def test_dataHalfDeletesThenInsertsWithTitlesAndLastVerses(self):
        notes = nc.collect([(1, note(5, "1,1-2,4a", "<b>INNO</b> " + link("Sal", "8", "Sal 8") + "; " + link("Gen", "50,1", "Gen 50,1")))])
        problems = []
        sql = nc.to_sql(notes, INDEX, problems)
        self.assertTrue(sql.startswith("BEGIN;"))
        self.assertIn("DELETE FROM note WHERE edition = 'CEI2008';", sql)
        self.assertIn("('CEI2008', 'footnote', NULL::text, '1,1-2,4a', 'INNO', 'Sal 8; Gen 50,1', '5')", sql)
        self.assertIn("last_book, last_chapter, last_verse", sql)
        self.assertIn("('5', 1, 1, 1, NULL::text, NULL::text, NULL::int, 'text', NULL::int, 1, 2, 4)", sql)
        self.assertIn("('5', 1, 23, 8, NULL::int, NULL::text, NULL::int, NULL::int, NULL::int, NULL::int, 0, 5)", sql)
        self.assertEqual(problems, [("5", "Gen 50,1", "unresolved-citation", "CEI2008 Gen 50,1")])
        self.assertTrue(sql.endswith("COMMIT;\n"))

    def test_aTitleOnlyNoteHasAnEmptyBody(self):
        notes = nc.collect([(1, note(8, "1,1", "<b>Solo titolo</b>"))])
        self.assertIn("('CEI2008', 'footnote', NULL::text, '1,1', 'Solo titolo', '', '8')", nc.to_sql(notes, INDEX, []))

    def test_anUnanchoredNoteIsReportedNotStored(self):
        notes = nc.collect([(1, note(7, "9,9", "X."))])
        problems = []
        sql = nc.to_sql(notes, INDEX, problems)
        self.assertNotIn("'7')", sql)
        self.assertEqual(problems, [("7", "9,9", "no-verse", "")])


class CorrectionsTest(unittest.TestCase):
    def test_aCitationFileCorrectsDropsAndReportsWhatItDidNotUse(self):
        import tempfile
        notes = nc.collect([(1, note(5, "1,1", link("Pr", "1,2", "1,2") + " e " + link("Gen", "6,5", "6,5") + " kg"))])
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "c.tsv"
            p.write_text("5\tPr 1,2\tGen 1,2\town book\n5\tGen 6,5\t-\tmeasure\n9\tGen 1,1\t-\tgone\n", encoding="utf-8")
            problems = nc.correct_citations(notes, p)
        self.assertEqual(notes["5"].refs, [("Gen", "1,2", 0, 3, None)])
        self.assertEqual(problems, [("9", "Gen 1,1", "unused-correction", "")])


class MainTest(unittest.TestCase):
    def test_anArchiveWithNoNotesWritesNothing(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            dump = Path(d) / "cei.copy"
            dump.write_text("1\t1\t1\t1\t\t\t\t\tT\t\t\t\n", encoding="utf-8")
            out = Path(d) / "out.sql"
            with self.assertRaises(SystemExit):
                nc.main(["--html", str(Path(d) / "missing"), "--dump", str(dump), "--out", str(out), "--report", str(Path(d) / "r.tsv")])
            self.assertFalse(out.exists())


if __name__ == "__main__":
    unittest.main()
