# Roadmap

This document is **forward-looking**: it captures where the library stands today
and what is planned or under consideration next. It is indicative, not
contractual — priorities may shift.

The exhaustive, dated record of what has shipped lives in
[`CHANGELOG.md`](CHANGELOG.md); this file deliberately does **not** duplicate it.
The library is versioned through git tags (no `version` field in
`composer.json`) and follows [Semantic Versioning](https://semver.org).

## Where we are

As of **1.4.0** (released 2026-06-21), with further additions accumulated under
`[Unreleased]`, the library covers the bulk of the AQL surface, a full
operational toolchain, and a federated search engine:

- **All 22 high-level operations** (`FOR`, `FILTER`, `SORT`, `LIMIT`, `LET`,
  `COLLECT`, `WINDOW`, `RETURN`, `INSERT`, `UPDATE`, `REPLACE`, `UPSERT`,
  `REMOVE`, graph traversal, `PRUNE`, `SEARCH`, `WITH`, `OPTIONS`, …).
- **~190 AQL functions** across strings, numerics (incl. vector/ANN distances),
  dates, arrays, documents, bit and geo.
- **ArangoSearch**: the View/Analyzer clients, the `SEARCH` / scored-search DSL,
  a model-level `AQL::VIEW` block (relevance-ranked search), and a **per-field
  search DSL** (boost / fuzzy / analyzer / lang / phrase / permissions, **multiple
  analyzers per field** for autocomplete, and **object-array sub-fields** via
  `[*]`, e.g. `contactPoints[*].email` — per field and View-level). Buildable
  analyzer types: `identity` / `norm` / `stem` / `text` / `ngram`.
- **Federated multi-collection search**: `search-alias` views over one inverted
  index per collection, the standalone `FederatedSearch` engine (two-phase
  *find* → *rebuild*, per-collection permission gating), and a read-only HTTP
  triplet (`SearchRoute` → `FederatedSearchController` → engine).
- **Field projection DSL**: edge-metadata projection (`Field::SCOPE`), reference
  wrapping under a key with its own sub-edges/joins (`Filter::WRAP`), per-type URL
  routing (`Field::PATHS`), and conditional field projection (`Field::WHEN` /
  `Field::ELSE`).
- **Query diagnostics**: typed `explain()` and profiling on the façade and model.
- **Operational tooling**: dump / restore (profiles, masking, rotation),
  maintenance commands (`views`, `doctor`, `migrate`) and the custom-analyzer
  lifecycle (`analyzers`: diff / sync / fix / prune, with `doctor` integration).
- **Transactions**, **18+ index types** (including a vector index), the full
  filter / facet / group engine (incl. array **and** relation `quant`
  quantifiers — `any` / `all` / `none` / `n` — on edge & join filters), masking
  extracted to
  [`oihana/php-masking`](https://github.com/BcommeBois/oihana-php-masking), and
  100% line/method test coverage.

## Versioning strategy

`1.0.0` shipped with all high-level AQL operations supported and a stable public
API. Everything since is **additive** — new functions, operations, clients and
tooling are non-breaking and ship as **minor** releases.

- **`1.0.0`** — released 2026-06-09 (all high-level AQL operations supported).
- **`1.1.0`** — released 2026-06-10 (vector/ANN, query analysis, function consolidation).
- **`1.2.0`** — released 2026-06-14 (ArangoSearch DSL, dump/restore, maintenance commands).
- **`1.3.0`** — released 2026-06-20 (per-field View search DSL, custom-analyzer
  lifecycle, field projection DSL, `search-alias` views, federated search).
- **`1.4.0`** — released 2026-06-21 (relation `quant` quantifiers on edge & join
  filters).
- **Next minor** — accumulated under `[Unreleased]`: object-array search fields
  (`[*]`), the `ngram` analyzer type, and multiple analyzers per field
  (autocomplete). Cut when Marc decides.

## Backlog (to be triaged)

Forward-looking items not yet scheduled, roughly by theme.

### Filtering & query DSL

- **`match` (multi-attribute condition) on edges & joins** — the array surface
  supports a `match` condition (several sub-fields on the same object, e.g.
  `members[*]` + `match {active:true, role:'admin'}`); extend it to relation
  traversals so a single edge/join filter can constrain the linked vertex on
  several attributes at once. Natural companion to the `quant` generalization
  (e.g. "no member with `{active:false, role:'admin'}`"). Aggregate comparisons
  (sum/avg/min/max) and key membership over a relation are intentionally **not**
  in scope here — they already live in `?facets` (`EDGE_AGGREGATE` /
  `JOIN_AGGREGATE`, and the `"-key"` negation).

### Federated search & ArangoSearch

- **Federated search follow-ups** — the deferred lots beyond the read-only engine:
  richer ranking / sort controls, and type-based provenance
  (`additionalType` → model, deliberately deferred during the C1–C5 design).
- **`search-alias` reconciliation in `doctor`** — mirror the analyzer/index
  registry reconciliation (the A5 pattern) for the database-level
  `searchAliasViews` registry, so `doctor` reports/repairs missing or drifted
  search-alias views.
- **Precise n-gram autocomplete (`NGRAM_MATCH` + similarity threshold)** — the
  multi-analyzer autocomplete ships today via `IN TOKENS` (matches on *any*
  shared fragment, intentionally loose; BM25 ranks). Expose ArangoDB's
  `NGRAM_MATCH` with a per-field **similarity threshold** so the `SEARCH` itself
  excludes weak matches. The `ngramMatch()` helper and the `SearchFunction::NGRAM_MATCH`
  enum already exist; what's missing is a per-field DSL facet (proposed
  `Search::NGRAM => [ 'analyzer' => …, 'threshold' => … ]`) wiring it into
  `prepareViewSearch()`. Note: `NGRAM_MATCH` wants an `ngram` analyzer with
  `min == max` / `preserveOriginal: false`.
- **Additional analyzer types** — `pipeline`, `aql`, `geo_*`, `segmentation`,
  `delimiter`, `minhash`, … as dedicated `AnalyzerOptions` classes (today
  `identity` / `norm` / `stem` / `text` / `ngram` are exposed).

### Platform & operations

- **Richer stream / JavaScript transaction helpers.**
- **Cluster / shard diagnostics.**
- **Pre-migration backup hook** — an opt-in `--backup` / `backupBeforeMigrate`
  that snapshots before applying a migration (deferred from the dump/restore work).
- **Pregel** (distributed graph analytics) — large surface, niche; likely out of
  scope.
