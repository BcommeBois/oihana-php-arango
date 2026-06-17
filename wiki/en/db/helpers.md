# AQL helpers `db/helpers/`

The [`src/oihana/arango/db/helpers/`](../../../src/oihana/arango/db/helpers/) folder gathers the standalone functions that compose **AQL text fragments**: value encoding, inline document construction, expression serialization, sub-expressions of modification operations, and *field builders* for `RETURN { ... }`.

> Not to be confused with two other namesake folders:
>
> - [`db/operations/`](../../../src/oihana/arango/db/operations/) — full AQL operations (`aqlFor`, `aqlFilter`, `aqlReturn`, ...), documented in [Building an AQL query step by step](../aql/aql-building-queries.md).
> - [`db/functions/`](../../../src/oihana/arango/db/functions/) — AQL functions on the value side (`CONCAT`, `LOWER`, `DATE_NOW`, ...), documented in the [String functions](../aql/aql-functions-strings.md) and following pages.
>
> The helpers on this page work on **AQL text**: they produce strings ready to be injected into a query.

## Categories

The folder counts 29 functions, organized in five categories:

| Category | Functions | Role |
|---|---|---|
| Value encoding | `aqlValue`, `aqlExpression`, `aqlDocument`, `aqlArray`, `aqlSafeArray` | Turn a PHP value into an AQL fragment. |
| Fragment composition | `aqlAssignments`, `aqlSerialize` | Serialize *key/value* pairs. |
| CUD sub-expressions | `aqlInsertExpression`, `aqlUpdateExpression`, `aqlReplaceExpression`, `aqlUpsertExpression` | Build the body of `INSERT` / `UPDATE` / `REPLACE` / `UPSERT` operations. |
| *Field builders* (`fields/`) | `aqlFields` + 12 typed `aqlField*` | Build `RETURN { key: doc.value, ... }`. |
| Introspection and projection | `isAQLExpression`, `isAQLFunction`, `isAQLId`, `isAttributeName`, `assertAttributeName`, `matchesSkin`, `resolveSkinFields` | Detect, validate and route. |

## Value encoding

### `aqlValue()` — the foundation

```php
function aqlValue( mixed $value , array $rawValues = [] ) : string
```

Turns a PHP value into a safe AQL expression. This is the most used function of the folder — every other directly or indirectly depends on it.

For strings, the function first attempts automatic detection: if the string looks like an **AQL expression** (function call like `CONCAT(...)`, document reference `doc.field`, *bind variable* `@var`, *document handle* `users/123`), it is returned as-is. Otherwise, it is escaped and wrapped in single *quotes*.

```php
use function oihana\arango\db\helpers\aqlValue ;

aqlValue( 'hello'       ) ;        // "'hello'"
aqlValue( 42            ) ;        // '42'
aqlValue( true          ) ;        // 'true'
aqlValue( null          ) ;        // 'null'
aqlValue( [1, 2, 3]     ) ;        // '[1,2,3]'

// Automatic detection
aqlValue( 'CONCAT("a","b")' ) ;    // 'CONCAT("a","b")' (raw)
aqlValue( 'doc.name'        ) ;    // 'doc.name'        (raw)
aqlValue( '@userId'         ) ;    // '@userId'         (raw)
aqlValue( 'users/123'       ) ;    // 'users/123'       (raw)
```

The second `$rawValues` parameter allows forcing *raw* treatment for strings that would otherwise be escaped:

```php
aqlValue( 'my_variable' )                       ; // "'my_variable'" (quoted)
aqlValue( 'my_variable' , [ 'my_variable' ] )   ; // 'my_variable'   (raw, forced)
```

Associative arrays are delegated to `aqlDocument()`, objects to `aqlDocument(get_object_vars(...))`, indexed arrays are serialized as `[v1,v2,v3]`. Any unsupported type throws `oihana\exceptions\UnsupportedOperationException`.

### `aqlExpression()` — simplified entry point

