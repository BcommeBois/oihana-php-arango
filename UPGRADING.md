# Upgrading

What to do when moving a project from one version of **oihana/php-arango** to the next.

The [CHANGELOG](CHANGELOG.md) tells the whole story of every release, in the order things happened.
This file answers a narrower question: *I am upgrading — what breaks, and what do I have to do about
it?* It starts at 1.6.0; for earlier versions, read the "Backward compatibility" paragraphs inside
the CHANGELOG entries.

## [Unreleased]

Ten breaking changes. The first two are on the same key — `AQL::DIRECTION`, read by the edge
surfaces — and both refuse a **declaration** that could not be honoured and was being half-honoured
in silence. The third is of another kind: no declaration is refused, but one that was already there
starts meaning something better. The fourth refuses a **request**, and only one that 1.6.0 already
refused everywhere else. The fifth and sixth refuse nothing at all: a filter that was being dropped
now applies, so a listing that answered everything starts answering the question. The seventh is not
about behaviour at all — two names move to another package, and nothing they do changes. The eighth
refuses a call that could only ever have produced an unusable query, the ninth stops a count
from answering on records it should never have counted, and the tenth makes "at least n" mean it.
None changes a signature.

### 🚨 Breaking

#### 1. A traversal direction that is not a `Traversal` keyword is refused

**Before.** The edge surfaces read the direction through `Traversal::get()`, whose contract is to
fall back on its default when it does not recognise a value. So `AQL::DIRECTION => 'OUTBOUD'`
compiled to `OUTBOUND`, silently. On a relation actually reached `INBOUND` that is an empty
projection in `200` — indistinguishable from "this relation has no vertices".

**Now.** An unrecognised keyword throws a `ConstantException`, the same way a linked facet already
refused one.

**What to do.**

1. **Grep your model declarations** for `AQL::DIRECTION` and check every value against
   `Traversal::INBOUND` / `OUTBOUND` / `ANY`. The keywords are **upper-case**: `'outbound'` is
   refused too.
2. A declaration that says nothing, or says `null`, still means `OUTBOUND` — nothing to do.
3. Nothing changes for a request: an unknown key coming from the URL keeps being dropped in silence.

#### 2. `Traversal::ANY` is refused on a projected relation whose two ends differ

**Before.** `ANY` walks the edge both ways, but the three places resolving the reached vertex model
picked it with a binary ternary — so `ANY` landed on the `_to` end. On a relation whose ends are two
different collections, the far side's vertices came back projected with the near side's fields, and
gated by the near side's `Field::REQUIRES`.

**Now.** When `ANY` cannot designate a single reached model, the projection throws an
`UnexpectedValueException` naming the edge collection.

**What to do.**

1. **Grep for `AQL::DIRECTION => Traversal::ANY`** in your `AQL::EDGES` definitions, in the nested
   ones, and in the edge configs your hierarchical filters walk.
2. On a **self-referential** relation — both ends on the same collection, a thesaurus,
   `user_follows` — there is nothing to do: it still resolves, on the same end as before.
3. Everywhere else, replace it with `Traversal::INBOUND` or `Traversal::OUTBOUND` — or declare two
   relations, one per direction, each projecting its own model.
4. ⚠ An edge model wiring **one** end only is refused with `ANY` as well: nothing declares what
   comes back from the other side. Wire both ends, or declare an oriented direction.
5. **Linked facets are not concerned.** A facet declares an edge *collection*, not a model, so it
   has nothing to resolve — `Traversal::ANY` stays unrestricted there.

#### 3. Sorting on a translated field now follows `?lang=`

**Before.** A sortable entry aiming at a **root** field declared `Filter::TRANSLATE` compiled to
`SORT doc.alternateName` — the whole translations object. ArangoDB does order objects (by attribute
count, then keys and values), so the listing was stable and reproducible; it simply had no relation
to the text on screen. A document holding one translation came before a document holding two,
whatever either of them said.

