#!/usr/bin/env python3
"""The editions' titles with their levels, as private data halves for migration 069 (book_outline).

The titles are our stored ones (title1-title3), which carry no level; the sources give it:
    NABRE     the SCML tag: bksect 1; bksubsect 2; chsect, cs, pc and run-in headings 3; chsubsect 4
    CEI2008   the page's class: "s" 1, "s s2" 2
CEI 2008 prints headings in few books. In the others, the titles of its commentary notes serve (in capitals 1, others
2), marked reader_only. Psalm stanza numerals and acrostic letters are not titles. Esther is read from its Greek text,
which has all of it. Design: docs/superpowers/specs/2026-11-24-outline-introductions-design.md. No edition's text is here.

Usage (dumps as for notes_nabre.py / notes_cei2008.py):
    python3 scripts/outline.py nabre --source ../NABRE-source/scml --dump nabre.copy --out OUT.sql --report R.tsv
    python3 scripts/outline.py cei2008 --source ../CEI2008-bibbiaedu/html --dump cei.copy --out OUT.sql --report R.tsv
"""

import argparse
import re
import sys
from collections import defaultdict, namedtuple
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import nabre_headings_from_scml as heads  # noqa: E402
import notes_cei2008 as cei  # noqa: E402
import notes_nabre  # noqa: E402
from notes_sql import chunks, lit  # noqa: E402

Row = namedtuple("Row", "book chapter verse seg trad pc titles")
Entry = namedtuple("Entry", "level title ident reader_only")  # ident: (book, chapter, verse, segment, tradition, printed chapter)

NABRE_LEVELS = {"bksect": 1, "bksubsect": 2, "chsect": 3, "cs": 3, "pc": 3, "chsubsect": 4}
ESTHER = 19
# A book with fewer printed headings than this is taken to print none of its own: the commentary's titles serve.
FEW = 10
LETTERS = ("Aleph Beth Gimel Daleth He Heh Waw Vav Zayin Heth Het Teth Tet Yodh Yod Kaph Kaf Lamedh Lamed Mem Nun Samekh Ayin "
           "Pe Peh Sadhe Sade Tsade Qoph Resh Shin Sin Taw Tav Alef Bet Ghimel Dalet Vau Zain Iod Caf Samec Ain Kof Res Tau")
NOT_A_TITLE = re.compile(r"^(?:[IVXLC]+|\W*[A-Za-z]\W*|(?:" + "|".join(LETTERS.split()) + r"))\.?$", re.I)
CEI_HEAD = re.compile(r'<div class="usfm usfm_titlesheadingsandlabel (s(?: s2)?)"[^>]*>(.*?)</div>', re.S)


def nabre_levels(scml):
    """{title: [levels, in order of appearance]}; run-in headings (a paragraph's bold opening) are sections."""
    out = defaultdict(list)
    for m in heads.TOKEN.finditer(heads.strip_notes(scml)):
        if m["htag"]:
            out[heads.clean(m["head"])].append(NABRE_LEVELS[m["htag"]])
        elif m["para"]:
            out[heads.clean(m["runin"]).rstrip(".")].append(3)
    return dict(out)


def cei_levels(page):
    out = defaultdict(list)
    for cls, inner in CEI_HEAD.findall(page):
        out[heads.clean(inner)].append(2 if "s2" in cls else 1)
    return dict(out)


def load_rows(path):
    rows = []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            f = [heads.copy_unescape(x) for x in line.rstrip("\n").split("\t")]
            rows.append(Row(int(f[1]), int(f[2]), int(f[3]), f[4], f[5], f[6], [t for t in f[9:12] if t]))
    return rows


def printed(rows, levels, default, problems):
    """{book: [Entry]} of the stored titles, each with the next level the source gives that title (else `default`)."""
    out = defaultdict(list)
    queues = {b: {t: list(ls) for t, ls in by.items()} for b, by in levels.items()}
    for r in rows:
        if r.book == ESTHER and r.trad == "HEBREW":
            continue
        for title in r.titles:
            if NOT_A_TITLE.match(title.strip()):
                continue
            if r.chapter == 0:  # a prologue (Sirach's), outside the book's divisions
                out[r.book].append(Entry(1, title, (r.book, r.chapter, r.verse, r.seg, r.trad, r.pc), False))
                continue
            queue = queues.get(r.book, {}).get(title)
            if not queue and levels:
                problems.append((r.book, title, "no-level"))
            level = default if not queue else (queue.pop(0) if len(queue) > 1 else queue[0])
            out[r.book].append(Entry(level, title, (r.book, r.chapter, r.verse, r.seg, r.trad, r.pc), False))
    return dict(out)


