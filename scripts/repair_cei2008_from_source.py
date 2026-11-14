#!/usr/bin/env python3
"""Generate the CEI2008 repair migrations from the bibbiaedu.it archive.

    --emit structure  -> migrations/025-cei2008-restore-verses.sql
    --emit wording    -> migrations/026-cei2008-wording-corrections.sql

Both read the same inputs as format_cei2008_from_source.py (whose parser
and formatter they reuse): the private archive BibleGet-I-O/CEI2008-bibbiaedu
and a COPY dump of the CEI2008 table taken *before* 023 (only text_plain and
the row identity are used, and 023 leaves both unchanged).

bibbiaedu.it is the only published digital text of CEI 2008: vatican.va
(archive/ITA0001) and LaParola.net carry the 1974 CEI translation. The 1974
text on vatican.va was used as an independent witness for *structure* (which
verses exist, what each one contains) wherever the site and our table
disagree; wording follows the site.

Structure (024)
---------------
Restores and re-divides verses that the original import lost or merged
(see STRUCTURE below for each case and its evidence), then rebuilds the
table with bibleget_rebuild_in_order() (migration 024) so it is in reading
order again, logically ("verseID") and physically: by book, chapter,
then the existing order within the chapter (which keeps CEI 2008's own
displacements such as Is 10:17, 18a, 19, 18b). This also moves back three
groups of rows that the import had appended after Revelation: Ps 119:82-176,
Rev 15:4 (restored between 15:3 and 15:5) and the whole of Esther (moved
between Judith and 1 Maccabees; its internal order is unchanged). New and
changed rows get the site's formatted text; their embeddings are left NULL
or stale for compute_embeddings.py. CEI2008_idx is recomputed for the
affected books (verses_count = distinct verse numbers, verses_last = max).

Wording (025)
-------------
For every row lined up with a site verse whose wording differs, the site's
wording is adopted -- different words, editorial marks (⌈ ⌉ ⊥), dashes,
spacing -- except where the only difference is a stress accent ("séminano"
vs "seminano"), where ours is kept. The site's layout is applied on top.
Changed rows get a new text_plain/text_hash (the trigger) and need
re-embedding.

Usage:
    python3 scripts/repair_cei2008_from_source.py --archive ../CEI2008-bibbiaedu \\
        --dump cei_before_023.copy --emit structure > migrations/025-cei2008-restore-verses.sql
    python3 scripts/repair_cei2008_from_source.py --archive ../CEI2008-bibbiaedu \\
        --dump cei_before_023.copy --emit wording > migrations/026-cei2008-wording-corrections.sql
"""

import argparse
import difflib
import sys
import unicodedata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import format_cei2008_from_source as F  # noqa: E402


# --------------------------------------------------------------- inputs

class Source:
    """Site chapters (formatted) and table rows, by verseID and by chapter."""

    def __init__(self, archive, dump):
        self.archive = Path(archive, "html")
        self.db = F.load_dump(dump)
        self.rows = {row["vid"]: dict(row, book=key[0], chapter=key[1], label=label)
                     for key, labels in self.db.items() for label, rows in labels.items() for row in rows}
        self._chapters = {}

    def chapter(self, book, chapter):
        """Ordered [(label, (text, titles, position))] of a site chapter."""
        if (book, chapter) not in self._chapters:
            part = "nt" if book > 46 else "at"
            path = self.archive / part / F.SLUGS[book - 1] / f"{chapter:03d}.html"
            self._chapters[(book, chapter)] = F.format_chapter_list(path)
        return self._chapters[(book, chapter)]

    def verse(self, book, chapter, label, occurrence=0):
        """Formatted site text (and titles) of a verse; `occurrence` picks
        among repeated labels (Job 31 has two "38")."""
        hits = [v for lab, v in self.chapter(book, chapter) if lab == label]
        return hits[occurrence]


