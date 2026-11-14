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

from markup import require_markup_ok

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
    """Python mirror of bibleget_title_position_ok() (migration 019)."""
    if pos is None:
        return True
    if not 0 < pos < len(text):
        return False
    if re.search(r"<[^>]*$", text[:pos]):
        return False  # inside a tag

    def visible(fragment):
        return re.sub(r"<[^>]*>", " ", re.sub(r"</?(sm|i|sup)>", "", fragment))

    before, after = visible(text[:pos]), visible(text[pos:])
    return not (before[-1:].isalnum() and after[:1].isalnum())  # would split a word


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


class VerseLines:
    """Turns one verse's source markup into tagged lines (see the module
    docstring). `in_poem` carries the open/closed state of a [ ... ] passage
    from verse to verse."""

    def __init__(self, key, in_poem):
        self.key, self.in_poem = key, in_poem
        self.lines = []        # [(kind, text)], kind: prose | pof | po | pol
        self.cur, self.opens, self.speaker = "", False, None
        self.title, self.title_at = None, None  # title_at: index into lines

    def flush(self, closes):
        """End the current line (at "/", "[", "]" or the end of the verse)."""
        t = re.sub(r"\s+", " ", self.cur).strip()
        if self.speaker:
            t = f"<speaker>{self.speaker}</speaker> {t}"
        if t:
            if self.in_poem or closes:
                kind = "pof" if self.opens else ("pol" if closes else "po")
            else:
                kind = "prose"
            self.lines.append((kind, t))
            self.cur, self.opens, self.speaker = "", False, None
            return
        # No text on this line: keep a pending "[" for the next line; a "]"
        # closes the previous poetic line.
        if closes and self.lines and self.lines[-1][0] == "po":
            self.lines[-1] = ("pol", self.lines[-1][1])
        self.cur = ""

    def marker(self, label):
        """Handle a <label>: a speaker, or a heading (title1)."""
        if label in SPEAKERS:
            if self.cur.strip():
                raise ValueError(f"speaker mid-line in {self.key}")
            self.speaker = label
            return
        if not (label in ACROSTIC or label.startswith(HEADING_PREFIXES)):
            raise ValueError(f"unknown marker <{label}> in {self.key}")
        if self.title is not None:
            if self.key == (31, 1, 1) and label == "Aleph":
                return  # see module docstring
            raise ValueError(f"two headings in {self.key}")
        if self.cur.strip():
            raise ValueError(f"heading mid-line in {self.key}")
        self.title, self.title_at = label, len(self.lines)

    def open_poem(self):
        if self.in_poem:
            raise ValueError(f"nested [ in {self.key}")
        self.flush(False)
        self.in_poem, self.opens = True, True

    def close_poem(self):
        if not self.in_poem:
            raise ValueError(f"unbalanced ] in {self.key}")
        self.flush(True)
        self.in_poem = False

    def feed(self, raw):
        actions = {"[": self.open_poem, "]": self.close_poem, "/": lambda: self.flush(False)}
        i = 0
        while i < len(raw):
            ch = raw[i]
            if ch == "<":
                j = raw.index(">", i)
                self.marker(raw[i + 1:j])
                i = j + 1
                continue
            if ch in actions:
                actions[ch]()
            else:
                self.cur += ch
            i += 1
        self.flush(False)
        return self

    def render(self):
        """Return (text, title1, title_position), checked."""
        rendered = [t if k == "prose" else f"<{k}>{t}</{k}>" for k, t in self.lines]
        text = "\n".join(rendered)
        position = None
        if self.title_at:  # a heading after the first line sits mid-verse
            position = len("\n".join(rendered[:self.title_at])) + 1
        require_markup_ok(text, f"VGCL {self.key}")
        if not valid_title_position(text, position):
            raise ValueError(f"title_position guard failed for {self.key}: {position}")
        if len(text) > TEXT_MAX or (self.title and len(self.title) > TITLE_MAX):
            raise ValueError(f"too long: {self.key}")
        return text, self.title, position


