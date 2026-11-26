"""Tests for introductions_cei2008.py, on synthetic pages and rows only (no CEI 2008 text).

Run: python3 -m unittest scripts/test_introductions_cei2008.py
"""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import introductions_cei2008 as ic  # noqa: E402
import notes_cei2008 as nc  # noqa: E402

# Genesis (1) 1-3, 1 Kings (11) 17, 2 Kings (12) 2, 1 Chronicles (13) 1, 2 Chronicles (14) 10 and 36, Esther (19) 10 with
# Greek 3a-3l, Zechariah (45) 7, Jude (72) 1, Acts (51) 13 and 21.
ROWS = ([(1, c, v, "", "") for c in (1, 2, 3) for v in range(1, 26)] + [(11, 17, v, "", "") for v in range(1, 25)]
        + [(12, 2, v, "", "") for v in range(1, 26)] + [(13, 1, 1, "", "")] + [(14, 10, 1, "", ""), (14, 36, 23, "", "")]
        + [(19, 6, 1, "", "")] + [(19, 10, 3, s, "GREEK") for s in "abcdefghijkl"] + [(19, 10, 3, "", "HEBREW")]
        + [(45, 7, v, "", "") for v in range(1, 15)] + [(72, 1, v, "", "") for v in range(1, 26)]
        + [(51, 13, 4, "", ""), (51, 21, 17, "", "")])
INDEX = nc.index_rows(ROWS)


def link(slug, sel, text, part="at"):
    return (f'<a title="vai a {slug} {sel}" href="/CEI2008/{part}/{slug}/{sel.split(",")[0]}/?sel={sel}" target="_self" '
            f'data-target="x1" data-tech-info-lt="NO-LIB-1">{text}</a><span class="verses target hide-if-no-js" id="x1" ></span>')


def page(body, title="Genesi"):
    return (f'<main><div class="verses-container" data-info="modifica05">\n<h2 class="book-title text-to-speech">{title}</h2>\n'
            f'{body}\n</div>\n<div class="container d-none d-md-block">next</div></main>')


def texts(blocks):
    return [(b["type"], b["text"]) for b in blocks]


class BodyTest(unittest.TestCase):
    def test_theBodyRunsFromTheTitleToTheNavigation(self):
        self.assertEqual(ic.body_of(page("<em>Testo.</em>")).strip(), "<em>Testo.</em>")
        self.assertEqual(ic.title_of(page("x", "Primo libro dei Re")), "Primo libro dei Re")

    def test_italicsAreInvertedTitlesOutsideEmAreItalic(self):
        self.assertEqual(ic.normalize("<em>Il libro della </em>Genesi<em> narra.</em>"), "Il libro della <i>Genesi</i> narra.")

    def test_spacesPunctuationLinksAndBoldOutsideEmStayRoman(self):
        raw = f"<em>Uno</em> (<em>due</em>) {link('Gen', '1,1', '1,1')} <b>Le caratteristiche</b>"
        out = ic.normalize(raw)
        self.assertNotIn("<i>", out)

    def test_anIWrapperIsReadAsEm(self):
        # Giudici and Tobia wrap the body in <i> (never closed) where the others use <em>.
        self.assertEqual(ic.normalize("<i><b>I contenuti<br></b>Il libro dei <b>Giudici</b> narra."),
                         "<b>I contenuti</b><br />Il libro dei <b>Giudici</b> narra.")

    def test_aBreakOpeningBoldMovesBeforeIt(self):
        self.assertEqual(ic.normalize("<em>Fine.<br /><b><br />\nLe caratteristiche<br /></b>Testo</em>"),
                         "Fine.<br /><br />\n<b>Le caratteristiche</b><br />Testo")

    def test_aBreakInsideBoldMovesAfterIt(self):
        self.assertEqual(ic.normalize("<em><b>I contenuti<br /></b>Testo</em>"), "<b>I contenuti</b><br />Testo")
        self.assertEqual(ic.normalize("<em><b>I contenuti</b></em><b><br /></b><em><b> </b>Testo</em>"), "<b>I contenuti</b><br />Testo")


