#!/usr/bin/env python3
"""NABRE's footnotes and cross-references, from the publisher's SCML, as a private data half for migration 064.

The SCML files (one per book, nab-01-gn.scml ... nab-74-rev.scml) are in the private repository
BibleGet-I-O/NABRE-source, under scml/; they and this script's outputs are copyrighted (CCD/USCCB) and are
never committed here. This script holds no NABRE text.

What it reads:
    <fnref id="BBCCCVVV-n">*</fnref> / <fn id="...">     a footnote and its body (<fnp> continues it)
    <enref id="BBCCCVVV-x">x</enref> / <en id="...">     a cross-reference note (lettered)
    <xref id="BBCCCVVV">...</xref>                        a citation inside a body (also vA-vB ranges)
Where a marker goes (design: docs/superpowers/specs/2026-11-23-footnotes-design.md, Part 2):
    in a verse          the visible text before it in that verse, matched against the start of our stored
                        text (quotes, dashes, entities and whitespace normalised alike), gives a position in
                        code points into the stored `text`, tags included (as title_position)
    in a heading        (<chsect>, <cs>, run-in <p><b>...</b> ...) the heading is the next verse's
                        title1..3; the marker is placed within it
    in <ch>             a chapter heading ("PSALM 1"): field 'chapter' on the chapter's first verse
    in an introduction  not stored (book introductions are out of scope), reported
A marker that cannot be placed is stored on the verse as a whole (position NULL) and reported; an
overrides file (TSV: source_id, field, position) places it by hand. Nothing is dropped silently.

Usage:
    python3 scripts/notes_nabre.py --scml ../NABRE-source/scml --dump nabre.copy \\
        [--overrides ../NABRE-source/notes-overrides.tsv] \\
        [--citations ../NABRE-source/notes-citations.tsv] \\
        --out ../NABRE-source/migrations/064-nabre-notes.sql --report ../NABRE-source/reports/nabre-notes.tsv
with nabre.copy from:
    \\copy (SELECT sort_order, book, chapter, verse, coalesce(verse_segment,''), coalesce(text_tradition,''),
            coalesce(printed_chapter::text,''), coalesce(verse_label,''), text, coalesce(title1,''),
            coalesce(title2,''), coalesce(title3,'') FROM "NABRE" ORDER BY sort_order) TO nabre.copy
"""

import argparse
import codecs
import html
import re
import sys
import unicodedata
from collections import namedtuple
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import notes_sql  # noqa: E402

EDITION = "NABRE"
TITLES = ("title1", "title2", "title3")
# NAB numbers Esther's Greek additions A-F as chapters 41-46, verses 1, 2, 3...; NABRE stores them as the Greek
# text's lettered verses after the verse they follow, with letters that skip "j" and "w" (4:17x follows 4:17v;
# 4:17aa follows 4:17z). Addition D runs through 5:1, 5:1a-k, 5:2, 5:2a-d. So NAB verse n is the n-th row of its
# addition's run, read from our own rows: (chapter, verse, whether the unlettered verse itself belongs to it).
ESTHER_ADDITIONS = {41: [(1, 1, False)], 42: [(3, 13, False)], 43: [(4, 17, False)],
                    44: [(5, 1, True), (5, 2, True)], 45: [(8, 12, False)], 46: [(10, 3, False)]}

# `inside`: (verse id, text so far) of the verse a heading may interrupt, for a marker printed right after it.
Event = namedtuple("Event", "place source_id kind marker vid prefix heading inside", defaults=(None,))
Anchor = namedtuple("Anchor", "source_id kind marker ident field position reason")
Note = namedtuple("Note", "kind marker label body refs dropped")


# ------------------------------------------------------------------ normalisation and positions

FOLD = {"‘": "'", "’": "'", "“": '"', "”": '"', "–": "-", "—": "-", " ": " "}


def fold_char(c):
    c = FOLD.get(c) or c
    return "" if c.isspace() else c


