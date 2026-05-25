# Bind variables `db/binds/`

Le dossier [`src/oihana/arango/db/binds/`](../../../src/oihana/arango/db/binds/) regroupe les cinq fonctions qui assurent l'**injection sûre** des valeurs et des noms de collection dans une requête AQL.

C'est la première brique de défense du framework contre l'injection AQL : aucune valeur dynamique ne devrait jamais être concaténée directement dans une requête. Toute valeur passe par `aqlBind()` ou `aqlBindCollection()`, qui retournent un *placeholder* sûr (`@var` ou `@@coll`) et stockent la valeur dans un tableau `bindVars` transmis séparément à ArangoDB.

## Pourquoi les *bind variables*

ArangoDB sépare le **texte de la requête** des **valeurs injectées** : les valeurs sont référencées par un *placeholder* (`@var` pour une valeur, `@@coll` pour un nom de collection) et fournies via le paramètre `bindVars`. Deux bénéfices :

1. **Sécurité.** Aucun risque d'injection AQL — le moteur traite les valeurs comme des données, jamais comme du code.
2. **Performance.** Le *query cache* du serveur factorise les plans d'exécution sur le texte AQL : deux requêtes identiques avec des valeurs différentes partagent le même plan, à condition d'utiliser des *bind variables*.

Les fonctions de `db/binds/` standardisent la production de ces *placeholders*.

## `aqlBind()` — cas standard

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

Lie une valeur arbitraire à un *placeholder* AQL. Le tableau `$binds` est passé par référence et reçoit la nouvelle entrée ; la fonction retourne le *placeholder* formaté prêt à être inséré dans la requête.

Si `$to` est `null`, un nom unique est généré sous la forme `<prefix>_<6 chiffres>` (par exemple `q_482931`). Le préfixe par défaut est `q` ; on peut le surcharger via `$toPrefix` (utile pour produire des noms parlants dans les *logs*).

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

Toute valeur scalaire, tableau ou objet est acceptée — ArangoDB se charge de la sérialisation côté serveur. Une `BindException` est levée si `$to` est fourni et ne respecte pas les règles de nommage ArangoDB.

## `aqlBindCollection()` — nom de collection

```php
function aqlBindCollection
(
    mixed   $value             ,
    array  &$binds   = []      ,
    ?string $to       = null   ,
    ?string $toPrefix = null
) : string
```

Variante dédiée aux **noms de collection**. En AQL, une collection bindée se distingue d'une valeur par le double préfixe `@@` (`FOR doc IN @@coll`) au lieu de `@` (`FILTER doc.x == @val`). C'est juste un *sugar* sur `aqlBind()` avec `isCollection: true` et un préfixe par défaut `c`.

```php
use function oihana\arango\db\binds\aqlBindCollection ;

$binds = [] ;

$coll = aqlBindCollection( 'users' , $binds ) ;
// $coll  => '@@c_654321'
// $binds => [ '@c_654321' => 'users' ]
```

Noter que la clé stockée dans `$binds` est `@c_654321` (avec un `@`) — c'est la convention ArangoDB pour distinguer les *bind variables* de collection des *bind variables* de valeur dans le tableau.

## Validation des noms

Les noms de *bind variables* ArangoDB obéissent à une grammaire stricte :

- caractère de tête : lettre (`a-zA-Z`) ou *underscore* (`_`) ;
- caractères suivants : lettres, chiffres, *underscores* ;
- préfixe `@` initial autorisé mais optionnel ;
- pas de tiret, pas de point, pas d'espace.

Exemples valides : `userId`, `_bar123`, `@userId`. Exemples invalides : `123abc` (commence par un chiffre), `user-id` (tiret), `@!invalid` (caractère interdit).

### `isBindVariable()` — vérification non-bloquante

```php
function isBindVariable( string $name ) : bool
```

Retourne `true` si la chaîne respecte la grammaire, `false` sinon. Pratique pour valider une entrée utilisateur avant de la passer à `aqlBind()`.

```php
use function oihana\arango\db\binds\isBindVariable ;

isBindVariable( '@userId' ) ;   // true
isBindVariable( 'foo'     ) ;   // true
isBindVariable( '123abc'  ) ;   // false
```

### `assertBindVariable()` — vérification bloquante

```php
function assertBindVariable( ?string $name ) : void
```

Variante qui **lève** `oihana\exceptions\BindException` si le nom n'est pas valide. `null` est explicitement toléré (la fonction retourne sans rien faire) — cela permet à `aqlBind()` d'appeler `assertBindVariable($to)` sans avoir à tester séparément le cas du nom auto-généré.

```php
use function oihana\arango\db\binds\assertBindVariable ;

assertBindVariable( '@userId' ) ; // OK
assertBindVariable( null      ) ; // OK
assertBindVariable( '123abc'  ) ; // BindException
```

## `formatBindVariable()` — formatage *bas niveau*

```php
function formatBindVariable( string $name , bool $isCollection = false ) : string
```

Préfixe `$name` avec `@` ou `@@` selon `$isCollection`. Cas particulier : si `$name` commence déjà par `@`, il est *wrappé* dans des *backticks* pour échapper le préfixe ambigu.

```php
use function oihana\arango\db\binds\formatBindVariable ;

formatBindVariable( 'userId'    ) ;        // '@userId'
formatBindVariable( '@userId'   ) ;        // '@`@userId`'
formatBindVariable( 'users' , true ) ;     // '@@users'
formatBindVariable( '@users', true ) ;     // '@@`@users`'
```

C'est un *helper* interne — en pratique, on appelle `aqlBind()` ou `aqlBindCollection()`, qui le délèguent. Documenté ici pour le cas où on aurait besoin de produire un *placeholder* sans toucher au tableau `$binds`.

## Pattern typique d'usage

L'idiome standard du framework : un tableau `$binds` local mutable, accumulé au fil de la composition d'une requête, puis transmis à `prepare()` :

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

Les modèles `Documents` et les *traits* AQL composent leurs *bind variables* exactement selon ce *pattern* — le tableau `$binds` est passé en référence aux *builders*, qui s'y branchent les uns après les autres.

## `BindException`

Toute violation de nommage lève `oihana\exceptions\BindException`. Cette exception fait partie de la famille standard exposée par `oihana/php-exceptions` et est laissée remontée volontairement : un nom invalide signale un *bug* programmeur, pas une condition utilisateur — il n'y a aucune raison de le rattraper.

## Voir aussi

- [Helpers AQL `db/helpers/`](helpers.md) — construire les expressions AQL qui consomment les *placeholders* produits ici.
- [Construire une requête AQL pas à pas](../aql/aql-building-queries.md) — vue d'ensemble du flot complet de composition d'une requête.
- [Glossaire — bind variable](../getting-started/glossary.md#bind-variable).
- [Documentation officielle ArangoDB — bind parameters](https://docs.arangodb.com/stable/aql/fundamentals/bind-parameters/).