class BlocksTest(unittest.TestCase):
    def blocks(self, body, book=1):
        problems = []
        return ic.blocks(ic.normalize(body), book, INDEX, "Gen", problems), problems

    def test_aBoldLineIsAHeadingAndADoubleBreakEndsAParagraph(self):
        out, _ = self.blocks("<em><b>I contenuti<br /></b>Primo.<br /> <br /><b>L'origine<br /></b>Secondo.</em>")
        self.assertEqual(texts(out), [("heading", "I contenuti"), ("paragraph", "Primo."), ("heading", "L'origine"),
                                      ("paragraph", "Secondo.")])
        self.assertEqual(out[0]["level"], 1)

    def test_linesAfterAColonAreTheOutlinesItems(self):
        out, _ = self.blocks("<em>Uno schema del libro: <br /> Creazione<br /> Diluvio</em>")
        self.assertEqual(texts(out), [("paragraph", "Uno schema del libro:"), ("item", "Creazione"), ("item", "Diluvio")])
        self.assertEqual(out[1]["level"], 1)

    def test_otherSingleBreaksAreParagraphs(self):
        out, _ = self.blocks("<em>Uno.<br />Due.</em>")
        self.assertEqual(texts(out), [("paragraph", "Uno."), ("paragraph", "Due.")])

    def test_aCitationIsASpanWithItsVerses(self):
        out, _ = self.blocks(f"<em>Le origini ({link('Gen', '1,1-2,4a', '1,1-2,4a')}).</em>")
        text, ref = out[0]["text"], out[0]["refs"][0]
        self.assertEqual(text[slice(*ref["span"])], "1,1-2,4a")
        self.assertEqual(ref["start"], {"book": 1, "chapter": 1, "verse": 1, "segment": None, "printed_chapter": None})
        self.assertEqual(ref["end"], {"book": 1, "chapter": 2, "verse": 4})

    def test_spansCountTagsAsTheReaderDoes(self):
        out, _ = self.blocks(f"<em>Il </em>libro<em> ({link('Gen', '3,1', '3,1')})</em>")
        b = out[0]
        self.assertEqual(b["text"][slice(*b["refs"][0]["span"])], "3,1")


