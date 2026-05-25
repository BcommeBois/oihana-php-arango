# Bind variables `db/binds/`

The [`api/src/oihana/arango/db/binds/`](../../../api/src/oihana/arango/db/binds/) folder groups the five functions that ensure **safe injection** of values and collection names into an AQL query.

This is the framework's first line of defense against AQL injection: no dynamic value should ever be concatenated directly into a query. Every value goes through `aqlBind()` or `aqlBindCollection()`, which return a safe *placeholder* (`@var` or `@@coll`) and store the value in a `bindVars` array passed separately to ArangoDB.

## Why *bind variables*

ArangoDB separates the **query text** from the **injected values**: values are referenced by a *placeholder* (`@var` for a value, `@@coll` for a collection name) and supplied through the `bindVars` parameter. Two benefits:

1. **Security.** No AQL injection risk — the engine treats values as data, never as code.
2. **Performance.** The server's *query cache* factorizes execution plans on the AQL text: two identical queries with different values share the same plan, provided *bind variables* are used.

The `db/binds/` functions standardize the production of these *placeholders*.

## `aqlBind()` — standard case

```php
function aqlBind
(
    mixed   $value                 ,
    array   &$binds       = []     ,
    ?string $to           = null   ,
    ?string $toPrefix     = null   ,
    bool    $isCollection = false
) : string
```

Binds an arbitrary value to an AQL *placeholder*. The `$binds` array is passed by reference and receives the new entry; the function returns the formatted *placeholder* ready to be inserted in the query.

If `$to` is `null`, a unique name is generated as `<prefix>_<6 digits>` (e.g. `q_482931`). The default prefix is `q`; it can be overridden via `$toPrefix` (useful to produce expressive names in *logs*).

```php
use function oihana\arango\db\binds\aqlBind ;

$binds = [] ;

$ph = aqlBind( 'John' , $binds , 'userName' ) ;
// $ph    => '@userName'
// $binds => [ 'userName' => 'John' ]

$ph = aqlBind( 42 , $binds ) ;
// $ph    => '@q_482931'
// $binds => [ 'userName' => 'John', 'q_482931' => 42 ]

$ph = aqlBind( true , $binds , null , 'flag' ) ;
// $ph    => '@flag_716052'
// $binds => [ ..., 'flag_716052' => true ]
```

Any scalar, array or object value is accepted — ArangoDB handles serialization server-side. A `BindException` is thrown if `$to` is supplied and does not respect ArangoDB's naming rules.

## `aqlBindCollection()` — collection name

```php
function aqlBindCollection
(
    mixed   $value             ,
    array  &$binds   = []      ,
    ?string $to       = null   ,
    ?string $toPrefix = null
) : string
```

Variant dedicated to **collection names**. In AQL, a bound collection is distinguished from a value by the double `@@` prefix (`FOR doc IN @@coll`) instead of `@` (`FILTER doc.x == @val`). It is just *sugar* on top of `aqlBind()` with `isCollection: true` and a default `c` prefix.

```php
use function oihana\arango\db\binds\aqlBindCollection ;

$binds = [] ;

$coll = aqlBindCollection( 'users' , $binds ) ;
// $coll  => '@@c_654321'
// $binds => [ '@c_654321' => 'users' ]
```

Note that the key stored in `$binds` is `@c_654321` (with an `@`) — that is the ArangoDB convention to distinguish collection *bind variables* from value *bind variables* in the array.

## Name validation

ArangoDB *bind variable* names follow a strict grammar:

- leading character: letter (`a-zA-Z`) or *underscore* (`_`);
- following characters: letters, digits, *underscores*;
- leading `@` allowed but optional;
- no hyphen, no dot, no space.

Valid examples: `userId`, `_bar123`, `@userId`. Invalid examples: `123abc` (starts with a digit), `user-id` (hyphen), `@!invalid` (forbidden character).

### `isBindVariable()` — non-blocking check

```php
function isBindVariable( string $name ) : bool
```

Returns `true` if the string matches the grammar, `false` otherwise. Useful to validate user input before passing it to `aqlBind()`.

```php
use function oihana\arango\db\binds\isBindVariable ;

isBindVariable( '@userId' ) ;   // true
isBindVariable( 'foo'     ) ;   // true
isBindVariable( '123abc'  ) ;   // false
```

### `assertBindVariable()` — blocking check

```php
function assertBindVariable( ?string $name ) : void
```

Variant that **throws** `oihana\exceptions\BindException` if the name is invalid. `null` is explicitly tolerated (the function returns without doing anything) — this allows `aqlBind()` to call `assertBindVariable($to)` without having to test the auto-generated name case separately.

```php
use function oihana\arango\db\binds\assertBindVariable ;

assertBindVariable( '@userId' ) ; // OK
assertBindVariable( null      ) ; // OK
assertBindVariable( '123abc'  ) ; // BindException
```

## `formatBindVariable()` — *low-level* formatting

```php
function formatBindVariable( string $name , bool $isCollection = false ) : string
```

Prefixes `$name` with `@` or `@@` depending on `$isCollection`. Special case: if `$name` already starts with `@`, it is *wrapped* in *backticks* to escape the ambiguous prefix.

```php
use function oihana\arango\db\binds\formatBindVariable ;

formatBindVariable( 'userId'    ) ;        // '@userId'
formatBindVariable( '@userId'   ) ;        // '@`@userId`'
formatBindVariable( 'users' , true ) ;     // '@@users'
formatBindVariable( '@users', true ) ;     // '@@`@users`'
```

This is an internal *helper* — in practice, you call `aqlBind()` or `aqlBindCollection()`, which delegate to it. Documented here for the rare case where you need to produce a *placeholder* without touching the `$binds` array.

## Typical usage pattern

The framework's standard idiom: a local mutable `$binds` array, accumulated while composing a query, then passed to `prepare()`:

```php
use oihana\arango\enums\AQL ;
use function oihana\arango\db\binds\aqlBind ;
use function oihana\arango\db\binds\aqlBindCollection ;
use function oihana\arango\db\helpers\functions\strings\contains ;

$binds = [] ;

$query = sprintf
(
    'FOR doc IN %s FILTER doc.active == %s AND %s RETURN doc' ,
    aqlBindCollection( 'users' , $binds )       ,                          // @@c_xxx
    aqlBind          ( true    , $binds , 'active' ) ,                     // @active
    contains
    (
        AQL::DOC . '.name' ,
        aqlBind( $search , $binds , 'search' )                             // @search
    )
) ;

$db
    ->prepare ( [ 'query' => $query , 'bindVars' => $binds ] )
    ->execute () ;

$rows = $db->getDocuments() ;
```

The `Documents` models and the AQL *traits* compose their *bind variables* following exactly this *pattern* — the `$binds` array is passed by reference to the *builders*, which plug into it one after another.

## `BindException`

Any naming violation throws `oihana\exceptions\BindException`. This exception is part of the standard family exposed by `oihana/php-exceptions` and is intentionally left to bubble: an invalid name signals a programmer *bug*, not a user condition — there is no reason to catch it.

## See also

- [AQL helpers `db/helpers/`](db-helpers.md) — build the AQL expressions that consume the *placeholders* produced here.
- [Building an AQL query step by step](aql/aql-building-queries.md) — overview of the full query composition flow.
- [Glossary — bind variable](glossary.md#bind-variable).
- [Official ArangoDB documentation — bind parameters](https://docs.arangodb.com/stable/aql/fundamentals/bind-parameters/).
