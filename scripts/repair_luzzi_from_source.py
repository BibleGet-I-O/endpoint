#!/usr/bin/env python3
"""Generate the SQL that restores LUZZI verses mangled by the original import.

Source
------
LUZZI (Riveduta 1924) was imported from ``luzzi.txt``, a Latin-1, CRLF,
pipe-delimited file (``4|book|chapter|verse|text|``, universal book numbers),
kept in the old VPS backup at
``httpdocs/biblical_texts/luzzi.txt`` (4,875,339 bytes, dated 2015-08-27).
The file is a dump of LaParola.net's "Luzzi/Riveduta" text (identical
wording and markup), which per https://www.laparola.net/versioni.php follows
the Società Biblica Britannica e Forestiera printing (1925) as reproduced in
the Libreria Sacre Scritture edition, Roma 1990, ISBN 88-237-1051-0 (recorded
in ``versions_available.notes`` by migration 016). Inside ``text``:

  * ``heading^verse text`` — a section heading before ``^`` (never imported:
    no LUZZI row has a ``title1``);
  * ``<i>…</i>`` — italics (superscriptions, *Sela*, headings);
  * ``<br />`` — poetic line breaks;
  * HTML entities for accented letters (``&egrave;`` …).

What went wrong
---------------
The original importer split on ``<br />`` and kept only the first segment,
so every poetic verse lost all but its first line (and a psalm with a
superscription kept only the superscription). Where the verse did not end in
``<br />`` the tags were dropped without a space, joining the lines
("m'accompagnerannotutti"). 2,450 of 2,460 Psalm verses were affected, plus
41 verses elsewhere (37 only joined, 4 truncated: Num 24:20, Prov 30:1,
Prov 31:1, Amos 1:2).

Conversion
----------
``convert()`` drops the heading, strips ``<i>``, turns ``<br />`` into a
space and decodes entities. Applied to every non-damaged row, it reproduces
the database text exactly (28,593 of 28,634 non-Psalm rows; the other 41
are the damaged ones), so it is the importer's own intended conversion.

Usage
-----
    # current rows, all books (verseID order):
    psql ... -At -F $'\\t' -c 'SELECT book, chapter, verse, "verseID", text
        FROM "LUZZI" ORDER BY "verseID"' > luzzi_db.tsv
    python3 scripts/repair_luzzi_from_source.py luzzi.txt luzzi_db.tsv \\
        [--also-old other_dump.tsv ...] [--mariadb] > out.sql

Every UPDATE is guarded on ``verseID`` and the old text. ``--also-old``
adds texts from other dumps of the same rows as acceptable old values, so
one script can repair databases in different prior states. Re-running is a
no-op, and later edits are never overwritten.
"""

import argparse
import collections
import html
import re
import sys


def convert(raw):
    if "^" in raw:
        raw = raw.split("^", 1)[1]
    text = re.sub(r"</?i>", "", raw)
    text = text.replace("<br />", " ")
    text = html.unescape(text)
    return re.sub(r" +", " ", text).strip()


def load_source(path):
    verses = {}
    with open(path, encoding="latin-1", newline="") as fh:
        for line in fh:
            _, book, chapter, verse, text, _ = line.rstrip("\r\n").split("|", 5)
            verses[(int(book), int(chapter), int(verse))] = convert(text)
    return verses


def load_dump(path):
    rows = {}
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            book, chapter, verse, verse_id, text = line.rstrip("\n").split("\t", 4)
            rows[(int(book), int(chapter), int(verse))] = (int(verse_id), text)
    return rows


def sql_literal(text):
    if "\\" in text:
        raise ValueError("backslash in verse text; escaping would differ between engines")
    return "'" + text.replace("'", "''") + "'"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("source", help="original luzzi.txt (Latin-1)")
    ap.add_argument("db_tsv", help="book\\tchapter\\tverse\\tverseID\\ttext dump of LUZZI")
    ap.add_argument("--also-old", action="append", default=[], metavar="TSV",
                    help="further dumps whose texts are also accepted as the old value")
    ap.add_argument("--mariadb", action="store_true", help="emit MariaDB (backtick) SQL")
    args = ap.parse_args()

    source = load_source(args.source)
    db = load_dump(args.db_tsv)
    if set(source) != set(db):
        sys.exit(f"verse sets differ: {sorted(set(source) ^ set(db))[:10]}")
    olds = collections.defaultdict(set)
    for path in [args.db_tsv] + args.also_old:
        for key, (verse_id, text) in load_dump(path).items():
            if verse_id != db[key][0]:
                sys.exit(f"verseID mismatch for {key} in {path}")
            olds[key].add(text)

    if args.mariadb:
        table, col_text, col_id = "`LUZZI`", "`text`", "`verseID`"
    else:
        table, col_text, col_id = '"LUZZI"', "text", '"verseID"'

    updates, per_book = [], collections.Counter()
    for key in sorted(db, key=lambda k: db[k][0]):
        new = source[key]
        stale = sorted(t for t in olds[key] if t != new)
        if not stale:
            continue
        per_book[key[0]] += 1
        guard = " OR ".join(f"{col_text} = {sql_literal(t)}" for t in stale)
        updates.append(
            f"UPDATE {table} SET {col_text} = {sql_literal(new)} "
            f"WHERE {col_id} = {db[key][0]} AND ({guard}); -- {key[0]} {key[1]}:{key[2]}"
        )

    print(f"-- Restore {len(updates)} LUZZI verses from the original luzzi.txt source.")
    print("-- Generated by scripts/repair_luzzi_from_source.py; see that script for details.")
    print("-- Idempotent: each UPDATE matches only a known damaged text.")
    print()
    print("SET NAMES utf8mb4;" if args.mariadb else "BEGIN;")
    print()
    print("\n".join(updates))
    if not args.mariadb:
        print("\nCOMMIT;")
    print(f"{len(updates)} updates; per book: {dict(sorted(per_book.items()))}", file=sys.stderr)


if __name__ == "__main__":
    main()
