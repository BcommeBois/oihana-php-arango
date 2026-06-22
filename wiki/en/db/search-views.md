# View search (ArangoSearch) — relevance-ranked `?search=`

Declare an **ArangoSearch View** on a `Documents` model (the `AQL::VIEW` block) and the [`?search=`](search.md) parameter switches, automatically and **without any URL change**, from the simple `LIKE` sweep to an **index-accelerated, relevance-ranked** search: linguistic matching (tokenization, stemming, accents), per-field boosts, exact-phrase bonus, typo tolerance, and a `BM25` score ordering the best matches first.

> New to ArangoSearch (Analyzers, Views, scoring)? Start with the [Understanding ArangoSearch](../getting-started/arangosearch.md) primer.

## Model declaration

```php
use oihana\arango\models\Documents ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Search ;

$places = new Documents( $container ,
[
    AQL::COLLECTION => 'places' ,
    AQL::VIEW =>
    [
        Search::NAME     => 'placesView' ,   // the View name (required)
        Search::ANALYZER => 'text_fr' ,      // Analyzer of the searched fields
        Search::FIELDS   =>
        [
            'name'        => 3 ,             // field => boost (name weighs 3×)
            'description' => 1 ,
        ] ,
        Search::PHRASE   => true ,           // exact-phrase bonus (boost ×2)
        Search::FUZZY    => 1 ,              // Levenshtein tolerance (0 = off)
    ] ,
]) ;
```

