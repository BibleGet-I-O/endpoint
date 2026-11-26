#!/usr/bin/env python3
"""The editions' introductions, as private data halves for migration 071 (book_introduction).

NABRE (the publisher's SCML, private BibleGet-I-O/NABRE-source): a book's introduction runs from its title (<bk>, with
a <bks> subtitle) to the first <bksect>, <ch>, <chsect>, <cs> or verse; a second <bk> ends a joint introduction (1-2
Samuel, 1-2 Kings), which then serves the next book too. The general introductions are files of their own (nab-01b...,
title <pt>), each covering the books listed in GENERAL. Blocks: <ah>/<bh> headings, paragraphs, numbered outlines
(<nl*> level 1, <nl1*>/<ul1*> level 2) and table rows; their text keeps <i>/<b>/<sm>, and each <xref> becomes a citation
(span in code points, as the notes'). A citation that does not resolve is kept as text and reported.
Curated corrections, from the private archive: --misprints (verified misprints, recorded as crmedr's are: the text
stays as printed and its block carries "sic": [{span, intended, ref}], which the reader shows as "[sic! expected: ...]")
and --citations (links whose id is wrong though their text is right). A record that matches nothing stops the import.
Design: docs/superpowers/specs/2026-11-24-outline-introductions-design.md. No edition's text is here.

Usage (the dump as for notes_nabre.py):
    python3 scripts/introductions.py nabre --source ../NABRE-source/scml --dump nabre.copy \\
        --misprints ../NABRE-source/misprints.json --citations ../NABRE-source/introductions-citations.tsv \\
        --out ../NABRE-source/migrations/071-nabre-introductions.sql --report ../NABRE-source/reports/nabre-introductions.tsv
"""

import argparse
import json
import re
import sys
from collections import namedtuple
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import notes_nabre as nn  # noqa: E402
from notes_sql import chunks, lit  # noqa: E402

Introduction = namedtuple("Introduction", "id title books general blocks")
# Curated corrections from the private archive: misprints (as crmedr's data/misprints.json: the text stays as printed,
# the reader is shown "[sic! expected: ...]") and citations whose link id is wrong though their text is right.
Fixes = namedtuple("Fixes", "misprints citations", defaults=((), {}))

# NABRE's general introductions: file letter code -> (id, the universal numbers of the books they introduce).
GENERAL = {
    "01b": ("pentateuch", range(1, 6)),
    "06a": ("historical-books", range(6, 22)),
    "17a": ("novellas", range(17, 20)),
    "22a": ("wisdom-books", range(22, 29)),
    "29a": ("prophetic-books", range(29, 47)),
    "48a": ("new-testament", range(47, 74)),
    "48b": ("gospels", range(47, 51)),
    "53a": ("new-testament-letters", range(52, 73)),
    "67a": ("catholic-letters", range(66, 73)),
}
FILE = re.compile(r"nab-(\d\d)([a-z]?)-")
BLOCK = re.compile(r"<(?P<tag>[a-z0-9]+)\b[^>]*>(?P<inner>.*?)</(?P=tag)>", re.S)
CELL = re.compile(r"<td\b[^>]*>(.*?)</td>", re.S)
END = re.compile(r"<bk>|<bksect\b|<ch\s+id=|<chsect\b|<cs\b|<(?!xref\b)[a-z0-9]+\s+id=\"\d{8}[a-z]?\"")
UNCLOSED = re.compile(r"<(p|pf|pl|pcon)>(?:(?!</\1>).)*\Z", re.S)
KINDS = {"p": ("paragraph", None), "pf": ("paragraph", None), "pl": ("paragraph", None), "pcon": ("paragraph", None),
         "pr": ("paragraph", None), "iu": ("paragraph", None), "ah": ("heading", 1), "bh": ("heading", 2),
         "bhaft": ("heading", 2)}
KINDS.update({t: ("item", 1) for t in ("nl", "nlf", "nll")})
KINDS.update({t: ("item", 2) for t in ("nl1", "nl1f", "nl1l", "ul1", "ul1f", "ul1l")})


def clean(s):
    return nn.flatten(nn.visible(s)).strip()


