# Object-array fields (`contactPoints[*].email`)

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

You want `?search=gmail` to find this document because **one** of its `contactPoints` contains "gmail". Declare the sub-field with the `[*]` marker ("for each element of the array"), the same notation as on the [`?filter=`](../filter.md) side:

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

> **Non-correlated search — Community, no Enterprise.** This finds "a document where *one* element contains the word X". It **cannot** require "the *same* element has X **and** Y" (e.g. the email contains `acme.com` **and** the type is `billing` on the **same** contact): the Community index flattens the array and loses the per-element boundary. That correlation would require ArangoSearch `nested` fields, **Enterprise**-only — out of scope here. If you need a correlated condition, express it through [`?filter=`](../filter.md) (`contactPoints[*]` with `match`/`quant`), which re-tests element by element. `trackListPositions` is **not** enabled (the default suits a non-correlated search).

## See also

- [Overview](overview.md) — declaring the View, provisioning, URLs.
- [Per-field options](per-field-options.md) — boost, fuzzy, Analyzer, autocomplete, language, phrase, permissions.