def format_book(verses):
    """verses: [(key, raw)] for one book in order.
    Return {key: (text, title1, title_position)}."""
    out, in_poem = {}, False
    for key, raw in verses:
        # "\\" is a paragraph break: not representable, dropped.
        parsed = VerseLines(key, in_poem).feed(raw.replace("\\", " "))
        in_poem = parsed.in_poem
        out[key] = parsed.render()
    if in_poem:
        raise ValueError(f"unterminated [ at end of book {verses[-1][0]}")
    return out


def sql_str(s):
    return "E'" + s.replace("\\", "\\\\").replace("'", "''").replace("\n", "\\n") + "'"


def load_db(path):
    """{key: (verseID, text, [title1, title2, title3])} from the COPY dump (Esther skipped)."""
    db = {}
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            vid, book, chapter, verse, text, t1, t2, t3 = line.rstrip("\n").split("\t")
            if int(book) == ESTHER:
                continue
            key = (int(book), int(chapter), int(verse))
            if key in db:
                raise ValueError(f"duplicate key outside Esther: {key}")
            db[key] = (int(vid), copy_unescape(text), [("" if t == "\\N" else copy_unescape(t)) for t in (t1, t2, t3)])
    return db


def check_content(key, text, old):
    """Apart from the markup, the new text must say what the stored text says
    (Sir 1:1, truncated on import, must extend it)."""
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


def build_update(key, vid, old, text, title, position, stats):
    """The guarded UPDATE for one verse, or None when nothing changes."""
    if title is None and re.sub(r"\s+", " ", old).strip() == text:
        return None  # no markup in this verse; leave its whitespace alone
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
    return (f'UPDATE "VGCL" SET {", ".join(sets)} WHERE "verseID" = {vid} '
            f"AND text = {sql_str(old)} AND coalesce(title1, '') = '' AND coalesce(title2, '') = '' "
            f"AND coalesce(title3, '') = '';"
            f"  -- {BOOKS[key[0] - 1]} {key[1]}:{key[2]}")


def write_sql(updates, stats):
    w = sys.stdout.write
    w("-- Generated by scripts/format_vgcl_from_source.py -- do not edit by hand.\n")
    w("-- Stores the Clementine Vulgate Project markup of VGCL the way the other\n")
    w("-- editions do (see the script's docstring for the rules), and restores the\n")
    w("-- truncated Sir 1:1. PostgreSQL only.\n")
    w(f"-- {len(updates)} rows: {stats['text']} text, {stats['title']} title1, {stats['position']} title_position.\n")
    w("-- Rows whose text changes get a new text_plain/text_hash from the trigger.\n")
    w("-- Re-embed afterwards: only embedding_labse (the default column) tracks\n")
    w("-- text_hash; MiniLM `embedding` is only filled where NULL. So first NULL\n")
    w("-- `embedding` where text_hash <> embedded_text_hash, then run\n")
    w("-- compute_embeddings.py --version VGCL and the same with --column embedding.\n\n")
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


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source", required=True, help="Clementine-Vulgate/src/utf8 directory")
    ap.add_argument("--dump", required=True, help="COPY dump of the VGCL table (see docstring)")
    args = ap.parse_args()

    db = load_db(args.dump)
    by_book = {}
    for book, chapter, verse, raw in load_source(args.source):
        if book != ESTHER:
            by_book.setdefault(book, []).append(((book, chapter, verse), raw))

    updates, stats = [], {"text": 0, "title": 0, "position": 0}
    for verses in by_book.values():
        for key, (text, title, position) in format_book(verses).items():
            if key not in db:
                raise ValueError(f"source verse missing from DB: {key}")
            vid, old, titles = db[key]
            check_content(key, text, old)
            if any(titles):
                raise ValueError(f"{key} already has titles: {titles}")
            update = build_update(key, vid, old, text, title, position, stats)
            if update:
                updates.append(update)

    write_sql(updates, stats)
    print(f"{len(updates)} updates; {stats}", file=sys.stderr)


if __name__ == "__main__":
    main()