def book_segment(scml):
    """(title, the introduction's markup, whether a second <bk> ended it: a joint introduction)."""
    head = re.search(r"<bk>(.*?)</bk>(?:\s*<bks>(.*?)</bks>)?", scml, re.S)
    title = " ".join(clean(t) for t in head.groups() if t)
    m = END.search(scml, head.end())
    markup = scml[head.end():m.start() if m else len(scml)]
    # A paragraph the first verse is printed in (after a run-in heading) begins before the verse: not ours.
    markup = UNCLOSED.sub("", markup)
    return title, markup.strip(), bool(m and m[0] == "<bk>")


def general_segment(scml):
    head = re.search(r"<pt>(.*?)</pt>", scml, re.S)
    return clean(head[1]), scml[head.end():].replace("</scml>", "").strip()


def ref(xref_id, index):
    found = nn.resolve_ref(xref_id, index)
    if found is None:
        return None
    (b, c, v, seg, pc), end = found
    return {"start": {"book": b, "chapter": c, "verse": v, "segment": seg or None, "printed_chapter": int(pc) if pc else None},
            "end": None if end is None else {"book": end[0], "chapter": end[1], "verse": end[2]}}


class Context:
    """One introduction's corrections, and what of them was used."""

    def __init__(self, id_, fixes, index):
        self.id, self.index, self.citations = id_, index, fixes.citations
        self.misprints = [m for m in fixes.misprints if m["introduction"] == id_]
        self.hits = [0] * len(self.misprints)
        self.used = set()


def whole(phrase):
    return re.compile(r"(?<![\w])" + re.escape(phrase) + r"(?![\w])")


def sic_notes(text, ctx):
    out = []
    for n, m in enumerate(ctx.misprints):
        for hit in whole(m["printed"]).finditer(text):
            ctx.hits[n] += 1
            cite = ref(m["cite"], ctx.index) if m.get("cite") else None
            if m.get("cite") and cite is None:
                sys.exit(f"misprint {m['printed']!r} in introduction {ctx.id}: its cite {m['cite']} is no stored verse")
            out.append({"span": [hit.start(), hit.end()], "intended": m["intended"], "ref": cite})
    return out


def piece(raw, index, where, problems, ctx=None):
    """{text, refs[, sic]} of one block's (or cell's) markup."""
    ctx = ctx or Context("", Fixes(), index)
    text, found, _ = nn.convert(raw)
    sic = sic_notes(text, ctx)
    refs = []
    for x, s, e in found:
        if (ctx.id, x) in ctx.citations:
            ctx.used.add((ctx.id, x))
            x = ctx.citations[(ctx.id, x)]
            if x is None:
                continue
        r = ref(x, index)
        if r is not None:
            refs.append({**r, "span": [s, e]})
        elif not any(n["span"][0] <= s and e <= n["span"][1] for n in sic):
            problems.append((where, "unresolved-citation", f"{x} {text[s:e]}"))
    return {"text": text, "refs": refs, **({"sic": sic} if sic else {})}


def blocks(markup, index, where, problems, ctx=None):
    out = []
    for m in BLOCK.finditer(markup):
        tag = m["tag"]
        if tag == "table":
            for tr in re.findall(r"<tr\b[^>]*>(.*?)</tr>", m["inner"], re.S):
                out.append({"type": "row", "cells": [piece(c, index, where, problems, ctx) for c in CELL.findall(tr)]})
        elif tag in KINDS:
            kind, level = KINDS[tag]
            out.append({"type": kind, **({"level": level} if level else {}), **piece(m["inner"], index, where, problems, ctx)})
        else:
            problems.append((where, "unknown-block", tag))
    return out


def require(found, source):
    if not found:
        sys.exit(f"no source files found under {source}: nothing written")
    return found


def load_citation_fixes(text):
    """{(introduction id, xref id as printed): corrected xref id, or None for no link}, from TSV lines of
    introduction id, xref id, correction ("-" = not a link), reason."""
    out = {}
    for line in text.splitlines():
        if line.strip() and not line.startswith("#"):
            id_, cited, corrected = line.split("\t")[:3]
            out[(id_, cited)] = None if corrected == "-" else corrected
    return out


def checked(intro, ctx):
    for m, hits in zip(ctx.misprints, ctx.hits):
        if hits != 1:
            sys.exit(f"misprint {m['printed']!r} occurs {hits} times as whole words in introduction {ctx.id}: make it unique")
    return intro


