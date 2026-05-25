# Opérateurs `db/operators/`

Le dossier [`src/oihana/arango/db/operators/`](../../../src/oihana/arango/db/operators/) fournit les **42 fonctions** qui produisent un **prédicat** AQL — une chaîne du type `'doc.x == doc.y'` qui s'insère dans un `aqlFilter()`, un `aqlReturn()`, ou tout endroit où une expression booléenne est attendue.

Toutes les fonctions partagent la même signature de base `(mixed $leftOperand, mixed $rightOperand) : string`, à l'exception des opérateurs logiques unaires (`logicalNot`) et de quelques opérateurs spéciaux (`ternary`, `nullish`, `rangeOperator`).

## Familles

| Famille | Nombre | Sémantique |
|---|---|---|
| Comparaison simple | 12 | Compare deux valeurs scalaires. |
| `ALL` (quantifié) | 8 | Vrai si **tous** les éléments du tableau de gauche satisfont la comparaison. |
| `ANY` (quantifié) | 8 | Vrai si **au moins un** élément satisfait. |
| `NONE` (quantifié) | 8 | Vrai si **aucun** élément ne satisfait. |
| Logique et conditionnel | 6 | `AND`, `OR`, `NOT`, ternaire, *nullish*, plage. |

## Comparaison simple

Les 12 comparateurs de base. Tous produisent `<left> <op> <right>`.

| Fonction | Opérateur AQL | Exemple |
|---|---|---|
| `equal` | `==` | `equal( 'doc.age' , 18 )` → `"doc.age == 18"` |
| `notEqual` | `!=` | `notEqual( 'doc.status' , "'closed'" )` |
| `greaterThan` | `>` | `greaterThan( 'doc.price' , 100 )` |
| `greaterThanOrEqual` | `>=` | `greaterThanOrEqual( 'doc.score' , 50 )` |
| `lessThan` | `<` | `lessThan( 'doc.qty' , 10 )` |
| `lessThanOrEqual` | `<=` | `lessThanOrEqual( 'doc.weight' , 25 )` |
| `in` | `IN` | `in( 'doc.role' , [ 'admin' , 'owner' ] )` |
| `notIn` | `NOT IN` | `notIn( 'doc.status' , [ 'closed' , 'archived' ] )` |
| `isLike` | `LIKE` | `isLike( 'doc.name' , "'%john%'" )` |
| `notLike` | `NOT LIKE` | `notLike( 'doc.code' , "'TMP%'" )` |
| `isMatch` | `=~` | `isMatch( 'doc.email' , "'^[a-z]+@'" )` (regex) |
| `notMatch` | `!~` | `notMatch( 'doc.email' , "'@spam\\\\.'" )` |