**Now.** As soon as a language is in play — `?lang=` on the request, or a fallback declared through
`Arango::DEFAULT_LANG` — the same entry orders on that locale:
`SORT NOT_NULL(doc.alternateName["fr"], …)`. Nothing else about the declaration changed; the
library reads the `Filter::TRANSLATE` that was already in `AQL::FIELDS`.

**What to do.**

1. **Grep your `AQL::SORTABLE` declarations** for entries whose resolved path is a **root** field
   also declared `Filter::TRANSLATE` in `AQL::FIELDS` — typically the indexed shorthand
   `AQL::SORTABLE => [ 'alternateName' ]`. Those are the ones that move. An entry aiming at
   `'alternateName.fr'` — a locale written into the path — is **not** detected as multilingual and
   is untouched.
2. **In almost every case this is the order you wanted**, and there is nothing to do beyond
   declaring `Field::ELSE`: without it, a document carrying no translation at all orders on `null`,
   which puts it at the **front** of the listing. See `wiki/en/db/sort.md`, "Sorting a multilingual
   label".
3. **Without `?lang=` and with no fallback declared anywhere, nothing moves** — the expression is
   the stored path, byte for byte as before. A project that never sends `?lang=` is not concerned.
4. There is deliberately **no opt-out**. Ordering a listing by the shape of a translations object is
   not a behaviour worth preserving a switch for; if you truly need the old order, sort on another
   field.

#### 4. A malformed request is refused on a nested key too, not only at the root

**Before.** 1.6.0 stopped folding an unusable operator back onto equality and started answering
`400` (see *1.5.0 → 1.6.0*, breaking change 3). On a **dotted** key that refusal never arrived: the
hierarchical walk caught it with everything else and dropped the filter, so the query ran without
it and answered the whole collection.

```
?filter={"key":"name","op":"zzz"}          → 400, naming the accepted codes
?filter={"key":"address.city","op":"zzz"}  → every customer, in 200
```

**Now.** Both answer `400`. The refusal is relayed out of the nested walk instead of being turned
into a dropped filter.

**What to do.**

1. **Nothing, if you already did the 1.6.0 pass.** The accepted operators are unchanged and the
   rules are the same at every depth — this only makes the depths agree. If your front-end sends
   only valid operators, no request moves.
2. **If you skipped that pass**, do it now over your *nested* keys as well: the function forms
   (`sw`, `nsw`, `ew`, `new`, `contains`, `ncontains`, `regex`, `nregex`) apply to `string` filters
   only, `between` to `string`, `number` and `date`, `distance` to `geo`. Everything else was
   already refused on a flat key, so a nested key is the only place a bad operator can still be
   hiding in your logs as a `200`.
3. **Watch for a request you did not know was malformed.** This is the uncomfortable half: a call
   that has been answering `200` for a while may have been answering it *because* its filter was
   being dropped. It now answers `400` — the surface did not break, the request was always wrong.
   The same applies to the other refusals reachable through a nested path: an unusable `quant`, an
   `atLeast.<op>` of the wrong shape, and `quant: all` with no condition to satisfy.

**What does not move.** A consumer's own custom leaf callable that throws is still caught, logged
and resolved to `null` — that is a server-side fault, not something a caller can fix, and refusing
the whole request over it would take a surface down. Only refusals built for the caller are relayed.

#### 5. Naming an object in a filter key now filters instead of being ignored

**Before.** A filter key ending on a `Filter::DOCUMENT` named no field, so the library looked for
one inside the object, found none and **dropped the filter**. The query ran without it:

```
?filter={"key":"resolution","val":null}   → every ticket, in 200
```

**Now.** The comparison bears on the location itself, and the same call answers only the tickets
whose `resolution` is absent or `null`:

```
?filter={"key":"resolution","val":null}   → doc.resolution == @value
```

**What to do.**