def norm(s):
    s = unicodedata.normalize("NFC", html.unescape(s))
    return "".join(fold_char(c) for c in s)


def visible(tagged):
    return re.sub(r"<[^>]*>", "", tagged)


LEAD = re.compile(r"(?:<speaker>[^<]*</speaker>|<[a-z0-9]+>)*")
TAG_AT = re.compile(r"<speaker>[^<]*</speaker>|<[^>]*>")
# After the matched text the marker leaves an inline tag it closes (<i>, <sm>, <sup>), but stays inside its line
# (a line tag's closing </pof> follows it), so the reader keeps it on that line.
CLOSING = re.compile(r"(?:</(?:i|sm|sup)>)*")


def find(tagged, prefix):
    """The code-point offset in `tagged` just after the visible text `prefix` (normalised), past inline closing tags; at
    the very start, after the opening tags and a speaker label. None when the field does not begin with it."""
    want = norm(prefix)
    if not want:
        return LEAD.match(tagged).end()
    got, i = "", 0
    while i < len(tagged):
        tag = TAG_AT.match(tagged, i) if tagged[i] == "<" else None
        if tag:
            i = tag.end()
            continue
        got += fold_char(tagged[i])
        i += 1
        if got == want:
            return CLOSING.match(tagged, i).end()
        if not want.startswith(got):
            return None
    return None


# ------------------------------------------------------------------ our rows

def unescape_copy(s):
    return re.sub(r"\\(.)", lambda m: {"n": "\n", "t": "\t", "\\": "\\"}.get(m[1], m[1]), s)


def load_dump(path):
    rows = []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            f = [unescape_copy(x) for x in line.rstrip("\n").split("\t")]
            book, chapter, verse = int(f[1]), int(f[2]), int(f[3])
            rows.append({"ident": (book, chapter, verse, f[4], f[5], f[6]), "key": (book, chapter, verse, f[4]),
                         "label": f[7], "fields": {"text": f[8], "title1": f[9], "title2": f[10], "title3": f[11]}})
    return rows


def greek(rows):
    return [r for r in rows if r["ident"][4] == "GREEK"]


class Index:
    def __init__(self, rows):
        self.by_key, self.first, lettered = {}, {}, {}
        for r in rows:
            self.by_key.setdefault(r["key"], []).append(r)
            self.first.setdefault(r["key"][:2], r)
            if r["key"][0] == 19 and r["key"][3]:
                lettered.setdefault(r["key"][1:3], []).append(r)
        self.esther = {nab: [row for chapter, verse, whole in runs
                             for row in greek(self.by_key.get((19, chapter, verse, ""), []))[:1 if whole else 0]
                             + lettered.get((chapter, verse), [])]
                       for nab, runs in ESTHER_ADDITIONS.items()}


def index_rows(rows):
    return Index(rows)


def nab_book(nab):
    return nab if nab <= 34 else nab - 1


def keys_for(vid):
    """Candidate row keys (book, chapter, verse, segment) for a NAB verse id (not an Esther addition)."""
    book, chapter, verse, letter = nab_book(int(vid[:2])), int(vid[2:5]), int(vid[5:8]), vid[8:]
    return ([(book, chapter, verse, letter)] if letter else []) + [(book, chapter, verse, "")]


def rows_for(vid, index):
    """Our rows for a NAB verse id: every text tradition of it, or an Esther addition's one Greek row."""
    if vid[:2] == "19" and int(vid[2:5]) in ESTHER_ADDITIONS:
        run, n = index.esther[int(vid[2:5])], int(vid[5:8])
        return [run[n - 1]] if 0 < n <= len(run) else []
    for key in keys_for(vid):
        if key in index.by_key:
            return index.by_key[key]
    return []


# ------------------------------------------------------------------ scanning the SCML for markers