def psalm_levels(entries):
    """CEI 2008's Psalms: the number ("SALMO 2 (2)") and the books of the Psalter lead, the psalm's title goes under."""
    top = re.compile(r"^SALM[OI]\b|\bLIBRO\b", re.I)
    return {b: [e._replace(level=1 if top.search(e.title) else 2) for e in es] if b == 23 else es for b, es in entries.items()}


def with_commentary(entries, anchored):
    """The printed titles, plus the commentary's (ident, title) in books that print few of their own."""
    out, few = dict(entries), defaultdict(list)
    for ident, title in anchored:
        if len(entries.get(ident[0], [])) < FEW:
            # A stray letter of the range it is printed under ("8,11-23a La ...").
            title = re.sub(r"^[a-z] (?=[A-ZÀ-Ý])", "", title)
            few[ident[0]].append(Entry(1 if title.isupper() else 2, title, ident, True))
    for book, extra in few.items():
        both = list(entries.get(book, [])) + extra
        out[book] = sorted(both, key=lambda e: (e.ident[1], e.ident[2], e.ident[3], e.level))
    return out


def to_sql(edition, entries):
    rows = []
    for book in sorted(entries):
        for ord_, e in enumerate(entries[book], 1):
            _, c, v, seg, trad, pc = e.ident
            rows.append(f"({lit(edition)}, {book}, {ord_}, {e.level}, {lit(e.title)}, {c}, {v}, {lit(seg)}, {lit(trad)}, "
                        f"{lit(pc, 'int')}, {'true' if e.reader_only else 'false'})")
    head = ("INSERT INTO book_outline (edition, book, ord, level, title, chapter, verse, verse_segment, text_tradition, "
            "printed_chapter, reader_only) VALUES\n")
    parts = ["BEGIN;", f"DELETE FROM book_outline WHERE edition = {lit(edition)};"]
    parts += [head + ",\n".join(c) + ";" for c in chunks(rows)]
    return "\n".join(parts + ["COMMIT;"]) + "\n"


def write(edition, entries, problems, out, report):
    if not any(entries.values()):
        sys.exit(f"no titles found for {edition}: nothing written")
    Path(out).write_text(to_sql(edition, entries), encoding="utf-8")
    Path(report).write_text("book\ttitle\treason\n" + "".join("\t".join(map(str, p)) + "\n" for p in problems), encoding="utf-8")
    print(f"titles {sum(map(len, entries.values()))} in {len(entries)} books, problems {len(problems)}", file=sys.stderr)


def require(found, source):
    """The source's files; without any, every title would silently get the default level."""
    if not found:
        sys.exit(f"no source files found under {source}: nothing written")
    return found


def nabre(source, rows, problems):
    levels = {}
    for path in require(sorted(Path(source).glob("nab-*.scml")), source):
        number = int(path.name[4:6])
        if number:
            levels.setdefault(notes_nabre.nab_book(number), {}).update(nabre_levels(notes_nabre.read_scml(path)))
    return printed(rows, levels, 3, problems)


def cei2008(source, rows, problems):
    levels, pages = defaultdict(lambda: defaultdict(list)), require(list(cei.pages(source)), source)
    for book, page in pages:
        for title, found in cei_levels(page).items():
            levels[book][title] += found
    index = cei.index_rows([(r.book, r.chapter, r.verse, r.seg, r.trad) for r in rows])
    anchored = []
    for n in cei.collect(pages).values():
        where = cei.anchor_for(n.book, n.label, index) if n.title else None
        if where:
            anchored.append((where[0], n.title))
    return with_commentary(psalm_levels(printed(rows, levels, 2, problems)), anchored)


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("edition", choices=["nabre", "cei2008"])
    for name in ("--source", "--dump", "--out", "--report"):
        ap.add_argument(name, required=True)
    args = ap.parse_args(argv)
    problems = []
    entries = {"nabre": nabre, "cei2008": cei2008}[args.edition](args.source, load_rows(args.dump), problems)
    write(args.edition.upper(), entries, problems, args.out, args.report)


if __name__ == "__main__":
    main()
