#!/usr/bin/env python3
"""Generate SQL that rebuilds LUZZI verse text and headings from the source.

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

  * ``heading^verse text`` — the headings that precede the verse, before ``^``
    (at most one ``^`` per verse);
  * ``<br />`` — poetic line breaks in the verse, and level breaks in the
    heading;
  * ``<i>…</i>`` — italics (section headings, superscriptions, *Sela*, Old
    Testament quotations in the New);
  * HTML entities for accented letters (``&egrave;`` …).

What went wrong in the 2015 import
----------------------------------
The importer split verses on ``<br />`` and kept only the first segment, so
every poetic verse lost all but its first line (and a psalm with a
superscription kept only the superscription). Where a verse did not end in
``<br />`` the tags were dropped without a space, joining the lines
("m'accompagnerannotutti"). It also dropped everything before ``^``: the
headings (``title1``-``title3`` stayed empty) and, in four verses where the
heading falls mid-verse, the first half of the verse itself (2 Kgs 25:21,
Neh 7:73, John 12:36, Acts 9:19).

Conversion (``parse_verse``)
----------------------------
Text: strip ``<i>``, ``<br />`` -> space, decode entities. Applied to every
undamaged row it reproduces the database text exactly, so it is the
importer's own intended conversion. A heading part that is plain prose (no
italics, contains lowercase, not a parenthetical) is verse text that
precedes a mid-verse heading, and is prepended to the verse.

Headings: the ``<br />``-separated parts before ``^`` are levels, outermost
first (e.g. "REGNO DI SALOMONE" / "(Cap. 1 a 11)" / "Vecchiaia di Davide…").
A parenthetical part (a chapter range or synoptic parallels) belongs to the
heading before it and is joined to it. The levels fill ``title1``,
``title2``, ``title3`` in order, so a verse with a single heading — the vast
majority — has it in ``title1``.

Usage
-----
    # current rows, all books (verseID order):
    psql ... -At -F $'\\t' -c 'SELECT book, chapter, verse, "verseID", text
        FROM "LUZZI" ORDER BY "verseID"' > luzzi_db.tsv
    python3 scripts/repair_luzzi_from_source.py luzzi.txt luzzi_db.tsv \\
        --emit text [--also-old other_dump.tsv ...] [--mariadb] > text.sql
    python3 scripts/repair_luzzi_from_source.py luzzi.txt luzzi_db.tsv \\
        --emit titles [--mariadb] > titles.sql
    python3 scripts/repair_luzzi_from_source.py luzzi.txt luzzi_db.tsv \\
        --emit formatted > migrations/020-luzzi-formatted-text.sql   # PG only

``--emit formatted`` (``format_edition``) rebuilds the *formatted* ``text``
in the NABRE tag set — <pof>/<po>/<pol> poetic lines joined by "\\n", <i>
kept — plus ``title_position`` for mid-verse headings. Every row is checked
to normalize (``plain_text``, the mirror of SQL ``bibleget_plain_text``) back
to exactly its current plain text, and every offset against the
``title_position`` guard (``valid_title_position``).

Text UPDATEs are guarded on ``verseID`` and a known old text (``--also-old``
adds texts from other dumps of the same rows, so one script repairs
databases in different prior states). Title UPDATEs only write while all
three title columns are empty. Re-running either is a no-op.
"""

import argparse
import collections
import html
import re
import sys

TITLE_MAX = 255


def clean(fragment):
    text = re.sub(r"</?i>", "", fragment)
    text = text.replace("<br />", " ")
    text = html.unescape(text)
    return re.sub(r"\s+", " ", text).strip()


def is_prose(part):
    """A heading-area part that is really verse text (mid-verse heading)."""
    return "<i>" not in part and not part.startswith("(") and clean(part) != clean(part).upper()


def parse_verse(raw):
    """Return (text, [title1, title2, title3]) for one luzzi.txt text field."""
    if "^" not in raw:
        return clean(raw), ["", "", ""]
    heading, body = raw.split("^", 1)
    parts = [p.strip() for p in heading.split("<br />") if p.strip()]
    prefix = []
    while parts and is_prose(parts[0]):
        prefix.append(parts.pop(0))
    if any(is_prose(p) for p in parts):
        raise ValueError(f"prose after a heading part: {heading!r}")
    levels = []
    for part in parts:
        if part.startswith("(") and levels:
            levels[-1] += " " + clean(part)
        else:
            levels.append(clean(part))
    if len(levels) > 3:
        raise ValueError(f"more than three heading levels: {heading!r}")
    if any(len(t) > TITLE_MAX for t in levels):
        raise ValueError(f"heading longer than {TITLE_MAX}: {heading!r}")
    text = clean(" ".join(prefix + [body]))
    return text, levels + [""] * (3 - len(levels))


def load_raw(path):
    """Source rows in file (reading) order: [((book, chapter, verse), raw_text)]."""
    rows = []
    with open(path, encoding="latin-1", newline="") as fh:
        for line in fh:
            _, book, chapter, verse, text, _ = line.rstrip("\r\n").split("|", 5)
            rows.append(((int(book), int(chapter), int(verse)), text))
    return rows