TOKEN = re.compile(
    r"<(?P<note>fn|en|fnp|fncon)\b[^>]*>.*?</(?P=note)>"
    r"|<ch\s+id=\"(?P<ch>\d{8})\"[^>]*>(?P<chin>.*?)</ch>"
    r"|<(?P<htag>bksect|bksubsect|chsect|chsubsect|cs|pc)\b[^>]*>(?P<head>.*?)</(?P=htag)>"
    r"|<(?P<para>p|pf|pl|pcon)>\s*<b>(?P<runin>.*?)</b>"
    r"|<(?P<ref>fnref|enref)\s+id=\"(?P<rid>[^\"]+)\"[^>]*>(?P<mark>.*?)</(?P=ref)>"
    r"|<(?P<skip>bk|bks|bcv|speaker)\b[^>]*>.*?</(?P=skip)>"
    r"|<(?P<vtag>[a-z0-9]+)\s+id=\"(?P<vid>\d{8}[a-z]?)\"[^>]*>"
    r"|<[^>]+>"
    r"|(?P<text>[^<]+)",
    re.S,
)
REF = re.compile(r"<(fnref|enref)\s+id=\"([^\"]+)\"[^>]*>(.*?)</\1>", re.S)


def kind_of(ref_tag):
    return "footnote" if ref_tag == "fnref" else "crossref"


def refs_in(fragment):
    """(source_id, kind, marker, visible text before it) for each marker inside a heading."""
    return [(m[2], kind_of(m[1]), visible(m[3]).strip(), visible(fragment[:m.start()]))
            for m in REF.finditer(fragment)]


class Scanner:
    def __init__(self):
        self.events, self.pending, self.current, self.prefix, self.started = [], [], None, "", False
        self.after = None  # the heading just printed, until a verse or text follows: a marker here is the heading's

    def chapter(self, m):
        self.started, self.current, self.after = True, None, None
        for sid, kind, mark, _ in refs_in(m["chin"]):
            self.events.append(Event("chapter", sid, kind, mark, m["ch"], "", ""))

    def heading(self, inner):
        heading = self.after = visible(REF.sub("", inner))
        for sid, kind, mark, before in refs_in(inner):
            self.pending.append(Event("title", sid, kind, mark, None, before, heading))

    def verse(self, vid):
        # A verse id ends the book's introduction (which has none).
        self.current, self.prefix, self.started, self.after = vid, "", True, None
        self.events.extend(e._replace(vid=vid) for e in self.pending)
        self.pending = []

    def marker(self, m):
        if self.after is not None:
            self.pending.append(Event("title", m["rid"], kind_of(m["ref"]), visible(m["mark"]).strip(), None,
                                      self.after.rstrip(". "), self.after,
                                      (self.current, self.prefix) if self.current else None))
            return
        place = "text" if self.current and self.started else "intro"
        self.events.append(Event(place, m["rid"], kind_of(m["ref"]), visible(m["mark"]).strip(),
                                 self.current, self.prefix, ""))

    def words(self, text):
        if text.strip():
            self.after = None
        if self.current:
            self.prefix += text

    def feed(self, m):
        if m["ch"]:
            self.chapter(m)
        elif m["htag"]:
            self.heading(m["head"])
        elif m["para"]:
            self.heading(m["runin"])
        elif m["ref"]:
            self.marker(m)
        elif m["vid"] and m["vtag"] != "xref" and not m["vid"][:8].endswith("000"):
            self.verse(m["vid"])
        elif m["text"] is not None:
            self.words(m["text"])


def scan_book(scml, nab=None):
    """The markers of one book's SCML, in document order (`nab`, the NAB book number, is for messages only)."""
    s = Scanner()
    for m in TOKEN.finditer(scml):
        if not m["note"]:
            s.feed(m)
    # No verse follows: a heading inside the last verse is that verse's; before any verse, an introduction's.
    s.events.extend(e._replace(vid=e.inside[0]) if e.inside else e._replace(place="intro") for e in s.pending)
    return s.events