```php
function aqlExpression( object|string|array|null $value ) : ?string
```

Lighter variant of `aqlValue()` that handles three cases: `null` returns `null`, a string is returned as-is (never quoted), and an *array* or *object* is delegated to `aqlDocument()`. To be used when you know the value is either an already-formed AQL textual expression or a document to serialize — not a scalar to escape.

```php
use function oihana\arango\db\helpers\aqlExpression ;

aqlExpression( 'FOR u IN users RETURN u'   ) ;     // 'FOR u IN users RETURN u'
aqlExpression( [ 'name' => 'John' ]        ) ;     // "{name:'John'}"
aqlExpression( null                        ) ;     // null
```

### `aqlDocument()` — build an AQL document

```php
function aqlDocument
(
    object|array|string|null $keyValues = []   ,
    array                    $options   = []
) : string
```

Builds an inline document expression `{key:value,...}`. Accepts an associative *array*, an indexed *array* of `[key, value]` pairs, an object, a string (placed as-is between braces), or `null` (returns `'{}'`).

Three options:

- `AQL::USE_SPACE` *(bool)* — adds spaces around braces and after commas (readability of long queries).
- `AQL::RAW_VALUES` *(array)* — list of keys whose value must be treated as a raw AQL expression (no escape, no *quotes*).
- `AQL::RAW_KEYS` *(array)* — list of keys whose **entire** value must stay raw (both the key AND the value).

```php
use function oihana\arango\db\helpers\aqlDocument ;
use oihana\arango\db\enums\AQL ;

aqlDocument( [ '_from' => 'u._id' , '_to' => 'p._id' ] ) ;
// "{_from:u._id,_to:p._id}"

aqlDocument
(
    [ '_key' => 'CONCAT("u_", i)' , 'name' => 'test' ] ,
    [ AQL::USE_SPACE => true , AQL::RAW_VALUES => [ '_key' ] ]
) ;
// "{ _key: CONCAT(\"u_\", i), name:'test' }"

aqlDocument( [ 'user' => [ 'name' => 'Eka' , 'age' => 47 ] , 'active' => true ] ) ;
// "{user:{name:'Eka',age:47},active:true}"
```

Keys are validated: only those matching `/^[a-zA-Z_]\w*$/` are left as-is, others are quoted and escaped.

### `aqlArray()` and `aqlSafeArray()`

```php
function aqlArray    ( mixed  $value = null                         ) : string
function aqlSafeArray( string $path , ?string $default = '[]'      ) : string
```

`aqlArray()` produces an AQL array expression from a PHP value: an *array* is JSON-encoded, a string is returned as-is (assumed to be an AQL reference like `doc.items`), an object is *cast* to *array*, any other type returns `[]`.

`aqlSafeArray()` produces a defensive expression that guarantees access to an array field returns at least the `$default` (`[]` by default) if the field is not an array server-side. Useful for projections on optional fields.

```php
use function oihana\arango\db\helpers\aqlArray ;
use function oihana\arango\db\helpers\aqlSafeArray ;

aqlArray( [ 1 , 2 , 3 ]  ) ;        // '[1,2,3]'
aqlArray( 'doc.items'    ) ;        // 'doc.items'
aqlArray( null           ) ;        // '[]'

aqlSafeArray( 'doc.offers' ) ;      // defensive expression on doc.offers
```

## Fragment composition

### `aqlAssignments()`

```php
function aqlAssignments
(
    ?array $assignments = []   ,
    string $separator   = ', '
) : string
```

Serializes an associative array into a list of `key = value` assignments joined by the separator. Used to build the `WITH { ... }` clause of an `UPDATE`, or anywhere else a textual list of assignments is expected.

### `aqlSerialize()`

```php
function aqlSerialize( mixed $value , bool $topLevel = true ) : string
```

Generic recursive serializer. Converts an arbitrary value (scalar, *array*, object) into an AQL fragment by delegating to specialized helpers based on the type encountered. The `$topLevel` parameter controls whether the root value should be wrapped (useful for internal recursion).

