#!/usr/bin/env python3
"""Generate the SQL that repairs the truncated LUZZI (Riveduta) Psalms.

Background
----------
The original LUZZI import mangled the poetic layout of the Psalter (book 23):

  * only the first poetic line of each verse was stored
    (Ps 1:1 "Beato l'uomo che non cammina secondo il consiglio degli empi,");
  * where a psalm has a superscription, verse 1 held the superscription
    alone and lost its text (Ps 23:1 "Salmo di Davide.");
  * in the last verse of each chapter the lines were joined without spaces
    ("m'accompagnerannotutti i giorni").

Prose books, Job and Proverbs are unaffected.

The BibleGet LUZZI text is a *proofread* edition — restored accents, « »
quotation marks, typo fixes — so the repair keeps every character already in
the database and only supplies what is missing, taken from the public-domain
eBible.org "ita1927" Riveduta (https://ebible.org/details.php?id=ita1927,
USFM: https://ebible.org/Scriptures/ita1927_usfm.zip).

Merge rules, per verse (case-insensitive alignment):
  * DB characters always win where both texts cover the same span;
  * whitespace (or "<punct> ") present in the source but missing in the DB
    is inserted — this undoes the joined lines;
  * source text after the end of the DB text is appended; at the junction
    the DB's trailing punctuation is kept and the source's is dropped;
  * the tail is upper-cased when the DB verse is all caps (the doxologies
    72:18-19, 89:52, 106:48);
  * the DB verse structure is authoritative: ita1927 splits 13:5-6, 98:8-9
    and 123:4-5 differently, so those are re-mapped onto the DB rows.

Usage
-----
    # 1. dump the current Psalm rows (verseID order) as TSV:
    psql ... -At -F $'\\t' -c 'SELECT chapter, verse, "verseID", text
        FROM "LUZZI" WHERE book = 23 ORDER BY "verseID"' > luzzi_ps.tsv
    # 2. unzip ita1927_usfm.zip and point at 20-PSAita1927.usfm:
    python3 scripts/repair_luzzi_psalms.py luzzi_ps.tsv 20-PSAita1927.usfm \\
        > migrations/015-fix-luzzi-psalms-truncation.sql

Pass --mariadb to emit backtick-quoted SQL for the legacy v3 database.
Diagnostics (flagged verses) are written to stderr.
"""

import argparse
import difflib
import re
import sys

# The ita1927 text is garbled here ("popoli tutti principi e tutti, i giudici").
OVERRIDES = {
    (148, 11): "re della terra e popoli tutti, principi e tutti i giudici della terra;",
}


def parse_usfm(path):
    verses, chapter = {}, 0
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            m = re.match(r"\\c (\d+)", line)
            if m:
                chapter = int(m.group(1))
                continue
            m = re.match(r"\\v (\d+) (.*)", line)
            if m:
                verses[(chapter, int(m.group(1)))] = m.group(2)
    return verses


def house_style(text):
    """Normalise ita1927 typography to the LUZZI table's conventions."""
    text = re.sub(r"\(H\d+-\d+\)\s*", "", text)  # alternate-numbering markers
    text = text.replace("’", "'").replace("‘", "'")
    text = text.replace("“", "'").replace("”", "'")
    text = re.sub(r"\bE' ", "È ", text)
    return re.sub(r"\s+", " ", text).strip()


def load_source(path):
    raw = parse_usfm(path)
    src = {k: house_style(v) for k, v in raw.items()}
    # Map ita1927 versification onto the DB rows where they disagree.
    src[(13, 5)] = src[(13, 5)] + " " + src.pop((13, 6))
    src[(98, 8)] = src[(98, 8)] + " " + src.pop((98, 9))
    first, second = re.split(r"\s*\(H123-5\)\s*", raw[(123, 4)])
    src[(123, 4)], src[(123, 5)] = house_style(first), house_style(second)
    return src


