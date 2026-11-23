#!/usr/bin/env python3
"""CEI 2008's notes, from the bibbiaedu.it archive, as a private data half for migration 064.

The archive (html/<at|nt>/<Book>/<NNN>.html, one page per chapter) is the private repository
BibleGet-I-O/CEI2008-bibbiaedu; it and this script's outputs are copyrighted (Fondazione di Religione Santi
Francesco d'Assisi e Caterina da Siena) and are never committed here. This script holds no CEI text.

What it reads (design: docs/superpowers/specs/2026-11-23-footnotes-design.md, Part 2):
    <span data-notes-id="ID" class="footer_notes">     a note; a note spanning chapters is on each page, kept once
      <span class="note-verse-identificative">1,1-2,4a  the verses it comments on
      <b>GENEALOGIE</b> ...                            a leading bold title (344 notes): the note's title
      <a href="/CEI2008/at/Sal/8/?sel=8">Sal 8</a>     a citation: the passage, and the span of the body printing it
Each note is a footnote with no marker (CEI prints none in the line): it is anchored to the first verse of its
range as a whole (position NULL, shown on the verse number), with the range's last verse recorded. Esther's notes
go on the Greek text where it has the verse. HTML comments (the site's markup notes, some holding a commented-out
link) are dropped first; a note that is all title has an empty body. Bodies keep <i> (<em>) and <b> (<strong>); <br> and paragraphs
become line breaks; other tags are dropped (text kept) and reported, as are citations and ranges that do not
resolve to a stored verse.

Citations after "NVg (" are the Nova Vulgata's verses at its own numbers ("24,1a NVg (24,1b) aggiunge: ..."): they
are resolved against NVBSE's rows (--nvbse-dump, the same \\copy of "NVBSE") and recorded with edition NVBSE.

Usage:
    python3 scripts/notes_cei2008.py --html ../CEI2008-bibbiaedu/html --dump cei.copy --nvbse-dump nvbse.copy \\
        --out ../CEI2008-bibbiaedu/migrations/064-cei2008-notes.sql --report ../CEI2008-bibbiaedu/reports/cei2008-notes.tsv
with cei.copy from:
    \\copy (SELECT sort_order, book, chapter, verse, coalesce(verse_segment,''), coalesce(text_tradition,''),
            coalesce(printed_chapter::text,''), coalesce(verse_label,''), text, coalesce(title1,''),
            coalesce(title2,''), coalesce(title3,'') FROM "CEI2008" ORDER BY sort_order) TO cei.copy
"""

import argparse
import html
import re
import sys
from collections import defaultdict, namedtuple
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import notes_sql  # noqa: E402
from format_cei2008_from_source import BOOK, copy_unescape  # noqa: E402

EDITION = "CEI2008"
Note = namedtuple("Note", "source_id book label title body refs dropped")
Index = namedtuple("Index", "rows chapters")

NOTE_RE = re.compile(r'<span data-notes-id="(\d+)" class="footer_notes".*?'
                     r'<span class="note-verse-identificative">(.*?)</span>(.*?)</div>', re.S)
COMMENT_RE = re.compile(r"<!--.*?-->", re.S)
TARGET_RE = re.compile(r'<span class="verses target[^"]*"[^>]*>\s*</span>')
TITLE_RE = re.compile(r"^\s*<b>(.*?)</b>\s*", re.S)
TOKEN_RE = re.compile(r"<(/?)(\w+)([^>]*)>|([^<]+)")
HREF_RE = re.compile(r'href="/CEI2008/(?:at|nt)/([^/"]+)/[^"?]*\?sel=([^"]+)"')
KEEP = {"i": "i", "em": "i", "b": "b", "strong": "b"}
# "24,1a NVg (24,1b) aggiunge: ...": the Nova Vulgata's reading, at the Nova Vulgata's own number. The site links
# it as a CEI verse; it is a verse of NVBSE, read from the printed number.
NOVA_VULGATA = "NVBSE"
NVG_BEFORE = re.compile(r"\bNVg\s*\(\s*$")
# Books of one chapter, cited by verse alone ("Abd 8-9", "Fm 24"): Obadiah, Philemon, 2 John, 3 John, Jude.
ONE_CHAPTER = {"Abd", "Fm", "2Gv", "3Gv", "Gd"}
REF_RE = re.compile(r"^\s*(\d+)(?:,(\d+)([a-z]*))?(?:\s*-\s*(?:(\d+),)?(\d+)[a-z]*)?")


