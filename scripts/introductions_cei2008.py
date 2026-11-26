"""CEI 2008's 73 book introductions, from the bibbiaedu.it archive (private BibleGet-I-O/CEI2008-bibbiaedu,
introductions/<at|nt>/<Book>.html: each page's <main>), as blocks for book_introduction (migration 071).
Called by introductions.py (edition cei2008). No CEI text is here.

What it reads:
    <h2 class="book-title">Genesi</h2> ... </div>    the title, then the body up to the page's navigation
    <em>...</em>                                   the site prints the body inside <em>, with the titles of books
                                                   outside it: the italics are inverted, so text outside <em> (not
                                                   bold, not a link) is the italic, and <em> is dropped
    <b>I contenuti<br /></b>                       a run-in heading, its line break moved after it: a heading (level 1)
    <br /><br />, <br />                           a paragraph's end, a line's end; the lines after one ending ":"
                                                   are the outline's items (level 1), other lines are paragraphs
    <a title="vai a Gen 1,1" href=...>1,1</a>      the site's citation links, made by a linker that takes a nearby
                                                   word for a book ("Giuda (2C" for 2 Chronicles' "2Cr") and cuts
                                                   citations ("(1Re 17,1-2R" + "e 2,18)")
Citations are read from the text with the CEI grammar ("Zc 7,5", "1,1-2,4a", "8,15-21.23-53", "cc. 22-24",
"1Re 17,1-2Re 2,18"), counted only where a site link overlaps them (so years and other numbers are not); the book is
the one printed, else the last printed inside the same parentheses, else the introduction's. Reported: where the
site's link resolves elsewhere (site-link-differs), an unnamed citation just after a title in italics
(book-named-in-prose: "dagli Atti degli Apostoli (da 13,4 a 21,17)"), and what does not resolve. Corrections
(introduction slug, citation as printed -> citation, or "-" for none) replace a citation.
"""

import html
import re
import sys
from collections import namedtuple
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import notes_cei2008 as nc  # noqa: E402
from format_cei2008_from_source import BOOK, SLUGS  # noqa: E402

Introduction = namedtuple("Introduction", "id title books general blocks")

PAGE = re.compile(r'<h2 class="book-title[^"]*">(.*?)</h2>(.*?)</div>\s*<div class="container d-none', re.S)
ALIASES = {"Zac": "Zc"}
# "1Cor" or "1 Cor": a numbered book may be printed with a space.
_ABBR = "|".join(re.sub(r"^([1-3])", r"\1\\s?", a) for a in sorted(list(BOOK) + list(ALIASES), key=len, reverse=True))
# A number followed by a numbered book's name is that book's ("; 1Pt 5,13", "; 2 Re 2,3"), not a chapter; a number is
# never cut short to tell ("In 7,14 Amos": possessive).
_NUMBERED = "|".join(sorted({a[1:] for a in BOOK if a[0].isdigit()}, key=len, reverse=True))
_NUM = rf"\d++(?:,\d++[a-z]{{0,2}}+)?+(?!\s?(?:{_NUMBERED})\b)"
_PART = rf"{_NUM}(?:-(?:(?:{_ABBR})\s*)?{_NUM})?"
CITE = re.compile(rf"(?<![\w])(?:(?P<book>{_ABBR})\s+)?(?P<cc>cc?\.\s*)?(?P<nums>{_PART}(?:(?:\.|;\s*){_PART})*)")
PART = re.compile(rf"(?P<sep>^|\.|;\s*)(?P<body>{_PART})")
RANGE = re.compile(rf"(\d+)(?:,(\d+)([a-z]*))?(?:-(?:({_ABBR})\s*)?(\d+)(?:,(\d+)([a-z]*))?)?")
PARAGRAPH = re.compile(r"(?:<br\s*/?>(?:\s|&nbsp;)*){2,}")
LINE = re.compile(r"<br\s*/?>")
HEADING = re.compile(r"\s*<b>((?:(?!</?b>).)*)</b>\s*", re.S)
LETTER = re.compile(r"[^\W\d_]")


