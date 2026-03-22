# Plan: Bible Reference Tokenizer

**Status**: Proposed
**Date**: 2026-03-22
**Related Issue**: https://github.com/BibleGet-I-O/endpoint/issues/57

## Motivation

The current Bible reference parsing pipeline (`QueryValidator` + `QueryFormulator`) works through regex matching, string mutation, and implicit state tracking across 5+ instance variables. While functional and surprisingly robust, this approach has produced a pattern of subtle state-carryover bugs (evidenced by recent commits like `32cc800`, `f8f7db9`, `a87fea2`, `c2b7661`). A dedicated tokenizer/parser would make the system more maintainable, debuggable, and extensible.

## Current Architecture

```
Raw input string
  → QuoteContext (normalize whitespace, detect notation, split by ";")
  → QueryValidator (12 regex rules, string-based validation)
  → QueryFormulator (regex extraction + string mutation → SQL WHERE clauses)
  → QueryExecutor (run SQL)
```

### Pain Points

1. **String mutation during parsing** — `captureBookIndicator()` modifies `$this->currentQuery` via `substr()`, making debugging and replay difficult.
2. **Implicit state** — `currentBook`, `currentChapter`, `currentQuery`, `bookIdxBase`, `nonZeroBookIdx` in Validator; `currentVariant`, `currentPreferOrigin`, `previousBook` in Formulator. State carryover between loop iterations is a recurring source of bugs.
3. **No intermediate representation** — References go from string directly to SQL with no inspectable parse tree.
4. **12 interleaved validation rules** — Each uses a different regex pattern with no unified grammar. Adding new constructs requires understanding the full interaction matrix.
5. **Psalm verse mapping embedded in SQL construction** — The Catholic-version Psalm remapping happens during `mapReference()` calls, tangled with SQL generation.

## Proposed Architecture

```
Raw input string
  → Normalizer (whitespace, notation detection — largely unchanged)
  → Tokenizer (string → Token[])
  → Parser (Token[] → AST of BibleReference nodes)
  → Validator (walk AST, check against DB metadata)
  → SQLCompiler (walk AST → SQL WHERE clauses)
  → Executor (run SQL)
```

### Phase 1: Token Types

Token names describe **semantic meaning** rather than the literal character, since the
same character can carry different meaning depending on notation (e.g. `,` is a
chapter-verse separator in European notation but a query separator in English notation
before normalization). The tokenizer operates *after* notation normalization, so each
separator character maps to exactly one semantic role.

Where a token's semantic role is **ambiguous at the lexical level** (e.g. `-` could
indicate a verse range, chapter range, or cross-chapter range), we use a general
semantic name. The parser resolves the ambiguity and encodes the distinction in AST
node types.

For numeric tokens, however, the tokenizer **can and should** resolve chapter vs verse
semantics. Every number in a Bible reference is deterministically either a chapter or a
verse based on what precedes and follows it. The tokenizer uses a small amount of
context (preceding token + 1-token lookahead) to classify each number, making the
token stream fully self-describing.

#### Number classification rules

The tokenizer tracks a minimal `context` flag (`chapter_level` vs `verse_level`) that
starts at `chapter_level` after each `BOOK_NAME` and shifts to `verse_level` after a
`CHAPTER_VERSE_SEPARATOR`. The rules:

| Preceding token | Following token | Classification | Example | Rationale |
|---|---|---|---|---|
| `BOOK_NAME` | any | `CHAPTER_NUMBER` | **1** in `Genesis1` | A number directly after a book name is always a chapter |
| any | `CHAPTER_VERSE_SEPARATOR` | `CHAPTER_NUMBER` | **1** in `1,5` | A number before `,` introduces the chapter for what follows |
| `CHAPTER_VERSE_SEPARATOR` | any | `VERSE_NUMBER` | **5** in `1,5` | A number after `,` is the verse within the preceding chapter |
| `RANGE_SEPARATOR` | `CHAPTER_VERSE_SEPARATOR` | `CHAPTER_NUMBER` | **2** in `1,5-2,3` | Followed by `,` → starts a new chapter,verse pair |
| `RANGE_SEPARATOR` | not `CHAPTER_VERSE_SEPARATOR` | inherits context | **10** in `1,5-10` → `VERSE_NUMBER`; **3** in `1-3` → `CHAPTER_NUMBER` | If the left side of the range was at verse level (`1,5-`), the right side stays at verse level. If at chapter level (`1-`), it stays at chapter level. |
| `EXPLICIT_VERSE_SEPARATOR` | any | `VERSE_NUMBER` | **5** in `1,1-3.5` | `.` always introduces a non-consecutive verse within the current chapter |