# ------------------------------------------------------------------ the note bodies

class Body:
    """A note body built token by token: code points, tags included, so citation spans count as the reader does."""

    def __init__(self):
        self.cps, self.refs, self.dropped, self.open = [], [], [], None

    def text(self, s):
        s = re.sub(r"\s+", " ", html.unescape(s))
        if s.startswith(" ") and (not self.cps or self.cps[-1] in " \n"):
            s = s[1:]
        self.cps += list(s)

    def newline(self):
        while self.cps and self.cps[-1] == " ":
            self.cps.pop()
        if self.cps and self.cps[-1] != "\n":
            self.cps.append("\n")

    def tag(self, closing, name, attrs):
        if name in KEEP:
            self.cps += list(f"<{'/' if closing else ''}{KEEP[name]}>")
        elif name in ("br", "p"):
            self.newline()
        elif name == "a":
            self.link(closing, attrs)
        elif name != "span" and name not in self.dropped:
            self.dropped.append(name)

    def link(self, closing, attrs):
        if not closing:
            m = HREF_RE.search(attrs)
            self.open = (m[1], html.unescape(m[2]), len(self.cps)) if m else None
        elif self.open:
            slug, sel, start = self.open
            edition = None
            if NVG_BEFORE.search(re.sub(r"<[^>]+>", "", "".join(self.cps[max(0, start - 24):start]))):
                edition, sel = NOVA_VULGATA, re.sub(r"<[^>]+>", "", "".join(self.cps[start:])).strip(" ()")
            self.refs.append((slug, sel, start, len(self.cps), edition))
            self.open = None

    def done(self):
        """The body (trimmed) and its citations, each span shifted by what the trimming took off the front and
        narrowed to its printed characters (no space at either end), so it stays inside the body."""
        lead = len(self.cps) - len("".join(self.cps).lstrip())
        body = "".join(self.cps).strip()
        cps, refs = list(body), []
        for slug, sel, s, e, edition in self.refs:
            s, e = max(0, s - lead), min(len(cps), e - lead)
            while s < e and cps[s].isspace():
                s += 1
            while e > s and cps[e - 1].isspace():
                e -= 1
            refs.append((slug, sel, s, e, edition))
        return body, refs


def parse_body(raw):
    b = Body()
    for m in TOKEN_RE.finditer(TARGET_RE.sub("", raw)):
        if m[4] is not None:
            b.text(m[4])
        else:
            b.tag(bool(m[1]), m[2].lower(), m[3])
    body, refs = b.done()
    return body, refs, b.dropped


def parse_page(page, book):
    """The notes on a chapter page."""
    out = []
    for nid, label, raw in NOTE_RE.findall(page):
        # Comments are the site's markup notes, some holding a commented-out link: never text, never a citation.
        raw = COMMENT_RE.sub("", raw)
        title = None
        m = TITLE_RE.match(raw)
        if m:
            title = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", "", m[1]))).strip() or None
            raw = raw[m.end():]
        body, refs, dropped = parse_body(raw)
        out.append(Note(nid, book, html.unescape(label).strip(), title, body, refs, dropped))
    return out


def collect(pages):
    """{source_id: Note} over (book, page) pairs, in page order; a note on several pages is kept once."""
    notes = {}
    for book, page in pages:
        for n in parse_page(page, book):
            notes.setdefault(n.source_id, n)
    return notes


# ------------------------------------------------------------------ stored verses

def index_rows(rows):
    """Index of (book, chapter, verse, segment, tradition) rows: the traditions of each verse, and the chapters."""
    out = defaultdict(list)
    for b, c, v, seg, trad in rows:
        out[(b, c, v, seg)].append(trad)
    return Index(dict(out), {(b, c) for b, c, _, _, _ in rows})


def load_dump(path):
    rows = []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            f = [copy_unescape(x) for x in line.rstrip("\n").split("\t")]
            rows.append((int(f[1]), int(f[2]), int(f[3]), f[4], f[5]))
    return index_rows(rows)


def stored(book, chapter, verse, letters, index):
    """(segment, traditions) of the stored verse: its lettered row, or the verse whole when the letter is not stored."""
    seg = letters[:1]
    for key in ((book, chapter, verse, seg), (book, chapter, verse, "")):
        if key in index.rows:
            return key[3], index.rows[key]
    return None