class Corrections:
    """One introduction's corrections ({(slug, citation as printed): citation or None}), and those used."""

    def __init__(self, where, fixes=None):
        self.where, self.fixes, self.used = where, fixes or {}, set()

    def get(self, printed):
        key = (self.where, printed)
        if key in self.fixes:
            self.used.add(key)
            return True, self.fixes[key]
        return False, None


# ------------------------------------------------------------------ the page

def title_of(page):
    return html.unescape(re.sub(r"<[^>]+>", "", PAGE.search(page)[1])).strip()


def body_of(page):
    return PAGE.search(page)[2]


def _wrap(text):
    """Text outside <em>: italic where it has a letter, its surrounding spaces left roman."""
    if not LETTER.search(html.unescape(text)):
        return text
    m = re.match(r"(\s*)(.*?)(\s*)$", text, re.S)
    return f"{m[1]}<i>{m[2]}</i>{m[3]}"


def normalize(raw):
    """The body with its italics put right: <em> (or the <i> wrapping a body) dropped, text outside it in <i>;
    <strong> as <b>; a line break closing bold moved after it, one opening it before it; empty bold dropped."""
    raw = nc.TARGET_RE.sub("", nc.COMMENT_RE.sub("", raw))
    depth = {"em": 0, "b": 0, "a": 0}
    out = []
    for m in nc.TOKEN_RE.finditer(raw):
        if m[4] is not None:
            out.append(_wrap(m[4]) if not any(depth.values()) else m[4])
            continue
        # <i> is the wrapper where <em> is not (Giudici, Tobia): the same inverted italics.
        closing, name = bool(m[1]), {"strong": "b", "i": "em"}.get(m[2].lower(), m[2].lower())
        if name in depth:
            depth[name] = max(0, depth[name] + (-1 if closing else 1))
        if name == "b":
            out.append(f"<{'/' if closing else ''}{name}>")
        elif name == "a":
            out.append(m[0])
        elif name in ("br", "p"):
            out.append("<br />" if name == "br" else ("<br /><br />" if closing else ""))
    s = "".join(out)
    s = re.sub(r"<br />(\s*)</b>", r"</b><br />\1", s)
    s = re.sub(r"<b>((?:\s|<br />)*<br />\s*)", r"\1<b>", s)
    s = re.sub(r"<b>(?:\s|&nbsp;)*</b>", "", s)
    s = re.sub(r"</b>(\s*)<b>", r"\1", s)
    s = re.sub(r"</i>(\s*)<i>", r"\1", s)
    return s.strip()


# ------------------------------------------------------------------ citations

def visible(text):
    """(the text without tags, the index in `text` of each of its characters)."""
    plain, pos = [], []
    for m in re.finditer(r"<[^>]+>|.", text, re.S):
        if not m[0].startswith("<") or len(m[0]) == 1:
            plain.append(m[0])
            pos.append(m.start())
    return "".join(plain), pos


def _book(abbr):
    abbr = re.sub(r"\s+", "", abbr)
    return BOOK[ALIASES.get(abbr, abbr)]


Verses = namedtuple("Verses", "last stored")


def _verses(index):
    """The last verse of each chapter, and every (book, chapter, verse) stored, whatever its segments."""
    last = {}
    for b, c, v, _ in index.rows:
        last[(b, c)] = max(v, last.get((b, c), 0))
    return Verses(last, {k[:3] for k in index.rows})


