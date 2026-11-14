#!/usr/bin/env python3
"""Generate migrations/023-cei2008-formatted-text.sql from the bibbiaedu.it archive.

CEI2008 was imported as flat text: no line breaks, no headings. bibbiaedu.it,
the edition's source, renders it from USFM data, and the private archive
BibleGet-I-O/CEI2008-bibbiaedu keeps each chapter's region byte for byte
(html/<at|nt>/<Book>/<NNN>.html). This script parses that archive and stores
the formatting the way the other editions do (NABRE tag set):

Layout on bibbiaedu.it (verified over all 1,328 chapters, 2026-11-14):
  * poetry: every line ends with <br>, lines run on across verses, <br><br>
    ends a stanza; in Psalms/Job a leading tab (or two) indents a line;
  * prose: no <br> inside a verse; a trailing <br> only ends a paragraph.
  A verse is poetic when it has two or more lines, or when it is a single
  line ending in <br> that sits inside a poem (multi-line verses on both
  sides) or on its edge and looks like a verse line (<= PROSE_LINE_MAX
  characters, not a ':' lead-in). Isolated single lines ending in <br>
  (median 129 characters) are prose paragraph ends.

Stored as:
  * poetic lines: <po>, or <poi> when indented deeper than the chapter's
    shallowest poetic line; the first line of a stanza/poem gets the "f"
    variant (<pof>/<poif>), the last the "l" variant (<pol>/<poil>);
    lines joined by "\\n";
  * a ':' lead-in before a poem and a psalm superscription (a line wholly in
    <i>) stay untagged lines, as in NABRE; prose stays untagged, paragraph
    ends are dropped (no paragraph construct to store them in);
  * <i> kept (<em> becomes <i>), balanced per line;
  * section headings (usfm "s"/"s s2", only present for Gen, Ex, Lv, Nm and
    Psalms on the site) -> title1..title3 of the next verse, in order; the
    "sr" verse-range labels are not stored;
  * Psalm acrostic letters (usfm "qa": Alef, Bet, ...) -> the next free
    title when they precede a verse, title_position when one falls inside
    a verse after its first line. A verse with several letters (Ps 111,
    112: one per half-line) or with a letter inside the verse *and*
    headings before it keeps only the headings (see issue #177).

Verses are matched to rows by chapter and label (verse number, or
verseequiv letter label such as "1a"; Psalm verseequiv "(9,22)" cross-
numbers are ignored), Esther's HEBREW/GREEK rows by matching text. A row is
updated only when the formatted text normalizes (bibleget_plain_text) to
exactly its current text_plain, so no wording, hash or embedding changes.
Where the wording differs only slightly (difflib ratio >= 0.9: spacing,
stress accents, Sirach's ⊥ marks) the site's tags and line breaks are
transplanted onto OUR characters, so the stored wording still never
changes; those rows go to --wording-report. Larger differences and rows
missing on either side go to --report for review and are not applied.

Usage:
    python3 scripts/format_cei2008_from_source.py \\
        --archive ../CEI2008-bibbiaedu --dump cei.copy \\
        --report ../CEI2008-bibbiaedu/reports/unmatched.tsv \\
        > migrations/023-cei2008-formatted-text.sql

where cei.copy is produced on the database with
    \\copy (SELECT "verseID", book, chapter, verse, coalesce(verseequiv,''),
            coalesce(versedescr,''), coalesce(verseorigin,''), text, text_plain,
            coalesce(title1,''), coalesce(title2,''), coalesce(title3,''),
            coalesce(title_position::text,'') FROM "CEI2008"
            ORDER BY "verseID") TO cei.copy
"""

import argparse
import collections
import difflib
import html
import re
import sys
from pathlib import Path

SLUGS = ("Gen Es Lv Nm Dt Gs Gdc Rt 1Sam 2Sam 1Re 2Re 1Cr 2Cr Esd Ne Tb Gdt Est 1Mac 2Mac "
         "Gb Sal Pr Qo Ct Sap Sir Is Ger Lam Bar Ez Dn Os Gl Am Abd Gn Mi Na Ab Sof Ag Zc Ml "
         "Mt Mc Lc Gv At Rm 1Cor 2Cor Gal Ef Fil Col 1Ts 2Ts 1Tm 2Tm Tt Fm Eb Gc 1Pt 2Pt "
         "1Gv 2Gv 3Gv Gd Ap").split()