# --------------------------------------------------------------- structure
#
# Each entry is checked against the dump (the row's current text_plain) when
# the migration is generated, and again by the migration itself.
#   ("set", verseID, site, verse, verseequiv)        replace a row's text/label
#   ("insert", after_verseID, site, book, chapter, verse, verseequiv)
#   ("move", verseID, after_verseID)                 reading-order fix only
# `site` is (book, chapter, label[, occurrence]) or ("text", literal).

STRUCTURE = [
    # 1 Chronicles 1: the import kept 9 rows of fragments from later in the
    # chapter; the site (and vatican.va 1974) have 54 verses.
    *[("set", 10255 + i, (13, 1, str(i + 1)), i + 1, None) for i in range(9)],
    *[("insert", 10263, (13, 1, str(v)), 13, 1, v, None) for v in range(10, 55)],
    # 1 Samuel 2:17 had been appended to 1:17.
    ("set", 7230, (9, 1, "17"), 17, None),
    ("insert", 7257, (9, 2, "17"), 9, 2, 17, None),
    # Numbers 25:19 "Dopo il flagello" (printed at the head of ch. 26) had
    # been stored as a "[25,19 ...]" prefix of 26:1.
    ("insert", 4490, ("text", "Dopo il flagello"), 4, 25, 19, None),
    ("set", 4491, (4, 26, "1"), 1, None),
    # Isaiah 10:16-19: CEI 2008 reads 17, 18a, 19, 18b; the import had
    # glued "b..." and "a..." into one row 18.
    ("set", 21538, (29, 10, "18a"), 18, "18a"),
    ("insert", 21539, (29, 10, "18b"), 29, 10, 18, "18b"),
    # Sirach 28:24-25: CEI 2008 reads 24a, 25b, 24b, 25a.
    ("set", 20692, (28, 28, "24a"), 24, "24a"),
    ("set", 20693, (28, 28, "25b"), 25, "25b"),
    ("insert", 20693, (28, 28, "24b"), 28, 28, 24, "24b"),
    ("insert", 20693, (28, 28, "25a"), 28, 28, 25, "25a"),
    # Sirach 43:15-18: CEI 2008 reads 15, 17a, 16, 17b, 18.
    ("set", 21113, (28, 43, "17a"), 17, "17a"),
    ("set", 21114, (28, 43, "16"), 16, None),
    ("insert", 21114, (28, 43, "17b"), 28, 43, 17, "17b"),
    # Job 31:35-40: CEI 2008 reads 38, 39, 40a, 35, 36, 37, 40b. Our rows were
    # in that order but numbered 35-41 with glued letters; the site's own
    # labels are partly wrong too (36/37/38/39/40 for 39/40a/35/36/37), so the
    # verses are taken by page position and identified by content against
    # vatican.va (1974).
    ("set", 15480, (22, 31, "38", 0), 38, None),
    ("set", 15481, (22, 31, "36"), 39, None),
    ("set", 15482, (22, 31, "37"), 40, "40a"),
    ("set", 15483, (22, 31, "38", 1), 35, None),
    ("set", 15484, (22, 31, "39"), 36, None),
    ("set", 15485, (22, 31, "40"), 37, None),
    ("set", 15486, (22, 31, "40b"), 40, "40b"),
    # Sirach 17:9-10: our two rows read "per narrare ... / Loderanno ...",
    # the reverse of the site's (and the sentence's) order.
    ("set", 20348, (28, 17, "9"), 9, None),
    ("set", 20349, (28, 17, "10"), 10, None),
    # Verse boundaries moved by the import: words belonging to v.3 / v.2 /
    # v.12 had been attached to the neighbouring verse.
    *[("set", vid, (b, c, lab), int(lab), None) for vid, b, c, lab in (
        (None, 22, 30, "3"), (None, 22, 30, "4"), (None, 59, 5, "2"), (None, 59, 5, "3"),
        (None, 52, 9, "11"), (None, 52, 9, "12"))],
    # Baruch 5:9 / 6:1: the superscription of the Letter of Jeremiah ("Copia
    # della lettera ...", unnumbered at the head of ch. 6 in CEI 2008) had been
    # appended to 5:9; it becomes 6:1's heading, as in VGCL (Bar 6:1).
    ("split_heading", 24274, 24275, " Copia della lettera", "Lettera di Geremia"),
    # Revelation 22:21, the last verse of the Bible, was missing.
    ("insert", 35154, (73, 22, "21"), 73, 22, 21, None),
    # Revelation 15:4 had been appended after Revelation; back between 15:3 and 15:5.
    ("move", 35415, 35004),
]