def _parts(match, book):
    """(the part's text start and end in the match, book, chapter, verse or None, letters, end (book, chapter, verse
    or None) or None) of each part of a citation: chapters after "cc.", verses after ".", a bare number a chapter
    (a verse in a book of one chapter)."""
    one = SLUGS[book - 1] in nc.ONE_CHAPTER
    out, chapter = [], None
    offset = match.start("nums") - match.start()
    for m in PART.finditer(match["nums"]):
        r = RANGE.fullmatch(m["body"])
        a, b_, letters, end_book, e1, e2, _ = r.groups()
        a, verse = int(a), int(b_) if b_ else None
        if verse is None and not match["cc"] and (m["sep"] == "." or one):
            a, verse = (chapter or 1), a
        chapter = a
        eb = _book(end_book) if end_book else book
        end = None
        if e1:
            e1 = int(e1)
            if e2:
                end = (eb, e1, int(e2))
            elif verse is not None and not end_book:
                end = (eb, chapter, e1)
            else:
                end = (eb, e1, None)
        start = offset + m.start("body") if out else 0
        out.append((start, offset + m.end(), book, chapter, verse, letters or "", end))
    return out


def _resolve(part, index, verses):
    _, _, book, chapter, verse, letters, end = part
    if verse is None:
        if (book, chapter) not in index.chapters:
            return None
        start = {"book": book, "chapter": chapter, "verse": None, "segment": None, "printed_chapter": None}
    else:
        found = nc.stored(book, chapter, verse, letters, index)
        if not found:
            return None
        start = {"book": book, "chapter": chapter, "verse": verse, "segment": found[0] or None, "printed_chapter": None}
    if end is None:
        return {"start": start, "end": None}
    eb, ec, ev = end
    ev = verses.last.get((eb, ec)) if ev is None else ev
    if ev is None or (eb, ec, ev) not in verses.stored:
        return None
    return {"start": start, "end": {"book": eb, "chapter": ec, "verse": ev}}


def _group_book(plain, start, explicit):
    """The book last printed inside the parentheses still open at `start`, or None."""
    depth, opened = 0, None
    for i in range(start - 1, -1, -1):
        if plain[i] == ")":
            depth += 1
        elif plain[i] == "(":
            if depth == 0:
                opened = i
                break
            depth -= 1
    if opened is None:
        return None
    # Only books printed at this level: one inside parentheses already closed ("(Gen 1,1 (cfr. Zc 7,5) 2,3)") stays there.
    level = [0] * (start + 1)
    for i in range(opened + 1, start):
        level[i + 1] = level[i] + (plain[i] == "(") - (plain[i] == ")")
    inside = [b for at, b in explicit if opened < at < start and level[at] == 0]
    return inside[-1] if inside else None


def _site(links, s, e, index):
    """The site's resolutions of the links overlapping [s, e), each with whether it starts there."""
    return [(slug, sel, nc.resolve_ref(slug, sel, index), s <= ls) for slug, sel, ls, le, _ in links if ls < e and s < le]