BOOK = {slug: n for n, slug in enumerate(SLUGS, 1)}  # BibleGet universal book number
PROSE_LINE_MAX = 90
TEXT_MAX = 900
TITLE_MAX = 255
INLINE_TAGS = r"</?(sm|i|sup)>"
BLOCK_TAGS = r"</?(po|pof|pol|poi|poil|poif|pos|po3|po3l|pr|bq|bqf|bql|speaker)>"
LETTER = "\x01"  # placeholder for an acrostic letter inside a verse body
# Hebrew letter names as bibbiaedu.it spells them ("qa" spans in the Psalms,
# italic words "<i> Alef</i>" in Lamentations, Proverbs 31 and Nahum 1).
LETTER_NAMES = ("Alef Bet Ghimel Dalet He Vau Zain Het Tet Iod Caf Lamed Mem Nun Samec Ain Pe Sade Kof "
                "Res Sin Tau").split()
TRANSPLANT_MIN_RATIO = 0.9

EVENT_RE = re.compile(
    r'<div class="usfm usfm_titlesheadingsandlabel (?P<hcls>s(?: s2)?)">(?P<heading>.*?)</div>'
    r'|<span class="usfm usfm_poetry qa">(?P<letter>[^<]*)</span>'
    r'|<span data-verses-id="\d+" class="verse[^"]*" id="verse_\d+">(?P<verse>.*?)</span>\s*</span>',
    re.S,
)


# ---------------------------------------------------------------- mirrors

def plain_text(formatted):
    """Python mirror of SQL bibleget_plain_text() (migration 019)."""
    text = re.sub(INLINE_TAGS, "", formatted)
    text = re.sub(BLOCK_TAGS, " ", text)
    text = re.sub(r"<br\s*/?>", " ", text, flags=re.I)
    return re.sub(r"\s+", " ", text).strip()


def valid_title_position(text, pos):
    """Python mirror of bibleget_title_position_ok() (migration 019)."""
    if pos is None:
        return True
    if not 0 < pos < len(text) or re.search(r"<[^>]*$", text[:pos]):
        return False

    def visible(fragment):
        return re.sub(r"<[^>]*>", " ", re.sub(INLINE_TAGS, "", fragment))

    return not (visible(text[:pos])[-1:].isalnum() and visible(text[pos:])[:1].isalnum())


# ---------------------------------------------------------------- parsing

def clean_inline(fragment):
    """Keep <i> (from <i> or <em>); drop every other tag; decode entities."""
    text = re.sub(r"<(/?)em>", r"<\1i>", fragment)
    text = re.sub(r"<(?!/?i>)[^>]+>", "", text)
    text = html.unescape(text).replace("\xa0", " ")
    return text


def balance_italics(lines):
    """Close an <i> left open at a line end and reopen it on the next line."""
    out, carry = [], False
    for line in lines:
        line = ("<i>" if carry else "") + line
        opens, closes = line.count("<i>"), line.count("</i>")
        carry = opens > closes
        line += "</i>" if carry else ""
        line = re.sub(r"<i>\s*</i>", "", line)
        line = re.sub(r"<i>\s+", " <i>", line)
        line = re.sub(r"\s+</i>", "</i> ", line)
        out.append(re.sub(r"\s+", " ", line).strip())
    return out


def verse_label(raw, previous):
    # The visible number wins over the hidden comment ("4a" vs "<!--<sup>4</sup>-->").
    m = re.search(r'<span class="verse_number">([^<]*)</span>', raw) or re.search(r"<!--<sup>([^<]*)</sup>-->", raw)
    if not m:
        return str(previous + 1)
    return m[1].split("(")[0].strip()


