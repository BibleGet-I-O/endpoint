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
    row(19, 4, 17, "C a.", seg="a", trad="GREEK"),
    row(19, 4, 17, "C b.", seg="b", trad="GREEK"),
    row(19, 4, 17, "C c.", seg="c", trad="GREEK"),
    row(19, 4, 17, "C d.", seg="d", trad="GREEK"),
    row(19, 4, 17, "C e.", seg="e", trad="GREEK"),
    row(19, 4, 17, "C f.", seg="f", trad="GREEK"),
    row(19, 4, 17, "C g.", seg="g", trad="GREEK"),
    row(19, 4, 17, "C h.", seg="h", trad="GREEK"),
    row(19, 4, 17, "C i.", seg="i", trad="GREEK"),
    row(19, 4, 17, "C k.", seg="k", trad="GREEK"),
    row(19, 4, 17, "C l.", seg="l", trad="GREEK"),
    row(19, 4, 17, "C m.", seg="m", trad="GREEK"),
    row(19, 4, 17, "C n.", seg="n", trad="GREEK"),
    row(19, 4, 17, "C o.", seg="o", trad="GREEK"),
    row(19, 4, 17, "C p.", seg="p", trad="GREEK"),
    row(19, 4, 17, "C q.", seg="q", trad="GREEK"),
    row(19, 4, 17, "C r.", seg="r", trad="GREEK"),
    row(19, 4, 17, "C s.", seg="s", trad="GREEK"),
    row(19, 4, 17, "C t.", seg="t", trad="GREEK"),
    row(19, 4, 17, "C u.", seg="u", trad="GREEK"),
    row(19, 4, 17, "C v.", seg="v", trad="GREEK"),
    row(19, 4, 17, "C x.", seg="x", trad="GREEK"),
    row(19, 4, 17, "C y.", seg="y", trad="GREEK"),
    row(19, 4, 17, "C z.", seg="z", trad="GREEK"),
    row(19, 4, 17, "C aa.", seg="aa", trad="GREEK"),
    row(19, 4, 17, "C bb.", seg="bb", trad="GREEK"),
    row(19, 4, 17, "C cc.", seg="cc", trad="GREEK"),
    row(19, 4, 17, "C dd.", seg="dd", trad="GREEK"),
    row(19, 4, 17, "C ee.", seg="ee", trad="GREEK"),
    row(19, 4, 17, "C ff.", seg="ff", trad="GREEK"),
    row(19, 5, 1, "D one.", trad="GREEK"),
    row(19, 5, 1, "D 1a.", seg="a", trad="GREEK"),
    row(19, 5, 1, "D 1b.", seg="b", trad="GREEK"),
    row(19, 5, 1, "D 1c.", seg="c", trad="GREEK"),
    row(19, 5, 1, "D 1d.", seg="d", trad="GREEK"),
    row(19, 5, 1, "D 1e.", seg="e", trad="GREEK"),
    row(19, 5, 1, "D 1f.", seg="f", trad="GREEK"),
    row(19, 5, 1, "D 1g.", seg="g", trad="GREEK"),
    row(19, 5, 1, "D 1h.", seg="h", trad="GREEK"),
    row(19, 5, 1, "D 1i.", seg="i", trad="GREEK"),
    row(19, 5, 1, "D 1k.", seg="k", trad="GREEK"),
    row(19, 5, 2, "D two.", trad="GREEK"),
    row(19, 5, 2, "D 2a.", seg="a", trad="GREEK"),
    row(19, 5, 2, "D 2b.", seg="b", trad="GREEK"),
    row(19, 5, 2, "D 2c.", seg="c", trad="GREEK"),
    row(19, 5, 2, "D 2d.", seg="d", trad="GREEK"),
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

    def test_aMarkerRightAfterARunInHeadingIsTheHeadings(self):
        # Printed after the bold, before the verse: at a chapter's start, and after another verse.
        scml = ('<ch id="26003000">CHAPTER 3</ch>\n<pf><b>Opening.</b><fnref id="26003005-1">*</fnref> <v id="26003005"><bcv>5</bcv>I adjure</v></pf>\n'
                '<p><b>The Procession.</b><fnref id="26003006-1">*</fnref><enref id="26003006-a">a</enref> <v id="26003006"><bcv>6</bcv>Who is this<fnref id="26003006-2">*</fnref></v></p>')
        ev = {e.source_id: (e.place, e.vid, e.prefix, e.heading) for e in nn.scan_book(scml)}
        self.assertEqual(ev["26003005-1"], ("title", "26003005", "Opening", "Opening."))
        self.assertEqual(ev["26003006-1"], ("title", "26003006", "The Procession", "The Procession."))
        self.assertEqual(ev["26003006-a"], ("title", "26003006", "The Procession", "The Procession."))
        self.assertEqual(ev["26003006-2"][:3], ("text", "26003006", "Who is this"))

    def test_aMarkerAfterAHeadingInsideAVerseStaysOnThatVerse(self):
        # The heading interrupts 3:6 (stored there): its title; one we do not store: after the words before it.
        scml = ('<pof id="05003006"><bcv>6</bcv><speaker>D</speaker> Who is this</pof><chsect>{}</chsect><fnref id="x-1">*</fnref>'
                '<poi>coming up from the desert,</poi><poi id="05003007"><bcv>7</bcv>He said</poi>')
        (a,) = self.anchors(scml.format("The Procession"))
        self.assertEqual((a.ident[:3], a.field, a.position), ((5, 3, 6), "title1", len("The Procession")))
        (b,) = self.anchors(scml.format("Not Stored"))
        self.assertEqual((b.ident[:3], b.field, b.reason), ((5, 3, 6), "text", ""))
        self.assertTrue(ROWS[1]["fields"]["text"][:b.position].endswith("Who is this"))

    def test_aMarkerAfterAHeadingInsideTheLastVerseIsKept(self):
        scml = '<po id="05004001"><bcv>1</bcv>Behold,</po><chsect>Not Stored</chsect><fnref id="x-1">*</fnref><poi> you are fair.</poi>'
        (a,) = self.anchors(scml)
        self.assertEqual((a.ident[:3], a.field, a.position, a.reason), ((5, 4, 1), "text", len("Behold,"), ""))

    def test_aMarkerBeforeAVersesNumberWithoutAHeadingIsNotTheOldHeadings(self):
        scml = '<ch id="26003000">CHAPTER 3</ch><p><b>H.</b><v id="26003005">I<fnref id="x">*</fnref></v></p>'
        self.assertEqual([(e.place, e.vid) for e in nn.scan_book(scml)], [("text", "26003005")])

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

    def test_estherAdditionsFollowTheStoredLetters(self):
        # NABRE's letters skip "j" and "w"; addition D runs through 5:1, 5:1a-k, 5:2, 5:2a-d.
        self.assertEqual(nn.resolve_ref("19043022", self.index)[0][:4], (19, 4, 17, "x"))
        self.assertEqual(nn.resolve_ref("19043028", self.index)[0][:4], (19, 4, 17, "dd"))
        self.assertEqual(nn.resolve_ref("19044001", self.index)[0][:4], (19, 5, 1, ""))
        self.assertEqual(nn.resolve_ref("19044011", self.index)[0][:4], (19, 5, 1, "k"))
        self.assertEqual(nn.resolve_ref("19044012", self.index)[0][:4], (19, 5, 2, ""))
        self.assertEqual(nn.resolve_ref("19044016", self.index)[0][:4], (19, 5, 2, "d"))
        self.assertIsNone(nn.resolve_ref("19044017", self.index))

    def test_anEstherAdditionMarkerGoesOnItsGreekRowOnly(self):
        scml = '<p id="19044012"><bcv>12</bcv>D two.<fnref id="19044012-1">*</fnref></p>'
        (a,) = nn.place_all(nn.scan_book(scml), self.index, {})
        self.assertEqual((a.ident[:5], a.reason), ((19, 5, 2, "", "GREEK"), ""))
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