def parse_range(s):
    """(chapter, verse or None, letters, end (chapter, verse) or None) of "1,1-2,4a", "8", "1,2.5", "8,2 (8,1)"."""
    m = REF_RE.match(s)
    if not m:
        return None
    chapter, verse = int(m[1]), int(m[2]) if m[2] else None
    end = (int(m[4]) if m[4] else chapter, int(m[5])) if m[5] and verse is not None else None
    return chapter, verse, m[3] or "", end


def stored_end(book, end, index):
    """(book, chapter, verse) of a range's end when the table has that verse, else None (not recorded)."""
    return (book,) + end if end and stored(book, end[0], end[1], "", index) else None


def anchor_for(book, label, index):
    """((book, chapter, verse, segment, tradition, printed_chapter), last (book, chapter, verse) or None), or None."""
    r = parse_range(label)
    if not r or r[1] is None:
        return None
    chapter, verse, letters, end = r
    found = stored(book, chapter, verse, letters, index)
    if not found:
        return None
    seg, trads = found
    trad = "GREEK" if "GREEK" in trads else trads[0]
    return (book, chapter, verse, seg, trad, ""), stored_end(book, end, index)


def resolve_ref(slug, sel, index):
    """((book, chapter, verse or None, segment, printed_chapter), end or None) of a citation, or None."""
    if slug in ONE_CHAPTER and "," not in sel:
        sel = f"1,{sel}"
    book, r = BOOK.get(slug), parse_range(sel)
    if book is None or not r:
        return None
    chapter, verse, letters, end = r
    if verse is None:
        return ((book, chapter, None, "", ""), None) if (book, chapter) in index.chapters else None
    found = stored(book, chapter, verse, letters, index)
    if not found:
        return None
    return (book, chapter, verse, found[0], ""), stored_end(book, end, index)


# ------------------------------------------------------------------ output

def ref_rows(n, index, problems, nova=None):
    """The note's citations; one into the Nova Vulgata is resolved against `nova` (NVBSE's verses) and named."""
    rows = []
    for ord_, (slug, sel, s, e, edition) in enumerate(n.refs, 1):
        target = index if edition is None else nova
        r = resolve_ref(slug, sel, target) if target is not None else None
        if r is None:
            printed = "".join(list(n.body)[s:e])
            problems.append((n.source_id, printed, "unresolved-citation", f"{edition or EDITION} {slug} {sel}"))
            continue
        rows.append((n.source_id, ord_, r[0], r[1], s, e) + ((edition,) if edition else ()))
    return rows


def to_sql(notes, index, problems=None, nova=None):
    problems = [] if problems is None else problems
    rows, anchors, refs = [], [], []
    for sid, n in notes.items():
        a = anchor_for(n.book, n.label, index)
        if n.dropped:
            problems.append((sid, n.label, "dropped-tags", ",".join(n.dropped)))
        if a is None:
            problems.append((sid, n.label, "no-verse", ""))
            continue
        rows.append((sid, "footnote", None, n.label, n.title, n.body))
        anchors.append((sid, a[0], "text", None, a[1]))
        refs += ref_rows(n, index, problems, nova)
    return notes_sql.data_half(
        EDITION, "CEI 2008 notes for migration 064 (private data half; generated by endpoint scripts/notes_cei2008.py).",
        rows, anchors, refs)


def report(problems):
    return "\n".join(["source_id\twhere\treason\tcontext"] + ["\t".join(map(str, p)) for p in problems]) + "\n"


def pages(root):
    for path in sorted(Path(root).glob("*/*/*.html")):
        if path.parent.name in BOOK:
            yield BOOK[path.parent.name], path.read_text(encoding="utf-8")


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--html", required=True)
    ap.add_argument("--dump", required=True)
    ap.add_argument("--nvbse-dump", help="NVBSE's rows (same \\copy), for the notes' Nova Vulgata citations")
    ap.add_argument("--out", required=True)
    ap.add_argument("--report", required=True)
    args = ap.parse_args(argv)
    notes, problems = collect(pages(args.html)), []
    if not notes:
        # A data half deletes the edition's notes first: never write one that would leave none.
        sys.exit(f"no notes found under {args.html}: nothing written")
    nova = load_dump(args.nvbse_dump) if args.nvbse_dump else None
    sql = to_sql(notes, load_dump(args.dump), problems, nova)
    Path(args.out).write_text(sql, encoding="utf-8")
    Path(args.report).write_text(report(problems), encoding="utf-8")
    reasons = defaultdict(int)
    for p in problems:
        reasons[p[2]] += 1
    print(f"notes {len(notes)}, problems {dict(reasons)}", file=sys.stderr)


if __name__ == "__main__":
    main()
