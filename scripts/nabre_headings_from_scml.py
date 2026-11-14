#!/usr/bin/env python3
"""Generate migrations/029-nabre-section-headings.sql from the NABRE SCML sources.

The NABRE import (2015) converted the publisher's SCML files -- a streaming,
word-processing style markup, one file per book (nab-01-gn.scml ...
nab-74-rev.scml) -- to structured XML and dropped footnotes and headings on
the way. The SCML files (with the DTD, CSS, HTML and Word editions and the
2015 conversion pipeline) are archived in the private repository
BibleGet-I-O/NABRE-source, under scml/. They are copyrighted (USCCB/CCD) and
are not committed here: pass that directory with --scml.

Headings recovered, in document order:
    <bksect>, <bksubsect>   book divisions ("I. THE STORY OF THE NATIONS")
    <chsect>, <chsubsect>   section headings ("Tobit's Prayer for Death"), and
                            in the Psalms stanza numerals ("I", "II") and
                            acrostic letters ("Aleph")
    <cs>                    psalm subtitles ("The Lord, Shepherd and Host")
    <pc>                    1 Jn 1 "The Word of Life"
    <p><b>Run-in.</b> ...   run-in section headings at the start of a
                            paragraph ("The Story of Creation."; final period
                            dropped)
<ch> ("CHAPTER 1", "PSALM 23") and <bk> (book titles) are not verse headings.
Footnote/endnote markers and blocks (<fnref>, <enref>, <fn>, <en>) are
removed first.

Headings that come right before a verse start (any element whose id is
BBCCCVVV with VVV > 0) become that verse's title1..title3 in order. A heading
followed by more text of the current verse (Ps 23:1 "A psalm of David." /
"I" / "The LORD is my shepherd"; Tob 3:11 "...she implored favor:" / "Sarah's
Prayer for Death" / prayer) is a mid-verse heading: title_position points at
the start of the line in our stored text that the following text begins.
A verse with headings both before it and inside it keeps the ones before it
(one title_position per verse, see #177); those are reported.

NAB book ids skip 35, so NAB 1-34 are BibleGet books 1-34 and NAB 36-74 are
35-73. Rows are matched by (book, chapter, verse), first row of the verse;
updates are keyed on identity, need empty titles, and are guarded on
text_plain (idempotent; no text changes, so embeddings are unaffected).

Usage:
    python3 scripts/nabre_headings_from_scml.py --scml ../NABRE-source/scml --dump nabre.copy \\
        > migrations/029-nabre-section-headings.sql
with nabre.copy from:
    \\copy (SELECT "verseID", book, chapter, verse, coalesce(verseequiv,''),
            coalesce(verseorigin,''), text, text_plain FROM "NABRE"
            ORDER BY "verseID") TO nabre.copy
"""

import argparse
import html
import re
import sys
from pathlib import Path

HEADING_TAGS = ("bksect", "bksubsect", "chsect", "chsubsect", "cs", "pc")
TITLE_MAX = 255
INLINE_TAGS = r"</?(sm|i|sup)>"


def nab_to_book(nab):
    return nab if nab <= 34 else nab - 1


def clean(fragment):
    text = re.sub(r"<[^>]+>", "", fragment)
    return re.sub(r"\s+", " ", html.unescape(text)).strip()


def strip_notes(s):
    s = re.sub(r"<(fn|en|fnp|fncon)\b[^>]*>.*?</\1>", "", s, flags=re.S)
    s = re.sub(r"<(fnref|enref)\b[^>]*>.*?</\1>", "", s, flags=re.S)
    return s


TOKEN = re.compile(
    r"<ch\s+id=\"(?P<ch>\d{8})\"[^>]*>.*?</ch>"
    r"|<(?P<htag>" + "|".join(HEADING_TAGS) + r")\b[^>]*>(?P<head>.*?)</(?P=htag)>"
    r"|<(?P<para>p|pf|pl|pcon)>\s*<b>(?P<runin>.*?)</b>"
    r"|<(?P<vtag>[a-z0-9]+)\s+id=\"(?P<vid>\d{8}[a-z]?)\"[^>]*>"
    r"|<bk>.*?</bk>|<bks>.*?</bks>|<bcv>.*?</bcv>"
    r"|<[^>]+>"
    r"|(?P<text>[^<]+)",
    re.S,
)


