# Per-field search options

Beyond the boost, each `Search::FIELDS` entry (of the `AQL::VIEW` block, see [Overview](overview.md)) accepts options declared **per field** (array form `field => [ … ]`). They all follow the same convention: **absent key = inherit the View level, explicit value = override** (an explicit `0` / `false` therefore disables the option for that field).

## Overview

| Per-field option | Role | Example |
|---|---|---|
| [`Search::FUZZY`](#per-field-typo-tolerance) | tolerate typos (text) / stay exact (codes) | `?search=scirie` finds « Scierie… » but not a near-miss code |
| [`Search::ANALYZER`](#per-field-analyzer) | one Analyzer per field (French, English, …) | `?search=workshops` matches via `text_en` (stem `workshop`) |
| [`Search::ANALYZER` (list)](#multiple-analyzers-per-field-autocomplete) | several Analyzers on **one** field (e.g. `text` + `ngram`) | `?search=ate` finds « Atelier » (autocomplete) |
| [`Search::NGRAM`](#precise-autocomplete-ngram_match) | **precise** autocomplete (NGRAM_MATCH + similarity threshold) | `?search=ate` finds « Atelier » without the noise |
| [`Search::LANG`](#localized-search-lang) | localized search driven by `?lang=` | `?search=menuiserie&lang=fr` targets the French side |
| [`Search::PHRASE`](#per-field-exact-phrase-bonus) | exact-phrase bonus where it matters | `?search=cuir vintage` lifts the adjacent phrase |
| [`Search::REQUIRES`](#search-permissions) | restrict a field to authorized requests | a `secret` field is searched only with the permission |

These options compose (a single field can declare boost + analyzer + language + fuzzy + phrase). Each section below details one, with an end-to-end concrete example.

## Per-field typo tolerance

`Search::FUZZY` may be declared **per field** in an array entry of `Search::FIELDS`, mirroring `Search::BOOST` exactly. A single View can then tolerate typos on text fields while staying **exact** on codes or identifiers (where tolerance would bring back the wrong record):

```php
Search::FIELDS =>
[
    'name' => [ Search::BOOST => 3 , Search::FUZZY => 1 ] , // text : typo-tolerant
    'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] , // code : exact
    'slogan' => 2 ,                                          // short form preserved (boost 2)
] ,
Search::FUZZY => 1 , // View-level default
```

`Search::FUZZY` is the **maximum edit distance** (Damerau‑Levenshtein), not a boolean: valid from `0` to `4` — `1` tolerates one typo, `2` two, etc.; `0` = exact.

Resolution rule: a field declaring `Search::FUZZY` wins (an **explicit `0` opts that field out** of tolerance); a field with no `FUZZY` key inherits the View-level `Search::FUZZY`; with no global value, tolerance is disabled. The behavior is **fully backward-compatible**: a declaration without per-field fuzzy produces exactly the former AQL.

**Concrete example.** With the fields above and a typo in the request:

```
GET /places?search=scirie
```

| Field | Tolerance | « scirie » (typo for « scierie ») |
|---|---|---|
| `name` (`FUZZY => 1`) | 1 edit tolerated | ✅ finds « Scierie de la Loire » |
| `code` (`FUZZY => 0`) | exact | ❌ no fuzzy match |

The AQL generated for `name` adds the tolerant branch next to the token match:

```aql
   BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)
OR LEVENSHTEIN_MATCH(doc.name, @search_0, 1)        // tolerates 1 edit
```

The `code` field emits **only** `doc.code IN TOKENS(...)` (no `LEVENSHTEIN_MATCH`): a search for `REF-00` never returns the code `REF-001`.

## Per-field Analyzer

Likewise, `Search::ANALYZER` may be declared **per field**. A single View can then index (and query) a French field with `text_fr` and an English field with `text_en`:

```php
Search::FIELDS =>
[
    'name'    => 3 ,                                  // View Analyzer
    'summary' => [ Search::ANALYZER => 'text_en' ] ,  // per-field override
] ,
Search::ANALYZER => 'text_fr' , // View-level default
```

Resolution rule: a field declaring `Search::ANALYZER` wins; otherwise it inherits the View-level `Search::ANALYZER` (itself `identity` by default). Since the Analyzer is **fixed at indexing time**, a per-field override is reflected on both sides: the View link indexes the field with its Analyzer, and the query groups expressions by Analyzer — one `ANALYZER(…, "<analyzer>")` per group, `OR`-ed together. With a single Analyzer the output is exactly the former one.

> **Token-exact "code" field.** Declaring `Search::ANALYZER => 'identity'` on a field — to match it as an exact token rather than linguistically — is fully supported and causes **no drift**. Since `identity` is the link's default Analyzer, the server stores such a field without spelling it out; the declaration therefore omits the redundant mention too, so `$model->viewDiff()` stays `IN_SYNC`.
>
> ```php
> Search::FIELDS =>
> [
>     'name' => 3 ,                                  // text_fr (the View default)
>     'code' => [ Search::ANALYZER => 'identity' ] , // exact token, no drift
> ] ,
> ```

**Concrete example.** `name` is indexed in French, `summary` in English:

```
GET /places?search=workshops
```

The English plural « workshops » is reduced to its stem « workshop » by the `text_en` Analyzer and finds the record whose `summary` is « woodworking workshop » — which `text_fr` could not do. The generated AQL produces **one `ANALYZER()` per Analyzer**, `OR`-ed:

```aql
   ANALYZER(BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3), "text_fr")
|| ANALYZER(doc.summary IN TOKENS(@search_0, "text_en"), "text_en")
```

> **Drift** — changing a field's Analyzer alters the View link. Like any declaration change, it does not update an already-created View: resynchronize with `$model->viewSync()` or `arangodb views --sync`. An analyzer (View-level or per-field) unknown to the server is reported by `$model->viewDiff()` (status `INVALID`).

## Multiple Analyzers per field (autocomplete)

A per-field `Search::ANALYZER` also accepts a **list**: the field is then indexed **and** queried under **each** Analyzer. This indexes the same field several ways at once — typically a `text` recipe for whole-word search **plus** an [`ngram`](../analyzers.md#ngramanalyzer--autocomplete--substrings) recipe for **autocomplete**:

```php
Search::FIELDS =>
[
    'name' => [ Search::ANALYZER => [ 'text_fr' , 'autocomplete' ] ] , // two recipes
] ,
```

> ⚠️ The `autocomplete` analyzer (an `ngram`) is **not** a built-in: it must be declared in the `analyzers` registry and created **before** the View (see [Analyzers](../analyzers.md)).

The link indexes the field with the **whole** list, and the query emits **one `ANALYZER(…)` branch per Analyzer**, OR-ed:

```aql
   ANALYZER(doc.name IN TOKENS(@search_0, "text_fr"), "text_fr")
|| ANALYZER(doc.name IN TOKENS(@search_0, "autocomplete"), "autocomplete")
```

Typing `ate` finds « **Ate**lier » through the `ngram` branch (which `text_fr` alone would not match), while whole words go through the `text_fr` branch. The field's other options (`BOOST`, `FUZZY`, `PHRASE`) apply to **each** branch.

> Fragment search is **loose** by nature: a whole word sent through the `ngram` branch may also bring back records that only share fragments. That is what the `score` (`BM25`) is for — surfacing the best ones first; combine with `BOOST` if needed. **Precision:** today, show the **top‑N** via `?limit` — the best matches rank first. For finer control, query the ngram analyzer by **similarity threshold** (`NGRAM_MATCH`) rather than "at least one shared fragment": see [Precise autocomplete](#precise-autocomplete-ngram_match). The **View-level** `Search::ANALYZER` stays a single value (the inherited default).

## Precise autocomplete (`NGRAM_MATCH`)

The `text` + `ngram` approach above queries the ngram analyzer through `IN TOKENS` — "≥ 1 shared fragment", hence **loose**: a whole word can bring back records that only share a fragment (BM25 ranks them lower, but they stay in the set). `Search::NGRAM` queries the ngram analyzer by **similarity threshold** (`NGRAM_MATCH`): a **fraction** of the fragments must match, which excludes the noise **inside the `SEARCH`** itself.

```php
Search::FIELDS =>
[
    'name' =>
    [
        Search::ANALYZER => 'text_fr' ,                                          // whole words (IN TOKENS, BM25)
        Search::NGRAM    => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 0.6 ] , // precise (NGRAM_MATCH)
    ] ,
] ,
// shorthand: Search::NGRAM => 'autocomplete'  (threshold = server default 0.7)
```

- `Search::NGRAM` is **disjoint** from `Search::ANALYZER`: the `text` recipes go under `ANALYZER` (queried by `IN TOKENS`), the `ngram` recipe goes here (queried by `NGRAM_MATCH`). The ngram analyzer is still **indexed** on the field.
- `Search::THRESHOLD`: a float `0.0–1.0` (fraction of n-grams required; higher = stricter). Absent → **server default `0.7`**. Out of range → `ValidationException`.
- The field's `BOOST` applies to the branch; `FUZZY` / `PHRASE` do not.

Generated AQL:

```aql
   ANALYZER(doc.name IN TOKENS(@search_0, "text_fr"), "text_fr")
|| ANALYZER(NGRAM_MATCH(doc.name, @search_0, 0.6, "autocomplete"), "autocomplete")
```

**The win.** With a threshold, typing `ate` finds « Atelier » (similarity 1.0) **and** the whole word « atelier » no longer brings back a « ferronnerie » record that only shares fragments (similarity below the threshold) — the precision the `IN TOKENS` approach lacks.

> ⚠️ `NGRAM_MATCH` wants an `ngram` analyzer declared with **`min == max`** (a single fragment size, e.g. a trigram) and **`preserveOriginal: false`** — see [Analyzers](../analyzers.md). A query shorter than `min` produces no n-gram (hence no match): that is the precision trade-off.

## Localized search (`?lang=`)

For an i18n attribute stored as an object `{ "fr": …, "en": … }`, index each localized sub-field (dotted path) with its Analyzer **and** its locale marker `Search::LANG`:

```php
Search::FIELDS =>
[
    'name'     => 3 ,                                                       // locale-agnostic : always searched
    'intro.fr' => [ Search::ANALYZER => 'text_fr' , Search::LANG => 'fr' ] ,
    'intro.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
] ,
```

When the request carries an active language (the [`?lang=`](README.md) parameter, already used for the `TRANSLATE()` projection in `RETURN`), the search aligns to it: only fields whose `Search::LANG` matches — **plus** the locale-agnostic fields (no `LANG`) — take part in the `SEARCH`. Without `?lang=`, every field is searched.

- `?lang=fr` → searches `name` + `intro.fr` (the English side is dropped);
- `?lang=en` → searches `name` + `intro.en`;
- **guard:** if the active language matches **no** field (e.g. `?lang=de`), the filter is ignored and every field is searched — never an empty `SEARCH`.

The `Search::LANG` (search) and the projection `?lang` (`TRANSLATE` in `RETURN`) are independent but consistent: the same active language narrows the search and localizes the output. Backward-compatible: with no `Search::LANG`, `?lang=` has no effect on the search.

**Concrete example.** On the i18n `intro` attribute above, the French word « menuiserie » lives only in `intro.fr`:

```
GET /places?search=menuiserie&lang=fr
```

`?lang=fr` searches only `name` (locale-agnostic) and `intro.fr` — the French record is found:

```aql
ANALYZER(BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3) || doc.intro.fr IN TOKENS(@search_0,"text_fr"), "text_fr")
```

The **same** request with `?lang=en` searches `name` + `intro.en` (the French side is dropped) — « menuiserie » then returns nothing:

```aql
ANALYZER(BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3),"text_fr") || ANALYZER(doc.intro.en IN TOKENS(@search_0,"text_en"),"text_en")
```

## Per-field exact-phrase bonus

`Search::PHRASE` may also be declared **per field**. The `PHRASE()` bonus (which ranks an exact phrase ahead of a scattered match) is then enabled where it makes sense — the title — and left off elsewhere — a code, an identifier:

```php
Search::FIELDS =>
[
    'name'        => [ Search::BOOST => 3 , Search::PHRASE => true  ] , // exact-phrase bonus
    'description' => [ Search::PHRASE => false ] ,                       // no phrase bonus
] ,
Search::PHRASE => true , // View-level default
```

Resolution rule: a field declaring `Search::PHRASE` wins (an **explicit `false` opts that field out**); a field with no `PHRASE` key inherits the View-level `Search::PHRASE`; with no global value, the bonus is disabled. The bonus weighs `boost × 2` (it composes with the per-field boost) and `PHRASE()` requires the field Analyzer to expose the `position` and `frequency` features. Backward-compatible: with no per-field phrase, the output is exactly the former one.

**Concrete example.** With the `name` field above (`Search::PHRASE => true`) and the request:

```
GET /places?search=cuir vintage
```

two records contain **both** words and therefore both match (token match):

| `name` | Words present | Adjacent and in order? | `PHRASE()` bonus |
|---|---|---|---|
| « Fauteuil **cuir vintage** » | cuir, vintage | ✅ yes | ✅ `boost × 2` → **ranks first** |
| « Sac en cuir, style vintage » | cuir, vintage | ❌ scattered | ❌ no bonus |

The AQL generated for that field adds, next to the token match, the exact-phrase branch:

```aql
   BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)   // token match (both records)
OR BOOST(PHRASE(doc.name, @search_0), 6)                // exact-phrase bonus (record 1 only)
```

As a result « Fauteuil cuir vintage » ranks **ahead of** « Sac en cuir, style vintage » in the `BM25` order. The `code` field (`Search::PHRASE => false`) never gets that branch: an identifier like `REF-2024` must not be "brought closer" to an approximate input.

## Search permissions

`Search::REQUIRES` declares the **permission subject(s)** required to search — a string or a list (OR semantics) — mirroring [`Field::REQUIRES`](../../projection.md) on the projection side. The decision is delegated to the request **authorizer** (the `Arango::AUTHORIZER` closure, injected by the controller and consulted by `isAuthorized()`). It is declared at **two levels**:

- on the **`AQL::VIEW` block** → gates the **whole** search (every field);
- inside a **`Search::FIELDS` entry** → gates **that single** field.

```php
AQL::VIEW =>
[
    Search::NAME     => 'placesView' ,
    Search::REQUIRES => 'app:search' ,                              // global gate : no search without this subject
    Search::FIELDS   =>
    [
        'name'   => 3 ,                                             // public (subject to the global gate only)
        'salary' => [ Search::REQUIRES => 'hr.salary:search' ] ,    // + 1 subject required
        'ssn'    => [ Search::REQUIRES => [ 'hr:admin' , 'hr:audit' ] ] , // + OR : admin OR audit
    ] ,
] ,
```

The two levels combine with **AND**: a field is searched when **(the View gate is absent or granted) AND (the field gate is absent or granted)**. Within a single list, subjects combine with **OR**. ⚠️ This is the **only** **additive** facet: unlike boost / fuzzy / analyzer / language / phrase (where a field *overrides* the View), `REQUIRES` **accumulate** (the most restrictive wins) — for safety.

**Concrete example.** The word « confidentiel » lives only in a `secret` field gated by `Search::REQUIRES => 'places:secret'`:

```
GET /places?search=confidentiel
```

| Request | `secret` searched? | Result |
|---|---|---|
| authorizer grants `places:secret` | ✅ yes | the record surfaces |
| authorizer denies | ❌ no (field removed) | no result |

Key points:

- **Global gate** — if the View's `Search::REQUIRES` is denied, the **whole** search returns `false` (zero results), whatever the declared fields.
- **No leak by default** — if permissions remove **every** searched field, the emitted `SEARCH` is `false`: zero results. It **never** falls back to searching everything or to the `LIKE` sweep (which would bypass the gate).
- **Fail-open without an authorizer** — if no `Arango::AUTHORIZER` is injected, the authorization layer is considered disabled and gated fields stay searchable (same behavior as the projection). In production the controller always injects the authorizer.
- **`count()` and `facetCounts()`** apply the same filtering (they reuse the same `SEARCH` expression).
- Backward-compatible: with no `REQUIRES` on any field, the AQL is unchanged.

## See also

- [Overview](overview.md) — declaring the View, URLs, relevance, provisioning.
- [Object-array fields](array-fields.md) — `contactPoints[*].email`.
- [Analyzers](../analyzers.md) — the Analyzer catalogue and creating a custom one.