def load_source(path):
    return {key: parse_verse(raw) for key, raw in load_raw(path)}


# ── Formatted text (NABRE tag vocabulary) ──────────────────────────────────

INLINE_TAGS = r"</?(sm|i|sup)>"
BLOCK_TAGS = r"</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>"


def plain_text(formatted):
    """Python mirror of the SQL bibleget_plain_text() (migration 019)."""
    text = re.sub(INLINE_TAGS, "", formatted)
    text = re.sub(BLOCK_TAGS, " ", text)
    text = re.sub(r"<br\s*/?>", " ", text, flags=re.I)
    return re.sub(r"\s+", " ", text).strip()


def valid_title_position(text, pos):
    """Python mirror of the <T>_title_position_check constraint (migration 019)."""
    if pos is None:
        return True
    if not 0 < pos < len(text):
        return False
    if text[pos - 1].isalnum() and text[pos].isalnum():
        return False  # would split a word
    return re.search(r"<[^>]*$", text[:pos]) is None  # not inside a tag


def inline(fragment):
    """Decode entities and collapse spaces, keeping <i> (an inline tag)."""
    return re.sub(r"\s+", " ", html.unescape(fragment)).strip()


def balance_italics(lines):
    """Close/reopen <i> at line ends so every line is well nested."""
    out, is_open = [], False
    for line in lines:
        if is_open:
            line = "<i>" + line
        for tag in re.findall(r"</?i>", line):
            is_open = tag == "<i>"
        if is_open:
            line += "</i>"
        out.append(re.sub(r"<i>\s*</i>", "", line))
    return out


def is_superscription(segment):
    return re.fullmatch(r"\s*<i>[^<]*</i>[\s.,;:]*", segment) is not None


def format_edition(raw_rows):
    """Return {key: (formatted_text, title_position)} for every source row.

    LaParola marks poetic lines with <br /> but carries no stanza or indent
    information, so: a poem starts (<pof>) at a chapter start, after a
    heading, or after a prose lead-in; following lines are <po>; the line
    that ends the poem (no trailing <br />) is <pol>. Prose lead-ins and
    psalm superscriptions stay untagged lines, as in NABRE.
    """
    # Pass 1: classify each verse.
    verses, prev_key, at_line_start = [], None, False
    for key, raw in raw_rows:
        new_chapter = prev_key is None or prev_key[:2] != key[:2]
        prev_key = key
        heading, body = raw.split("^", 1) if "^" in raw else ("", raw)
        parts = [p.strip() for p in heading.split("<br />") if p.strip()]
        prefix = []
        while parts and is_prose(parts[0]):
            prefix.append(parts.pop(0))
        segments = body.split("<br />")
        ends_with_break = len(segments) > 1 and segments[-1].strip() == ""
        if ends_with_break:
            segments.pop()
        segments = [s.strip() for s in segments]
        v = {
            "key": key,
            "prefix": prefix,
            "segments": segments,
            "ends_with_break": ends_with_break,
            "new_block": new_chapter or bool(parts),
            "prose": len(segments) == 1 and not ends_with_break,
            "lead_in": 0,
        }
        if not v["prose"]:
            if prefix:
                raise ValueError(f"mid-verse heading in a poetic verse: {key}")
            first = segments[0]
            narrative_intro = len(segments) > 1 and first.endswith(":") and segments[1].startswith("«")
            if is_superscription(first) or narrative_intro or not (at_line_start or v["new_block"]):
                v["lead_in"] = 1  # untagged lead-in / superscription line
        # A poem starts in this verse unless it continues the previous one.
        v["starts_poem"] = not v["prose"] and (v["new_block"] or v["lead_in"] == 1 or not at_line_start)
        verses.append(v)
        at_line_start = ends_with_break

    # Pass 2: tag lines; a poem ends at a verse without a trailing <br />, or
    # where the next verse opens a new block, a lead-in, or prose.
    result = {}
    for idx, v in enumerate(verses):
        key, segments = v["key"], v["segments"]
        if v["prose"]:
            pre = inline(" ".join(v["prefix"]))
            body_text = balance_italics([inline(segments[0])])[0]
            text = f"{pre} {body_text}" if pre else body_text
            position = len(pre) + 1 if pre else None
        else:
            nxt = verses[idx + 1] if idx + 1 < len(verses) else None
            poem_ends = (
                not v["ends_with_break"]
                or nxt is None
                or nxt["key"][:2] != key[:2]
                or nxt["new_block"]
                or nxt["prose"]
                or nxt["lead_in"] == 1
            )
            lines = balance_italics([inline(s) for s in segments])
            first_poem = v["lead_in"]
            tagged = []
            for i, line in enumerate(lines):
                if i < first_poem:
                    tagged.append(line)
                elif i == first_poem and v["starts_poem"]:
                    tagged.append(f"<pof>{line}</pof>")
                elif i == len(lines) - 1 and poem_ends:
                    tagged.append(f"<pol>{line}</pol>")
                else:
                    tagged.append(f"<po>{line}</po>")
            text = "\n".join(tagged)
            position = None
        if not valid_title_position(text, position):
            raise ValueError(f"title_position guard failed for {key}: {position}")
        result[key] = (text, position)
    return result