class CitationTest(unittest.TestCase):
    def refs(self, body, book=1):
        problems = []
        out = ic.blocks(ic.normalize(f"<em>{body}</em>"), book, INDEX, "Gen", problems)
        return [(b["text"][slice(*r["span"])], r["start"]["book"], r["start"]["chapter"], r["start"]["verse"],
                 r["end"] and (r["end"]["book"], r["end"]["chapter"], r["end"]["verse"])) for b in out for r in b["refs"]], problems

    def test_aWordTheSiteTookForABookIsNotTheBook(self):
        # The site links "Giuda (2C" to Jude and "10,1-36,23" to Jude 10: the text cites 2 Chronicles.
        body = f"I re di {link('Gd', '2C', 'Giuda (2C', 'nt')}r {link('Gd', '10,1-36,23', '10,1-36,23', 'nt')})."
        refs, problems = self.refs(body, book=13)
        self.assertEqual(refs, [("2Cr 10,1-36,23", 14, 10, 1, (14, 36, 23))])
        self.assertIn("site-link-differs", [p[1] for p in problems])

    def test_anAbbreviationBeforeTheLinkNamesTheBook(self):
        refs, _ = self.refs(f"accenna Zc {link('Lam', '7,5', '7,5')}).")
        self.assertEqual(refs, [("Zc 7,5", 45, 7, 5, None)])

    def test_aNumberedBookMayBePrintedWithASpace(self):
        refs, _ = self.refs(f"(cfr. {link('2Re', '2,3', '2 Re 2,3')})")
        self.assertEqual(refs, [("2 Re 2,3", 12, 2, 3, None)])

    def test_aNumberedBookAfterASemicolonIsANewCitation(self):
        refs, _ = self.refs(f"({link('Zc', '7,5', 'Zc 7,5')}; {link('2Re', '2,3', '2Re 2,3')})")
        self.assertEqual([(r[0], r[1]) for r in refs], [("Zc 7,5", 45), ("2Re 2,3", 12)])

    def test_zacIsZechariah(self):
        refs, _ = self.refs(f"accenna Zac {link('Lam', '7,5', '7,5')}).")
        self.assertEqual(refs, [("Zac 7,5", 45, 7, 5, None)])

    def test_withoutAnAbbreviationTheBookIsTheIntroductions(self):
        refs, _ = self.refs(f"Geremia ({link('Ger', '2,3', '2,3')}).")
        self.assertEqual(refs, [("2,3", 1, 2, 3, None)])

    def test_aBookCarriesOnInsideItsParentheses(self):
        refs, _ = self.refs(f"({link('Zc', '7,5', 'Zc 7,5')}; {link('Zc', '7,6', '7,6')}) e {link('Gen', '1,2', '1,2')}")
        self.assertEqual([(r[0], r[1]) for r in refs], [("Zc 7,5", 45), ("7,6", 45), ("1,2", 1)])

    def test_aRangeAcrossTwoBooksIsOneCitation(self):
        body = f"Elia {link('1Re', '17,1-2R', '(1Re 17,1-2R')}e {link('1Re', '2,18', '2,18')})."
        refs, _ = self.refs(body, book=11)
        self.assertEqual(refs, [("1Re 17,1-2Re 2,18", 11, 17, 1, (12, 2, 18))])

    def test_chaptersAfterCc(self):
        refs, _ = self.refs(f"nei {link('Sir', '1-3', 'cc. 1-3')}")
        self.assertEqual(refs, [("cc. 1-3", 1, 1, None, (1, 3, 25))])

    def test_versesListedAfterADotKeepTheirChapter(self):
        refs, _ = self.refs(f"({link('Gen', '1,2-3.5-7.9', '1,2-3.5-7.9')})")
        self.assertEqual(refs, [("1,2-3", 1, 1, 2, (1, 1, 3)), ("5-7", 1, 1, 5, (1, 1, 7)), ("9", 1, 1, 9, None)])

    def test_aLetteredVerseIsItsSegment(self):
        problems = []
        out = ic.blocks(ic.normalize(f"<em>({link('Est', '6,1-10,3l', 'Ebrei (6,1-10,3l')})</em>"), 19, INDEX, "Est", problems)
        self.assertEqual(out[0]["refs"][0]["end"], {"book": 19, "chapter": 10, "verse": 3})

    def test_inABookOfOneChapterANumberIsAVerse(self):
        refs, _ = self.refs(f"({link('Gd', '3-13', 'Gd 3-13', 'nt')})", book=72)
        self.assertEqual(refs, [("Gd 3-13", 72, 1, 3, (72, 1, 13))])

    def test_aTitleInItalicsBeforeAnUnnamedCitationIsReported(self):
        body = f"dagli </em>Atti degli Apostoli<em> (da {link('Rm', '13,4', '13,4')} a {link('Rm', '21,17', '21,17')})"
        _, problems = self.refs(body, book=1)
        self.assertIn(("Gen", "book-named-in-prose", "13,4"), problems)

    def test_numbersWithoutALinkAreNotCitations(self):
        refs, problems = self.refs("nel 587 a.C. e 70 anni")
        self.assertEqual((refs, problems), ([], []))

    def test_anUnresolvedCitationIsTextAndReported(self):
        refs, problems = self.refs(f"({link('Gen', '9,9', '9,9')})")
        self.assertEqual(refs, [])
        self.assertIn(("Gen", "unresolved-citation", "Gen 9,9"), problems)

    def test_aCorrectionReplacesACitation(self):
        problems = []
        fixes = {("Gen", "13,4"): "At 13,4", ("Gen", "21,17"): "At 21,17"}
        body = f"dagli </em>Atti<em> (da {link('Rm', '13,4', '13,4')} a {link('Rm', '21,17', '21,17')})"
        ctx = ic.Corrections("Gen", fixes)
        out = ic.blocks(ic.normalize(f"<em>{body}</em>"), 1, INDEX, "Gen", problems, ctx)
        self.assertEqual([r["start"]["book"] for r in out[0]["refs"]], [51, 51])
        self.assertEqual(ctx.used, set(fixes))
        self.assertNotIn("book-named-in-prose", [p[1] for p in problems])


class PagesTest(unittest.TestCase):
    def test_eachBookHasItsIntroductionInBookOrder(self):
        pages = [("Gen", page("<em>Uno.</em>")), ("1Re", page("<em>Due.</em>", "Primo libro dei Re"))]
        intros = ic.introductions(pages, INDEX, [])
        self.assertEqual([(i.id, i.title, i.books, i.general) for i in intros],
                         [("1", "Genesi", [1], False), ("11", "Primo libro dei Re", [11], False)])

    def test_anUnusedCorrectionStopsTheImport(self):
        with self.assertRaises(SystemExit):
            ic.introductions([("Gen", page("<em>Uno.</em>"))], INDEX, [], {("Gen", "1,1"): "Gen 1,2"})


if __name__ == "__main__":
    unittest.main()