# ------------------------------------------------------------------ placing the markers

def anchor(e, r, field, position, reason=""):
    return Anchor(e.source_id, e.kind, e.marker, r["ident"], field, position, reason)


def place_text(e, rows):
    found = [anchor(e, r, "text", p) for r in rows if (p := find(r["fields"]["text"], e.prefix)) is not None]
    return found or [anchor(e, rows[0], "text", None, "no-match")]


def heading_field(r, heading):
    want = norm(heading).rstrip(".")
    for f in TITLES:
        if want and norm(visible(r["fields"][f])).rstrip(".") == want:
            return f
    return None


def on_titles(e, rows):
    found = []
    for r in rows:
        f = heading_field(r, e.heading)
        if f:
            p = find(r["fields"][f], e.prefix)
            found.append(anchor(e, r, f, p, "" if p is not None else "no-match"))
    return found


def place_title(e, rows, index):
    """On the heading, stored on the verse after it or on the one it interrupts; else where the words before it end."""
    found = on_titles(e, rows)
    if not found and e.inside:
        held = rows_for(e.inside[0], index)
        found = on_titles(e, held) or (place_text(e._replace(prefix=e.inside[1]), held) if held else [])
    return found or [anchor(e, rows[0], "chapter", None, "heading-not-imported")]


def place_event(e, index, problems):
    if e.place == "intro":
        problems.append((e.source_id, e.kind, e.vid or "", "introduction", ""))
        return []
    if e.place == "chapter":
        r = index.first.get((nab_book(int(e.vid[:2])), int(e.vid[2:5])))
        return [anchor(e, r, "chapter", None)] if r else []
    rows = rows_for(e.vid, index) or (rows_for(e.inside[0], index) if e.inside else [])
    if not rows:
        problems.append((e.source_id, e.kind, e.vid, "no-row", e.prefix[-40:]))
        return []
    return place_text(e, rows) if e.place == "text" else place_title(e, rows, index)


def place_all(events, index, overrides, problems=None):
    problems = [] if problems is None else problems
    out = []
    for e in events:
        for a in place_event(e, index, problems):
            if a.reason and a.source_id in overrides:
                field, position = overrides[a.source_id]
                a = a._replace(field=field, position=position, reason="override")
            out.append(a)
    return out


# ------------------------------------------------------------------ note bodies

BODY_TOKEN = re.compile(r"<xref\s+id=\"(?P<x>[^\"]+)\"[^>]*>(?P<xin>.*?)</xref>|<(?P<close>/?)(?P<tag>[a-z0-9]+)\b[^>]*>"
                        r"|(?P<text>[^<]+)", re.S)
OPEN = {"ver": "<i>", "i": "<i>", "b": "<b>", "sm": "<sm>", "smver": "<sm>", "smfn": "<sm>", "smfni": "<i><sm>",
        "sup": "<sup>"}
SHUT = {"ver": "</i>", "i": "</i>", "b": "</b>", "sm": "</sm>", "smver": "</sm>", "smfn": "</sm>",
        "smfni": "</sm></i>", "sup": "</sup>"}


def flatten(s):
    return re.sub(r"\s+", " ", html.unescape(s))


def convert(raw):
    """(body with allowed tags only, [(xref id, start, end)] in code points into the body, dropped tag names)."""
    out, refs, dropped = "", [], []
    for m in BODY_TOKEN.finditer(raw):
        if m["x"]:
            text = flatten(visible(m["xin"]))
            refs.append((m["x"], len(out), len(out) + len(text)))
            out += text
        elif m["tag"]:
            table = SHUT if m["close"] else OPEN
            if m["tag"] in table:
                out += table[m["tag"]]
            elif not m["close"]:
                dropped.append(m["tag"])
        else:
            out += flatten(m["text"])
    lead = len(out) - len(out.lstrip())
    return out.strip(), [(x, s - lead, e - lead) for x, s, e in refs], dropped