1. **Grep your front-end for a filter key that names a declared `Filter::DOCUMENT` with nothing
   after it** — `{"key":"<object>"}` rather than `{"key":"<object>.<field>"}`. Those calls used to
   return the whole collection and now return a subset. The result changes for the better, but it
   changes: a page that looked complete was complete because the filter was being ignored.
2. **Nothing to declare.** The behaviour comes from the `Filter::DOCUMENT` you already declare; no
   new key, no opt-in.
3. **Nothing else moves.** Filtering *inside* the object is untouched at any depth
   (`resolution.closedAt`, `resolution.audit.by`), and a scalar, an edge or a join named last keep
   exactly the behaviour they had.

**What "present" means.** AQL reads a missing attribute as `null`, so `== null` selects both the
document with no attribute at all and the one storing an explicit `null`. An **empty object** `{}`
counts as *present* — the test bears on the location, not on its contents.

**Two things that are deliberately refused.** A function-form operator on an object (`sw`, `ew`,
`contains`, `regex`, `between`) answers `400` rather than being folded into equality — it means
nothing there. And `quant` is inert on an object, as it already is on a scalar key.

**Still ignored, on purpose: an array named last.** `attachments[*]` with no sub-field keeps being
dropped. "The slot is absent" and "the list is empty" are two different questions and both deserve
an answer; that is a separate change, not this one.

#### 6. Naming a list of objects in a filter key now counts it instead of being ignored

**Before.** A filter key ending on a `Filter::ARRAY_EXPANSION` — the list named with its `[*]` and
nothing after it — named no field, so the filter was **dropped** and the query ran without it:

```
?filter={"key":"attachments[*]"}                  → every ticket, in 200
?filter={"key":"attachments[*]","quant":"none"}   → every ticket, in 200
```

**Now.** The list is counted, through the same `quant` key the relations use:

```
{"key":"attachments[*]"}                  → LENGTH(doc.attachments[*]) > 0    at least one
{"key":"attachments[*]","quant":"none"}   → LENGTH(doc.attachments[*]) == 0   none
{"key":"attachments[*]","quant":3}        → LENGTH(doc.attachments[*]) >= 3   at least three
{"key":"attachments[*]","quant":"all"}    → 400 — nothing to satisfy
```

**What to do.**

1. **Grep your front-end for a filter key naming a declared `Filter::ARRAY_EXPANSION` with nothing
   after the `[*]`.** Those calls used to return the whole collection and now return a subset. The
   result changes for the better, but it changes: a page that looked complete was complete because
   the filter was being ignored.
2. **Nothing to declare.** The behaviour comes from the `Filter::ARRAY_EXPANSION` you already
   declare; no new key, no opt-in.
3. **If you were sending `quant: "all"` on such a key**, it now answers `400`. It always meant
   "every element satisfies the condition", and with no condition there was nothing for it to mean.

**What "none" selects.** Every shape of emptiness at once: the empty list, the absent attribute, an
explicit `null`, and a value that is not a list. The caller asks one question and never has to know
which of those the record holds.

**What does not move.**

- **Filtering the elements** — `attachments[*].name`, `resolution.steps[*].dueAt` — is untouched at
  any depth.
- **A `match` on the list** — `{"key":"attachments[*]","match":{…}}` — keeps its behaviour and its
  per-sub-field permission gates. It is a multi-field test on the elements, not a count.
- **The list named without its `[*]`** — `{"key":"attachments"}` — keeps being dropped by the strict
  notation rule. That rule is what catches the caller who forgot the marker, and it is deliberately
  left standing rather than given a second meaning.

#### 7. `DefaultLangTrait` and `isLanguageCode()` moved to `oihana/php-controllers`

**Before.** Both lived here:

```php
use oihana\arango\traits\DefaultLangTrait;
use function oihana\arango\db\helpers\isLanguageCode;
```

**Now.** Both live in `oihana/php-controllers`:

```php
use oihana\controllers\traits\DefaultLangTrait;
use function oihana\controllers\helpers\isLanguageCode;
```

**Why.** Neither is an ArangoDB notion. `defaultLang` is the locale answered when a request asked
for none, and it belongs beside the two things that already frame it in that package —
`LanguagesTrait`, the set a request may ask for, and `PrepareLang`, what it did ask. A consumer
wiring a fallback locale should not have to depend on a database driver to get one.

**What to do.**

1. **Change the two `use` statements**, nothing else. Same trait, same helper, same behaviour, same
   `'defaultLang'` key — a configuration file does not move a character.
2. **No dependency to add.** `oihana/php-arango` already required `oihana/php-controllers`, so the
   classes are on your autoloader already. Run `composer update oihana/php-controllers`.
3. **⚠ If you read the key as `DefaultLangTrait::DEFAULT_LANG`**, that is a **fatal error** since
   PHP 8.4 — a trait constant cannot be accessed directly, and it was one here too. Read it through
   the class that uses the trait (`MyModel::DEFAULT_LANG`) or write the literal `'defaultLang'`.

**What stays here.** `assertLanguageCode()` does not move. Its job is the part that *is* an ArangoDB
concern: deciding who to blame — a `400` for a tag typed into a `?lang=`, a `500` for one declared
in code — and the `400` it raises is this package's own exception type. It now reads the predicate
from `php-controllers`, so there is one regex and not two.

#### 8. Calling `prepareFilter()` with an `alt` and no binds map is refused

**Before.** `prepareFilter( $init )` — with the `$binds` argument omitted — accepted an `alt` chain
and returned an AQL string. The chain's parameters were bound into a map the caller never received,
so the string declared placeholders nothing could fill. Handed to a server, it was refused whole.

**Now.** That call throws a `ValidationException` naming the wiring mistake, rather than returning a
query that cannot run.

**What to do.**

1. **Pass your binds by reference**, as every documented example already does:
   `$model->prepareFilter( $init , $binds )`. That is the only change, and if you were already
   doing it — which you were, or your queries would not have been running — nothing moves.
2. **A filter with no `alt` is unaffected.** The refusal is specific to a chain that has parameters
   to bind and nowhere to put them.
3. **A chain your own code declares through `trustedAlt()` is unaffected too.** It interpolates its
   parameters rather than binding them, so it needs no map.

**Why it is worth a refusal rather than a fix in silence.** This is the missing half of a guard that
already existed: `alterExpression()` refuses a request-supplied chain that arrives with no binder,
so that a reading point forgetting to supply one cannot reopen the hole quietly. It could not catch
the case where a binder *is* supplied but writes into nothing. Adding this half turned up a second
occurrence in the date filter within seconds of being written.

#### 9. An `alt` chain on a list-declared key is compiled against the list

**Before.** `{"key":"tags","val":3,"op":"ge","alt":"count"}` compiled to `COUNT(doc.tags) >= @v`.
`COUNT()` is defined on strings as well as arrays, so a record storing `"backend"` under a
list-declared key answered **7** and was counted among the heavily tagged ones — while
`{"val":0,"op":"eq","alt":"count"}`, *which records have no tags*, left that record **out**.

**Now.** The chain is compiled against a list-or-nothing form,
`COUNT((IS_ARRAY(doc.tags) ? doc.tags : [])) >= @v`. A value that is not a list becomes an empty
list and counts `0`.

**What to do.**

1. **Nothing, if your data matches your declarations.** A real list passes through the guard
   untouched, so every well-formed record answers exactly what it answered before — for every
   function of the catalogue, not just `count`.
2. **Expect malformed records to move**, in both directions: they drop out of "at least N" answers
   and appear in "none" answers. That is the fix. If a listing's totals shift, it is telling you
   which records store something other than a list under a key you declared as one.
3. **Nothing to declare, no opt-in.** The behaviour follows the `FilterType::ARRAY` you already
   declare.