def split_lines(raw):
    """Verse body -> (lines, trailing_break). Each line is a dict with text,
    indent, stanza_end (a blank line follows) and letters (acrostic letters
    found at its start)."""
    body = re.sub(r"<button.*?</button>|<sup>.*?</sup>|<!--.*?-->", "", raw, flags=re.S)
    body = re.sub(r'^\s*<span class="text-to-speech">', "", body)
    body = re.sub(r'<span class="usfm usfm_poetry qa">([^<]*)</span>', lambda m: f"{LETTER}{m[1].strip()}{LETTER}", body)
    body = re.sub(r"<i>\s*(" + "|".join(LETTER_NAMES) + r")\s*</i>", lambda m: f"{LETTER}{m[1]}{LETTER}", body)
    body = re.sub(r"\\qa\s+(" + "|".join(LETTER_NAMES) + r")\b\s*", lambda m: f"{LETTER}{m[1]}{LETTER}", body)  # raw USFM leak (Ps 37:28)
    for _ in range(3):  # "<i>Di Davide.<br></i>": the </i> closes the line before the <br>
        body = re.sub(r"(<br\s*/?>)(\s*</(?:i|em)>)", r"\2\1", body)
    parts = re.split(r"<br\s*/?>", body)
    lines, texts, trailing = [], [], False
    for i, part in enumerate(parts):
        visible = re.sub(r"<[^>]+>|\s", "", part)
        if not visible:
            if lines and i < len(parts) - 1:
                lines[-1]["stanza_end"] = True
            trailing = trailing or (bool(lines) and i == len(parts) - 1)
            continue
        letters = re.findall(f"{LETTER}([^{LETTER}]*){LETTER}", part)
        before_letter = re.sub(r"<[^>]+>|\s", "", part.split(LETTER, 1)[0]) if letters else ""
        part = re.sub(f"{LETTER}[^{LETTER}]*{LETTER}", "", part)
        lead = re.match(r"[ ]*(\t*)", re.sub(r"<[^>]+>", "", part))
        indent = len(lead[1]) if lead else 0
        lines.append({"indent": indent, "stanza_end": False, "letters": letters,
                      "letter_mid": bool(letters) and bool(before_letter)})
        texts.append(clean_inline(part.replace("\t", " ")))
    for line, text in zip(lines, balance_italics(texts)):
        line["text"] = text
    trailing = trailing or (len(parts) > 1 and not re.sub(r"<[^>]+>|\s", "", parts[-1]))
    return lines, trailing


def parse_chapter(path):
    """Ordered events: ("heading", text) and ("verse", label, lines, trailing)."""
    events, previous = [], 0
    for m in EVENT_RE.finditer(path.read_text(encoding="utf-8")):
        if m["heading"] is not None:
            events.append(("heading", re.sub(r"\s+", " ", clean_inline(re.sub(r"</?i>", "", m["heading"]))).strip()))
        elif m["letter"] is not None:
            events.append(("letter", m["letter"].strip()))
        else:
            label = verse_label(m["verse"], previous)
            number = re.match(r"\d+", label)
            previous = int(number[0]) if number else previous
            lines, trailing = split_lines(m["verse"])
            events.append(("verse", label, lines, trailing))
    return events


# ---------------------------------------------------------------- layout

def attach_headings(events):
    """Fold heading events into the following verse: [(label, titles, lines, trailing)]."""
    verses, pending, letters = [], [], 0
    for event in events:
        if event[0] in ("heading", "letter"):
            pending.append(event[1])
            letters += event[0] == "letter"
        else:
            verses.append({"label": event[1], "titles": pending, "lines": event[2], "trailing": event[3],
                           "letters_before": letters})
            pending, letters = [], 0
    return verses


def is_lead_in(line):
    return line["text"].rstrip().endswith(":")


def is_superscription(line):
    return re.fullmatch(r"<i>[^<]*</i>[\s.]*", line["text"]) is not None


def mark_poetic(verses):
    """Set verse["poetic"] (see module docstring)."""
    multi = [len(v["lines"]) >= 2 for v in verses]
    for i, verse in enumerate(verses):
        if multi[i]:
            verse["poetic"] = True
            continue
        if not (verse["lines"] and verse["trailing"]):
            verse["poetic"] = False
            continue
        neighbours = (i > 0 and multi[i - 1]) + (i + 1 < len(verses) and multi[i + 1])
        line = verse["lines"][0]
        verse["poetic"] = neighbours == 2 or (
            neighbours == 1 and len(plain_text(line["text"])) <= PROSE_LINE_MAX and not is_lead_in(line)
        )