## CUD operation sub-expressions

Four functions build the **body** of an AQL modification operation. They all consume a `$init` array of `AQL::*` keys (collection, document, options, etc.) and return the corresponding textual sub-expression.

| Function | Signature | Produces |
|---|---|---|
| `aqlInsertExpression` | `(array $init = []) : string` | `INSERT { ... } INTO collection [OPTIONS { ... }]` |
| `aqlUpdateExpression` | `(array $init = []) : string` | `UPDATE key WITH { ... } IN collection [OPTIONS { ... }]` |
| `aqlReplaceExpression` | `(array $init = []) : string` | `REPLACE key WITH { ... } IN collection [OPTIONS { ... }]` |
| `aqlUpsertExpression` | `(array $init = []) : string` | `UPSERT { ... } INSERT { ... } UPDATE { ... } IN collection [OPTIONS { ... }]` |

These functions are consumed by the CRUD *traits* of models (`DocumentsInsertTrait`, `DocumentsUpdateTrait`, ...) — in direct use within a custom query, they are rarely called.

## *Field builders* — `fields/` sub-folder

The [`fields/`](../../../src/oihana/arango/db/helpers/fields/) sub-folder contains 12 *builders* that produce typed `key: doc.value` sub-expressions of a `RETURN { ... }`. Each handles a field type and applies the appropriate projection (cast, access, transformation).

### Entry point — `aqlFields()`

```php
function aqlFields
(
    ?array              $fields    = null      ,
    string              $docRef    = AQL::DOC  ,
    ?ContainerInterface $container = null
) : string
```

Composes a complete `RETURN { ... }` expression from an array of field definitions. Each definition is routed to the appropriate *field builder* based on its `Field::FILTER`. `$docRef` is the document alias in the query (default `doc`). `$container` is used by some *builders* that need to resolve a dependency (e.g. `aqlFieldUrl` for the *base URL*).

### Catalog of the 12 *field builders*

| *Builder* | Associated filter | Role |
|---|---|---|
| `aqlFieldDefault` | `Filter::DEFAULT` | Simple reference `key: doc.keyName`. |
| `aqlFieldBool` | `Filter::BOOL` | Boolean cast `key: TO_BOOL(doc.x)`. |
| `aqlFieldNumber` | `Filter::NUMBER` | Numeric cast `key: TO_NUMBER(doc.x)`. |
| `aqlFieldDateTime` | `Filter::DATETIME` | ISO 8601 date cast. |
| `aqlFieldArray` | `Filter::ARRAY` | Defensive array field. |
| `aqlFieldArrayCount` | `Filter::ARRAY_COUNT` | Number of elements in the array. |
| `aqlFieldArrayFirst` | `Filter::ARRAY_FIRST` | First element of the array. |
| `aqlFieldDocument` | `Filter::DOCUMENT` | Nested document (with sub-projection). |
| `aqlFieldObject` | `Filter::OBJECT` | Object or first element of an array. |
| `aqlFieldMap` | `Filter::MAP` | Mapping of an array to structured documents. |
| `aqlFieldTranslate` | `Filter::TRANSLATE` | Translated field (current *locale* selection). |
| `aqlFieldUrl` | `Filter::URL` | Document URL with dynamic *placeholders* (and optional per-type routing). |

Minimal example of the simplest *builder*:

```php
use function oihana\arango\db\helpers\fields\aqlFieldDefault ;

aqlFieldDefault( 'name'   , 'doc'          ) ;    // "name: doc.name"
aqlFieldDefault( 'userId' , 'doc' , 'id' ) ;      // "userId: doc.id"
```

In normal use, these *builders* are not called directly: you declare a `Documents` model with typed `Field::FILTER`s and `aqlFields()` routes automatically.

### Per-field options

Besides `Field::FILTER`, each field definition accepts options:

