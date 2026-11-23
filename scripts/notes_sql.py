"""The SQL of a notes data half (migration 064's tables), shared by the editions' import scripts.

A data half deletes the edition's notes and inserts them again, in one transaction: re-running it gives the same
rows. Anchors and citations find their note by (edition, source_id), so the serial ids never appear here.
"""

NoteRow = tuple  # (source_id, kind, marker, label, title, body)
AnchorRow = tuple  # (source_id, (book, chapter, verse, segment, tradition, printed_chapter), field, position, last)
RefRow = tuple  # (source_id, ord, (book, chapter, verse, segment, printed_chapter), end, span_start, span_end[, edition])


def lit(v, cast="text"):
    """A SQL literal: NULL for None or ""; an int; or a quoted string."""
    if v is None or v == "":
        return f"NULL::{cast}"
    if cast == "int":
        return str(int(v))
    return "'" + str(v).replace("'", "''") + "'"


def chunks(values, size=500):
    for i in range(0, len(values), size):
        yield values[i:i + size]


def load_citations(path):
    """{(source_id, citation as the source gives it): corrected citation, or None for none}, from a TSV of
    source_id, citation, correction ("-" = not a link), reason."""
    out = {}
    if path:
        for line in open(path, encoding="utf-8").read().splitlines():
            if line.strip() and not line.startswith("#"):
                sid, cited, corrected = line.split("\t")[:3]
                out[(sid, cited)] = None if corrected == "-" else corrected
    return out


def correct_refs(sid, refs, corrections, key, change):
    """A note's citations with the corrections applied, and the (source_id, citation) keys used."""
    out, used = [], set()
    for r in refs:
        k = (sid, key(r))
        if k not in corrections:
            out.append(r)
            continue
        used.add(k)
        if corrections[k] is not None:
            out.append(change(r, corrections[k]))
    return out, used


def _joined(edition, head, columns, rows):
    tail = f"\n) AS v({columns})\nJOIN note n ON n.edition = {lit(edition)} AND n.source_id = v.source_id;"
    return [head + ",\n".join(c) + tail for c in chunks(rows)]


def insert_notes(edition, notes):
    """Notes; an empty body (a note that is all title) stays an empty string: `body` is NOT NULL."""
    rows = []
    for sid, kind, marker, label, title, body in notes:
        title_sql = lit(title) if title else "NULL"
        body_sql = lit(body) if body else "''"
        rows.append(f"({lit(edition)}, {lit(kind)}, {lit(marker)}, {lit(label)}, {title_sql}, {body_sql}, {lit(sid)})")
    return [("INSERT INTO note (edition, kind, marker, label, title, body, source_id) VALUES\n"
             + ",\n".join(c) + ";") for c in chunks(rows)]


def insert_anchors(edition, anchors):
    """Anchors; the last verse of a note's range (`last`, a (book, chapter, verse) or None) only when one has it."""
    with_last = any(a[4] for a in anchors)
    rows = []
    for sid, (b, c, v, seg, trad, pc), field, position, last in anchors:
        pos = lit(position, "int") if position is not None else "NULL::int"
        row = f"({lit(sid)}, {b}, {c}, {v}, {lit(seg)}, {lit(trad)}, {lit(pc, 'int')}, {lit(field)}, {pos}"
        if with_last:
            lb, lc, lv = last or (None, None, None)
            row += f", {lit(lb, 'int')}, {lit(lc, 'int')}, {lit(lv, 'int')}"
        rows.append(row + ")")
    cols = "note_id, book, chapter, verse, verse_segment, text_tradition, printed_chapter, field, position"
    vals = "n.id, v.book, v.chapter, v.verse, v.seg, v.trad, v.pc, v.field, v.pos"
    names = "source_id, book, chapter, verse, seg, trad, pc, field, pos"
    if with_last:
        cols, vals, names = cols + ", last_book, last_chapter, last_verse", vals + ", v.lb, v.lc, v.lv", names + ", lb, lc, lv"
    return _joined(edition, f"INSERT INTO note_anchor ({cols})\nSELECT {vals} FROM (VALUES\n", names, rows)


def insert_refs(edition, refs):
    """Citations; a 7th element names another edition the passage is in (migration 066), written only when one does."""
    other = any(len(r) > 6 and r[6] for r in refs)
    rows = []
    for r in refs:
        sid, ord_, (b, c, v, seg, pc), end, s, e = r[:6]
        eb, ec, ev = end or (None, None, None)
        row = (f"({lit(sid)}, {ord_}, {b}, {c}, {lit(v, 'int')}, {lit(seg)}, {lit(pc, 'int')}, "
               f"{lit(eb, 'int')}, {lit(ec, 'int')}, {lit(ev, 'int')}, {s}, {e}")
        rows.append(row + (f", {lit(r[6] if len(r) > 6 else None)})" if other else ")"))
    cols, vals, names = "span_start, span_end", "v.s, v.e", "s, e"
    if other:
        cols, vals, names = cols + ", edition", vals + ", v.ed", names + ", ed"
    head = ("INSERT INTO note_ref (note_id, ord, book, chapter, verse, verse_segment, printed_chapter, end_book, "
            f"end_chapter, end_verse, {cols})\nSELECT n.id, v.ord, v.book, v.chapter, v.verse, v.seg, v.pc, "
            f"v.eb, v.ec, v.ev, {vals} FROM (VALUES\n")
    return _joined(edition, head, f"source_id, ord, book, chapter, verse, seg, pc, eb, ec, ev, {names}", rows)


def data_half(edition, comment, notes, anchors, refs):
    """The whole data half: BEGIN, delete the edition's notes, insert notes, anchors and citations, COMMIT."""
    parts = ["BEGIN;", f"-- {comment}", f"DELETE FROM note WHERE edition = {lit(edition)};"]
    parts += insert_notes(edition, notes) + insert_anchors(edition, anchors) + insert_refs(edition, refs)
    parts.append("COMMIT;")
    return "\n".join(parts) + "\n"
