"""Tests for notes_nabre.py, on synthetic SCML and rows only (no NABRE text).

Run: python3 -m unittest scripts/test_notes_nabre.py
"""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import notes_nabre as nn  # noqa: E402


def row(book, chapter, verse, text, seg="", trad="", pc="", label="", titles=("", "", "")):
    return {"ident": (book, chapter, verse, seg, trad, pc), "key": (book, chapter, verse, seg),
            "label": label, "fields": {"text": text, "title1": titles[0], "title2": titles[1], "title3": titles[2]}}


ROWS = [
    row(5, 3, 5, "<po>I adjure you, daughters,</po>\n<poi>by the does.</poi>"),
    row(5, 3, 6, "<pof><speaker>D</speaker> Who is this coming up from the desert,</pof>\n<poi>like smoke</poi>",
        titles=("The Procession", "", "")),
    row(5, 3, 7, "He said: “It is Solomon’s litter—see!”"),
    row(5, 4, 1, "Behold, you are fair."),
    row(19, 1, 1, "Greek one.", seg="a", trad="GREEK"),
    row(19, 2, 1, "Canonical verse.", trad="GREEK"),
    row(19, 2, 1, "Canonical verse.", trad="HEBREW"),
    row(23, 1, 1, "Blessed the one."),
    row(35, 1, 1, "The word of the Lord."),
]


def notes_of(scml):
    return nn.parse_notes(scml)


class PlacementTest(unittest.TestCase):
    def setUp(self):
        self.index = nn.index_rows(ROWS)

    def anchors(self, scml, overrides=None):
        return nn.place_all(nn.scan_book(scml, 5), self.index, overrides or {})

    def test_placesAMarkerAfterItsWord(self):
        scml = '<pof id="05003006"><bcv>6</bcv><speaker>D</speaker> Who is this coming up from the desert,<fnref id="05003006-1">*</fnref></pof>'
        (a,) = self.anchors(scml)
        text = ROWS[1]["fields"]["text"]
        self.assertEqual(a.field, "text")
        self.assertEqual(text[:a.position], "<pof><speaker>D</speaker> Who is this coming up from the desert,")
        self.assertEqual(a.reason, "")

    def test_markerAfterSpeakerSitsAfterTheTag(self):
        scml = '<pof id="05003006"><bcv>6</bcv><speaker>D</speaker><enref id="05003006-d">d</enref> Who is this</pof>'
        (a,) = self.anchors(scml)
        self.assertEqual(ROWS[1]["fields"]["text"][:a.position], "<pof><speaker>D</speaker>")
        self.assertEqual((a.kind, a.marker), ("crossref", "d"))

    def test_markerOnARunInHeadingAnchorsToItsTitle(self):
        scml = ('<po id="05003005"><bcv>5</bcv>I adjure you, daughters,</po><poi>by the does.</poi>'
                '<chsect>The Procession<fnref id="05003006-2">*</fnref></chsect>'
                '<pof id="05003006"><bcv>6</bcv>Who is this</pof>')
        (a,) = self.anchors(scml)
        self.assertEqual(a.ident[:3], (5, 3, 6))
        self.assertEqual(a.field, "title1")
        self.assertEqual(a.position, len("The Procession"))

    def test_quotesAndDashesMatchAcrossConversions(self):
        scml = '<p id="05003007"><bcv>7</bcv>He said: &#8220;It is Solomon&#8217;s litter&#8212;<fnref id="05003007-1">*</fnref>see!&#8221;</p>'
        (a,) = self.anchors(scml)
        self.assertEqual(ROWS[2]["fields"]["text"][:a.position], "He said: “It is Solomon’s litter—")

    def test_unmatchedMarkerIsWholeVerseAndReported(self):
        scml = '<p id="05004001"><bcv>1</bcv>Something else entirely<fnref id="05004001-1">*</fnref></p>'
        (a,) = self.anchors(scml)
        self.assertIsNone(a.position)
        self.assertEqual(a.reason, "no-match")

    def test_overrideSetsThePosition(self):
        scml = '<p id="05004001"><bcv>1</bcv>Something else entirely<fnref id="05004001-1">*</fnref></p>'
        (a,) = self.anchors(scml, {"05004001-1": ("text", 7)})
        self.assertEqual((a.field, a.position, a.reason), ("text", 7, "override"))

    def test_chapterHeadingNoteAnchorsToTheChapter(self):
        scml = '<ch id="05004000">CHAPTER 4<fnref id="05004000-1">*</fnref></ch><p id="05004001"><bcv>1</bcv>Behold</p>'
        (a,) = self.anchors(scml)
        self.assertEqual((a.ident[:3], a.field, a.position), ((5, 4, 1), "chapter", None))

    def test_oneFootnoteSeveralAnchors(self):
        scml = ('<p id="05004001"><bcv>1</bcv>Behold,<fnref id="05004001-1">*</fnref> you</p>'
                '<p id="05003007"><bcv>7</bcv>He said:<fnref id="05004001-1">*</fnref></p>')
        anchors = self.anchors(scml)
        self.assertEqual([a.source_id for a in anchors], ["05004001-1", "05004001-1"])
        self.assertEqual([a.ident[:3] for a in anchors], [(5, 4, 1), (5, 3, 7)])

    def test_bothEstherTextsGetTheMarker(self):
        scml = '<p id="19002001"><bcv>1</bcv>Canonical<fnref id="19002001-1">*</fnref> verse.</p>'
        anchors = nn.place_all(nn.scan_book(scml, 19), self.index, {})
        self.assertEqual(sorted(a.ident[4] for a in anchors), ["GREEK", "HEBREW"])

    def test_introductionMarkersAreReportedNotStored(self):
        scml = '<p>An introduction<fnref id="05000000-1">*</fnref></p><p id="05004001"><bcv>1</bcv>Behold</p>'
        self.assertEqual(self.anchors(scml), [])