class OwnIdsTest(unittest.TestCase):
    def test_aNoteIdOfAnotherBookIsGivenItsOwn(self):
        # The publisher numbers Galatians' (56) cross-references as Ephesians' (57); verse and xref ids are right.
        scml = ('<v id="56001001">Paul<enref id="57001001-a">a</enref><fnref id="56001001-1">*</fnref></v>'
                '<en id="57001001-a">a. [1:1] <xref id="53001001">Rom 1:1</xref>.</en><fn id="56001001-1">* [1:1] Body.</fn>')
        fixed = nn.own_ids(scml, 56)
        self.assertEqual(sorted(nn.parse_notes(fixed)), ["56001001-1", "56001001-a"])
        self.assertEqual([e.source_id for e in nn.scan_book(fixed)], ["56001001-a", "56001001-1"])
        self.assertIn('<xref id="53001001">', fixed)
        # Daniel's file holds 34 and 35.
        self.assertEqual(nn.own_ids('<fn id="35001001-1">', 34), '<fn id="35001001-1">')

    def test_anUnpairedMarkerOrNoteIsReported(self):
        notes = {"a": 1, "b": 1}
        events = [nn.Event("text", s, "footnote", "*", "01001001", "", "") for s in ("a", "c")]
        self.assertEqual(nn.unpaired(events, notes), [("b", "", "", "note-without-marker", ""), ("c", "footnote", "01001001", "marker-without-note", "")])

    def test_theOneMisnumberedNoteIsPaired(self):
        self.assertIn('<en id="33032012-e">', nn.own_ids('<en id="33032010-e">e. [32:12] x</en>', 33))

    def test_anIdUsedTwiceInOneFileStopsTheImport(self):
        with self.assertRaises(SystemExit):
            nn.parse_notes('<fn id="x">* [1:1] A.</fn><fn id="x">* [1:2] B.</fn>')
        # A continuation is not a second note.
        self.assertEqual(nn.parse_notes('<fn id="x">* [1:1] A.</fn><fnp>More.</fnp>')["x"].body, "A.\nMore.")

    def test_anIdUsedTwiceStopsTheImport(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            for n in ("01-gn", "02-ex"):
                (Path(d) / f"nab-{n}.scml").write_text('<fn id="x">* [1:1] B.</fn>', encoding="utf-8")
            dump = Path(d) / "nabre.copy"
            dump.write_text("1\t1\t1\t1\t\t\t\t\tT\t\t\t\n", encoding="utf-8")
            with self.assertRaises(SystemExit):
                nn.main(["--scml", d, "--dump", str(dump), "--out", str(Path(d) / "o"), "--report", str(Path(d) / "r")])


class SourceTest(unittest.TestCase):
    def test_windows1252BytesAreReadAsSuch(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "nab-01-gn.scml"
            p.write_bytes("caf\u00e9 ".encode("utf-8") + b"when\x85then")
            self.assertEqual(nn.read_scml(p), "caf\u00e9 when\u2026then")

    def test_aCitationFileCorrectsDropsAndReportsWhatItDidNotUse(self):
        import tempfile
        n = nn.Note("crossref", "a", "1:1", "A; B; C", [("36001010", 0, 1), ("99001001", 3, 4), ("05003005", 6, 7)], [])
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "c.tsv"
            p.write_text("# note\tprinted\tcorrected\treason\nx\t36001010\t36002001\told numbering\n"
                         "x\t99001001\t-\tno such verse\nx\t11111111\t22222222\tunused\n", encoding="utf-8")
            notes = {"x": n}
            problems = nn.correct_citations(notes, p)
        self.assertEqual(notes["x"].refs, [("36002001", 0, 1), ("05003005", 6, 7)])
        self.assertEqual(problems, [("x", "", "11111111", "unused-correction", "")])


class MainTest(unittest.TestCase):
    def test_noScmlWritesNothing(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            dump = Path(d) / "nabre.copy"
            dump.write_text("1\t1\t1\t1\t\t\t\t\tT\t\t\t\n", encoding="utf-8")
            out = Path(d) / "out.sql"
            with self.assertRaises(SystemExit):
                nn.main(["--scml", str(Path(d) / "missing"), "--dump", str(dump), "--out", str(out), "--report", str(Path(d) / "r.tsv")])
            self.assertFalse(out.exists())


if __name__ == "__main__":
    unittest.main()