def resolve_ids(source, ops):
    """Fill in verseIDs given as None (looked up by book/chapter/verse)."""
    out = []
    for op in ops:
        if op[0] == "set" and op[1] is None:
            book, chapter, label = op[2]
            row = source.db[(book, chapter)][label][0]
            op = ("set", row["vid"], *op[2:])
        out.append(op)
    return out


def site_text(source, spec):
    if spec[0] == "text":
        return spec[1], [], None
    return source.verse(*spec)


# --------------------------------------------------------------- SQL helpers

def lit(value):
    if value is None:
        return "NULL"
    if isinstance(value, int):
        return str(value)
    return "'" + str(value).replace("'", "''") + "'"


def titles_sql(titles):
    t = (list(titles) + ["", "", ""])[:3]
    return ", ".join(lit(x) for x in t)


def guarded(statement, what):
    """A PL/pgSQL statement that must affect exactly one row."""
    return (f"    {statement}\n"
            f"    GET DIAGNOSTICS n = ROW_COUNT;\n"
            f"    IF n <> 1 THEN RAISE EXCEPTION 'guard failed: {what} (% rows)', n; END IF;\n")


def set_sql(source, op):
    _, vid, spec, verse, equiv = op
    row = source.rows[vid]
    text, titles, position = site_text(source, spec)
    what = f"{F.SLUGS[row['book'] - 1]} {row['chapter']}:{row['label']} -> {equiv or verse}"
    return guarded(
        f'UPDATE "CEI2008" SET verse = {verse}, verseequiv = {lit(equiv)}, text = {lit(text)}, '
        f"title1 = {lit((titles + ['', '', ''])[0])}, title2 = {lit((titles + ['', '', ''])[1])}, "
        f"title3 = {lit((titles + ['', '', ''])[2])}, title_position = {lit(position)} "
        f'WHERE "verseID" = {vid} AND text_plain = {lit(row["plain"])};', what.replace("'", "''"))


def insert_sql(source, op, seq):
    _, after, spec, book, chapter, verse, equiv = op
    text, titles, position = site_text(source, spec)
    pos = f"{after}.{seq:03d}"
    what = f"insert {F.SLUGS[book - 1]} {chapter}:{equiv or verse}"
    return guarded(
        f'WITH ins AS (INSERT INTO "CEI2008" (testament, section, book, chapter, verse, verseequiv, text, '
        f"title1, title2, title3, title_position) SELECT testament, section, {book}, {chapter}, {verse}, "
        f"{lit(equiv)}, {lit(text)}, {titles_sql(titles)}, {lit(position)} "
        f'FROM "CEI2008" WHERE "verseID" = {after} RETURNING "verseID") '
        f"INSERT INTO cei_pos SELECT \"verseID\", {pos} FROM ins;", what)


def split_heading_sql(source, op):
    """Move a superscription glued to the end of one verse into the next
    verse's title1/title2."""
    _, vid, next_vid, marker, heading = op
    row, nxt = source.rows[vid], source.rows[next_vid]
    head, sep, tail = row["plain"].partition(marker)
    if not sep or len(tail) + len(marker) > F.TITLE_MAX:
        raise ValueError(f"cannot split heading out of verseID {vid}")
    superscription = (marker + tail).strip()
    return (guarded(f'UPDATE "CEI2008" SET text = {lit(head)} WHERE "verseID" = {vid} '
                    f"AND text_plain = {lit(row['plain'])};", f"trim {heading}")
            + guarded(f'UPDATE "CEI2008" SET title1 = {lit(heading)}, title2 = {lit(superscription)} '
                      f'WHERE "verseID" = {next_vid} AND text_plain = {lit(nxt["plain"])} '
                      f"AND coalesce(title1, '') = '';", f"heading {heading}"))


