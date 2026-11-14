"""Python mirror of the SQL bibleget_markup_ok() (migration 030).

Used by the data generators (format_cei2008_from_source.py,
repair_cei2008_from_source.py, repair_luzzi_from_source.py,
format_vgcl_from_source.py) to refuse a malformed formatted text before any
SQL is written: the database would reject it anyway through the
<T>_markup_check constraint.
"""

import re

LINE_TAGS = frozenset("po pof pol poi poil poif pos po3 po3l pr bq bqf bql".split())
INLINE_TAGS = frozenset("sm i sup speaker".split())
TAG = re.compile(r"<(/?)([A-Za-z][A-Za-z0-9]*)([^>]*)>")


def markup_problem(text):
    """None when the markup is well formed, else a short description."""
    for line in text.split("\n"):
        stack = []
        for m in TAG.finditer(line):
            closing, tag, rest = m[1] == "/", m[2], m[3]
            if rest or (tag not in LINE_TAGS and tag not in INLINE_TAGS):
                return f"unknown or malformed tag {m[0]!r}"
            if not closing:
                if tag in LINE_TAGS and any(t in LINE_TAGS for t in stack):
                    return f"line tag <{tag}> inside a line tag"
                stack.append(tag)
            elif not stack or stack[-1] != tag:
                return f"unmatched or mis-nested </{tag}>"
            else:
                stack.pop()
        if stack:
            return f"<{stack[-1]}> left open at the end of a line"
    return None


def markup_ok(text):
    return markup_problem(text) is None


def require_markup_ok(text, where):
    """Raise ValueError naming `where` when the markup is malformed."""
    problem = markup_problem(text)
    if problem:
        raise ValueError(f"malformed markup in {where}: {problem}")