def is_plain_line(verse, k, prev_poetic):
    """A poetic verse's first line stays untagged when it is a psalm
    superscription or a ':' lead-in introducing the poem."""
    if k != 0:
        return False
    line = verse["lines"][0]
    return is_superscription(line) or (is_lead_in(line) and not prev_poetic and len(verse["lines"]) > 1)


class PoemTracker:
    """Flags the first and last line of every stanza/poem across verses."""

    def __init__(self):
        self.previous, self.open = None, False

    def close(self):
        if self.previous is not None:
            self.previous["last"] = True
        self.open = False

    def add(self, line, starts_block):
        line["first"], line["last"] = not self.open or starts_block, False
        if line["first"] and self.previous is not None:
            self.previous["last"] = True
        self.previous, self.open = line, not line["stanza_end"]
        if line["stanza_end"]:
            line["last"] = True


def line_roles(verses):
    """For every poetic verse, mark each line as "plain" (lead-in or
    superscription) or poetic, and flag stanza/poem starts and ends."""
    poem = PoemTracker()
    for i, verse in enumerate(verses):
        if not verse["poetic"]:
            poem.close()
            continue
        prev_poetic = i > 0 and verses[i - 1]["poetic"]
        for k, line in enumerate(verse["lines"]):
            if is_plain_line(verse, k, prev_poetic):
                line["role"] = "plain"
                poem.close()
                continue
            line["role"] = "poem"
            poem.add(line, bool(verse["titles"]) and k == 0)
    poem.close()


def render_line(line, base_indent):
    if line.get("role") != "poem":
        return line["text"]
    tag = "poi" if line["indent"] > base_indent else "po"
    tag += "f" if line["first"] else ("l" if line["last"] else "")
    return f"<{tag}>{line['text']}</{tag}>"


def half_line_acrostic(verses):
    """Ps 111/112 put a letter before each half-line, several per verse: no
    single heading position can hold them, so such chapters keep none."""
    return any(verse["letters_before"] + sum(len(line["letters"]) for line in verse["lines"]) > 1
               or any(line["letter_mid"] for line in verse["lines"])
               for verse in verses)


def drop_letters(verses):
    for verse in verses:
        if verse["letters_before"]:
            verse["titles"] = verse["titles"][:-verse["letters_before"]]
        for line in verse["lines"]:
            line["letters"] = []


def render_verse(verse, base_indent):
    """(text, titles, title_position) for one site verse."""
    lines = verse["lines"]
    if not verse["poetic"]:
        return " ".join(line["text"] for line in lines), list(verse["titles"]), None
    rendered = [render_line(line, base_indent) for line in lines]
    titles, position = list(verse["titles"]), None
    inner = [(k, letter) for k, line in enumerate(lines) for letter in line["letters"]]
    if len(inner) == 1 and inner[0][0] == 0:
        titles.append(inner[0][1])
    elif len(inner) == 1 and not titles:
        k = inner[0][0]
        position = len("\n".join(rendered[:k])) + 1
        titles = [inner[0][1]]
    return "\n".join(rendered), titles, position


def format_chapter(path):
    """{label: (text, titles, title_position)} for every verse of a chapter."""
    verses = attach_headings(parse_chapter(path))
    if half_line_acrostic(verses):
        drop_letters(verses)
    mark_poetic(verses)
    line_roles(verses)
    indents = [line["indent"] for v in verses if v["poetic"] for line in v["lines"] if line.get("role") == "poem"]
    base = min(indents) if indents else 0
    return {verse["label"]: render_verse(verse, base) for verse in verses}


# ---------------------------------------------------------------- database

def copy_unescape(s):
    return re.sub(r"\\(.)", lambda m: {"n": "\n", "t": "\t", "\\": "\\"}.get(m[1], m[1]), s)


def load_dump(path):
    """{(book, chapter): {label: [row, ...]}} (Esther has HEBREW and GREEK rows per label)."""
    rows = collections.defaultdict(lambda: collections.defaultdict(list))
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            f = [copy_unescape(x) for x in line.rstrip("\n").split("\t")]
            label = f[3] if not f[4] or f[4].startswith("(") else f[4]
            rows[(int(f[1]), int(f[2]))][label].append({
                "vid": int(f[0]), "verse": int(f[3]), "text": f[7], "plain": f[8],
                "titles": f[9:12], "position": f[12], "origin": f[6],
            })
    return rows