def emit_structure(source):
    ops = resolve_ids(source, STRUCTURE)
    body, seq = [], {}
    for op in ops:
        if op[0] == "set":
            body.append(set_sql(source, op))
        elif op[0] == "split_heading":
            body.append(split_heading_sql(source, op))
        elif op[0] == "insert":
            seq[op[1]] = seq.get(op[1], 0) + 1
            body.append(insert_sql(source, op, seq[op[1]]))
        else:
            body.append(f"    INSERT INTO cei_pos VALUES ({op[1]}, {op[2]}.5);\n")
    books = sorted({source.rows[op[1]]["book"] for op in ops if op[0] in ("set", "move", "split_heading")}
                   | {op[3] for op in ops if op[0] == "insert"} | {19, 23})
    inserts = sum(op[0] == "insert" for op in ops)
    return STRUCTURE_SQL.format(body="\n".join(body), books=", ".join(map(str, books)),
                                total=len(source.rows) + inserts, inserts=inserts)


STRUCTURE_SQL = """-- Migration: restore CEI2008 verses lost or merged on import; rebuild in reading order
--
-- Generated by scripts/repair_cei2008_from_source.py --emit structure; see the
-- script for every case and its evidence. Requires 019, 023 and 024
-- (bibleget_rebuild_in_order). {inserts} rows are inserted; afterwards the
-- table has {total} rows, "verseID" 1..{total} in reading order. New rows have
-- no embeddings and changed rows are stale: run compute_embeddings.py for both
-- columns afterwards.

BEGIN;

CREATE TEMP TABLE cei_pos (vid INT PRIMARY KEY, pos NUMERIC NOT NULL) ON COMMIT DROP;
CREATE TEMP TABLE cei_ord (vid INT PRIMARY KEY, ord NUMERIC NOT NULL) ON COMMIT DROP;

DO $mig$
DECLARE
    n INT;
BEGIN
    IF (SELECT count(*) FROM "CEI2008" WHERE book = 13 AND chapter = 1) = 54 THEN
        RAISE NOTICE '025 already applied';
        RETURN;
    END IF;

{body}
    -- Reading order: book, chapter (Esther keeps its internal order), then the
    -- existing order within the chapter; inserted/moved rows sit at their pos.
    INSERT INTO cei_pos SELECT "verseID", "verseID" FROM "CEI2008" ON CONFLICT (vid) DO NOTHING;
    INSERT INTO cei_ord
        SELECT c."verseID", row_number() OVER (ORDER BY c.book, CASE WHEN c.book = 19 THEN 0 ELSE c.chapter END, p.pos)
        FROM "CEI2008" c JOIN cei_pos p ON p.vid = c."verseID";
    PERFORM bibleget_rebuild_in_order('CEI2008', 'cei_ord');

    IF (SELECT count(*) FROM "CEI2008") <> {total} OR (SELECT max("verseID") FROM "CEI2008") <> {total} THEN
        RAISE EXCEPTION 'unexpected row count or verseID range after the rebuild';
    END IF;

    -- Index metadata for the books touched (distinct verse numbers, highest verse).
    UPDATE "CEI2008_idx" i SET verses_count = x.cnt, verses_last = x.last
    FROM (
        SELECT book, string_agg(d::text, ',' ORDER BY chapter) AS cnt, string_agg(m::text, ',' ORDER BY chapter) AS last
        FROM (SELECT book, chapter, count(DISTINCT verse) AS d, max(verse) AS m FROM "CEI2008"
              WHERE book IN ({books}) AND book <> 19 GROUP BY book, chapter) per_chapter
        GROUP BY book
    ) x
    WHERE i.book = x.book;
END
$mig$;

COMMIT;
"""


# --------------------------------------------------------------- wording

