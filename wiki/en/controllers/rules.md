# Rules

## What is a *rule*?

A *rule* is a **constraint applied to a value** to check that it matches what we expect before writing it to the database. Some concrete examples:

- *"the `name` field is required, must be 2 to 70 characters long, and may only contain lowercase ASCII letters, digits, spaces, dashes and underscores"*;
- *"the `color` field must be a string in `#RRGGBB` format"*;
- *"the `description` field must be a typed i18n object (`{ fr: "...", en: "..." }`), not a flat string"*;
- *"the `level` field must be an integer between 0 and 9000"*.

The `oihana/arango` framework runs these constraints **after** payload preparation (cf. [Payloads](payloads.md)) and **before** writing to the database. If a single rule fails, the request is rejected with HTTP `422 Unprocessable Entity` and a per-field error map — no invalid data ever reaches the collection.

## Why a dedicated layer

Without a framework, validation quickly turns into a patchwork of `if`/`throw` scattered across every HTTP verb of every controller:

```php
// Don't do this — verbose, asymmetric, easy to forget on PATCH
public function post( ... ) : mixed
{
    $name = $payload[ 'name' ] ?? null ;
    if ( $name === null )                   throw new ValidationException( 'name required'   ) ;
    if ( strlen( $name ) < 2 )              throw new ValidationException( 'name too short'  ) ;
    if ( strlen( $name ) > 70 )             throw new ValidationException( 'name too long'   ) ;
    if ( !preg_match( '/^[a-z]+$/' , $name ) ) throw new ValidationException( 'name invalid' ) ;
    // ... same for color, description, level
}
```

The framework replaces that code with a **declaration** in the controller's DI definition. The benefits:

- **All the rules of an endpoint are visible in one place** (the `Arango::RULES` definition), not scattered across the controller.
- **All errors are collected in one pass**, not bubbled up at the first `throw`. The 422 response lists **every** problem so the client can fix them in one go.
- **The same rule catalog is reusable** across verbs (`POST`, `PATCH`, `PUT`) with targeted differences (`required` only on POST, for instance).
- **Business-specific rules** (a role name must match a precise regex) live in dedicated, testable classes (`RoleNameRule`, `ColorRule`...) rather than *inline* in controllers.

## What this page covers

The framework exposes **two keys** in the DI definition:

- [`Arango::RULES`](../enums.md) — per-field, per-method validation rules, expressed with the Somnambulist library *helpers* (`rules()`, `min()`, `max()`, `between()`, etc.).
- [`Arango::CUSTOM_RULES`](../enums.md) — a `field → DI identifier` mapping to plug in custom *Rule* classes from the project (`ColorRule`, `RoleNameRule`, `Iso8601DateOrDurationRule`, `I18nRule`...).

This page documents:

- The **position** of rules in the controller pipeline (after payload, before model).
- The **format** of both keys and the *custom rule* activation pattern.
- The **vendor catalog** `Rules::*` (the standard rules shipped by Somnambulist).
- The **project catalog** of `CustomRules::*` (the business rules specific to `oihana-odbc-php`).
- The **422 error format** returned to the client.

## Position in the pipeline

```
HTTP body
  → enforceI18nShape($init)              i18n shape   (cf. payloads.md)
  → preparePayload($init, &$relations)   extraction   (cf. payloads.md)
  → prepareRules($method)                merge ALL + current method
  → validator->validate($payload, $rules)  VALIDATION (this page)
  → 422 on errors  OR
  → beforeModelCall($request, &$init)    user hook
  → model->insert/update(...)            AQL write
  → afterModelCall(...)                  user hook
  → response
```

The validator consumes **the already-prepared payload** (typed, *whitelisted*, normalized). Rules therefore apply to clean data — `min(2)` on an extracted `string`, not on a still-unparsed raw JSON string.

## The `Arango::RULES` key

