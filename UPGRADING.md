# Upgrading

What to do when moving a project from one version of **oihana/php-arango** to the next.

The [CHANGELOG](CHANGELOG.md) tells the whole story of every release, in the order things happened.
This file answers a narrower question: *I am upgrading — what breaks, and what do I have to do about
it?* It starts at 1.6.0; for earlier versions, read the "Backward compatibility" paragraphs inside
the CHANGELOG entries.

## [Unreleased]

---

## 1.5.0 → 1.6.0 - 2026-08-24

Three breaking changes, all about **telling a caller that their request was not understood** instead
of quietly answering something else. None changes a signature: no override in a consuming project has
to be rewritten.

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