> **Key insight**: the `RANGE_SEPARATOR` is the only case requiring inherited context.
> All other positions are fully determined by the immediately adjacent tokens.
> The tokenizer tracks this with a single boolean flag, not a grammar stack.

Two additional classification rules for special constructs:

| Pattern | Classification | Example | Rationale |
|---|---|---|---|
| Lowercase letter immediately after `VERSE_NUMBER` | `PARTIAL_VERSE_SUFFIX` | **a** in `4a` | Indicates a sub-verse; always follows a `VERSE_NUMBER` with no intervening separator |
| `(` after `CHAPTER_NUMBER`, number inside, `)` | `OPEN_PARENTHESIS` + `ALTERNATE_CHAPTER` + `CLOSE_PARENTHESIS` | **(50)** in `Psalm51(50)` | Dual Psalm numbering; the number outside parens is `CHAPTER_NUMBER`, the one inside is `ALTERNATE_CHAPTER` |

```php
enum TokenType: string
{
    // ── Identifiers ──────────────────────────────────────────────
    case BOOK_NUMERIC_PREFIX  = 'BOOK_NUMERIC_PREFIX';  // "1"–"4" preceding a book name
    case BOOK_NAME            = 'BOOK_NAME';            // "Genesis", "John", "Gv", …

    // ── Numeric tokens (resolved by context during tokenization) ─
    case CHAPTER_NUMBER       = 'CHAPTER_NUMBER';       // number in chapter position
    case VERSE_NUMBER         = 'VERSE_NUMBER';         // number in verse position
    case PARTIAL_VERSE_SUFFIX = 'PARTIAL_VERSE_SUFFIX'; // "a", "b", "c" etc. after a VERSE_NUMBER

    // ── Psalm dual numbering ────────────────────────────────────
    case OPEN_PARENTHESIS     = 'OPEN_PARENTHESIS';     // "(" — begins alternate chapter number
    case ALTERNATE_CHAPTER    = 'ALTERNATE_CHAPTER';     // number inside parentheses (alternate numbering system)
    case CLOSE_PARENTHESIS    = 'CLOSE_PARENTHESIS';    // ")" — ends alternate chapter number

    // ── Structural separators (semantic, post-normalization) ────
    case CHAPTER_VERSE_SEPARATOR          = 'CHAPTER_VERSE_SEPARATOR';          // "," — delimits chapter from verse
    case EXPLICIT_VERSE_SEPARATOR         = 'EXPLICIT_VERSE_SEPARATOR';         // "." — separates non-consecutive verses
    case RANGE_SEPARATOR                  = 'RANGE_SEPARATOR';                  // "-" — indicates a consecutive range
    //   The RANGE_SEPARATOR is intentionally general: the parser determines whether
    //   it spans verses within a chapter, whole chapters, or verses across chapters,
    //   and records that distinction in the AST (VerseRange vs ChapterRange).
    case QUERY_SEPARATOR                  = 'QUERY_SEPARATOR';                  // ";" — separates independent queries

    // ── Control ──────────────────────────────────────────────────
    case EOF                  = 'EOF';
}
```

Each `Token` value object carries:
- `type: TokenType` — semantic classification
- `value: string` — the original literal text (preserves what the user typed)
- `position: int` — byte offset in the *original* (pre-normalization) input, for error reporting

> **Design note — why no COLON token?**
> After notation normalization (English → European), `:` has already been replaced by `,`.
> The tokenizer operates on normalized input, so it only ever sees `,` in the chapter-verse
> separator role. If we later need to support mixed-notation or pre-normalization tokenization,
> we can add a `CHAPTER_VERSE_SEPARATOR_ENGLISH` variant without changing the grammar.

#### Partial verse suffixes