HEAD = re.compile(r"\s*(?P<mark>\S+?)\.?\s*\[(?P<label>.*?)\]\s*(?P<body>.*)\Z", re.S)
NOTE = re.compile(r"<(?P<tag>fn|en|fnp|fncon)\b(?:\s+id=\"(?P<id>[^\"]+)\")?[^>]*>(?P<inner>.*?)</(?P=tag)>", re.S)


def parse_notes(scml):
    notes, last = {}, None
    for m in NOTE.finditer(scml):
        if m["tag"] in ("fnp", "fncon"):
            if last in notes:
                n = notes[last]
                body, refs, dropped = convert(m["inner"])
                shift = len(n.body) + 1
                notes[last] = n._replace(body=n.body + "\n" + body, dropped=n.dropped + dropped,
                                         refs=n.refs + [(x, s + shift, e + shift) for x, s, e in refs])
            continue
        head = HEAD.match(m["inner"])
        mark, label, raw = (head["mark"], flatten(visible(head["label"])).strip(), head["body"]) if head else ("", None, m["inner"])
        body, refs, dropped = convert(raw)
        kind = "footnote" if m["tag"] == "fn" else "crossref"
        if m["id"] in notes:
            sys.exit(f"note id used twice in one file: {m['id']}")
        notes[m["id"]] = Note(kind, mark, label, body, refs, dropped)
        last = m["id"]
    return notes


# ------------------------------------------------------------------ citations

def resolve_ref(xref_id, index):
    """((book, chapter, verse or None, segment, printed chapter), (end book, chapter, verse) or None), or None."""
    parts = xref_id.replace("v", "").split("-")
    start = parts[0]
    if not re.fullmatch(r"\d{8}[a-z]?", start):
        return None
    if start[5:8] == "000":
        book, chapter = nab_book(int(start[:2])), int(start[2:5])
        return ((book, chapter, None, "", ""), None) if (book, chapter) in index.first else None
    rows = rows_for(start, index)
    if not rows:
        return None
    b, c, v, seg, _, pc = rows[0]["ident"]
    end = None
    if len(parts) > 1 and re.fullmatch(r"\d{8}[a-z]?", parts[1]):
        end_rows = rows_for(parts[1], index)
        end = end_rows[0]["ident"][:3] if end_rows else None
    return (b, c, v, seg, pc), end


# ------------------------------------------------------------------ output

def ref_rows(used, index, problems):
    rows = []
    for sid, n in used:
        for ord_, (x, s, e) in enumerate(n.refs, 1):
            r = resolve_ref(x, index)
            if r is None:
                problems.append((sid, n.kind, x, "unresolved-citation", n.body[s:e]))
                continue
            start, end = r
            rows.append((sid, ord_, start, end, s, e))
    return rows


def to_sql(notes, anchors, index, problems=None):
    problems = [] if problems is None else problems
    anchored = {a.source_id for a in anchors}
    used = [(sid, n) for sid, n in sorted(notes.items()) if sid in anchored]
    return notes_sql.data_half(
        EDITION,
        "NABRE notes for migration 064 (private data half; generated by endpoint scripts/notes_nabre.py).",
        [(sid, n.kind, n.marker, n.label, None, n.body) for sid, n in used],
        [(a.source_id, a.ident, a.field, a.position, None) for a in anchors],
        ref_rows(used, index, problems),
    )


def report(anchors, problems, notes):
    lines = ["source_id\tkind\twhere\treason\tcontext"]
    lines += [f"{a.source_id}\t{a.kind}\t{a.ident[:4]} {a.field}\t{a.reason}\t" for a in anchors if a.reason]
    lines += ["\t".join(str(x) for x in p) for p in problems]
    lines += [f"{sid}\t{n.kind}\t\tdropped-tags\t{','.join(n.dropped)}" for sid, n in sorted(notes.items()) if n.dropped]
    return "\n".join(lines) + "\n"


