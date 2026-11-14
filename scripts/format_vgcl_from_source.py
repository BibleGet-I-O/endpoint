#!/usr/bin/env python3
"""Generate migrations/021-vgcl-source-markup.sql from the Clementine Vulgate
Project source files.

The VGCL table was imported from the Clementine Vulgate Project text
(mirror: https://github.com/BibleGet-I-O/Clementine-Vulgate, src/utf8/*.lat,
one file per book, one "chapter:verse text" line per verse). The importer
turned "/" into a newline and dropped "\\" (paragraph break), but left the
rest of the source markup in the verse text. This script stores it the way
the other editions do:

    [ ... ]                 start / end of a poetic passage
                            -> each line wrapped in <pof> (first line of the
                               passage), <pol> (last line) or <po>; prose
                               before "[" or after "]" stays an untagged line
                               (lead-in / psalm superscription, as in NABRE)
    /                       poetic line break -> "\\n" between lines
    <Sponsa>, <Sponsus>,    Song of Songs speakers
    <Chorus ...>            -> <speaker>Sponsa</speaker> at the start of the line
    <Aleph> ... <Thau>      acrostic letters (Ps 118, Lam 1-4) -> title1; when
                            the letter is not at the start of the verse
                            (Ps 118:1 "Alleluja. [<Aleph>Beati...") the
                            offset goes in title_position
    <Prologus>, <Oratio     headings -> title1
    ...>, <Exemplar ...>

Sir 1:1 (Prologue + verse 1, 1,701 characters in the source) was truncated
at 900 characters on import; it is rebuilt in full (the migration widens
text/text_plain to VARCHAR(2000) first).

Lam 1:1 has two headings: <Prologus> before the verse and <Aleph> before the
poem inside it. title1-title3 share a single title_position, so title1 gets
"Prologus" and the Aleph is not stored (documented in the wiki).

Esther (book 19) carries no markup in the source and is not touched.

Every UPDATE is guarded on the row's current text and empty titles, so the
migration is idempotent and refuses to overwrite anything it did not expect.

Usage:
    python3 scripts/format_vgcl_from_source.py \\
        --source /path/to/Clementine-Vulgate/src/utf8 \\
        --dump vgcl.copy > migrations/021-vgcl-source-markup.sql

where vgcl.copy is produced on the database with
    \\copy (SELECT "verseID", book, chapter, verse, text, title1, title2,
            title3 FROM "VGCL" ORDER BY "verseID") TO vgcl.copy
"""

import argparse
import re
import sys

OT = ("Gn Ex Lv Nm Dt Jos Jdc Rt 1Rg 2Rg 3Rg 4Rg 1Par 2Par Esr Neh Tob Jdt Est "
      "1Mcc 2Mcc Job Ps Pr Ecl Ct Sap Sir Is Jr Lam Bar Ez Dn Os Joel Am Abd Jon "
      "Mch Nah Hab Soph Agg Zach Mal").split()
NT = ("Mt Mc Lc Jo Act Rom 1Cor 2Cor Gal Eph Phlp Col 1Thes 2Thes 1Tim 2Tim Tit "
      "Phlm Hbr Jac 1Ptr 2Ptr 1Jo 2Jo 3Jo Jud Apc").split()
BOOKS = OT + NT  # index + 1 == BibleGet universal book number
ESTHER = 19
TEXT_MAX = 2000
TITLE_MAX = 255

ACROSTIC = set("Aleph Beth Ghimel Daleth He Vau Zain Heth Teth Jod Caph Lamed Mem "
               "Nun Samech Ain Phe Sade Coph Res Sin Tau Thau".split())
SPEAKERS = {"Sponsa", "Sponsus", "Chorus", "Chorus Fratrum", "Chorus Adolescentularum"}
HEADING_PREFIXES = ("Prologus", "Oratio ", "Exemplar ")

BLOCK_TAGS = r"</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>"


def plain_text(text):
    """Python mirror of the SQL bibleget_plain_text()."""
    s = re.sub(r"</?(sm|i|sup)>", "", text)
    s = re.sub(BLOCK_TAGS, " ", s)
    s = re.sub(r"<br\s*/?>", " ", s, flags=re.I)
    return re.sub(r"\s+", " ", s).strip()


def valid_title_position(text, pos):
    """Python mirror of the <T>_title_position_check constraint."""
    if pos is None:
        return True
    if not 0 < pos < len(text):
        return False
    if text[pos - 1].isalnum() and text[pos].isalnum():
        return False
    return re.search(r"<[^>]*$", text[:pos]) is None