class Line:
    """One line's text, its links, and where the citations found go."""

    def __init__(self, text, links, book, index, where, problems, ctx):
        self.text, self.links, self.book, self.index = text, links, book, index
        self.where, self.problems, self.ctx = where, problems, ctx or Corrections(where)
        self.plain, self.pos = visible(text)
        self.verses = _verses(index)

    def span(self, a, b):
        return self.pos[a], self.pos[b - 1] + 1

    def citations(self):
        refs, explicit = [], []
        for m in CITE.finditer(self.plain):
            s, e = self.span(m.start(), m.end())
            # An unlinked citation is no citation, but the book it prints carries on in its parentheses.
            book, named = self.book_of(m, explicit)
            if not any(ls < e and s < le for _, _, ls, le, _ in self.links):
                continue
            for part in _parts(m, book):
                refs += self.part(m, part, named)
        return refs

    def book_of(self, m, explicit):
        if m["book"]:
            explicit.append((m.start(), _book(m["book"])))
            return _book(m["book"]), True
        group = _group_book(self.plain, m.start(), explicit)
        return (group, True) if group else (self.book, False)

    def part(self, m, part, named):
        a, b = m.start() + part[0], m.start() + part[1]
        printed = self.plain[a:b]
        s, e = self.span(a, b)
        fixed, replacement = self.ctx.get(printed)
        if fixed:
            return self.replaced(replacement, s, e)
        if not named and "</i>" in self.text[max(0, s - 40):s]:
            self.problems.append((self.where, "book-named-in-prose", printed))
        r = _resolve(part, self.index, self.verses)
        if r is None:
            nums = re.sub(rf"^(?:(?:{_ABBR})\s+)?(?:cc?\.\s*)?", "", printed)
            self.problems.append((self.where, "unresolved-citation", f"{SLUGS[part[2] - 1]} {nums}"))
            return []
        self.compare(r, s, e, printed)
        return [{**r, "span": [s, e]}]

    def compare(self, r, s, e, printed):
        # The verse is compared where the link starts: one link over "1,2-3.5-7" names its first verse only.
        for slug, sel, site, starts in _site(self.links, s, e, self.index):
            ours = r["start"]
            if site is None or site[0][:2] != (ours["book"], ours["chapter"]) or (
                    starts and site[0][2] is not None and site[0][2] != ours["verse"]):
                self.problems.append((self.where, "site-link-differs", f"{printed}: site {slug} {sel}"))

    def replaced(self, replacement, s, e):
        if replacement is None:
            return []
        m = CITE.fullmatch(replacement)
        if not m or not m["book"]:
            sys.exit(f"correction {replacement!r} in {self.where}: a citation with its book, e.g. 'At 13,4'")
        parts = _parts(m, _book(m["book"]))
        if len(parts) != 1:
            sys.exit(f"correction {replacement!r} in {self.where}: one passage per correction")
        r = _resolve(parts[0], self.index, self.verses)
        if r is None:
            sys.exit(f"correction {replacement!r} in {self.where} is no stored verse")
        return [{**r, "span": [s, e]}]


# ------------------------------------------------------------------ blocks

def _block(kind, raw, book, index, where, problems, ctx, level=None):
    text, links, _ = nc.parse_body(raw)
    refs = Line(text, links, book, index, where, problems, ctx).citations() if kind != "heading" else []
    return {"type": kind, **({"level": level} if level else {}), "text": text, "refs": refs}


def blocks(norm, book, index, where, problems, ctx=None):
    out = []
    for para in PARAGRAPH.split(norm):
        outline = False
        for line in LINE.split(para):
            if not re.search(r"[^\W_]", html.unescape(re.sub(r"<[^>]+>", "", line))):
                continue
            h = HEADING.fullmatch(line)
            if h:
                out.append(_block("heading", h[1], book, index, where, problems, ctx, 1))
                continue
            kind = "item" if outline else "paragraph"
            out.append(_block(kind, line, book, index, where, problems, ctx, 1 if outline else None))
            outline = outline or out[-1]["text"].rstrip().endswith(":")
    return out


def introductions(pages, index, problems, fixes=None):
    """The introductions of (slug, page) pairs, in book order; a correction that matches nothing stops the import."""
    out, used = [], set()
    for slug, page in sorted(pages, key=lambda p: BOOK[p[0]]):
        ctx = Corrections(slug, fixes)
        bl = blocks(normalize(body_of(page)), BOOK[slug], index, slug, problems, ctx)
        out.append(Introduction(str(BOOK[slug]), title_of(page), [BOOK[slug]], False, bl))
        used |= ctx.used
    if unused := sorted(set(fixes or {}) - used):
        sys.exit(f"citation corrections that match no citation: {unused}")
    return out


def load_corrections(text):
    """{(slug, citation as printed): citation or None ("-")}, from TSV lines: slug, printed, corrected, reason."""
    out = {}
    for line in text.splitlines():
        if line.strip() and not line.startswith("#"):
            slug, printed, corrected = line.split("\t")[:3]
            out[(slug, printed)] = None if corrected == "-" else corrected
    return out


def pages(root):
    files = sorted(Path(root).glob("*/*.html"))
    if not files:
        sys.exit(f"no introduction pages under {root}: nothing written")
    return [(f.stem, f.read_text(encoding="utf-8")) for f in files]