Some references include a lowercase letter after a verse number to indicate a sub-verse
(e.g. `Genesis2,4a`, `Mark16,9b`). The tokenizer emits these as `PARTIAL_VERSE_SUFFIX`
tokens immediately following the `VERSE_NUMBER` they modify.

The suffix is **preserved in the token stream and AST** for completeness (the user typed
it, so we record it), but **silently discarded during SQL compilation**. We cannot map
partial verse indicators to database rows because there is no standard for which portion
of the text they refer to — the database stores whole verses. The SQL compiler treats
`VERSE_NUMBER("4") PARTIAL_VERSE_SUFFIX("a")` identically to `VERSE_NUMBER("4")`.

#### Dual Psalm numbering

Psalms have two numbering traditions that diverge for Psalms 10–147:
- **Hebrew (Masoretic)** — used by Protestant translations and most modern Catholic translations
- **Greek (Septuagint/Vulgate)** — used by traditional Catholic/Orthodox liturgical sources

Many editions show both numbers, with the alternate in parentheses. The convention for
which number is primary vs parenthetical varies:

| Source | Format | Example |
|---|---|---|
| Modern Catholic (NABRE, CEI) | Hebrew first, Greek in parens | `Psalm 51(50)` |
| Traditional/Liturgical (Vulgate, older Catholic) | Greek first, Hebrew in parens | `Psalm 50(51)` |

The tokenizer recognizes this pattern: `CHAPTER_NUMBER OPEN_PARENTHESIS ALTERNATE_CHAPTER
CLOSE_PARENTHESIS`. It does not determine *which* numbering system is primary — that is
the parser's/validator's job based on the requested Bible version:

- For versions using Hebrew numbering (most modern translations): the primary
  `CHAPTER_NUMBER` is used; the `ALTERNATE_CHAPTER` is informational
- For versions using Greek numbering (VGCL, DRB): the `ALTERNATE_CHAPTER` may be
  used instead, or the validator can cross-check both against the version's index

**Standard for reliability**: the tokenizer always treats the **number outside
parentheses as the primary `CHAPTER_NUMBER`** and the **number inside parentheses as
`ALTERNATE_CHAPTER`**. The version-aware validator/compiler then decides which to use
for the actual query. This keeps the tokenizer stateless with respect to Bible version
metadata while allowing downstream stages to handle the version-specific mapping.

#### Tokenizer examples

| Input (normalized) | Token stream |
|---|---|
| `Genesis1` | `BOOK_NAME("Genesis") CHAPTER_NUMBER("1") EOF` |
| `Genesis1,5` | `BOOK_NAME("Genesis") CHAPTER_NUMBER("1") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("5") EOF` |
| `Genesis1,5-10` | `… CHAPTER_NUMBER("1") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("5") RANGE_SEPARATOR("-") VERSE_NUMBER("10") EOF` |
| `Genesis1-3` | `… CHAPTER_NUMBER("1") RANGE_SEPARATOR("-") CHAPTER_NUMBER("3") EOF` |
| `Genesis1,5-2,3` | `… CHAPTER_NUMBER("1") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("5") RANGE_SEPARATOR("-") CHAPTER_NUMBER("2") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("3") EOF` |
| `Genesis1,1-3.5.10` | `… CHAPTER_NUMBER("1") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("1") RANGE_SEPARATOR("-") VERSE_NUMBER("3") EXPLICIT_VERSE_SEPARATOR(".") VERSE_NUMBER("5") EXPLICIT_VERSE_SEPARATOR(".") VERSE_NUMBER("10") EOF` |
| `1John3,16` | `BOOK_NUMERIC_PREFIX("1") BOOK_NAME("John") CHAPTER_NUMBER("3") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("16") EOF` |
| `Genesis2,4a` | `… CHAPTER_NUMBER("2") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("4") PARTIAL_VERSE_SUFFIX("a") EOF` |
| `Genesis2,4a-7b` | `… VERSE_NUMBER("4") PARTIAL_VERSE_SUFFIX("a") RANGE_SEPARATOR("-") VERSE_NUMBER("7") PARTIAL_VERSE_SUFFIX("b") EOF` |
| `Psalm51(50),1` | `BOOK_NAME("Psalm") CHAPTER_NUMBER("51") OPEN_PARENTHESIS("(") ALTERNATE_CHAPTER("50") CLOSE_PARENTHESIS(")") CHAPTER_VERSE_SEPARATOR(",") VERSE_NUMBER("1") EOF` |