| Key | Type | Role |
|---|---|---|
| `Search::NAME` | `string` | **Required** — the View name. Without it the block is inert and `?search=` stays the `LIKE` sweep. |
| `Search::ANALYZER` | `string` | Analyzer used to index **and** query the fields (default `identity` — declare a text Analyzer for linguistic search). Overridable per field — see below. |
| `Search::FIELDS` | `array` | `field => boost` map (or `field => [ Search::BOOST => n, Search::FUZZY => d ]` to carry per-field options). Dotted paths supported, as well as **sub-fields of arrays of objects** via `[*]` ([see below](#object-array-fields-contactpointsemail)). Falls back to `AQL::SEARCHABLE` (boost 1). |
| `Search::PHRASE` | `bool` | Adds an exact-phrase bonus: a `PHRASE()` match weighs `boost × 2`. |
| `Search::FUZZY` | `int` | View-level typo tolerance: `LEVENSHTEIN_MATCH` with this maximum edit distance (valid value `0`–`4`, `0` = off). Overridable per field — see below. |

### Per-field options — overview

Beyond the boost, each `Search::FIELDS` entry accepts options declared **per field** (array form `field => [ … ]`). They all follow the same convention: **absent key = inherit the View level, explicit value = override** (an explicit `0` / `false` therefore disables the option for that field).

| Per-field option | Role | Example |
|---|---|---|
| [`Search::FUZZY`](#per-field-typo-tolerance) | tolerate typos (text) / stay exact (codes) | `?search=scirie` finds « Scierie… » but not a near-miss code |
| [`Search::ANALYZER`](#per-field-analyzer) | one Analyzer per field (French, English, …) | `?search=workshops` matches via `text_en` (stem `workshop`) |
| [`Search::LANG`](#localized-search-lang) | localized search driven by `?lang=` | `?search=menuiserie&lang=fr` targets the French side |
| [`Search::PHRASE`](#per-field-exact-phrase-bonus) | exact-phrase bonus where it matters | `?search=cuir vintage` lifts the adjacent phrase |
| [`Search::REQUIRES`](#search-permissions) | restrict a field to authorized requests | a `secret` field is searched only with the permission |

These options compose (a single field can declare boost + analyzer + language + fuzzy + phrase). Each section below details one, with an end-to-end concrete example.

### Per-field typo tolerance

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

### Per-field Analyzer

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

### Localized search (`?lang=`)

For an i18n attribute stored as an object `{ "fr": …, "en": … }`, index each localized sub-field (dotted path) with its Analyzer **and** its locale marker `Search::LANG`:

```php
Search::FIELDS =>
[
    'name'     => 3 ,                                                       // locale-agnostic : always searched
    'intro.fr' => [ Search::ANALYZER => 'text_fr' , Search::LANG => 'fr' ] ,
    'intro.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
] ,
```

When the request carries an active language (the [`?lang=`](search.md) parameter, already used for the `TRANSLATE()` projection in `RETURN`), the search aligns to it: only fields whose `Search::LANG` matches — **plus** the locale-agnostic fields (no `LANG`) — take part in the `SEARCH`. Without `?lang=`, every field is searched.

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

### Per-field exact-phrase bonus

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

### Search permissions

`Search::REQUIRES` declares the **permission subject(s)** required to search — a string or a list (OR semantics) — mirroring [`Field::REQUIRES`](../edges-joins-projection.md) on the projection side. The decision is delegated to the request **authorizer** (the `Arango::AUTHORIZER` closure, injected by the controller and consulted by `isAuthorized()`). It is declared at **two levels**:

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

### Object-array fields (`contactPoints[*].email`)

A document often carries an **array of objects** — a list of contact points, tags, members…:

```json
{
  "name": "Marc",
  "contactPoints":
  [
    { "email": "marc@acme.com",  "type": "work" },
    { "email": "marc@gmail.com", "type": "home" }
  ]
}
```

You want `?search=gmail` to find this document because **one** of its `contactPoints` contains "gmail". Declare the sub-field with the `[*]` marker ("for each element of the array"), the same notation as on the [`?filter=`](filter.md) side:

```php
Search::FIELDS =>
[
    'name'                       => 5 ,
    'contactPoints[*].email'     => [ Search::FUZZY => 0 , Search::PHRASE => false ] ,
    'contactPoints[*].telephone' => [ Search::FUZZY => 0 , Search::PHRASE => false ] ,
] ,
```

The `[*]` is a **developer-facing notation**: internally it is **stripped on both stages**.

**Link created** (the `[*]` dropped — flat path): ArangoSearch (**Community** edition) descends into the array on its own and indexes the `email` of every element.

```json
{ "fields": { "name": { "analyzers": ["text_fr"] },
              "contactPoints": { "fields": { "email":     { "analyzers": ["text_fr"] },
                                             "telephone": { "analyzers": ["text_fr"] } } } } }
```

**Generated query** (the `[*]` dropped too) — the ArangoSearch `SEARCH` clause **rejects** the `[*]` expansion, and the flat path already matches any element of the array:

```aql
SEARCH ANALYZER(
       doc.name                  IN TOKENS(@search_0, "text_fr")
    OR doc.contactPoints.email     IN TOKENS(@search_0, "text_fr")
    OR doc.contactPoints.telephone IN TOKENS(@search_0, "text_fr")
, "text_fr")
```

Per-field options (`Search::ANALYZER`, `FUZZY`, `PHRASE`, `BOOST`, `LANG`, `REQUIRES`) work the same on a `[*]` field.

**Multiple levels.** Every `[*]` is stripped, whatever the depth: `employees[*].contactPoints[*].email` indexes `employees` → `contactPoints` → `email` and is queried through `doc.employees.contactPoints.email IN TOKENS(...)`.

> **Non-correlated search — Community, no Enterprise.** This finds "a document where *one* element contains the word X". It **cannot** require "the *same* element has X **and** Y" (e.g. the email contains `acme.com` **and** the type is `billing` on the **same** contact): the Community index flattens the array and loses the per-element boundary. That correlation would require ArangoSearch `nested` fields, **Enterprise**-only — out of scope here. If you need a correlated condition, express it through [`?filter=`](filter.md) (`contactPoints[*]` with `match`/`quant`), which re-tests element by element. `trackListPositions` is **not** enabled (the default suits a non-correlated search).

**Provisioning is automatic**: like the collection and its `AQL::INDEXES`, the View is lazily created at model initialization when it does not exist (searched fields linked with the declared Analyzer). An existing View is **never altered automatically** — after changing the declaration, inspect and resynchronize explicitly: `$model->viewDiff()` detects the gap, `$model->viewSync()` repairs it through `updateProperties()` (the View stays queryable while re-indexing), and the [`views` action of the `arangodb` command](../commands/arangodb.md#views--arangosearch-view-management) does the same from the CLI (`--diff` / `--sync`), ready for deployment scripts:

```bash
# after an AQL::VIEW declaration change: review the gap, then resynchronize
composer arango:views -- --diff              # read-only: lists Views to create / drifted
composer arango:views -- --sync              # creates the missing ones + resyncs every drifted one
composer arango:views -- --sync=placesView   # targeted (several names, comma-separated)
```

> Equivalent long form: `php bin/console.php command:arangodb views --sync`. `--sync` favors `updateProperties()` (soft update) over a drop + recreate.

## URLs and behavior

```
GET /places?search=scierie
```

generates (terms bound — user input never reaches the AQL text):

```aql
FOR doc IN placesView
  SEARCH ANALYZER(
       BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)
    OR BOOST(PHRASE(doc.name, @search_0), 6)
    OR LEVENSHTEIN_MATCH(doc.name, @search_0, 1)
    OR doc.description IN TOKENS(@search_0, "text_fr")
    OR BOOST(PHRASE(doc.description, @search_0), 2)
    OR LEVENSHTEIN_MATCH(doc.description, @search_0, 1)
  , "text_fr")
  SORT BM25(doc) DESC
  LIMIT 0, 50
  RETURN { ... }
```

The `?search=` contract is unchanged: comma-separated terms, **OR** everywhere — only the engine differs. And the rest of the pipeline ([`?filter=`](filter.md), [`?facets=`](facets.md), `?limit`/`?offset`, skins, projections) keeps working as before: filters apply **after** the `SEARCH` as a post-processing `FILTER`.

### Relevance and `?sort=`

An active search exposes the synthetic **`score`** sort key (the relevance counterpart of [`distance`](search-and-filtering.md#distance-sorting-near) for `?near=`):

| Request | Order |
|---|---|
| `?search=scierie` | `score` DESC (default — most relevant first, overrides `SORT_DEFAULT`) |
| `?search=scierie&sort=-score,name` | relevance, then name |
| `?search=scierie&sort=name` | name only — relevance **not** appended (explicit `?sort` decides) |
| `?sort=score` without `?search=` | dropped (no active search) |

### Responses

The JSON envelope is **identical** to a classic list (the standard `status` / `url` / `count` / `total` / `result` success envelope of the controllers) — only the order (and the matching quality) changes:

```json
{
  "status": "success",
  "url": "https://api.example.org/places?search=bois",
  "count": 2,
  "total": 2,
  "result":
  [
    { "name": "Atelier du bois" , "description": "menuiserie fine" } ,
    { "name": "Scierie de la Loire" , "description": "le bois de chêne et de sapin" }
  ]
}
```

`total`, [`?count`](../models.md) **and** [`?facetCounts=`](facets.md#facet-counts-facetcounts) all follow the same `SEARCH` — the list, the totals and the facet buckets always agree on the matched set.

## Recipes

**Search bar with relevance** — the declaration above; nothing else. `?search=scierie` returns the best matches first, tolerates one typo (`scierei`), and survives accents/plurals via the Analyzer.

**Autocomplete-ish prefix bias** — keep `Search::PHRASE => true`: while typing whole words, exact phrases rank on top.

**Localized sub-field** — fields are paths: `Search::FIELDS => [ 'description.fr' => 1 ]` searches the French side of an i18n `{ "fr": …, "en": … }` attribute. To go further, a [per-field Analyzer](#per-field-analyzer) (French/English) and a [`?lang=`-driven selection](#localized-search-lang) are available.

**Object-array sub-field** — `Search::FIELDS => [ 'contactPoints[*].email' => 1 ]` makes the `email` of **every** element of the `contactPoints` array searchable — see [Object-array fields](#object-array-fields-contactpointsemail).

**Forcing a classic order** — relevance is only the *default*: `?search=bois&sort=name` (or any `?sort=`) takes over entirely, exactly as before.

**Opting out** — remove the `AQL::VIEW` block (or its `Search::NAME`): `?search=` instantly falls back to the historical `LIKE` sweep over `AQL::SEARCHABLE`. No URL, controller or route changes either way.

## Good to know

- **Eventual consistency** — a freshly inserted document becomes searchable in the View after ~1 s (`commitIntervalMsec`). Lists without `?search=` are not affected.
- **Scoring requirements** — the `BM25` score needs the Analyzer `frequency` feature (built-in text Analyzers have it); `PHRASE` needs `position` + `frequency`.
- **The search is bound** — terms travel as `@search_N` bind variables; field names come from the model declaration, never from the URL.

## See also

- [Search `?search=`](search.md) — the `LIKE` sweep (models without a View).
- [Search & filtering](search-and-filtering.md) — overview of the levers.
- [ArangoSearch functions](../aql/aql-functions-search.md) — the underlying `SEARCH` helpers and a primer.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — the standalone scored-query builder.
- [ArangoSearch clients](../clients/arangosearch.md) — Views and Analyzers management.
