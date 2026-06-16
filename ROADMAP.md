# Roadmap

This document tracks the planned evolution of **oihana/php-arango** against the
ArangoDB 3.12 AQL feature set. It is indicative, not contractual: priorities may
shift. The library is versioned through git tags (no `version` field in
`composer.json`), and follows [Semantic Versioning](https://semver.org).

## Where we are

As of **1.2.0** (released 2026-06-14), the library covers the bulk of the AQL
surface and a full operational toolchain:

- **All 22 high-level operations** (`FOR`, `FILTER`, `SORT`, `LIMIT`, `LET`,
  `COLLECT`, `WINDOW`, `RETURN`, `INSERT`, `UPDATE`, `REPLACE`, `UPSERT`, `REMOVE`,
  graph traversal, `PRUNE`, `SEARCH`, `WITH`, `OPTIONS`, …).
- **~190 AQL functions** across strings, numerics (incl. vector/ANN distances),
  dates, arrays, documents, bit and geo.
- **ArangoSearch**: the View/Analyzer clients, the `SEARCH` / scored-search DSL,
  and a model-level `AQL::VIEW` block (relevance-ranked search, per-field
  boost/fuzzy/analyzer/lang/phrase/permissions — see the per-field DSL below).
- **Query diagnostics**: typed `explain()` and profiling on the façade and model.
- **Operational tooling**: dump / restore (profiles, masking, rotation),
  maintenance commands (`views`, `doctor`, `migrate`).
- **Transactions**, **18+ index types** (including a vector index), the full
  filter / facet / group engine, and 100% line/method test coverage.

## Versioning strategy

`1.0.0` shipped with all high-level AQL operations supported and a stable public
API. Everything since is **additive** — new functions, operations, clients and
tooling are non-breaking and ship as **minor** releases.

- **`1.0.0`** — ✅ released 2026-06-09 (all high-level AQL operations supported).
- **`1.1.0`** — ✅ released 2026-06-10 (vector/ANN, query analysis, function consolidation).
- **`1.2.0`** — ✅ released 2026-06-14 (ArangoSearch DSL, dump/restore, maintenance commands).
- **Unreleased** — per-field View search DSL, `doctor` index registry, masking
  extracted to `oihana/php-masking` (see below); pending the next minor.

## Milestones

### 1.0.0 — released

| Lot | Scope | Status |
|-----|-------|--------|
| **W — `WINDOW`** | `aqlWindow()` for both forms — row-based (`WINDOW { preceding, following } AGGREGATE …`) and range-based (`WINDOW rangeValue WITH { preceding, following } AGGREGATE …`) — plus the dedicated `aqlWindowBounds()` helper. Unit + live + docs (FR/EN) + CHANGELOG. | ✅ Done |

### 1.1.0 — released

| Lot | Scope | Status |
|-----|-------|--------|
| **V — Vector / ANN** | `approxNearCosine()` / `approxNearL2()` (with `nProbe`), `l1Distance()` / `l2Distance()`, the `aqlVectorSearch()` operation, and the `VectorMetric` / `VectorSearchOption` constants. Builds on the existing `VectorIndex`. | ✅ Done |
| **X — Query analysis** | Typed `explain()` → `ExplainResult` (optimizer rules, indexes actually used) and profiling via the cursor `profile` option → `ProfileResult` / `ExecutionStats`, wired on both the façade and the model (`explainList()`, `Arango::PROFILE`). | ✅ Done |
| **F — Function consolidation** | Completed `functions/documents/` (21 helpers) and added `functions/bit/` (12 helpers), filling the enum↔implementation gaps. | ✅ Done |

### 1.2.0 — released

| Lot | Scope | Status |
|-----|-------|--------|
| **S — ArangoSearch DSL** | `db/functions/search/` wrappers (`ANALYZER()`, `BOOST()`, `PHRASE()`, `LEVENSHTEIN_MATCH()`, …), the completed `aqlSearch()` (`ANALYZER` wrap + `OPTIONS`), the `aqlScoredSearch()` operation (BM25/TFIDF), and the model-level `AQL::VIEW` block with relevance-ranked search, synthetic `score`, lazy provisioning and `ViewManagementTrait`. | ✅ Done |
| **D — Dump / restore tooling** | `dump` / `restore` actions with config defaults, profiles, `--dry-run` / `--complete`, restore guard-rails, masking, archive rotation (`--prune`), per-profile output directory, and the strategy docs. | ✅ Done |
| **M — Maintenance commands** | `views` (View diff/sync), `doctor` (collection/index/View diagnose + `--apply` / `--prune`) and `migrate` (versioned migrations: `--create`, `--status`, apply/`--down`). | ✅ Done |

### Unreleased — in `main`, pending the next minor

| Lot | Scope | Status |
|-----|-------|--------|
| **VF — Per-field View search DSL** | `Search::FIELDS` array entries accept per-field `FUZZY` (VF1), `ANALYZER` (VF2), `LANG` driven by `?lang=` (VF3), `PHRASE` (VF4a), and `REQUIRES` permissions at field (VF4b) and View level (VF4c), plus `REQUIRES` on the `LIKE` `SEARCHABLE` list via `Search::KEY` (VF5). | ✅ Done |
| **Doctor index registry** | `collectionIndexes` registry (indexes declared independently of models, reconciled by `doctor`); `AQL::INDEXES` accepts a single `IndexOptions`. | ✅ Done |
| **ArangoSearch enums** | `BuiltinAnalyzer` / `CaseFolding` / `Compression` / `ConsolidationPolicyType` constants (no more magic strings). | ✅ Done |
| **Masking extraction** | The masking engine moved to the standalone `oihana/php-masking` library (no behaviour change). | ✅ Done |
| **identity-analyzer drift fix** | `buildViewLink()` omits the redundant link-default `analyzers`; empty field nodes serialize as `{}`. | ✅ Done |

### Analyzer lifecycle tooling — design frozen (2026-06-16), pending implementation

Manage **custom** analyzers the way Views and indexes are already managed
(declare → diagnose → provision). Assigning an analyzer (View-level and
per-field) already works — this milestone only adds the lifecycle. The
structuring constraint is that analyzers are **immutable, shared and
database-scoped**: repairing a drifted analyzer means drop + recreate, which
cascades to every dependent View (an in-use analyzer cannot be dropped without
`force`, and a forced drop leaves dependent Views dangling until rebuilt). So
the policy is graduated and never destructive by surprise.

| Lot | Scope | Status |
|-----|-------|--------|
| **A1 — Façade** | `AnalyzerManagementTrait`: `analyzerDiff()` / `analyzerSync($force)` (typed `DiffReport`) + `analyzerDependentViews()` (scan links). Comparison: exact `type`, subset `properties`, set-equal `features`, normalized `dbname::` prefix. | Planned |
| **A2 — Registry** | `AnalyzerDefinition( name, AnalyzerOptions $options, array $features )` value object + `ArangoAnalyzersTrait` + `ArangoCommandParam::ANALYZERS` — declared at database level, mirroring the `collectionIndexes` registry. | Planned |
| **A3 — `arango:analyzers` action** | `--diff` (report), `--sync` (create missing, report drifted), `--fix` (generate a ready-to-review migration per drift), `--force` (live cascade repair), `--prune` (opt-in: drop unused orphans, report in-use ones; built-ins never touched). Composer script. | Planned |
| **A4 — Pre-filled migrations** | Extend `MigrationGenerator` to emit a filled `up()/down()` body; `--fix` writes a self-contained same-name drop+recreate+`viewSync` migration. | Planned |
| **A5 — Doctor integration** | `ArangoDoctorAction` reads the registry, creates missing analyzers, reports drift; never triggers the cascade, never prunes. | Planned |
| **A6 — Docs** | `db/analyzers.md` (lifecycle + path A vs B), `commands/arangodb.md` (FR/EN), CHANGELOG. | Planned |

## Backlog (to be triaged)

Lower-priority or large-surface items, not currently scheduled:

- Complete search-alias View support.
- Additional analyzer types (`ngram`, `pipeline`, `aql`, `geo_*`, `segmentation`,
  `delimiter`, `minhash`, …) as dedicated `AnalyzerOptions` classes — today only
  `identity` / `norm` / `stem` / `text` are exposed.
- Richer stream / JavaScript transaction helpers.
- Cluster / shard diagnostics.
- Pregel (distributed graph analytics) — large surface, niche; likely out of scope.