### Phase 2: AST Node Types

The AST encodes the **resolved semantic structure** that the token stream left ambiguous.
In particular, the parser resolves `RANGE_SEPARATOR` into the correct range node type.

```php
// A single verse: book 43, chapter 3, verse 16
class VerseRef {
    public int $book;
    public int $chapter;
    public ?int $alternateChapter;  // dual Psalm numbering: number in parentheses
    public ?int $verse;             // null = whole chapter
    public ?string $partialSuffix;  // "a", "b", etc. — preserved but ignored in SQL
}

// A consecutive range of verses, possibly spanning chapters
// The parser determines the range kind from surrounding context:
//   "1,5-10"   → same chapter (chapter=1, fromVerse=5, toVerse=10)
//   "1-3"      → chapter range (fromChapter=1, toChapter=3, no verses)
//   "1,5-2,3"  → cross-chapter (from=1:5, to=2:3)
class VerseRange {
    public VerseRef $from;
    public VerseRef $to;
}

// A complete query: may contain ranges and individual verses
// Segments are separated by EXPLICIT_VERSE_SEPARATOR (".")
class BibleQuery {
    public int $book;
    /** @var array<VerseRef|VerseRange> */
    public array $segments;  // non-consecutive segments joined by "."
}
```

### Phase 3: Parser

A simple recursive-descent parser that consumes the token stream. Because the tokenizer
has already resolved `CHAPTER_NUMBER` vs `VERSE_NUMBER`, the grammar productions map
directly to AST nodes without further disambiguation:

```
query          → bookRef segments
bookRef        → BOOK_NUMERIC_PREFIX? BOOK_NAME
segments       → segment (EXPLICIT_VERSE_SEPARATOR verseRef)*
segment        → chapterRef (RANGE_SEPARATOR rangeTarget)?
chapterRef     → CHAPTER_NUMBER altChapter? (CHAPTER_VERSE_SEPARATOR verse)?
rangeTarget    → CHAPTER_NUMBER altChapter? (CHAPTER_VERSE_SEPARATOR verse)?
               | verse
verseRef       → verse (RANGE_SEPARATOR verse)?
verse          → VERSE_NUMBER PARTIAL_VERSE_SUFFIX?
altChapter     → OPEN_PARENTHESIS ALTERNATE_CHAPTER CLOSE_PARENTHESIS
```

The parser reads the already-classified token types and builds the correct AST node:

- `Genesis1` → `BibleQuery(book, [VerseRef(chapter=1, verse=null)])`
- `Genesis1,5` → `BibleQuery(book, [VerseRef(chapter=1, verse=5)])`
- `Genesis1,5-10` → `BibleQuery(book, [VerseRange(from=1:5, to=1:10)])` — `VERSE_NUMBER("10")` tells parser it's same-chapter
- `Genesis1-3` → `BibleQuery(book, [VerseRange(from=ch1, to=ch3)])` — `CHAPTER_NUMBER("3")` tells parser it's a chapter range
- `Genesis1,5-2,3` → `BibleQuery(book, [VerseRange(from=1:5, to=2:3)])` — `CHAPTER_NUMBER("2")` tells parser it's cross-chapter
- `Genesis1,1-3.5.10` → `BibleQuery(book, [VerseRange(from=1:1, to=1:3), VerseRef(1:5), VerseRef(1:10)])`
- `Genesis2,4a` → `BibleQuery(book, [VerseRef(chapter=2, verse=4, partialSuffix="a")])` — suffix preserved in AST, ignored in SQL
- `Psalm51(50),1` → `BibleQuery(book, [VerseRef(chapter=51, altChapter=50, verse=1)])` — validator picks the correct chapter per version

### Phase 4: Validation Pass

Walk the AST and validate against database metadata:
- Book name exists in the requested language
- Chapter number is within bounds for the book
- Verse number is within bounds for the chapter (per version)
- Range start <= range end
- Cross-chapter range chapters are in order