The `Arango::RULES` key accepts a **double-indexed** array: by HTTP method, then by field name. Same format as [`Arango::PAYLOAD`](payloads.md#arangopayload-definition-format) — the consistency is intentional.

```php
use function oihana\validations\rules\helpers\rules ;
use function oihana\validations\rules\helpers\min   ;
use function oihana\validations\rules\helpers\max   ;
use function oihana\validations\rules\helpers\between ;
use oihana\validations\enums\Rules ;

Arango::RULES =>
[
    HttpMethod::ALL =>
    [
        Prop::NAME  => rules( min(2) , max(70) ) ,
        Prop::LEVEL => rules( Rules::INTEGER , between( 0 , 9000 ) ) ,
    ] ,
    HttpMethod::POST =>
    [
        Prop::NAME => rules( Rules::REQUIRED , min(2) , max(70) ) ,
    ] ,
] ,
```

**Merge rule**: `prepareRules($method)` does `array_merge(rules[ALL], rules[$method])`. On `POST`, the `POST` block's `NAME` definition **fully replaces** the one from the `ALL` block. You therefore have to repeat `min(2)` and `max(70)` in the `POST` block if you want to keep those constraints alongside `required`.

**Why `required` is not in `ALL`**: on `PATCH`, the absence of a field means "don't touch it". Putting `required` in `ALL` would break every partial update.

### Composition helpers

| Helper | Signature | Output |
|---|---|---|
| `rules` | `rules( ...$rules ) : string` | Concatenates several rules with the `\|` separator expected by Somnambulist. |
| `min` | `min( int\|float $value ) : string` | `'min:2'` |
| `max` | `max( int\|float $value ) : string` | `'max:70'` |
| `between` | `between( int\|float $min , int\|float $max ) : string` | `'between:0,9000'` |

Other rules are written either as constants (`Rules::REQUIRED` = `'required'`, `Rules::INTEGER` = `'integer'`, ...), or as parameterized strings (`'in:foo,bar,baz'`, `'regex:/^[a-z]+$/'`, ...).

## The "final tag" pattern — activating a *custom rule*

At the heart of the system, a critical detail: if the **last element** passed to `rules()` is a field name that matches a key in `Arango::CUSTOM_RULES`, the corresponding *custom rule* is added to the chain. This is what wires the `ColorRule`, `RoleNameRule`, etc. classes.

```php
Arango::CUSTOM_RULES =>
[
    Prop::COLOR => CustomRules::COLOR ,         // 'rules:color' (DI identifier)
    Prop::NAME  => CustomRules::ROLE_NAME ,     // 'rules:auth:role:name'
] ,
Arango::RULES =>
[
    HttpMethod::ALL =>
    [
        // The trailing `Prop::NAME` is a tag that activates CustomRules::ROLE_NAME
        Prop::NAME  => rules( min(2) , max(70) , Prop::NAME ) ,
        // Same for color
        Prop::COLOR => rules( Rules::STRING , max(7) , Prop::COLOR ) ,
    ] ,
] ,
```

The pattern is sometimes condensed when **only** the custom rule applies:

```php
HttpMethod::ALL =>
[
    Prop::DESCRIPTION => Prop::DESCRIPTION ,    // activates CustomRules::I18N, nothing else
] ,
```

## The `Arango::CUSTOM_RULES` key

It maps a field name to a **DI identifier** of a Rule class, declared separately in `api/definitions/@api/rules/`. The validator resolves each identifier through the container at validation time.

```php
Arango::CUSTOM_RULES =>
[
    Prop::COLOR       => CustomRules::COLOR ,
    Prop::DESCRIPTION => CustomRules::I18N  ,
    Prop::NAME        => CustomRules::ROLE_NAME ,
] ,
```

On the Rule definition side (simplified extract):

```php
// api/definitions/@api/rules/auth.php
return
[
    CustomRules::ROLE_NAME => fn() => new RoleNameRule() ,
    // ...
] ;
```

Each Rule class extends `Somnambulist\Components\Validation\Rule` and exposes a `check(mixed $value) : bool` method.

## Vendor `Rules::*` catalog

The [`oihana\validations\enums\Rules`](../../../../api/vendor/oihana/php-system/src/oihana/validations/enums/Rules.php) enum lists every standard rule shipped by the Somnambulist library. The most common:

| Constant | Effect |
|---|---|
| `Rules::REQUIRED` | Field must be present and non-empty. |
| `Rules::STRING` | Must be a string. |
| `Rules::INTEGER` | Must be an integer. |
| `Rules::NUMERIC` | Must be numeric (int or float). |
| `Rules::BOOLEAN` | Must be a boolean (or *truthy*/*falsy* depending on mode). |
| `Rules::ARRAY` | Must be an array. |
| `Rules::DATE` | Must be a parseable date. |
| `Rules::EMAIL` | Email format. |
| `Rules::URL` | Valid URL. |
| `Rules::IP` | Valid IP address. |
| `Rules::ALPHA` | Alphabetic characters only. |
| `Rules::ALPHA_NUM` | Alphanumeric only. |
| `Rules::ALPHA_DASH` | Alphanumeric + dash + underscore. |
| `Rules::ALPHA_SPACES` | Alphanumeric + space. |
| `Rules::IN` | Value in a closed list (`in:foo,bar,baz`). |
| `Rules::NOT_IN` | Value outside a closed list. |
| `Rules::REGEX` | *Regex* match. |
| `Rules::ACCEPTED` | Acceptance value (`yes`, `on`, `1`, `true`). |
| `Rules::DIFFERENT` | Different from another field. |
| `Rules::SAME` | Identical to another field. |
| `Rules::ANY_OF` | At least one rule from a group. |
| `Rules::CALLBACK` | Validation through a PHP *callback*. |
| `Rules::COLOR` | Color format (used internally by `ColorRule`). |
| `Rules::DEFAULT` | Default value if absent. |
| `Rules::DIGITS` | Digits only, exact length. |

The full enum contains around fifty constants. See the [Somnambulist docs](https://github.com/somnambulist-projects/validation) for the parameterized syntax (`min:N`, `between:A,B`, `regex:/pattern/`, ...).

## Project `CustomRules::*` catalog

The [`fr\bouney\enums\CustomRules`](../../../../api/src/fr/bouney/enums/CustomRules.php) enum lists the business rules specific to `oihana-odbc-php`. All extend `Somnambulist\Components\Validation\Rule` and are registered in the DI container.

| Constant | DI identifier | Underlying class | Role |
|---|---|---|---|
| `CustomRules::COLOR` | `rules:color` | `ColorRule` | Validates `#RRGGBB` shape (hex regex). |
| `CustomRules::I18N` | `rules:i18n` | `I18nRule` | Validates an object's `{ fr: "...", en: "..." }` shape with allowed locale codes. |
| `CustomRules::ROLE_NAME` | `rules:auth:role:name` | `RoleNameRule` | Validates the canonical role name grammar: `[a-z0-9 _-]{2,70}`. |
| `CustomRules::ISO_DATE_OR_DURATION` | `rules:iso8601:date:or:duration` | `Iso8601DateOrDurationRule` | Validates an ISO 8601 date or duration (`2026-05-18` or `P30D`). |
| `CustomRules::POSTAL_CODE` | `rules:postal:code` | `PostalCodeRule` | Validates a postal code by country. |
| `CustomRules::GREATER_THAN` | `rules:greater:than` | `GreaterThanRule` | Strict comparison against a threshold. |
| `CustomRules::GREATER_THAN_OR_EQUAL` | `rules:greater:than:or:equal` | `GreaterThanOrEqualRule` | Same, ≥ comparison. |
| `CustomRules::LESS_THAN` | `rules:less:than` | `LessThanRule` | Strict comparison against a ceiling. |
| `CustomRules::LESS_THAN_OR_EQUAL` | `rules:less:than:or:equal` | `LessThanOrEqualRule` | Same, ≤ comparison. |
| `CustomRules::EQUAL` | `rules:equal` | `EqualRule` | Strict equality against a reference value. |
| `CustomRules::HTTP_METHOD` | `rules:http:method` | `HttpMethodRule` | Validates an HTTP verb (`GET`, `POST`, `PATCH`, ...). |
| `CustomRules::LATITUDE` | `rules:geo:latitude` | geo rule | Latitude (`-90..+90`). |
| `CustomRules::LONGITUDE` | `rules:geo:longitude` | geo rule | Longitude (`-180..+180`). |
| `CustomRules::ELEVATION` | `rules:geo:elevation` | geo rule | Reasonable altitude. |
| `CustomRules::APIS_HAS_API` | `rules:apis:has:api` | business rule | Checks that a referenced API exists in the database. |
| `CustomRules::API_HAS_IDENTIFIER` | `rules:api:has:identifier` | business rule | Checks that an API has a declared identifier. |
| `CustomRules::API_HAS_UNIQUE_IDENTIFIER` | `rules:api:has:unique:identifier` | business rule | Checks API identifier uniqueness. |
| `CustomRules::USERS_HAS_USER` | `rules:users:has:user` | business rule | Checks that a referenced user exists in the database. |

> Note: rules prefixed `rules:apis:`, `rules:api:`, `rules:users:` perform **database lookups** at validation time. Use sparingly on high-traffic endpoints (each insertion costs one extra AQL `EXIST`).

### Writing a custom rule

```php
namespace fr\bouney\rules ;

use Somnambulist\Components\Validation\Rule ;

class MyCustomRule extends Rule
{
    protected string $message = ':attribute must be a valid foo bar' ;

    public function check( mixed $value ) : bool
    {
        return is_string( $value ) && preg_match( '/^foo-[a-z0-9]+-bar$/' , $value ) === 1 ;
    }
}
```

DI declaration:

```php
CustomRules::MY_CUSTOM => fn() => new MyCustomRule() ,
```

Activation in a controller definition:

```php
Arango::CUSTOM_RULES =>
[
    Prop::SOMETHING => CustomRules::MY_CUSTOM ,
] ,
Arango::RULES =>
[
    HttpMethod::ALL =>
    [
        Prop::SOMETHING => rules( Rules::REQUIRED , Prop::SOMETHING ) ,
    ] ,
] ,
```

## 422 error format

When at least one rule fails, the validator collects **all** errors (not just the first) and the controller returns a `422 Unprocessable Entity` response with this JSON *body*:

```json
{
    "status": "error",
    "code":   422,
    "result":
    {
        "errors":
        {
            "name":  "name must be a valid name (required, min:2, max:70)",
            "color": "color must be a valid color expression, ex: #ff0000"
        }
    }
}
```

Conventions:

- **One key per invalid field**. Valid fields are not listed.
- **A description string** rather than a list, even when several rules failed — the message is composed to stay readable.
- **No technical leak** — the string contains no raw regex nor Rule class name. If a message needs to be very precise (geo cases, business cases), the Rule provides its own clear `$message`.

Client-side, the recommended pattern: display each error next to the corresponding form field, and don't resubmit until every error is resolved.

## Full example — `roles.php`

Complete annotated definition for the `roles` collection, combining RULES + CUSTOM_RULES:

```php
Arango::CUSTOM_RULES =>
[
    Prop::COLOR       => CustomRules::COLOR     ,   // validates #RRGGBB via ColorRule
    Prop::DESCRIPTION => CustomRules::I18N      ,   // validates i18n shape (locale keys + string|null values)
    Prop::NAME        => CustomRules::ROLE_NAME ,   // validates `[a-z0-9 _-]{2,70}` grammar via RoleNameRule
] ,

Arango::RULES =>
[
    // ALL block: constraints applied on every verb. NO `required` here —
    // a partial PATCH must be allowed to omit a field without crashing.
    HttpMethod::ALL =>
    [
        Prop::NAME        => rules( min(2) , max(70) , Prop::NAME ) ,                       // final tag activates ROLE_NAME
        Prop::COLOR       => rules( Rules::STRING , max(7) , Prop::COLOR ) ,                // final tag activates COLOR
        Prop::DESCRIPTION => Prop::DESCRIPTION ,                                            // standalone tag activates I18N
        Prop::LEVEL       => rules( Rules::INTEGER , between( $minLevel , $maxLevel ) ) ,   // no custom rule
    ] ,

    // POST block: adds `required` on creation-mandatory fields.
    // The array_merge overrides NAME → we re-write min/max if we want them too.
    HttpMethod::POST =>
    [
        Prop::NAME => rules( Rules::REQUIRED , min(2) , max(70) , Prop::NAME ) ,
    ] ,

    HttpMethod::PATCH => [] ,                                                                // nothing to add to ALL
] ,
```

Behavior:

- `POST /roles { "name": "ED" }` → 422: `name` too short (`min:2` accepts 2, but ROLE_NAME requires lowercase so `'ED'` violates the regex).
- `POST /roles { }` → 422: `name` *required*.
- `PATCH /roles/{id} { "name": "editor" }` → OK: `required` absent on PATCH, regex respected.
- `PATCH /roles/{id} { "level": 9001 }` → 422: `level` outside the `[0, 9000]` *range*.
- `POST /roles { "name": "editor", "color": "purple" }` → 422: `color` invalid (`max:7` accepts, but ColorRule requires `#RRGGBB`).

## See also

- [Payloads](payloads.md) — the extraction layer that produces the data validated by rules.
- [Controllers overview](README.md) — full pipeline, hooks, injection traits.
- [Enums reference](../enums.md) — `Arango::*`, `HttpMethod::*`.
- [Tips and pitfalls](../tips.md) — cross-cutting golden rules.
- [Somnambulist documentation](https://github.com/somnambulist-projects/validation) — underlying validation library.