| Option | Effect | Output example |
|---|---|---|
| `Field::NAME` | Alias: the output key differs from the source attribute | `slug:doc.title` |
| `Field::ALTERS` | `alt` transformation chain applied to the projected value | `name:LOWER(TRIM(doc.name))` |
| `Field::QUOTED` | Double-quoted output label (keys with special characters) | `` "my-key":doc.`my-key` `` |
| `Field::UNIQUE` | Unique variable name for the AQL expression | — |
| `Field::REQUIRES` | Permission subject(s): the field is dropped if authorization is denied | — |
| `Field::SCOPE` | Projection source inside an edge sub-query: `Scope::VERTEX` (default) or `Scope::EDGE` (the relationship metadata) | `since:DATE_ISO8601(e.created)` |
| `Field::WHEN` / `Field::ELSE` | Conditional value: project the field only when a condition holds, else fall back. See [Conditional fields](conditional-fields.md). | `price:doc.visibility == 'public' ? doc.price : null` |

> `Field::QUOTED` quotes **only the output label** and reaches the attribute with **backticks** (`` doc.`my-key` ``) — the valid AQL form for a special-character attribute (`doc."my-key"` is invalid and rejected by ArangoDB). A `Field::NAME` then supplies the source: only the label is quoted (`"slug":doc.title`).

Worked examples:

```php
use oihana\arango\enums\Field ;
use oihana\arango\enums\Filter ;
use function oihana\arango\db\helpers\aqlFields ;

// Default projection
aqlFields([ 'name' => [] ]);
// name:doc.name

// Several typed fields (joined by ', ')
aqlFields([
    'name'   => [] ,
    'price'  => [ Field::FILTER => Filter::NUMBER ] ,
    'active' => [ Field::FILTER => Filter::BOOL ] ,
]);
// name:doc.name, price:TO_NUMBER(doc.price), active:TO_BOOL(doc.active)

// Custom document reference (edge/join sub-query)
aqlFields([ 'tags' => [ Field::FILTER => Filter::ARRAY ] ], 'edge');
// tags:IS_ARRAY(edge.tags) ? edge.tags : []

// Key alias (Field::NAME)
aqlFields([ 'slug' => [ Field::NAME => 'title' ] ]);
// slug:doc.title

// Output-side transformation (Field::ALTERS)
aqlFields([ 'name' => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ]);
// name:LOWER(TRIM(doc.name))

// Special-character key (quoted label, backtick attribute)
aqlFields([ 'my-key' => [ Field::QUOTED => true ] ]);
// "my-key":doc.`my-key`
```

### URL fields — `Filter::URL`

A `Filter::URL` field builds a full URL `CONCAT(<path>, '/', doc._key)`. The path
(`Field::PATH`) is resolved **at build time** in PHP: `{param}` placeholders are
replaced from `Arango::ARGS` and the *base URL* (resolved from the container) is
prefixed, so the same path applies to every document.

```php
aqlFields([ 'url' => [ Field::FILTER => Filter::URL , Field::PATH => '/places' ] ], 'doc', $container);
// url:CONCAT('https://base.url/places','/',doc._key)
```

**Per-type routing — `Field::PATHS`.** When the route depends on the document's type,
declare a `Field::PATHS` map `'<discriminant value>' => '<route>'`. The path is then
chosen **at query time** with AQL `TRANSLATE()` on a discriminant attribute — by default
`Schema::ADDITIONAL_TYPE`, overridable with `Field::PROPERTY`. `Field::PATH` becomes the
**mandatory** fallback route for documents whose type is not in the map (it is emitted as
the third `TRANSLATE()` argument, so an unmatched type never leaks the raw discriminant
into the URL).

```php
aqlFields([ 'url' =>
[
    Field::FILTER   => Filter::URL ,
    Field::PATH     => '/thing' ,                              // fallback (mandatory with PATHS)
    Field::PATHS    => [ 'Place' => '/places' , 'Person' => '/people' ] ,
    Field::PROPERTY => Schema::ADDITIONAL_TYPE ,               // optional, this is the default
]], 'doc');
// url:CONCAT(TRANSLATE(doc.additionalType,{Place:'/places',Person:'/people'},'/thing'),'/',doc._key)
```