def load_source(src_dir):
    rows = []  # [(book, chapter, verse, raw)] in source order
    for num, name in enumerate(BOOKS, 1):
        with open(f"{src_dir}/{name}.lat", encoding="utf-8") as fh:
            for line in fh:
                line = line.rstrip("\r\n")
                if not line:
                    continue
                m = re.fullmatch(r"(\d+):(\d+) (.*)", line)
                if not m:
                    raise ValueError(f"{name}: unparsable line {line[:40]!r}")
                rows.append((num, int(m[1]), int(m[2]), m[3]))
    return rows


def copy_unescape(s):
    return re.sub(r"\\(.)", lambda m: {"n": "\n", "t": "\t", "\\": "\\", "N": None}.get(m[1], m[1]) or "", s)


def format_book(verses):
    """verses: [(key, raw)] for one book in order.
    Return {key: (text, title1, title_position)}."""
    out = {}
    in_poem = False
    for key, raw in verses:
        raw = raw.replace("\\", " ")  # paragraph break: not representable, drop
        title, title_at = None, None  # title_at: index into `lines` (+ char 0)
        lines = []                    # [(kind, text)] kind: prose|pof|po|pol
        cur, cur_opens = "", False
        cur_speaker = None

        def flush(closes):
            nonlocal cur, cur_opens, cur_speaker
            t = re.sub(r"\s+", " ", cur).strip()
            if cur_speaker:
                t = f"<speaker>{cur_speaker}</speaker> {t}"
            if t:
                if in_poem or closes:
                    kind = "pof" if cur_opens else ("pol" if closes else "po")
                else:
                    kind = "prose"
                lines.append((kind, t))
                cur, cur_opens, cur_speaker = "", False, None
                return
            # No text on this line: keep a pending "[" for the next line; a "]"
            # closes the previous poetic line.
            if closes and lines and lines[-1][0] == "po":
                lines[-1] = ("pol", lines[-1][1])
            cur = ""

        i = 0
        while i < len(raw):
            ch = raw[i]
            if ch == "<":
                j = raw.index(">", i)
                label = raw[i + 1:j]
                i = j + 1
                if label in SPEAKERS:
                    if cur.strip():
                        raise ValueError(f"speaker mid-line in {key}")
                    cur_speaker = label
                elif label in ACROSTIC or label.startswith(HEADING_PREFIXES):
                    if title is not None:
                        if key == (31, 1, 1) and label == "Aleph":
                            continue  # see module docstring
                        raise ValueError(f"two headings in {key}")
                    if cur.strip():
                        raise ValueError(f"heading mid-line in {key}")
                    title, title_at = label, len(lines)
                else:
                    raise ValueError(f"unknown marker <{label}> in {key}")
                continue
            if ch == "[":
                if in_poem:
                    raise ValueError(f"nested [ in {key}")
                flush(False)
                in_poem, cur_opens = True, True
            elif ch == "]":
                if not in_poem:
                    raise ValueError(f"unbalanced ] in {key}")
                flush(True)
                in_poem = False
            elif ch == "/":
                flush(False)
            else:
                cur += ch
            i += 1
        flush(False)

        rendered = [t if k == "prose" else f"<{k}>{t}</{k}>" for k, t in lines]
        text = "\n".join(rendered)
        position = None
        if title_at is not None and title_at > 0:
            position = len("\n".join(rendered[:title_at])) + 1
        if not valid_title_position(text, position):
            raise ValueError(f"title_position guard failed for {key}: {position}")
        if len(text) > TEXT_MAX or (title and len(title) > TITLE_MAX):
            raise ValueError(f"too long: {key}")
        out[key] = (text, title, position)
    if in_poem:
        raise ValueError(f"unterminated [ at end of book {verses[-1][0]}")
    return out