def nabre(source, index, problems, fixes=Fixes()):
    out, served, used = [], set(), set()
    for path in require(sorted(Path(source).glob("nab-*.scml")), source):
        m = FILE.match(path.name)
        if not m or m[1] == "00":
            continue
        scml = nn.read_scml(path)
        if m[2]:
            if m[1] + m[2] not in GENERAL:
                sys.exit(f"{path.name}: a general introduction not in GENERAL; add the books it introduces")
            id_, books = GENERAL[m[1] + m[2]]
            title, markup = general_segment(scml)
            ctx = Context(id_, fixes, index)
            out.append(checked(Introduction(id_, title, list(books), True, blocks(markup, index, path.name, problems, ctx)), ctx))
            used |= ctx.used
            continue
        book = nn.nab_book(int(m[1]))
        title, markup, joint = book_segment(scml)
        if not markup:
            if book not in served:
                problems.append((path.name, "no-introduction", ""))
            continue
        books = [book, book + 1] if joint else [book]
        served.update(books)
        ctx = Context(str(book), fixes, index)
        out.append(checked(Introduction(str(book), title, books, False, blocks(markup, index, path.name, problems, ctx)), ctx))
        used |= ctx.used
    ids = {i.id for i in out}
    if stray := [m["printed"] for m in fixes.misprints if m["introduction"] not in ids]:
        sys.exit(f"misprints recorded for introductions that do not exist: {stray}")
    if unused := sorted(set(fixes.citations) - used):
        sys.exit(f"citation corrections that match no citation: {unused}")
    # Print order: a general introduction before the books it opens, the wider one first.
    return sorted(out, key=lambda i: (i.books[0], not i.general, -len(i.books)))


def to_sql(edition, intros):
    rows = [f"({lit(edition)}, {lit(i.id)}, {n}, {lit(i.title)}, '{{{','.join(map(str, i.books))}}}'::int[], "
            f"{'true' if i.general else 'false'}, {lit(json.dumps(i.blocks, ensure_ascii=False))}::jsonb)"
            for n, i in enumerate(intros, 1)]
    head = "INSERT INTO book_introduction (edition, id, ord, title, books, general, blocks) VALUES\n"
    parts = ["BEGIN;", f"-- {edition} introductions for endpoint migration 071 (private data half; generated by "
                       "endpoint scripts/introductions.py).", f"DELETE FROM book_introduction WHERE edition = {lit(edition)};"]
    parts += [head + ",\n".join(c) + ";" for c in chunks(rows, 20)]
    return "\n".join(parts + ["COMMIT;"]) + "\n"


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("edition", choices=["nabre"])
    for name in ("--source", "--dump", "--out", "--report"):
        ap.add_argument(name, required=True)
    ap.add_argument("--misprints", help="JSON of verified misprints: {misprints: [{introduction, printed, intended, cite?, verified}]}")
    ap.add_argument("--citations", help="TSV of corrected citation links: introduction id, xref id, corrected id or -, reason")
    args = ap.parse_args(argv)
    require(sorted(Path(args.source).glob("nab-*.scml")), args.source)
    problems = []
    fixes = Fixes(json.loads(Path(args.misprints).read_text(encoding="utf-8"))["misprints"] if args.misprints else [],
                  load_citation_fixes(Path(args.citations).read_text(encoding="utf-8")) if args.citations else {})
    for m in fixes.misprints:
        if not {"introduction", "printed", "intended", "verified"} <= m.keys():
            sys.exit(f"a misprint needs introduction, printed, intended and verified: {m}")
    intros = nabre(args.source, nn.index_rows(nn.load_dump(args.dump)), problems, fixes)
    if not intros:
        sys.exit(f"no introductions found under {args.source}: nothing written")
    Path(args.out).write_text(to_sql(args.edition.upper(), intros), encoding="utf-8")
    Path(args.report).write_text("file\tproblem\tdetail\n" + "".join("\t".join(p) + "\n" for p in problems), encoding="utf-8")
    cites = sum(len(b.get("refs", [])) + sum(len(c["refs"]) for c in b.get("cells", [])) for i in intros for b in i.blocks)
    print(f"introductions {len(intros)} ({sum(i.general for i in intros)} general), blocks "
          f"{sum(len(i.blocks) for i in intros)}, citations {cites}, problems {len(problems)}", file=sys.stderr)


if __name__ == "__main__":
    main()