def merge_lettered(site, db_labels):
    """Site "4a","4b" for a row stored as plain "4": join them (lines, with
    a heading between them as a mid-verse title_position)."""
    merged, groups = {}, collections.defaultdict(list)
    for label, value in site.items():
        number = re.match(r"(\d+)[a-z]$", label)
        if number and label not in db_labels and number[1] in db_labels and number[1] not in site:
            groups[number[1]].append(value)
        else:
            merged[label] = value
    for number, parts in groups.items():
        text, titles, position = parts[0]
        for part_text, part_titles, _ in parts[1:]:
            if part_titles and not titles and position is None:
                position, titles = len(text) + 1, part_titles
            text = f"{text}\n{part_text}" if "\n" in part_text or "\n" in text else f"{text} {part_text}"
        merged[number] = (text, titles, position)
    return merged


def visible_map(formatted):
    """Characters of plain_text(formatted) with, for each, the index in
    `formatted` it comes from (-1 for a separator that a tag or a line break
    became)."""
    out, i = [], 0
    while i < len(formatted):
        tag = re.match(r"</?([a-z0-9]+)>", formatted[i:])
        if tag:
            if not re.fullmatch(r"sm|i|sup", tag[1]):
                out.append((" ", -1))
            i += len(tag[0])
            continue
        out.append((formatted[i], i))
        i += 1
    collapsed = []
    for ch, idx in out:
        if ch.isspace():
            if collapsed and not collapsed[-1][0].isspace():
                collapsed.append((" ", idx))
            continue
        collapsed.append((ch, idx))
    while collapsed and collapsed[-1][0].isspace():
        collapsed.pop()
    return collapsed


def transplant(formatted, target):
    """Rewrite `formatted` so that plain_text() gives exactly `target` (our
    stored wording), keeping the site's tags and line breaks. Only for small
    differences (spacing, accents, editorial marks); None otherwise."""
    vis = visible_map(formatted)
    source = "".join(ch for ch, _ in vis)
    matcher = difflib.SequenceMatcher(None, source, target, autojunk=False)
    if matcher.ratio() < TRANSPLANT_MIN_RATIO:
        return None
    edits = []  # (start, end, replacement) in `formatted` coordinates
    for op, i1, i2, j1, j2 in matcher.get_opcodes():
        if op == "equal":
            continue
        positions = [vis[k][1] for k in range(i1, i2)]
        if any(p < 0 for p in positions):
            return None  # would have to change a line break or a tag boundary
        if positions:
            edits.append((positions[0], positions[-1] + 1, target[j1:j2]))
        else:
            # insert right after the previous character, so it stays on its line
            anchor = vis[i1 - 1][1] + 1 if i1 > 0 and vis[i1 - 1][1] >= 0 else (
                vis[i1][1] if i1 < len(vis) else len(formatted))
            if anchor < 0:
                return None
            edits.append((anchor, anchor, target[j1:j2]))
    for start, end, repl in sorted(edits, reverse=True):
        formatted = formatted[:start] + repl + formatted[end:]
    return formatted if plain_text(formatted) == target else None


def match_row(candidates, text):
    """(row, text) where text normalizes to the row's text_plain: an exact
    match first, else the closest row with our wording transplanted."""
    for row in candidates:
        if plain_text(text) == row["plain"]:
            return row, text
    for row in candidates:
        moved = transplant(text, row["plain"])
        if moved is not None:
            return row, moved
    return None, None


def check_titles(titles, position, text):
    if len(titles) > 3 or any(len(t) > TITLE_MAX for t in titles):
        return "too many or too long titles"
    if len(text) > TEXT_MAX:
        return "formatted text longer than the column"
    if not valid_title_position(text, position):
        return "title_position fails the guard"
    return None


# ---------------------------------------------------------------- output

def sql_str(s):
    return "'" + s.replace("'", "''") + "'"