def scan_book(s):
    """Yield (verse id, headings, following text): the headings, the verse in
    effect when the next text appears, and that text (to place them)."""
    pending, current, started = [], None, False
    for m in TOKEN.finditer(strip_notes(s)):
        if m["ch"]:
            started, current = True, None
        elif not started and m["htag"] != "bksect":
            continue  # the book's introduction (its outline uses <nl>, not headings)
        elif m["htag"]:
            started = True
            pending.append(clean(m["head"]))
        elif m["para"]:
            pending.append(clean(m["runin"]).rstrip("."))
        elif m["vid"] and m["vtag"] != "xref" and not m["vid"][:8].endswith("000"):
            current = m["vid"]
        elif m["text"] is not None and m["text"].strip() and pending and current:
            yield current, pending, clean(m["text"])
            pending = []
    if pending:
        yield current, pending, None


# --------------------------------------------------------------- database

def copy_unescape(s):
    return re.sub(r"\\(.)", lambda m: {"n": "\n", "t": "\t", "\\": "\\"}.get(m[1], m[1]), s)


def load_dump(path):
    """Rows by (book, chapter, verse, verseequiv), plus their order."""
    rows, order = {}, []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            f = [copy_unescape(x) for x in line.rstrip("\n").split("\t")]
            key = (int(f[1]), int(f[2]), int(f[3]), f[4])
            row = {"vid": int(f[0]), "key": key, "equiv": f[4], "origin": f[5], "text": f[6], "plain": f[7]}
            rows.setdefault(key, row)
            order.append(row)
    return rows, order


# NAB numbers Esther's Greek additions A-F as chapters 41-46; NABRE stores
# them as lettered sub-verses after the verse they follow.
ESTHER_ADDITIONS = {41: (1, 1), 42: (3, 13), 43: (4, 17), 44: (5, 1), 45: (8, 12), 46: (10, 3)}


def target_keys(vid):
    """Candidate (book, chapter, verse, verseequiv) keys for a NAB id."""
    book, chapter, verse, letter = nab_to_book(int(vid[:2])), int(vid[2:5]), int(vid[5:8]), vid[8:]
    if book == 19 and chapter in ESTHER_ADDITIONS:
        ch, v = ESTHER_ADDITIONS[chapter]
        letters = "abcdefghiklmnopqrstuvwxyz"  # NABRE skips "j"
        return [(19, ch, v, f"{v}{letters[verse - 1]}")] if verse <= len(letters) else []
    keys = [(book, chapter, verse, f"{verse}{letter}")] if letter else []
    return keys + [(book, chapter, verse, "")]


def find_row(rows, order, vid):
    for key in target_keys(vid):
        if key in rows:
            return rows[key]
    # No such row (e.g. NABRE has no Num 25:19): the next row in reading order.
    book, chapter, verse = target_keys(vid)[-1][:3]
    following = [r for r in order if r["key"][:3] > (book, chapter, verse) and r["key"][0] == book]
    return following[0] if following else None


def visible(fragment):
    return re.sub(r"<[^>]*>", " ", re.sub(INLINE_TAGS, "", fragment))


def valid_title_position(text, pos):
    """Python mirror of bibleget_title_position_ok() (migration 019)."""
    if pos is None:
        return True
    if not 0 < pos < len(text) or re.search(r"<[^>]*$", text[:pos]):
        return False
    return not (visible(text[:pos])[-1:].isalnum() and visible(text[pos:])[:1].isalnum())


def place(text, following):
    """(found, title_position): position None = before the verse, else the
    offset of the line of `text` that begins with `following`."""
    probe = re.sub(r"\s+", " ", following or "").strip()[:20]
    offset = 0
    for line in text.split("\n"):
        if re.sub(r"\s+", " ", visible(line)).strip().startswith(probe):
            return True, (offset if offset > 0 else None)
        offset += len(line) + 1
    return False, None


def lit(value):
    return "NULL" if value is None else "'" + str(value).replace("'", "''") + "'"


def collect(events, rows, order, report):
    """{row key: (row, headings, title_position)}."""
    placed = {}
    for vid, heads, following in events:
        row = find_row(rows, order, vid) if vid else None
        if row is None or following is None:
            report.append(f"{vid}: no verse to attach {heads}")
            continue
        found, position = place(row["text"], following)
        if not found:
            # The computed row is not it (Esther's additions use their own
            # verse/letter scheme): the row of this book holding that line.
            row, found, position = search_book(order, row["key"][0], following)
        if not found:
            report.append(f"{vid}: line '{following[:30]}' not found for {heads}")
            continue
        merge_placement(placed, row, heads, position, report)
    return placed