def units(s):
    """Characters, with a typed "E'" (keyboard substitute for "È") as one unit;
    each unit is (comparison key without accents, original text)."""
    out, i = [], 0
    while i < len(s):
        if s[i] == "E" and s[i + 1:i + 2] in ("'", "’"):
            out.append(("E", s[i:i + 2]))
            i += 2
            continue
        key = "".join(c for c in unicodedata.normalize("NFD", s[i]) if not unicodedata.combining(c))
        out.append((key, s[i]))
        i += 1
    return out


def corrected_wording(ours, site):
    """The site's wording, except where a unit differs only by a stress accent
    (or "E'" for "È"): there ours is kept."""
    a, b = units(ours), units(site)
    out = []
    matcher = difflib.SequenceMatcher(None, [k for k, _ in a], [k for k, _ in b], autojunk=False)
    for op, i1, i2, j1, j2 in matcher.get_opcodes():
        out.extend(orig for _, orig in (a[i1:i2] if op == "equal" else b[j1:j2]))
    return "".join(out)


def structural_vids(source):
    ops = resolve_ids(source, STRUCTURE)
    return ({op[1] for op in ops if op[0] in ("set", "move", "split_heading")}
            | {op[2] for op in ops if op[0] == "split_heading"})


def wording_updates(source):
    skip = structural_vids(source)
    for (book, chapter), labels in sorted(source.db.items()):
        if book == 19 or chapter not in F_chapters(source, book):
            continue  # Esther is reviewed separately
        site = F.merge_lettered(dict(source.chapter(book, chapter)), set(labels))
        for label, (text, _titles, _position) in site.items():
            for row in labels.get(label, []):
                yield from wording_update(row, label, text, skip, (book, chapter))


def F_chapters(source, book):
    part = "nt" if book > 46 else "at"
    return {int(p.stem) for p in (source.archive / part / F.SLUGS[book - 1]).glob("*.html")}


def wording_update(row, label, text, skip, key):
    site_plain = F.plain_text(text)
    if row["vid"] in skip or site_plain == row["plain"]:
        return
    if difflib.SequenceMatcher(None, site_plain, row["plain"], autojunk=False).ratio() < F.TRANSPLANT_MIN_RATIO:
        return
    wording = corrected_wording(row["plain"], site_plain)
    if wording == row["plain"]:
        return  # accents only: ours is kept
    formatted = F.transplant(text, wording) or wording
    # Keyed on the verse's identity, not "verseID": 025 renumbers the table.
    yield (f'UPDATE "CEI2008" SET text = {lit(formatted)} WHERE book = {key[0]} AND chapter = {key[1]} '
           f"AND verse = {row['verse']} AND coalesce(verseequiv, '') = {lit(row['equiv'])} "
           f"AND coalesce(verseorigin, '') = {lit(row['origin'])} "
           f"AND text_plain = {lit(row['plain'])};  -- {F.SLUGS[key[0] - 1]} {key[1]}:{label}")


def emit_wording(source):
    updates = list(wording_updates(source))
    return (WORDING_HEADER.format(n=len(updates)) + "BEGIN;\n\n" + "\n".join(updates) + "\n\nCOMMIT;\n")


WORDING_HEADER = """-- Migration: correct CEI2008 wording to the published text (bibbiaedu.it)
--
-- Generated by scripts/repair_cei2008_from_source.py --emit wording. {n} rows:
-- the site's words, editorial marks (⌈ ⌉ ⊥), dashes and spacing are adopted;
-- where the only difference is a stress accent ours is kept. Rows changed by
-- 025 and Esther are not touched here. Each UPDATE is keyed on the verse's
-- identity (book, chapter, verse, verseequiv, verseorigin -- not "verseID",
-- which 025 renumbers) and guarded on its text_plain before the change
-- (idempotent). text_plain/text_hash change, so
-- re-run compute_embeddings.py for both columns afterwards.

"""


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--archive", required=True)
    ap.add_argument("--dump", required=True)
    ap.add_argument("--emit", choices=("structure", "wording"), required=True)
    args = ap.parse_args()
    source = Source(args.archive, args.dump)
    sys.stdout.write(emit_structure(source) if args.emit == "structure" else emit_wording(source))


if __name__ == "__main__":
    main()