def load_overrides(path):
    out = {}
    if path:
        for line in Path(path).read_text(encoding="utf-8").splitlines():
            if line.strip() and not line.startswith("#"):
                sid, field, position = line.split("\t")[:3]
                out[sid] = (field, int(position) if position else None)
    return out


def correct_citations(notes, path):
    """Apply the citations file (notes_sql.load_citations; xref ids) to the notes; returns its unused lines as problems."""
    corrections, used = notes_sql.load_citations(path), set()
    for sid in {sid for sid, _ in corrections} & set(notes):
        refs, hit = notes_sql.correct_refs(sid, notes[sid].refs, corrections, lambda r: r[0], lambda r, x: (x,) + r[1:])
        notes[sid] = notes[sid]._replace(refs=refs)
        used |= hit
    return [(sid, "", x, "unused-correction", "") for sid, x in sorted(set(corrections) - used)]


def _windows1252(error):
    return error.object[error.start:error.end].decode("cp1252"), error.end


codecs.register_error("windows1252", _windows1252)


def read_scml(path):
    """The SCML's text: UTF-8, but for stray Windows-1252 bytes (0x85, the ellipsis, about 800 times)."""
    return Path(path).read_bytes().decode("utf-8", "windows1252")


NOTE_ID = re.compile(r'(<(?:fnref|enref|fn|en)\b[^>]*\bid=")(\d\d)(\d{6}-)')


def own_ids(scml, nab):
    """The SCML with every note id under its own book: the publisher gives Galatians' cross-references Ephesians'
    numbers (28 ids are then used twice), and Hebrews' Philemon's. Daniel's file rightly holds 34 and 35."""
    mine = {"34", "35"} if nab == 34 else {f"{nab:02d}"}
    scml = scml.replace('<en id="33032010-e">', '<en id="33032012-e">')  # Ez 32:12's note, misnumbered
    return NOTE_ID.sub(lambda m: m[0] if m[2] in mine else f"{m[1]}{nab:02d}{m[3]}", scml)


def unpaired(events, notes):
    """The notes no marker names, and the markers whose note the source lacks: neither can be shown."""
    marked = {e.source_id for e in events}
    return ([(sid, "", "", "note-without-marker", "") for sid in sorted(set(notes) - marked)]
            + [(e.source_id, e.kind, e.vid or "", "marker-without-note", "") for e in events if e.source_id not in notes])


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--scml", required=True)
    ap.add_argument("--dump", required=True)
    ap.add_argument("--overrides")
    ap.add_argument("--citations", help="TSV of corrected citations: source_id, xref id, corrected id or -, reason")
    ap.add_argument("--out", required=True)
    ap.add_argument("--report", required=True)
    args = ap.parse_args(argv)
    index = index_rows(load_dump(args.dump))
    events, notes = [], {}
    for path in sorted(Path(args.scml).glob("nab-*.scml")):
        scml = own_ids(read_scml(path), int(path.name[4:6]))
        found = parse_notes(scml)
        if twice := sorted(set(found) & set(notes)):
            sys.exit(f"{path.name}: note ids used in another book too: {twice[:5]}")
        events += scan_book(scml, int(path.name[4:6]))
        notes.update(found)
    if not notes:
        # A data half deletes the edition's notes first: never write one that would leave none.
        sys.exit(f"no notes found under {args.scml}: nothing written")
    problems = unpaired(events, notes) + correct_citations(notes, args.citations)
    anchors = place_all(events, index, load_overrides(args.overrides), problems)
    Path(args.out).write_text(to_sql(notes, anchors, index, problems), encoding="utf-8")
    Path(args.report).write_text(report(anchors, problems, notes), encoding="utf-8")
    exact = sum(1 for a in anchors if not a.reason)
    print(f"notes {len(notes)}, anchors {len(anchors)} (exact {exact}), problems {len(problems)}", file=sys.stderr)


if __name__ == "__main__":
    main()