def build_update(row, text, titles, position):
    titles = (titles + ["", "", ""])[:3]
    if text == row["text"] and not any(titles) and position is None:
        return None
    sets = [f"text = {sql_str(text)}"] + [f"title{n} = {sql_str(t)}" for n, t in enumerate(titles, 1)]
    sets.append(f"title_position = {position if position is not None else 'NULL'}")
    return (f'UPDATE "CEI2008" SET {", ".join(sets)} WHERE "verseID" = {row["vid"]} '
            f"AND text = {sql_str(row['text'])} AND coalesce(title1, '') = '' AND coalesce(title2, '') = '' "
            f"AND coalesce(title3, '') = '' AND bibleget_plain_text({sql_str(text)}) = text_plain;")


def process(archive, db, report, wording):
    updates, stats, seen = [], collections.Counter(), set()
    for path in sorted(Path(archive, "html").glob("*/*/*.html")):
        key = (BOOK[path.parent.name], int(path.stem))
        rows = db.get(key, {})
        site = merge_lettered(format_chapter(path), set(rows))
        for label, (site_text, titles, position) in site.items():
            text = site_text
            row, text = match_row(rows.get(label, []), text)
            if row is None:
                stats["unmatched (site)"] += 1
                report.append((path.parent.name, key[1], label, "site", plain_text(site_text)))
                continue
            seen.add(row["vid"])
            problem = check_titles(titles, position, text)
            if problem:
                stats[problem] += 1
                report.append((path.parent.name, key[1], label, problem, plain_text(text)))
                continue
            if plain_text(site_text) != row["plain"]:
                stats["wording kept (layout only)"] += 1
                wording.append((path.parent.name, key[1], label, plain_text(site_text), row["plain"]))
            update = build_update(row, text, titles, position)
            if update:
                updates.append(f"{update}  -- {path.parent.name} {key[1]}:{label}")
                stats["headings" if titles else "formatted"] += 1
            else:
                stats["unchanged"] += 1
    for key, labels in db.items():
        for label, rows in labels.items():
            for row in rows:
                if row["vid"] not in seen:
                    stats["unmatched (db)"] += 1
                    report.append((SLUGS[key[0] - 1], key[1], label, "db", row["plain"]))
    return updates, stats


HEADER = """-- Migration: CEI2008 formatted text and section headings from bibbiaedu.it
--
-- Generated by scripts/format_cei2008_from_source.py from the private archive
-- BibleGet-I-O/CEI2008-bibbiaedu (downloaded 2026-11-14); see the script's
-- docstring for the layout rules. Requires 019 (text_plain, title_position).
--
-- Each UPDATE matches the row's current text and empty titles AND requires
-- bibleget_plain_text(new text) = text_plain, so the wording, text_hash and
-- embeddings cannot change. Where the site's wording differs slightly
-- (spacing, accents, editorial marks such as Sirach's ⊥), the site's layout
-- is applied to OUR wording (listed in the archive's reports/wording.tsv);
-- larger differences and rows missing on either side are not touched
-- (reports/unmatched.tsv). Idempotent.
--
"""


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--archive", required=True, help="CEI2008-bibbiaedu checkout")
    ap.add_argument("--dump", required=True, help="COPY dump of CEI2008 (see docstring)")
    ap.add_argument("--report", required=True, help="TSV of rows not formatted (contains copyrighted text: keep private)")
    ap.add_argument("--wording-report", required=True,
                    help="TSV of rows formatted with our wording where the site's differs (keep private)")
    args = ap.parse_args()

    report, wording = [], []
    updates, stats = process(args.archive, load_dump(args.dump), report, wording)
    with open(args.report, "w", encoding="utf-8") as fh:
        fh.write("book\tchapter\tlabel\tside\ttext\n")
        fh.writelines("\t".join(str(x) for x in r) + "\n" for r in report)
    with open(args.wording_report, "w", encoding="utf-8") as fh:
        fh.write("book\tchapter\tlabel\tsite_wording\tour_wording\n")
        fh.writelines("\t".join(str(x) for x in r) + "\n" for r in wording)
    out = sys.stdout
    out.write(HEADER)
    out.write(f"-- {len(updates)} rows: {dict(sorted(stats.items()))}\n\nBEGIN;\n\n")
    out.write("\n".join(updates))
    out.write("\n\nCOMMIT;\n")
    print(f"{len(updates)} updates; {dict(sorted(stats.items()))}", file=sys.stderr)


if __name__ == "__main__":
    main()
