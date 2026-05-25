# AQL helpers `db/helpers/`

The [`src/oihana/arango/db/helpers/`](../../src/oihana/arango/db/helpers/) folder gathers the standalone functions that compose **AQL text fragments**: value encoding, inline document construction, expression serialization, sub-expressions of modification operations, and *field builders* for `RETURN { ... }`.

> Not to be confused with two other namesake folders:
>
> - [`db/operations/`](../../src/oihana/arango/db/operations/) — full AQL operations (`aqlFor`, `aqlFilter`, `aqlReturn`, ...), documented in [Building an AQL query step by step](aql/aql-building-queries.md).
> - [`db/functions/`](../../src/oihana/arango/db/functions/) — AQL functions on the value side (`CONCAT`, `LOWER`, `DATE_NOW`, ...), documented in the [String functions](aql/aql-functions-strings.md) and following pages.
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
| Introspection and projection | `isAQLExpression`, `isAQLFunction`, `isAQLId`, `matchesSkin`, `resolveSkinFields` | Detect and route. |

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

The [`fields/`](../../src/oihana/arango/db/helpers/fields/) sub-folder contains 12 *builders* that produce typed `key: doc.value` sub-expressions of a `RETURN { ... }`. Each handles a field type and applies the appropriate projection (cast, access, transformation).

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
| `aqlFieldUrl` | `Filter::URL` | Document URL with dynamic *placeholders*. |

Minimal example of the simplest *builder*:

```php
use function oihana\arango\db\helpers\fields\aqlFieldDefault ;

aqlFieldDefault( 'name'   , 'doc'          ) ;    // "name: doc.name"
aqlFieldDefault( 'userId' , 'doc' , 'id' ) ;      // "userId: doc.id"
```

In normal use, these *builders* are not called directly: you declare a `Documents` model with typed `Field::FILTER`s and `aqlFields()` routes automatically.

## AQL introspection

Three predicates classify a string:

| Function | Signature | True if... |
|---|---|---|
| `isAQLExpression` | `(mixed $value) : bool` | The string looks like an AQL expression (function, doc reference, bind, *handle*). |
| `isAQLFunction` | `(string $expression) : bool` | The string is a valid and known AQL function call (`COUNT(doc)`, `DATE_NOW()`, ...). |
| `isAQLId` | `(mixed $value) : bool` | The string matches the *document handle* format `collection/key`. |

These predicates are consumed internally by `aqlValue()` to decide whether to escape a string. They are public for cases where you need the same heuristic on the validation side.

## *Skin* projection helpers

Two functions tied to the *skin* projection system (covered in detail in [Edge and join projection](edges-joins-projection.md)):

| Function | Signature | Role |
|---|---|---|
| `matchesSkin` | `(mixed $skins, ?string $currentSkin) : bool` | Evaluates a `Field::SKINS` marker against the request *skin*. |
| `resolveSkinFields` | `(array $definition, ?string $skin) : mixed` | Selects which projection an *edge* or *join* definition should use, depending on the current *skin* (`AQL::SKIN_FIELDS` first, `AQL::FIELDS` next). |

These two functions are almost never called directly by application code — they are consumed internally by `FieldsTrait::filterFieldsBySkin` and `buildVariables`. Documented here for catalog consistency.

## See also

- [Bind variables `db/binds/`](db-binds.md) — place actual values produced by `aqlValue()` behind safe *placeholders*.
- [Building an AQL query step by step](aql/aql-building-queries.md) — assemble these helpers with the AQL operations.
- [Edge and join projection](edges-joins-projection.md) — `matchesSkin` and `resolveSkinFields` in context.
- [Official AQL documentation — fundamentals](https://docs.arangodb.com/stable/aql/fundamentals/).