def search_book(order, book, following):
    for row in order:
        if row["key"][0] == book:
            found, position = place(row["text"], following)
            if found:
                return row, True, position
    return None, False, None


notes = []  # headings applied, but moved to before the verse


def merge_placement(placed, row, heads, position, report):
    """A verse with headings before it and inside it (Ps 23:1: subtitle, then
    the stanza numeral "I" after the superscription) keeps one position: the
    inner ones join the headings before the verse (see #177)."""
    key = row["key"]
    if key not in placed:
        placed[key] = (row, list(heads), position)
        return
    _, old_heads, old_position = placed[key]
    if old_position is None and position is not None:
        notes.append(f"{key}: {heads} placed before the verse (it already has headings there)")
        placed[key] = (row, old_heads + list(heads), None)
    elif old_position is not None and position is None:
        notes.append(f"{key}: {old_heads} placed before the verse (it also has headings there)")
        placed[key] = (row, list(heads) + old_heads, None)
    else:
        placed[key] = (row, old_heads + list(heads), old_position)


def build_updates(placed, report):
    updates = []
    for key, (row, heads, position) in sorted(placed.items()):
        if len(heads) > 3 or any(len(h) > TITLE_MAX for h in heads):
            report.append(f"{key}: too many or too long headings {heads}")
            continue
        if not valid_title_position(row["text"], position):
            report.append(f"{key}: title_position {position} fails the guard")
            continue
        t = (heads + ["", "", ""])[:3]
        updates.append(
            f'UPDATE "NABRE" SET title1 = {lit(t[0])}, title2 = {lit(t[1])}, title3 = {lit(t[2])}, '
            f"title_position = {lit(position)} WHERE book = {key[0]} AND chapter = {key[1]} AND verse = {key[2]} "
            f"AND coalesce(verseequiv, '') = {lit(row['equiv'])} AND coalesce(verseorigin, '') = {lit(row['origin'])} "
            f"AND text_plain = {lit(row['plain'])} AND coalesce(title1, '') = '' AND coalesce(title2, '') = '' "
            f"AND coalesce(title3, '') = '';  -- {key[0]} {key[1]}:{key[3] or key[2]}")
    return updates


HEADER = """-- Migration: NABRE section headings from the publisher's SCML sources
--
-- Generated by scripts/nabre_headings_from_scml.py (see its docstring). The 2015
-- import dropped every heading; this restores {n} verses' title1-title3
-- ({mid} with a mid-verse title_position). Titles only: text, text_plain and
-- embeddings are unchanged. Each UPDATE is keyed on the verse's identity, needs
-- empty titles and the expected text_plain (idempotent). Requires 028 (Num 25:19).
--
-- {moved} psalms have a subtitle before verse 1 and a stanza numeral ("I") or
-- acrostic letter after its superscription: one title_position per verse (#177),
-- so the numeral is stored with the headings before the verse.
-- Not applied: {skipped}

BEGIN;

"""


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--scml", required=True, help="directory with nab-NN-*.scml")
    ap.add_argument("--dump", required=True, help="COPY dump of NABRE (see docstring)")
    args = ap.parse_args()
    rows, order = load_dump(args.dump)
    events = []
    for path in sorted(Path(args.scml).glob("nab-*.scml")):
        events.extend(scan_book(path.read_text(encoding="utf-8", errors="replace")))
    report = []
    updates = build_updates(collect(events, rows, order, report), report)
    mid = sum("title_position = NULL" not in u for u in updates)
    skipped = "; ".join(report) if report else "none"
    sys.stdout.write(HEADER.format(n=len(updates), mid=mid, moved=len(notes), skipped=skipped.replace("\n", " ")))
    sys.stdout.write("\n".join(updates) + "\n\nCOMMIT;\n")
    print(f"{len(updates)} updates ({mid} mid-verse); {len(report)} not applied:", file=sys.stderr)
    for line in report:
        print("  " + line, file=sys.stderr)
    print(f"{len(notes)} verses with inner headings moved before the verse:", file=sys.stderr)
    for line in notes:
        print("  " + line, file=sys.stderr)


if __name__ == "__main__":
    main()