Errors carry token position for precise user-facing messages:
```
"Invalid verse reference at position 12: verse 99 exceeds maximum (31) for John chapter 3"
```

### Phase 5: SQL Compiler

Walk the AST to produce SQL WHERE clauses. This is where:
- Psalm verse mapping for Catholic versions is applied (as an AST transformation *before* SQL generation)
- `preferorigin` annotations are added
- Same-chapter vs cross-chapter range predicates are chosen

The Psalm mapping becomes an explicit AST rewrite pass rather than being entangled with SQL generation.

### Phase 6: Integration

- `QuoteHandler` orchestration remains the same
- `QuoteContext` continues to hold shared state (DB connection, metadata, results)
- The tokenizer/parser/validator/compiler replace the internals of `QueryValidator` and `QueryFormulator`
- Public API of the pipeline stays unchanged

## Implementation Steps

### Step 1: Token and AST types
- [ ] Create `src/Pipeline/Tokenizer/TokenType.php` (enum)
- [ ] Create `src/Pipeline/Tokenizer/Token.php` (value object)
- [ ] Create `src/Pipeline/Ast/VerseRef.php`
- [ ] Create `src/Pipeline/Ast/VerseRange.php`
- [ ] Create `src/Pipeline/Ast/BibleQuery.php`

### Step 2: Tokenizer
- [ ] Create `src/Pipeline/Tokenizer/ReferenceTokenizer.php`
- [ ] Handle Unicode book names (same regex as current `matchBookInQuery`)
- [ ] Handle notation normalization (English→European) at token level or as pre-tokenization step
- [ ] Unit tests: tokenize various reference formats

### Step 3: Parser
- [ ] Create `src/Pipeline/Parser/ReferenceParser.php`
- [ ] Implement recursive-descent grammar
- [ ] Produce AST nodes from token stream
- [ ] Syntax error reporting with token positions
- [ ] Unit tests: parse → AST for all reference patterns

### Step 4: AST-based Validator
- [ ] Create `src/Pipeline/Validator/AstValidator.php`
- [ ] Walk AST nodes, validate against DB metadata (chapter/verse bounds)
- [ ] Return structured error list with positions
- [ ] Unit tests covering all 12 current validation rules

### Step 5: Psalm Mapping as AST Transform
- [ ] Create `src/Pipeline/Transform/PsalmRemapper.php`
- [ ] Apply Catholic-version Psalm verse mapping as AST → AST rewrite
- [ ] Unit tests for all mapping cases

### Step 6: SQL Compiler
- [ ] Create `src/Pipeline/Compiler/SqlCompiler.php`
- [ ] Walk AST to produce SQL WHERE clauses
- [ ] Handle same-chapter ranges, cross-chapter ranges, discontinuous verses
- [ ] Handle `preferorigin` annotation
- [ ] Handle copyright-limited verse count (`LIMIT 30`)
- [ ] Unit tests: AST → SQL for all query shapes

### Step 7: Integration
- [ ] Refactor `QueryValidator` to delegate to Tokenizer → Parser → AstValidator
- [ ] Refactor `QueryFormulator` to delegate to PsalmRemapper → SqlCompiler
- [ ] Ensure all existing integration/HTTP tests pass
- [ ] Remove dead code from old regex-based implementation

### Step 8: Cleanup
- [ ] Remove unused regex constants and helper methods
- [ ] Update CLAUDE.md architecture documentation
- [ ] Update any relevant inline comments

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Regression in edge cases | Comprehensive test suite before refactoring; run old and new parsers in parallel during transition |
| Performance overhead of AST allocation | Bible references are short strings; allocation cost is negligible vs DB query time |
| Scope creep | Each step is independently testable and deployable; can pause after any step |
| Unicode edge cases | Reuse existing Unicode regex patterns from current implementation |

## Non-Goals (for now)

- Changing the public API or response format
- Adding new reference syntaxes (e.g., cross-references, footnote markers)
- Changing the database schema
- Multi-version query syntax changes

## Success Criteria

- All existing tests pass without modification
- No implicit mutable state in the parsing pipeline
- Token positions in error messages for all validation failures
- Psalm mapping is a standalone, testable AST transform
- Adding a new reference construct requires only grammar + AST node changes, not touching SQL generation