def sql_str(s):
    return "E'" + s.replace("\\", "\\\\").replace("'", "''").replace("\n", "\\n") + "'"


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source", required=True, help="Clementine-Vulgate/src/utf8 directory")
    ap.add_argument("--dump", required=True, help="COPY dump of the VGCL table (see docstring)")
    args = ap.parse_args()

    source = load_source(args.source)
    db = {}
    with open(args.dump, encoding="utf-8") as fh:
        for line in fh:
            vid, book, chapter, verse, text, t1, t2, t3 = line.rstrip("\n").split("\t")
            if int(book) == ESTHER:
                continue
            key = (int(book), int(chapter), int(verse))
            if key in db:
                raise ValueError(f"duplicate key outside Esther: {key}")
            db[key] = (int(vid), copy_unescape(text), [("" if t == "\\N" else copy_unescape(t)) for t in (t1, t2, t3)])

    by_book = {}
    for book, chapter, verse, raw in source:
        if book != ESTHER:
            by_book.setdefault(book, []).append(((book, chapter, verse), raw))

    updates, stats = [], {"text": 0, "title": 0, "position": 0}
    for book, verses in by_book.items():
        for key, (text, title, position) in format_book(verses).items():
            if key not in db:
                raise ValueError(f"source verse missing from DB: {key}")
            vid, old, titles = db[key]
            # Sanity: apart from the markup, the text must be what we already have
            # (except Sir 1:1, which was truncated).
            old_norm = re.sub(r"[\[\]]|<(?:Aleph|Beth|Ghimel|Daleth|He|Vau|Zain|Heth|Teth|Jod|Caph|Lamed|Mem|Nun|Samech|Ain|Phe|Sade|Coph|Res|Sin|Tau|Thau|Prologus|Oratio[^>]*|Exemplar[^>]*)>", " ", old)
            old_norm = re.sub(r"<(Sponsa|Sponsus|Chorus[^>]*)>", r" \1 ", old_norm)
            old_norm = re.sub(r"\s+", " ", old_norm).strip()
            new_cmp = plain_text(text)
            if key == (31, 1, 1):
                new_cmp = new_cmp.replace(" Aleph ", " ")  # dropped Aleph is not in new text
            if key == (28, 1, 1):
                if not new_cmp.startswith(old_norm.rstrip()):
                    raise ValueError("Sir 1:1 is not an extension of the stored text")
            elif new_cmp != old_norm:
                raise ValueError(f"content mismatch {key}:\n  db : {old_norm[:200]}\n  new: {new_cmp[:200]}")
            if any(titles):
                raise ValueError(f"{key} already has titles: {titles}")
            if title is None and re.sub(r"\s+", " ", old).strip() == text:
                continue  # no markup in this verse; leave its whitespace alone
            sets = []
            if text != old:
                sets.append(f"text = {sql_str(text)}")
                stats["text"] += 1
            if title is not None:
                sets.append(f"title1 = {sql_str(title)}")
                stats["title"] += 1
            if position is not None:
                sets.append(f"title_position = {position}")
                stats["position"] += 1
            updates.append(f'UPDATE "VGCL" SET {", ".join(sets)} WHERE "verseID" = {vid} '
                           f"AND text = {sql_str(old)} AND coalesce(title1, '') = '' AND coalesce(title2, '') = '' "
                           f"AND coalesce(title3, '') = '';"
                           f"  -- {BOOKS[key[0] - 1]} {key[1]}:{key[2]}")

    w = sys.stdout.write
    w("-- Generated by scripts/format_vgcl_from_source.py -- do not edit by hand.\n")
    w("-- Stores the Clementine Vulgate Project markup of VGCL the way the other\n")
    w("-- editions do (see the script's docstring for the rules), and restores the\n")
    w("-- truncated Sir 1:1. PostgreSQL only.\n")
    w(f"-- {len(updates)} rows: {stats['text']} text, {stats['title']} title1, {stats['position']} title_position.\n")
    w("-- Rows whose text changes get a new text_plain/text_hash from the trigger;\n")
    w("-- re-run scripts/compute_embeddings.py --version VGCL afterwards.\n\n")
    w("BEGIN;\n\n")
    w("-- Sir 1:1 (Prologue + verse 1) needs 1,7xx characters. Widen every edition\n")
    w("-- so the schema stays uniform; varchar widening is a catalog-only change.\n")
    w("DO $$\nDECLARE t TEXT;\nBEGIN\n")
    w("    FOR t IN SELECT sigla FROM versions_available LOOP\n")
    w("        -- the text_hash trigger is UPDATE OF text, which blocks ALTER TYPE\n")
    w("        EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I', t || '_text_hash_trigger', t);\n")
    w("        EXECUTE format('ALTER TABLE %I ALTER COLUMN text TYPE VARCHAR(2000), "
      "ALTER COLUMN text_plain TYPE VARCHAR(2000)', t);\n")
    w("        EXECUTE format('CREATE TRIGGER %I BEFORE INSERT OR UPDATE OF text ON %I '\n")
    w("                       'FOR EACH ROW EXECUTE FUNCTION update_text_hash()', t || '_text_hash_trigger', t);\n")
    w("    END LOOP;\nEND $$;\n\n")
    for u in updates:
        w(u + "\n")
    w(f"""
DO $$
DECLARE n INT;
BEGIN
    SELECT count(*) INTO n FROM "VGCL" WHERE text ~ '[][]|<(Aleph|Beth|Sponsa|Sponsus|Chorus|Prologus|Oratio|Exemplar)';
    IF n <> 0 THEN RAISE EXCEPTION 'VGCL still has % rows with source markup', n; END IF;
    SELECT count(*) INTO n FROM "VGCL" WHERE title1 <> '';
    IF n <> {stats['title']} THEN RAISE EXCEPTION 'expected {stats['title']} VGCL headings, found %', n; END IF;
END $$;

COMMIT;
""")
    print(f"{len(updates)} updates; {stats}", file=sys.stderr)


if __name__ == "__main__":
    main()