⚠ **If you match on the emitted AQL** — in a test, a log assertion or an `EXPLAIN` snapshot — the
shape of the key changes for these filters, even though the answer does not.

**What does not move.**

- **The `at` index** — `{"key":"tags","at":0,"alt":"upper"}` still compiles `UPPER(doc.tags[0])`.
- **The comparison itself** — `{"key":"tags","val":["a","b"]}`, `quant`, and the `atLeast` forms
  still compare `doc.tags`. Only the key an `alt` chain wraps is expanded.
- **A `count` on a key declared as a string** still counts characters, which is what it means there.
- **An `alt` that only wraps the value** (`alt:{val:…}`) leaves the key alone.

#### 10. `quant: <n>` on a list of values now counts the elements that match

**Before.** `{"key":"ratings","op":"ge","val":4,"quant":3}` compiled to
`doc.ratings AT LEAST (3) >= @v`. ArangoDB answers `true` to that operator whenever the array holds
**fewer than `n` elements**, whatever they are, so the filter returned every record with fewer than
three ratings — the opposite of the question:

```
A: [5,4,4,2]  → 3 ratings >= 4   kept, correctly
C: [4,4]      → 2 ratings        kept, wrongly (list shorter than 3)
```

**Now.** It compiles to `doc.ratings[? AT LEAST (3) FILTER CURRENT >= @v]`, the same operator arrays
of objects have always used, which counts the elements that actually match.

**What to do.**

1. **Expect "at least n" listings to shrink**, and to become right. Anything that looked plausible
   before was including records too short to qualify.
2. **Nothing to declare, no opt-in**, and no change to how you write the filter.
3. **The legacy alias `op: ["atLeast.<cmp>", n]` moves with it** — same fix, same shape.

**What does not move.** `any`, `all` and `none` return exactly the same rows as before; they were
correct in either spelling and were aligned only so that one key does not produce two shapes. The
`at` index form is unchanged, and an `alt` chain in front of a quantifier still compiles.

⚠ **If you match on the emitted AQL** — a test, a log assertion, an `EXPLAIN` snapshot — the shape
changes for scalar arrays, including for the three quantifiers whose answers do not.

---

## 1.5.0 → 1.6.0 - 2026-08-24

Four breaking changes. Three are about **telling a caller that their request was not understood**
instead of quietly answering something else; the fourth stops a projection from rewriting an
identifier. None changes a signature: no override in a consuming project has to be rewritten.

### 🚨 Breaking

#### 1. An `alt` function the catalogue does not carry is refused

**Before.** The name of an `alt` transform was looked up in `FilterFunction`, and when it was not
there the transformation simply vanished: the comparison ran on the raw field. Asking for ISO week
34 with a mistyped `iw` compiled to `doc.startDate == @v` — and no date is ever equal to `34`. The
caller got an empty page, in `200`, with nothing to tell "the collection is empty" from "you
mistyped the function".

**Now.** The name is checked at every link of the chain, and an unknown one fails the query.

**What to do.**

1. **Grep your model declarations** for `Field::ALTERS`, `Field::WHEN` and `Facet::ALT`, and check
   every function name against the catalogue. A typo that used to pass unremarked will now fail the
   query on first deployment. The real names are `dateIsoWeek`, `dateYear`, `dateMonth`, `lower`,
   `trim`, … — spelled in full.
2. **Check the `alt` values your own front-end sends**, if it builds `?filter=`, `?group=` or
   `?facets=` from a list of its own.
3. Nothing else. A valid chain compiles exactly as before, byte for byte.

**One position is not checked, on purpose.** In `["trim","lowr","lower"]` the second element is read
as a *parameter* of `trim` — exactly as it is in the legitimate `["trim","-"]` ("strip dashes"). The
two notations are indistinguishable there. Write such a chain with its links nested —
`["trim",["substring",0,3],"lower"]` — and every link is checked again.