> Placeholders and the *base URL* are applied to **each** branch and to the fallback alike.
> Declaring `Field::PATHS` **without** a `Field::PATH` fallback (or with an empty / non-associative
> map) throws an `UnsupportedOperationException` at build time; the discriminant attribute is
> validated by `assertAttributeName` (injection guard).

## AQL introspection

Four predicates classify a string:

| Function | Signature | True if... |
|---|---|---|
| `isAQLExpression` | `(mixed $value) : bool` | The string looks like an AQL expression (function, doc reference, bind, *handle*). |
| `isAQLFunction` | `(string $expression) : bool` | The string is a valid and known AQL function call (`COUNT(doc)`, `DATE_NOW()`, ...). |
| `isAQLId` | `(mixed $value) : bool` | The string matches the *document handle* format `collection/key`. |
| `isAttributeName` | `(mixed $value) : bool` | The string is a safe attribute name — one or more identifier segments joined by dots (`value`, `_key`, `breeding.alternateName`). |

The first three predicates are consumed internally by `aqlValue()` to decide whether to escape a string. They are public for cases where you need the same heuristic on the validation side.

### Injection guard — `isAttributeName` / `assertAttributeName`

An untrusted **value** always goes behind a *bind* (see [Bind variables](binds.md)), so it can never be injected. But an **attribute name** (a key) coming from user input and concatenated into a `doc.<name>` accessor **cannot** be a bind — it is an identifier, not a value. That is what this pair guards (the attribute counterpart of `isBindVariable` / `assertBindVariable`):

| Function | Signature | Role |
|---|---|---|
| `isAttributeName` | `(mixed $value) : bool` | Predicate: `true` when the string is a safe attribute name (or dotted path). |
| `assertAttributeName` | `(mixed $value) : void` | Throws `ValidationException` when the name is unsafe. |

The accepted pattern is `^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$`: any character able to break out of an attribute path (space, `(`, `||`, `"`, `;`, `-`, ...) is rejected.

```php
use function oihana\arango\db\helpers\assertAttributeName;

assertAttributeName( 'breeding.alternateName' ); // ok (nested path)
assertAttributeName( 'a || 1==1' );              // throws ValidationException
```

Used by the complex facets (`Facet::ARRAY_COMPLEX`, `Facet::EDGE_COMPLEX`) to validate the **sub-field names** supplied in `?facets=` before interpolating them into the query: a malicious sub-field makes the facet fail (dropped + warning logged), and no fragment ever reaches the AQL. See [Facets](facets.md).

## *Skin* projection helpers

Two functions tied to the *skin* projection system (covered in detail in [Edge and join projection](../edges-joins-projection.md)):

| Function | Signature | Role |
|---|---|---|
| `matchesSkin` | `(mixed $skins, ?string $currentSkin) : bool` | Evaluates a `Field::SKINS` marker against the request *skin*. |
| `resolveSkinFields` | `(array $definition, ?string $skin) : mixed` | Selects which projection an *edge* or *join* definition should use, depending on the current *skin* (`AQL::SKIN_FIELDS` first, `AQL::FIELDS` next). |

These two functions are almost never called directly by application code — they are consumed internally by `FieldsTrait::filterFieldsBySkin` and `buildVariables`. Documented here for catalog consistency.

## See also

- [Bind variables `db/binds/`](binds.md) — place actual values produced by `aqlValue()` behind safe *placeholders*.
- [Building an AQL query step by step](../aql/aql-building-queries.md) — assemble these helpers with the AQL operations.
- [Edge and join projection](../edges-joins-projection.md) — `matchesSkin` and `resolveSkinFields` in context.
- [Official AQL documentation — fundamentals](https://docs.arangodb.com/stable/aql/fundamentals/).