def merge(db_text, src_text):
    """Return (merged_text, flags). See module docstring for the rules."""
    flags = []
    trailing = re.search(r"[\s,;:.!?]*$", db_text)
    assert trailing is not None
    core, db_punct = db_text[: trailing.start()], db_text[trailing.start():].strip()
    caps = core.isupper()
    ops = difflib.SequenceMatcher(None, core.lower(), src_text.lower(), autojunk=False).get_opcodes()

    src_end = 0  # source offset just past the last character the DB covers
    for op, _i1, i2, _j1, j2 in ops:
        if op == "equal" and i2 == len(core):
            src_end = j2
    if src_end == 0:
        return db_text, [("no-anchor",)]

    out = []
    for op, i1, i2, j1, j2 in ops:
        if j1 >= src_end and i1 >= len(core):
            break
        if op == "equal":
            out.append(core[i1:i2])
        elif op == "insert":
            ins = src_text[j1:j2]
            if ins.strip() == "":
                out.append(" ")
            elif re.fullmatch(r"[,;:.!?]+\s+", ins):
                out.append(ins)
            else:
                flags.append(("db-wording-kept", ins))
        else:  # replace / delete: DB wording wins
            out.append(core[i1:i2])

    tail = src_text[src_end:].strip()
    src_punct = re.match(r"[,;:.!?]*", tail)
    assert src_punct is not None
    tail = tail[src_punct.end():].strip()
    junction = db_punct or src_punct.group(0)
    if tail:
        out.append(junction + " " + (tail.upper() if caps else tail))
    else:
        out.append(junction)
    return re.sub(r" +", " ", "".join(out)).strip(), flags


def sql_literal(text):
    if "\\" in text:
        raise ValueError("backslash in verse text; escaping would differ between engines")
    return "'" + text.replace("'", "''") + "'"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("db_tsv", help="chapter\\tverse\\tverseID\\ttext rows for LUZZI book 23")
    ap.add_argument("usfm", help="ita1927 Psalms USFM (20-PSAita1927.usfm)")
    ap.add_argument("--mariadb", action="store_true", help="emit MariaDB (backtick) SQL")
    args = ap.parse_args()

    db = {}
    with open(args.db_tsv, encoding="utf-8") as fh:
        for line in fh:
            chapter, verse, verse_id, text = line.rstrip("\n").split("\t", 3)
            db[(int(chapter), int(verse))] = (int(verse_id), text)
    src = load_source(args.usfm)
    if set(db) != set(src):
        sys.exit(f"verse sets differ: {sorted(set(db) ^ set(src))[:10]}")

    if args.mariadb:
        table, col_text, col_id = "`LUZZI`", "`text`", "`verseID`"
    else:
        table, col_text, col_id = '"LUZZI"', "text", '"verseID"'

    updates = []
    for key in sorted(db, key=lambda k: db[k][0]):
        verse_id, old = db[key]
        new, flags = merge(old, src[key])
        if key in OVERRIDES:
            new, flags = OVERRIDES[key], flags + [("override",)]
        for flag in flags:
            print(f"Ps {key[0]}:{key[1]} {flag}", file=sys.stderr)
        if new != old:
            updates.append(
                f"UPDATE {table} SET {col_text} = {sql_literal(new)} "
                f"WHERE {col_id} = {verse_id} AND {col_text} = {sql_literal(old)}; -- Ps {key[0]}:{key[1]}"
            )

    print(f"-- Repair truncated LUZZI (Riveduta 1924) Psalms — {len(updates)} verses.")
    print("-- Generated by scripts/repair_luzzi_psalms.py; see that script for the rules.")
    print("-- Idempotent: each UPDATE matches the exact pre-repair text.")
    print()
    print("SET NAMES utf8mb4;" if args.mariadb else "BEGIN;")
    print()
    print("\n".join(updates))
    if not args.mariadb:
        print("\nCOMMIT;")
    print(f"{len(updates)} updates", file=sys.stderr)


if __name__ == "__main__":
    main()
