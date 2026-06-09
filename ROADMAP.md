# Roadmap

This document tracks the planned evolution of **oihana/php-arango** against the
ArangoDB 3.12 AQL feature set. It is indicative, not contractual: priorities may
shift. The library is versioned through git tags (no `version` field in
`composer.json`), and follows [Semantic Versioning](https://semver.org).

## Where we are

As of **1.0.0**, the library covers the bulk of the AQL surface:

- **All 22 high-level operations** (`FOR`, `FILTER`, `SORT`, `LIMIT`, `LET`,
  `COLLECT`, `WINDOW`, `RETURN`, `INSERT`, `UPDATE`, `REPLACE`, `UPSERT`, `REMOVE`,
  graph traversal, `PRUNE`, `SEARCH`, `WITH`, `OPTIONS`, …) — `WINDOW` landed in 1.0.0.
- **~165 AQL functions** across strings, numerics, dates, arrays, documents and geo.
- **ArangoSearch**: View and Analyzer management clients, plus the `SEARCH` operation.
- **Transactions**, **18+ index types** (including a vector index), the full
  filter / facet / group engine, and 100% line/method test coverage.

## Versioning strategy

`1.0.0` ships with all high-level AQL operations supported and a stable public API.
Everything below is **additive** — new functions, operations and clients are
non-breaking and ship as **minor** releases.

- **`1.0.0`** — ✅ released (all high-level AQL operations supported).
- **`1.1.0`+** — feature extensions and consolidation, as below.

## Milestones

### 1.0.0 — released

| Lot | Scope | Status |
|-----|-------|--------|
| **W — `WINDOW`** | `aqlWindow()` for both forms — row-based (`WINDOW { preceding, following } AGGREGATE …`) and range-based (`WINDOW rangeValue WITH { preceding, following } AGGREGATE …`) — plus the dedicated `aqlWindowBounds()` helper. Unit + live + docs (FR/EN) + CHANGELOG. | ✅ Done |

### 1.1.0

| Lot | Scope | Value | Effort |
|-----|-------|-------|--------|
| **V — Vector / ANN** | `APPROX_NEAR_COSINE`, `APPROX_NEAR_L2` function wrappers; implement `L1_DISTANCE` / `L2_DISTANCE` (currently enum-only); a vector-search query helper (`SORT APPROX_NEAR… LIMIT`). Builds on the existing `VectorIndex` / `VectorIndexOptions` / `COSINE_SIMILARITY`. | High (semantic search / RAG) | Medium |
| **X — Query analysis** | A query `explain()` / profiling client over `/_api/explain` and the cursor `profile` option: execution plan, applied optimizer rules, warnings, and the collections/indexes actually used. Helps verify that filters and traversals hit indexes (and that no cluster traversal full-scans). | High (production diagnostics) | Medium |
| **F — Function consolidation** | Complete `functions/documents/` (`KEYS`, `VALUES`, `ATTRIBUTES`, `ENTRIES`, `ZIP`, `KEEP`, `UNSET`, `MATCHES`, `MERGE_RECURSIVE`, …); add `functions/bit/` (`BIT_AND`/`OR`/`XOR`/`POPCOUNT`/`SHIFT`…); fill remaining enum↔implementation gaps. Each is a small wrapper file + enum constant. | Medium | Low (mechanical) |

### 1.2.0

| Lot | Scope | Value | Effort |
|-----|-------|-------|--------|
| **S — ArangoSearch DSL** | Search-function wrappers (`ANALYZER()`, `BOOST()`, `PHRASE()`, `NGRAM_MATCH()`, `MIN_MATCH()`, `LEVENSHTEIN_MATCH()`, …) and a `SEARCH` / scored-facet builder integrated with the filter engine. The View/Analyzer/`SEARCH` half of the stack already exists. | High | High |

## Backlog (to be triaged)

Lower-priority or large-surface items, not currently scheduled:

- Complete search-alias View support.
- Richer stream / JavaScript transaction helpers.
- Backup / restore clients.
- Cluster / shard diagnostics.
- Pregel (distributed graph analytics) — large surface, niche; likely out of scope.
