# Construire une requête AQL pas à pas

Cette page explique comment composer une requête AQL en utilisant les fonctions standalone du dossier [`src/oihana/arango/db/operations/`](../../../src/oihana/arango/db/operations/) et les opérateurs de [`db/operators/`](../../../src/oihana/arango/db/operators/). C'est le point d'entrée pédagogique de la couche `db/` ; les catalogues complets sont dans les pages [Opérations AQL](aql-operations.md), [Opérateurs](aql-operators.md) et [Fonctions de chaînes](aql-functions-strings.md) et suivantes.

## Le modèle mental

`oihana/php-arango` ne fournit pas de *query builder* objet à la `$qb->from(...)->where(...)->select(...)`. Une requête AQL est ici un **texte construit par concaténation** de fragments produits par des fonctions namespace.

Chaque opération AQL (`FOR`, `FILTER`, `SORT`, `LIMIT`, `RETURN`, ...) a sa fonction `aql*` qui produit la sous-chaîne correspondante. On assemble ces sous-chaînes manuellement via `sprintf`, `compile()`, ou simple concaténation. Les valeurs dynamiques passent par [`aqlBind()`](../db/binds.md) pour rester sûres.

Cette approche a deux avantages : on lit dans le code PHP exactement la même structure que la requête AQL finale (pas d'abstraction qui « cache » le SQL), et on peut composer librement sans se battre contre une API restrictive.

## L'ordre canonique des opérations

Une requête AQL suit une grammaire stable. L'ordre des opérations dans une boucle `FOR` :

```
FOR <var> IN <expression>           // itère sur une collection ou une sous-requête
   [ SEARCH <expr>  ]               // optionnel — filtre indexé via ArangoSearch view
   [ OPTIONS { ... } ]              // optionnel — hint d'index, cache, etc.
   [ FILTER <expr>  ]*              // restreint le flot (autant qu'on veut)
   [ LET <name> = <expr> ]*         // variable intermédiaire
   [ COLLECT ... INTO ... ]?        // agrégation
   [ SORT <expr> ASC|DESC ]?        // tri
   [ LIMIT <offset>, <count> ]?     // pagination
RETURN <expr>                       // sortie — obligatoire au niveau racine
```

Les opérations de modification (`INSERT`, `UPDATE`, `REPLACE`, `UPSERT`, `REMOVE`) remplacent typiquement le `RETURN` final dans une boucle d'écriture.

## Exemple 1 — Requête de lecture simple

L'écho de toute la chaîne — un `FOR ... RETURN` minimal :

```php
use function oihana\arango\db\operations\aqlFor    ;
use function oihana\arango\db\operations\aqlReturn ;
use oihana\arango\enums\AQL ;

$query = aqlFor
([
    AQL::DOC_REF => 'doc'   ,
    AQL::IN      => 'users' ,
])
. ' ' . aqlReturn( 'doc' ) ;

// "FOR doc IN users RETURN doc"
```

`aqlFor()` consomme un tableau de clés `AQL::*` et produit le fragment `FOR doc IN users`. `aqlReturn()` accepte directement une chaîne. La concaténation se fait par `' '` ou par `compile([...])` du package `oihana/php-core`.

## Exemple 2 — Filtrer, trier, limiter

Ajout des trois opérations les plus courantes :

```php
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\operations\aqlSort   ;
use function oihana\arango\db\operations\aqlLimit  ;
use function oihana\arango\db\operators\equal      ;
use function oihana\arango\db\operators\greaterThan ;
use function oihana\arango\db\binds\aqlBind        ;

$binds = [] ;

$query = implode( ' ' ,
[
    aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' ] ) ,
    aqlFilter
    ([
        equal      ( 'doc.active' , aqlBind( true , $binds , 'active' ) ) ,
        greaterThan( 'doc.age'    , aqlBind( 18   , $binds , 'minAge' ) ) ,
    ]) ,
    aqlSort  ( 'doc.created DESC'   ) ,
    aqlLimit ( 0 , 50               ) ,
    aqlReturn( 'doc'                ) ,
]) ;

// FOR doc IN users
//   FILTER doc.active == @active && doc.age > @minAge
//   SORT doc.created DESC
//   LIMIT 0, 50
//   RETURN doc

// $binds === [ 'active' => true , 'minAge' => 18 ]
```

Trois points à retenir :

1. **`aqlFilter()`** accepte une chaîne unique ou un tableau de conditions ; un tableau est joint par défaut avec `&&`. Les opérateurs (`equal`, `greaterThan`, ...) produisent ces conditions sous forme de chaîne `'doc.x == @bind'`.
2. **Les *bind variables*** sont accumulées dans `$binds` par référence à chaque appel d'`aqlBind()`. Le tableau final est passé à `prepare()` sur l'instance `ArangoDB`.
3. **`aqlSort()`** accepte du texte AQL direct. Pour les tris dynamiques, on peut utiliser les *operators* `aqlAsc`, `aqlDesc`, `aqlSort` qui prennent en charge un nom de champ et la direction.

## Exemple 3 — Boucle de modification

Le pattern `FOR ... UPDATE` :

```php
use function oihana\arango\db\operations\aqlUpdate ;

$query = implode( ' ' ,
[
    aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' ] ) ,
    aqlFilter
    ([
        equal( 'doc.status' , aqlBind( 'pending' , $binds , 'status' ) ) ,
    ]) ,
    aqlUpdate
    ([
        AQL::KEY        => 'doc' ,
        AQL::WITH       => [ 'status' => aqlBind( 'active' , $binds , 'newStatus' ) ] ,
        AQL::COLLECTION => 'users' ,
    ]) ,
]) ;
```

Pour `UPDATE`, `REPLACE`, `INSERT`, `UPSERT` et `REPSERT`, l'argument est un tableau de clés `AQL::*` (`KEY`, `WITH`, `COLLECTION`, `OPTIONS`, ...). Le résultat n'a généralement pas besoin de `RETURN` explicite — ArangoDB expose les pseudo-variables `OLD` et `NEW` en sortie si besoin.

## Exemple 4 — Traversée de graphe

Le pattern `FOR v, e, p IN min..max <DIRECTION> <start> GRAPH 'g'` est encapsulé par `aqlTraversal()` :

```php
use function oihana\arango\db\operations\aqlTraversal ;
use oihana\arango\db\enums\Traversal ;

$query = aqlTraversal
([
    AQL::VERTEX    => 'v'                                                ,
    AQL::EDGE      => 'e'                                                ,
    AQL::PATH      => 'p'                                                ,
    AQL::MIN       => 1                                                  ,
    AQL::MAX       => 3                                                  ,
    AQL::DIRECTION => Traversal::OUTBOUND                                ,
    AQL::START     => aqlBind( 'users/42' , $binds , 'startVertex' )    ,
    AQL::GRAPH     => 'social'                                           ,
])
. ' ' . aqlFilter( equal( 'v.active' , 'true' ) )
. ' ' . aqlReturn( 'v' ) ;

// FOR v, e, p IN 1..3 OUTBOUND @startVertex GRAPH 'social'
//   FILTER v.active == true
//   RETURN v
```

## Le rôle des opérateurs

Le dossier [`db/operators/`](../../../src/oihana/arango/db/operators/) fournit 42 fonctions qui produisent un **prédicat** sous forme de chaîne — `'doc.x == doc.y'`, `'doc.age > 18'`, `'doc.role IN ["admin", "owner"]'`, etc.

Cinq familles :

- **Comparaison simple** — `equal`, `notEqual`, `greaterThan`, `greaterThanOrEqual`, `lessThan`, `lessThanOrEqual`, `in`, `notIn`, `isLike`, `notLike`, `isMatch`, `notMatch`.
- **Quantifiés `ALL`** — `allEqual`, `allGreaterThan`, ... — vrai si **tous** les éléments du côté gauche satisfont la comparaison.
- **Quantifiés `ANY`** — `anyEqual`, `anyGreaterThan`, ... — vrai si **au moins un** élément satisfait.
- **Quantifiés `NONE`** — `noneEqual`, `noneGreaterThan`, ... — vrai si **aucun** élément ne satisfait.
- **Logiques** — `logicalAnd`, `logicalOr`, `logicalNot`, `ternary`, `nullish`, `rangeOperator`.

Le catalogue complet est dans [Opérateurs](aql-operators.md). Tous ces opérateurs retournent simplement une chaîne et peuvent donc être enchaînés dans un `aqlFilter()`, un `aqlReturn(['result' => ...])`, ou n'importe quel autre point d'insertion AQL.

## Le rôle des fonctions

Le dossier [`db/functions/`](../../../src/oihana/arango/db/functions/) contient 144 fonctions qui correspondent aux fonctions AQL natives — `CONCAT`, `LOWER`, `DATE_NOW`, `COUNT`, `SUM`, etc. — réparties en cinq sous-dossiers : `strings/`, `dates/`, `numerics/`, `arrays/`, `documents/`.

Chaque fonction PHP prend un ou plusieurs arguments (typiquement une référence à un champ, par exemple `'doc.name'`) et retourne la chaîne `LOWER(doc.name)`. On les utilise dans les prédicats ou dans les projections.

```php
use function oihana\arango\db\functions\strings\concat ;
use function oihana\arango\db\functions\strings\lower  ;
use function oihana\arango\db\functions\dates\dateNow  ;

aqlFilter( equal( lower( 'doc.email' ) , aqlBind( strtolower( $email ) , $binds , 'email' ) ) ) ;
// FILTER LOWER(doc.email) == @email

aqlReturn( [
    'fullName' => concat( 'doc.firstName' , "' '" , 'doc.lastName' ) ,
    'now'      => dateNow() ,
] ) ;
// RETURN { fullName: CONCAT(doc.firstName, ' ', doc.lastName), now: DATE_NOW() }
```

Les catalogues complets, par type, sont dans les pages [Fonctions de chaînes](aql-functions-strings.md), [Fonctions de dates](aql-functions-dates.md), [Fonctions numériques](aql-functions-numerics.md), [Fonctions de tableaux](aql-functions-arrays.md), [Fonctions binaires](aql-functions-bit.md), [Fonctions ArangoSearch](aql-functions-search.md) et [Fonctions documents et vérifications](aql-functions-checks.md).

## Exécuter la requête finale

Une fois la requête assemblée et les *bind variables* accumulées, on les passe à `ArangoDB::prepare()` puis on exécute :

```php
$db
    ->prepare ( [ 'query' => $query , 'bindVars' => $binds ] )
    ->execute () ;

$results = $db->getDocuments() ;
```

Voir [Quickstart `ArangoDB`](../db/quickstart.md) pour le détail des méthodes d'exécution et d'hydratation.

## Au-delà du *manuel* — les modèles haut-niveau

Pour les opérations CRUD standardisées (lister, lire, créer, mettre à jour, supprimer un document d'une collection), on n'écrit pas tout ce code manuellement : le modèle [`Documents`](../models.md) le compose pour soi à partir de la déclaration des `AQL::FIELDS`, `AQL::FILTERS`, `AQL::EDGES`, `AQL::JOINS`. On retombe sur la composition manuelle décrite ici dans deux cas :

- Une requête **trop spécifique** pour rentrer dans le modèle générique (agrégation custom, jointures complexes, traversées spécialisées).
- Un **trait custom** branché sur un modèle existant pour ajouter une opération métier.

Comprendre la composition manuelle est donc utile même quand on consomme les modèles : on lit ce qu'ils produisent.

## Voir aussi

- [Opérations AQL `db/operations/`](aql-operations.md) — catalogue complet des 21 opérations.
- [Opérateurs `db/operators/`](aql-operators.md) — catalogue des 42 opérateurs.
- [Fonctions de chaînes / dates / numériques / tableaux / documents](aql-functions-strings.md).
- [Helpers AQL `db/helpers/`](../db/helpers.md) — encodage de valeurs et composition de fragments.
- [Bind variables `db/binds/`](../db/binds.md) — injection sûre.
- [Documentation officielle AQL — high-level operations](https://docs.arangodb.com/stable/aql/high-level-operations/).