class NotesTest(unittest.TestCase):
    def test_footnoteLabelBodyAndRefs(self):
        scml = ('<fn id="05003006-1">* [<xref id="05003006">3:6</xref>] <ver>Who</ver>: cf. '
                '<xref id="05006010">6:10</xref>; <xref id="36001001">Mt 1:1</xref>.</fn>'
                '<fnp>Second <i>paragraph</i>.</fnp>')
        n = notes_of(scml)["05003006-1"]
        self.assertEqual((n.kind, n.marker, n.label), ("footnote", "*", "3:6"))
        self.assertEqual(n.body, "<i>Who</i>: cf. 6:10; Mt 1:1.\nSecond <i>paragraph</i>.")
        self.assertEqual([(t, n.body[s:e]) for t, s, e in n.refs], [("05006010", "6:10"), ("36001001", "Mt 1:1")])

    def test_crossrefMarkerAndLabel(self):
        scml = '<en id="05003006-d">d. [<xref id="05003006">3:6</xref>] <xref id="05008005">Sg 8:5</xref>.</en>'
        n = notes_of(scml)["05003006-d"]
        self.assertEqual((n.kind, n.marker, n.label, n.body), ("crossref", "d", "3:6", "Sg 8:5."))

    def test_bodyKeepsOnlyAllowedTags(self):
        scml = '<fn id="05003006-1">* [3:6] <smfn>Lord</smfn> and <weird>x</weird> and <sup>2</sup>.</fn>'
        n = notes_of(scml)["05003006-1"]
        self.assertEqual(n.body, "<sm>Lord</sm> and x and <sup>2</sup>.")
        self.assertEqual(n.dropped, ["weird"])


class RefsTest(unittest.TestCase):
    def setUp(self):
        self.index = nn.index_rows(ROWS)

    def test_xrefResolvesToTheStoredVerse(self):
        self.assertEqual(nn.resolve_ref("05003006", self.index), ((5, 3, 6, "", ""), None))
        self.assertEqual(nn.resolve_ref("23001001", self.index), ((23, 1, 1, "", ""), None))
        self.assertEqual(nn.resolve_ref("36001001", self.index), ((35, 1, 1, "", ""), None))  # NAB skips 35
        self.assertEqual(nn.resolve_ref("19041001", self.index), ((19, 1, 1, "a", ""), None))  # Esther addition A 1
        self.assertEqual(nn.resolve_ref("05004000", self.index), ((5, 4, None, "", ""), None))  # a chapter
        self.assertEqual(nn.resolve_ref("v05003005-v05003007", self.index), ((5, 3, 5, "", ""), (5, 3, 7)))
        self.assertIsNone(nn.resolve_ref("05009009", self.index))


class SqlTest(unittest.TestCase):
    def test_dataHalfDeletesThenInserts(self):
        notes = notes_of('<fn id="05003006-1">* [3:6] It&#8217;s.</fn>')
        anchors = [nn.Anchor("05003006-1", "footnote", "*", (5, 3, 6, "", "", ""), "text", 3, "")]
        sql = nn.to_sql(notes, anchors, nn.index_rows(ROWS))
        self.assertTrue(sql.lstrip().startswith("BEGIN;"))
        self.assertIn("DELETE FROM note WHERE edition = 'NABRE';", sql)
        self.assertLess(sql.index("DELETE FROM note"), sql.index("INSERT INTO note "))
        self.assertIn("'It’s.'", sql)
        self.assertIn("INSERT INTO note_anchor", sql)
        self.assertTrue(sql.rstrip().endswith("COMMIT;"))


if __name__ == "__main__":
    unittest.main()