#### 2. A malformed request answers `400` instead of `500`

**Before.** Every refusal this library writes answered `500 Internal Server Error`, including the
ones caused by the caller: a mistyped `quant`, an unsupported operator inside a `match`, an unknown
facet aggregator. The message was already right — several of them enumerate the accepted values —
only the status disagreed.

**Now.** A refusal the caller can act on answers `400 Bad Request`. The message and the response
body are unchanged; only the status moves.

Concerned: an unknown `quant` (filter and traversal) or one below 1, an `all` with no leaf
condition, an unsupported operator inside a `match`, a `match` value that is not a scalar, an unsafe
sub-field name coming from the URL, an unknown `alt` or one missing its operand, an unknown facet
aggregator, and an aggregate outside the whitelist under `AggregatablePolicy::STRICT`.

**Still `500`, deliberately:** a fault in your own declaration or wiring — an invalid position key,
an `alt` declared in your model that does not exist, a reading point handing a request chain to the
engine with no binder. No URL will ever fix those.

**What to do.**

1. **Your tests.** Any test asserting `500` on a deliberately malformed request will turn red.
   That is the change; update the expectation.
2. **Your error handling.** If a middleware, a logger or an alert behaves differently on `4xx` and
   `5xx`, these refusals move from one bucket to the other. A rule logging `5xx` as `error` will go
   quiet on them.
3. **Retries.** Clients and gateways commonly replay a `500` and never a `400`. A malformed request
   that used to be replayed will now fail once — which is the correct behaviour, but it changes what
   your logs look like.
4. **Monitoring.** Your `5xx` rate will drop after deployment. It is a reclassification, not an
   improvement; note it next to the graph so nobody reads it as one.
5. **Your API documentation.** Every endpoint accepting `?filter=`, `?facets=`, `?group=` or
   `?sort=` should now document `400`.

**In PHP, nothing breaks.** The new `RequestValidationException` **extends** the `ValidationException`
every existing `catch` already names. If you want to tell the two apart, catch the new type first.

#### 3. An operator the filter cannot honour is refused

**Before.** An `op` the filter could not translate became an **equality**, in silence. Two mistakes
landed there:

- a code that does not exist — `zzz`, `GT` with the wrong case, `>` instead of `gt`, `sw ` with a
  trailing space ;
- a code that exists but not for that field — `sw` means "starts with", which is meaningful on text
  and meaningless on a number.

```
?filter={"key":"price","op":"sw","val":12}      ← "prices starting with 12"
→ doc.price == 12                                ← "prices equal to 12"
```

A handful of plausible rows, in `200`, answering a question nobody asked. The second case is the
dangerous one: an empty page gets noticed, a wrong page does not.

**Now.** Both are refused with a `400`, naming the refused code and listing what is accepted. The
same applies to the two array forms — `?quant=` and `atLeast.<op>` — and an operator handed as a
list of the wrong shape, which used to raise a PHP `TypeError`.

**What to do.**

1. **Check the operators your front-end sends.** The accepted spellings are lowercase names, not
   AQL symbols: `gt`, not `>` or `GT`.
2. **Check where you use a function form.** `sw`, `nsw`, `ew`, `new`, `contains`, `ncontains`,
   `regex` and `nregex` apply to `string` filters only; `between` to `string`, `number` and `date`;
   `distance` to `geo`. The twelve plain comparators (`eq`, `ne`, `gt`, `ge`, `lt`, `le`, `in`,
   `nin`, `like`, `nlike`, `match`, `nmatch`) apply everywhere.
3. **Nothing else.** An absent `op` still means `eq`, and so does an empty one — an unfilled
   `<select>` submits an empty string, and that keeps meaning "no operator".

**How much this is likely to affect you:** applying the fix turned red exactly the 39 combinations a
new test matrix had pinned as degrading, and **nothing else in the library's 5000 essays**. Nothing
depended on the old behaviour there, which is the best available signal that little consumer code
does either.