def load_dump(path):
    rows = {}
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            book, chapter, verse, verse_id, text = line.rstrip("\n").split("\t", 4)
            rows[(int(book), int(chapter), int(verse))] = (int(verse_id), text)
    return rows


def sql_literal(text):
    if "\\" in text:
        raise ValueError("backslash in text; escaping would differ between engines")
    return "'" + text.replace("'", "''") + "'"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("source", help="original luzzi.txt (Latin-1)")
    ap.add_argument("db_tsv", help="book\\tchapter\\tverse\\tverseID\\ttext dump of LUZZI")
    ap.add_argument("--emit", choices=("text", "titles", "formatted"), required=True)
    ap.add_argument("--also-old", action="append", default=[], metavar="TSV",
                    help="(text) further dumps whose texts are also accepted as the old value")
    ap.add_argument("--mariadb", action="store_true", help="emit MariaDB (backtick) SQL")
    args = ap.parse_args()

    source = load_source(args.source)
    db = load_dump(args.db_tsv)
    if set(source) != set(db):
        sys.exit(f"verse sets differ: {sorted(set(source) ^ set(db))[:10]}")

    q = (lambda s: f"`{s}`") if args.mariadb else (lambda s: f'"{s}"')
    table, col_id = q("LUZZI"), q("verseID")
    col_text = "`text`" if args.mariadb else "text"
    order = sorted(db, key=lambda k: db[k][0])
    updates, per_book = [], collections.Counter()

    if args.emit == "text":
        olds = collections.defaultdict(set)
        for path in [args.db_tsv] + args.also_old:
            for key, (verse_id, text) in load_dump(path).items():
                if verse_id != db[key][0]:
                    sys.exit(f"verseID mismatch for {key} in {path}")
                olds[key].add(text)
        for key in order:
            new = source[key][0]
            stale = sorted(t for t in olds[key] if t != new)
            if not stale:
                continue
            per_book[key[0]] += 1
            guard = " OR ".join(f"{col_text} = {sql_literal(t)}" for t in stale)
            updates.append(
                f"UPDATE {table} SET {col_text} = {sql_literal(new)} "
                f"WHERE {col_id} = {db[key][0]} AND ({guard}); -- {key[0]} {key[1]}:{key[2]}"
            )
        header = f"Restore {len(updates)} LUZZI verses from the original luzzi.txt source."
    elif args.emit == "titles":
        t = [q(f"title{n}") for n in (1, 2, 3)]
        empty = " AND ".join(f"COALESCE({c}, '') = ''" for c in t)
        for key in order:
            titles = source[key][1]
            if not any(titles):
                continue
            per_book[key[0]] += 1
            sets = ", ".join(f"{c} = {sql_literal(v)}" for c, v in zip(t, titles))
            updates.append(
                f"UPDATE {table} SET {sets} WHERE {col_id} = {db[key][0]} AND {empty}; -- {key[0]} {key[1]}:{key[2]}"
            )
        header = f"Store the {len(updates)} LUZZI section headings from luzzi.txt in title1-title3."
    else:  # formatted
        if args.mariadb:
            sys.exit("--emit formatted targets PostgreSQL only (v3 MariaDB keeps plain text)")
        formatted = format_edition(load_raw(args.source))
        for key in order:
            new, position = formatted[key]
            plain = source[key][0]
            if plain_text(new) != plain:
                sys.exit(f"plain-text round trip failed for {key}:\n  {plain_text(new)!r}\n  {plain!r}")
            if new == plain and position is None:
                continue
            per_book[key[0]] += 1
            pos_sql = "NULL" if position is None else str(position)
            # Guarded on the current plain text, and on the formatted text
            # normalizing back to exactly that, so text_plain/text_hash (and
            # with them the embeddings) cannot change.
            updates.append(
                f"UPDATE {table} SET text = {sql_literal(new)}, title_position = {pos_sql} "
                f"WHERE {col_id} = {db[key][0]} AND text = {sql_literal(plain)} "
                f"AND bibleget_plain_text({sql_literal(new)}) = text; -- {key[0]} {key[1]}:{key[2]}"
            )
        header = f"Store formatted text (NABRE tag set) for {len(updates)} LUZZI verses."

    print(f"-- {header}")
    print("-- Generated by scripts/repair_luzzi_from_source.py; see that script for details.")
    print()
    print("SET NAMES utf8mb4;" if args.mariadb else "BEGIN;")
    print()
    print("\n".join(updates))
    if not args.mariadb:
        print("\nCOMMIT;")
    print(f"{len(updates)} updates; per book: {dict(sorted(per_book.items()))}", file=sys.stderr)


if __name__ == "__main__":
    main()