Doc officielle : [Comparison operators](https://docs.arangodb.com/stable/aql/operators/#comparison-operators).

## Opérateurs quantifiés `ALL`

Vrai si **tous** les éléments du tableau gauche satisfont la comparaison. Produit `<left> ALL <op> <right>`.

| Fonction | AQL | Cas d'usage |
|---|---|---|
| `allEqual` | `[...] ALL == x` | Liste homogène. |
| `allNotEqual` | `[...] ALL != x` | Aucun élément n'est `x`. |
| `allGreaterThan` | `[...] ALL > x` | Toutes les valeurs au-dessus du seuil. |
| `allGreaterThanOrEqual` | `[...] ALL >= x` | Au moins le seuil partout. |
| `allLessThan` | `[...] ALL < x` | Toutes les valeurs sous le seuil. |
| `allLessThanOrEqual` | `[...] ALL <= x` | Plafond respecté partout. |
| `allIn` | `[...] ALL IN [...]` | Toutes les valeurs sont dans la *whitelist*. |
| `allNotIn` | `[...] ALL NOT IN [...]` | Aucune valeur dans la *blacklist*. |

```php
allGreaterThan( 'doc.scores' , 50 ) ;
// "doc.scores ALL > 50"
```

## Opérateurs quantifiés `ANY`

Vrai si **au moins un** élément satisfait. Produit `<left> ANY <op> <right>`.

| Fonction | AQL | Cas d'usage |
|---|---|---|
| `anyEqual` | `[...] ANY == x` | Au moins une valeur est `x`. |
| `anyNotEqual` | `[...] ANY != x` | Au moins une valeur diffère. |
| `anyGreaterThan` | `[...] ANY > x` | Au moins un élément au-dessus du seuil. |
| `anyGreaterThanOrEqual` | `[...] ANY >= x` | Au moins un élément atteint le seuil. |
| `anyLessThan` | `[...] ANY < x` | Au moins un élément sous le seuil. |
| `anyLessThanOrEqual` | `[...] ANY <= x` | Au moins un élément en-dessous ou égal. |
| `anyIn` | `[...] ANY IN [...]` | Au moins un élément dans la liste. |
| `anyNotIn` | `[...] ANY NOT IN [...]` | Au moins un élément hors de la liste. |

```php
anyIn( 'doc.tags' , [ 'urgent' , 'critical' ] ) ;
// "doc.tags ANY IN [\"urgent\",\"critical\"]"
```

## Opérateurs quantifiés `NONE`

Vrai si **aucun** élément ne satisfait. Produit `<left> NONE <op> <right>`.

| Fonction | AQL | Cas d'usage |
|---|---|---|
| `noneEqual` | `[...] NONE == x` | Aucun élément n'est `x`. |
| `noneNotEqual` | `[...] NONE != x` | Tous les éléments sont `x` (équivalent à `allEqual`). |
| `noneGreaterThan` | `[...] NONE > x` | Aucun élément au-dessus du seuil. |
| `noneGreaterThanOrEqual` | `[...] NONE >= x` | Aucun élément atteint le seuil. |
| `noneLessThan` | `[...] NONE < x` | Aucun élément sous le seuil. |
| `noneLessThanOrEqual` | `[...] NONE <= x` | Aucun élément en-dessous ou égal. |
| `noneIn` | `[...] NONE IN [...]` | Aucun élément dans la *blacklist*. |
| `noneNotIn` | `[...] NONE NOT IN [...]` | Tous les éléments dans la *whitelist* (équivalent à `allIn`). |

```php
noneEqual( 'doc.statuses' , "'closed'" ) ;
// "doc.statuses NONE == 'closed'"
```

Doc officielle pour `ALL` / `ANY` / `NONE` : [Array comparison operators](https://docs.arangodb.com/stable/aql/operators/#array-comparison-operators).

## Opérateurs logiques et conditionnels

| Fonction | Signature | Sortie | Sémantique |
|---|---|---|---|
| `logicalAnd` | `(mixed $a , mixed $b)` | `<a> && <b>` | Conjonction. |
| `logicalOr` | `(mixed $a , mixed $b)` | `<a> \|\| <b>` | Disjonction. |
| `logicalNot` | `(mixed $a)` | `!<a>` | Négation. |
| `ternary` | `(mixed $cond , mixed $then , mixed $else)` | `<cond> ? <then> : <else>` | Conditionnel ternaire. |
| `nullish` | `(mixed $a , mixed $fallback)` | `<a> ?? <fallback>` | Coalescence : `$fallback` si `$a` est `null`. |
| `rangeOperator` | `(mixed $low , mixed $high)` | `<low>..<high>` | Plage inclusive (utile pour `aqlTraversalRange` ou les itérations sur entiers). |

```php
logicalAnd( equal( 'doc.x' , 1 ) , greaterThan( 'doc.y' , 0 ) ) ;
// "doc.x == 1 && doc.y > 0"

ternary( equal( 'doc.role' , "'admin'" ) , "'full'" , "'limited'" ) ;
// "doc.role == 'admin' ? 'full' : 'limited'"

nullish( 'doc.nickname' , "'Anonymous'" ) ;
// "doc.nickname ?? 'Anonymous'"

rangeOperator( 1 , 10 ) ;
// "1..10"
```

Pour combiner plusieurs prédicats dans un `aqlFilter()`, il est généralement plus lisible de passer un tableau et de laisser `aqlFilter` les joindre avec `&&` ou `||` :

```php
aqlFilter
([
    equal      ( 'doc.active' , 'true' )      ,
    greaterThan( 'doc.score'  , 50      )      ,
    in         ( 'doc.role'   , [ 'admin' , 'owner' ] ) ,
] , Logic::AND ) ;
```

Doc officielle pour les opérateurs logiques et ternaires : [Logical operators](https://docs.arangodb.com/stable/aql/operators/#logical-operators).

## Comportement face aux valeurs

Toutes les fonctions produisent simplement une **chaîne** et ne valident pas leurs arguments. C'est à l'appelant de s'assurer que les opérandes sont :

- soit des références AQL (`'doc.field'`, `'@bind'`, `'users/42'`) — passées telles quelles ;
- soit des valeurs encodées via [`aqlValue()`](../db/helpers.md#aqlvalue--la-fondation) — préférable dès qu'il y a un risque d'ambiguïté ;
- soit des *placeholders* produits par [`aqlBind()`](../db/binds.md) — recommandé pour toute valeur dynamique.

Le mélange direct de valeurs PHP brutes avec des opérateurs sans passer par les helpers est possible mais déconseillé : la chaîne produite ne sera pas échappée et exposera la requête à l'injection.

## Voir aussi

- [Construire une requête AQL pas à pas](aql-building-queries.md) — exemples d'enchaînement opérateurs + opérations.
- [Opérations AQL `db/operations/`](aql-operations.md) — où ces prédicats sont consommés (`aqlFilter`, `aqlPrune`, `aqlSearch`).
- [Helpers AQL `db/helpers/`](../db/helpers.md) — `aqlValue` pour encoder les valeurs.
- [Bind variables `db/binds/`](../db/binds.md) — injection sûre.
- [Documentation officielle AQL — operators](https://docs.arangodb.com/stable/aql/operators/).