#### 4. A projected id is no longer converted to a number

**Before.** `Filter::ID` projects the document key under a public name, and it did so through
`TO_NUMBER(doc._key)`. An ArangoDB key **is** a string, and that conversion never fails — it returns
`0`. Measured on a real server:

| `_key` | before | after |
|---|---|---|
| `"007"` | `7` | `"007"` |
| `"1234"` | `1234` | `"1234"` |
| `"abc-42"` | `0` | `"abc-42"` |
| `"t9"` | `0` | `"t9"` |

The damage was **identity**, not precision: four documents came back carrying three identifiers,
with nothing in the response saying so, and a client indexing or de-duplicating on id silently lost
rows. Purely numeric keys were hit too — a zero-padded `"007"` came back as `7`, which no longer
addresses anything, and a key above 2^53 lost precision.

**Now.** The projected id is the key, as a string.

**What to do.**

1. **Anything comparing an id with `===` against a number breaks.** `id === 7` becomes
   `id === "007"`. Front-end code, caches keyed by id, and fixtures are the usual places.
2. **Check your sorts.** A column sorted on a projected id now sorts as text: `"10"` comes before
   `"9"`. If you need numeric order, sort on a numeric field rather than on the key.
3. **Your JSON schemas / OpenAPI** should declare the id as a string.

#### `assertAttributeName()` takes a second parameter

`assertAttributeName( mixed $value , bool $fromRequest = false )`. The default keeps the previous
behaviour, so existing calls are unaffected. Pass `fromRequest: true` where the name you are
guarding came from the wire, so the caller is told they wrote something the API cannot read.

### ✨ New, and optional

Nothing below is required. Both are additive; a project that ignores them behaves exactly as before.

#### Knowing which model call a lifecycle hook is serving

`beforeModelCall()` and `afterModelCall()` are shared by every verb, and one HTTP request runs
several model calls through them: a `PATCH` reaches `beforeModelCall()` **three times** — the
existence probe, the write, then the read that hands the document back — and `getMethod()` answers
`PATCH` to all three.

Each call now announces itself in the init:

```php
protected function beforeModelCall( ?Request $request , array &$init ) : void
{
    parent::beforeModelCall( $request , $init ) ;

    if ( ( $init[ Arango::OPERATION ] ?? null ) === ModelOperation::INSERT )
    {
        // The real insertion — not the read that hands the document back right after.
    }
}
```

If your controllers sniff the shape of `$init` to work out what they are serving — testing for the
absence of a target key, or of `Arango::DOC` — that guesswork can now be replaced by the
announcement. See `wiki/en/controllers/README.md` for how many times each hook runs, per verb.

#### Computed aggregates

An entry of `Arango::AGGREGATABLE` may hold an `AggregateExpression` instead of a path, so an
aggregate can read more than one place in the document — the sum of a slice of an array, the sum of
a difference between two arrays. See `wiki/en/db/grouping.md`.

### How to check your project

- [ ] `Field::ALTERS`, `Field::WHEN`, `Facet::ALT`: every function name spelled in full and present
      in the catalogue
- [ ] comparisons and caches keyed on a projected id, which is now a string (`"007"`, not `7`)
- [ ] the `op` values your front-end sends: lowercase names (`gt`), never AQL symbols (`>`), and
      function forms (`sw`, `contains`, `regex`, …) only on `string` filters
- [ ] tests asserting `500` on a malformed request
- [ ] middlewares, loggers and alerts that branch on `4xx` vs `5xx`
- [ ] retry policies, client-side or at the gateway
- [ ] dashboards and thresholds based on the `5xx` rate
- [ ] OpenAPI / route documentation: `400` on every endpoint taking `?filter=`, `?facets=`,
      `?group=` or `?sort=`
- [ ] code inspecting an exception **message** to work out its nature — it can now read the type or
      the status instead
