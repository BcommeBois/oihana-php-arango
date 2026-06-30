# Roadmap

This document is **forward-looking**: it captures where the library stands today
and what is planned or under consideration next. It is indicative, not
contractual — priorities may shift.

The exhaustive, dated record of what has shipped lives in
[`CHANGELOG.md`](../../CHANGELOG.md); this file deliberately does **not**
duplicate it. The library is versioned through git tags (no `version` field in
`composer.json`) and follows [Semantic Versioning](https://semver.org).

> French version: [`wiki/fr/roadmap.md`](../fr/roadmap.md).

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
  (`[*]`), the `ngram` analyzer type, multiple analyzers per field (autocomplete),
  and precise n-gram search by similarity threshold (`Search::NGRAM` →
  `NGRAM_MATCH`). Cut when Marc decides.

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
- **Hierarchical traversals (variable depth) on a relation projection** (effort
  **S→M**, *in progress*) — a `Filter::EDGES` field projects depth 1 only today.
  Three lots: **(A)** read `AQL::MIN_DEPTH` / `AQL::MAX_DEPTH` on an edge
  definition so a self-referential relation (e.g. a thesaurus) projects a **flat
  list** of descendants/ancestors up to depth N in a single traversal (default
  unchanged → depth 1); **(B)** opt-in path metadata, injecting `_parent` /
  `_depth` from the traversal path for nodes that do not store their parent;
  **(C)** a `buildTree()` helper (flat → nested `children[]`, parent source
  configurable) wired through `Alter::MAP` to deliver the tree transparently.
  **Homogeneous (self-referential) only** — heterogeneous `Type1→Type2→Type3` is
  already covered by declared nested edges. Range fields require a bounded
  `MAX_DEPTH` (cycle guard).

### Federated search & ArangoSearch

- **`[*]` parity on facet counts** (effort **S**, *next up*) — array-expansion
  sub-fields are supported by `?filter=` and by View search, but **not by facet
  counts** (`?facetCounts=`): `FacetCountsQueryTrait` validates a plain attribute
  via `assertAttributeName`, which rejects `offers[*].priceCurrency`. Extend the
  count sub-query with the same `Operator::ARRAY_EXPANSION` + `stripArrayExpansion()`
  convention — emit `FOR item IN doc.offers COLLECT value = item.priceCurrency …`.
  This is pure notation parity (the count side reaching the same paths the filter
  and search sides already accept); it does **not** couple facets to filters.
- **Federated search follow-ups** (effort **M**, needs scoping) — the lots
  deliberately deferred during the C1–C5 design:
  - *Richer ranking / sort controls* — `find()` is hardcoded to global `BM25`
    `DESC`. Three levers, by usefulness: **per-source boost** (make a customer
    outrank a product), scorer choice + `k`/`b` tuning (the low-level
    `aqlScoredSearch()` already supports it), and a secondary sort key.
  - *Type-based provenance* (`additionalType` → model) — `rebuild()` resolves
    **collection → one model**; for a polymorphic collection (e.g. `places`),
    route `collection:additionalType → model`. Only worth building once a real
    collection needs it.
- **`search-alias` reconciliation in `doctor`** (effort **S/M**) — mirror the
  analyzer/index registry reconciliation (the A5 pattern) for the database-level
  `searchAliasViews` registry, so `doctor` reports/repairs missing or drifted
  search-alias views.
- **Correlated search over object arrays** (effort: doc **S**) — today's
  object-array search (`contactPoints[*].email`) is **non-correlated**: it finds
  a document where *one* element contains a token, but cannot require *the same*
  element to satisfy two conditions. The supported answer is **document-only**:
  use `?filter=` (`contactPoints[*]` + `match`/`quant`), which already correlates
  *outside* the index. The index-level alternative (ArangoSearch `nested` fields)
  is Enterprise-only — see the Enterprise section below.
- **Scoring controls** (effort **S/M**) — `Search::SCORE` is hardcoded to
  `BM25(doc)`; expose `BM25` `k`/`b` (and `TFIDF`) tuning (the `bm25()` /
  `tfidf()` helpers and `aqlScoredSearch()` already take them, only the
  model-level DSL hardcodes the scorer). Needs a plain-language primer first
  (BM25 vs TFIDF) — not urgent.
- **Highlighting / `OFFSET_INFO`** (effort: helper **S**, pipeline **M/L**) —
  return match offsets to highlight snippets. Two steps: ship the missing
  `offsetInfo()` helper (`SearchFunction::OFFSET_INFO` enum exists, helper does
  not), then wire offsets into the model result pipeline (requires the `offset`
  analyzer feature).
- **Additional analyzer types** (effort **S** per simple type) — `delimiter`,
  `stopwords`, `pipeline`, `aql`, `geo_*`, `segmentation`, `minhash`, … as
  dedicated `AnalyzerOptions` classes (today `identity` / `norm` / `stem` /
  `text` / `ngram` are exposed). Not a capability gap: `RawAnalyzer($type,
  $properties)` already creates any type generically — dedicated classes are
  typed-constructor ergonomics. Prioritize by real need.
- **Typed View-level options** (effort **S**) — `primarySortCompression` /
  `optimizeTopK` are passed untyped today; model them as the rest of the View
  options.
- **Smaller DSL gaps** (effort **S–M** each, opportunistic) — **wildcard
  `SEARCH`** (`atel*`, its own lot when scheduled), a `MINHASH_MATCH` facet
  (helper exists, no DSL facet), phrase proximity / **slop**, a `MIN_MATCH`
  facet, and `primarySort` acceleration in the `AQL::VIEW` DSL.

### Enterprise (out of open-source scope)

Features tied to ArangoDB **Enterprise Edition**. The open-source library does
**not** model them today; if a project adopts Enterprise, add the dedicated
classes then.

- **`nested` fields on View links** — index an array of objects so that *the
  same* element can be required to satisfy several conditions (true correlated
  search inside the index). The non-Enterprise workaround lives above
  (*Correlated search* → `?filter=`).
- **Link `cache` option** — the Enterprise per-link/field value cache
  (`ArangoSearchLink` does not model `cache` today).
- **SmartGraphs / SatelliteGraphs** — Enterprise graph sharding strategies.

### Platform & operations

- **Richer stream / JavaScript transaction helpers.**
- **Cluster / shard diagnostics.**
- **Pre-migration backup hook** — an opt-in `--backup` / `backupBeforeMigrate`
  that snapshots before applying a migration (deferred from the dump/restore work).
- **Pregel** (distributed graph analytics) — large surface, niche; likely out of
  scope.
