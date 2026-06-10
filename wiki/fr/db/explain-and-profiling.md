# Expliquer et profiler les requêtes

Deux questions reviennent sans cesse quand on optimise une requête ArangoDB :

1. **« Cette requête va-t-elle utiliser mon index, ou est-ce un full scan de la collection ? »** → on l'*explique*.
2. **« Pourquoi cette requête est-elle lente — où part réellement le temps ? »** → on la *profile*.

La couche `db/` répond aux deux avec des **objets de résultat typés** : on lit `result->usesIndex()` et `result->indexesUsed()` au lieu de fouiller un arbre de plan JSON profond et dépendant de la version.

> `explain` est une API **core** d'ArangoDB (aucun flag serveur particulier). Elle analyse la requête **sans l'exécuter**.

## En un coup d'œil

| Vous avez… | Appel | Vous récupérez |
|---|---|---|
| Une chaîne AQL brute | `ArangoDB::explain($query, $binds)` | [`ExplainResult`](#lobjet-explainresult) (plan, **sans exécuter**) |
| Un modèle `Documents` + des filtres de liste | `Documents::explainList($init)` | [`ExplainResult`](#expliquer-la-requête-de-liste-dun-modèle) pour la requête exacte de `list()` |
| Un list/get à **chronométrer** | `Documents::list([ …, 'profile' => true ])` puis `getProfile()` / `getStats()` | [`ProfileResult`](#lobjet-profileresult) / [`ExecutionStats`](#lobjet-executionstats) (**exécute** la requête) |

## Expliquer une requête brute

`ArangoDB::explain()` demande à l'optimiseur le plan qu'il *exécuterait* et l'enrobe dans un [`ExplainResult`](#lobjet-explainresult) :

```php
use oihana\arango\db\ArangoDB ;

$db = new ArangoDB( $config ) ; // la section de config [arango]

$plan = $db->explain(
    'FOR u IN users FILTER u.age > @min SORT u.name LIMIT 5 RETURN u' ,
    [ 'min' => 30 ] ,
) ;

$plan->usesIndex() ;        // true  → le FILTER est accéléré par un index
$plan->rules() ;            // ['…','use-indexes','remove-filter-covered-by-index', …]
$plan->collections() ;     // ['users']
$plan->estimatedCost() ;   // 122.76  (coût estimé par l'optimiseur)
```

`explain()` accepte aussi un `AqlQuery` (qui porte ses propres binds) et un tableau `$options` transmis au serveur (`allPlans`, `optimizer.rules`, …) :

```php
use oihana\arango\clients\aql\AqlQuery ;

$db->explain( new AqlQuery( 'FOR u IN users RETURN u' ) ) ;
$db->explain( $query , $binds , [ 'optimizer' => [ 'rules' => [ '-use-indexes' ] ] ] ) ; // forcer un scan pour comparer
```

## L'objet `ExplainResult`

[`oihana\arango\db\results\ExplainResult`](../../../src/oihana/arango/db/results/ExplainResult.php) est une vue en lecture seule sur la réponse `/_api/explain` :

| Méthode | Retour | Signification |
|---|---|---|
| `usesIndex()` | `bool` | `true` si la requête touche au moins un index (pas un full scan). |
| `indexesUsed()` | `IndexUse[]` | Les index réellement utilisés — voir ci-dessous. |
| `rules()` | `string[]` | Les règles d'optimiseur appliquées (`use-indexes`, `move-filters-up`, …). |
| `collections()` | `string[]` | Les collections lues/écrites. |
| `nodeTypes()` | `string[]` | Les types de nœuds d'exécution, dans l'ordre (`IndexNode`, `SortNode`, …). |
| `estimatedCost()` | `float` | Le coût estimé par l'optimiseur. |
| `estimatedNrItems()` | `int` | Le nombre estimé de lignes de résultat. |
| `isModificationQuery()` | `bool` | Si la requête écrit des données. |
| `isCacheable()` | `bool` | Si le résultat pourrait venir du cache de requêtes. |
| `warnings()` | `array` | Les avertissements de l'optimiseur. |
| `plan()` / `raw()` | `array` | Le plan brut / la réponse complète, pour tout ce qui n'est pas exposé ci-dessus. |

### « Quels index ma requête utilise-t-elle ? »

`indexesUsed()` renvoie un [`IndexUse`](../../../src/oihana/arango/db/results/IndexUse.php) par index choisi par l'optimiseur, collecté depuis chaque `IndexNode` du plan :

```php
foreach ( $plan->indexesUsed() as $index )
{
    echo $index->name ;        // "idx_age"
    echo $index->type ;        // "persistent" | "primary" | "geo" | "vector" | …
    echo $index->collection ;  // "users"
    echo implode( ',' , $index->fields ) ; // "age"
    $index->unique ;           // false
    $index->selectivityEstimate ; // 1.0  (0…1, quand disponible)
}
```

Une assertion courante dans un test ou un health-check :

```php
// Échoue vite si une requête critique dégénère silencieusement en full scan.
if ( ! $db->explain( $query , $binds )->usesIndex() )
{
    throw new RuntimeException( 'La requête n\'est pas accélérée par un index — ajoutez ou corrigez un index.' ) ;
}
```

## Expliquer la requête de liste d'un modèle

Quand vous construisez une liste avec le modèle `Documents` (filtres, facettes, recherche, tri, pagination), vous n'écrivez quasiment jamais l'AQL à la main — donc difficile de vérifier d'un œil si elle utilise vos index. `Documents::explainList()` explique **la requête exacte que `list()` exécuterait** pour la même entrée :

```php
$init =
[
    'active' => true ,
    'filter' => [ 'age' => [ '$gte' => 30 ] ] ,
    'sort'   => [ 'name' => 'ASC' ] ,
    'limit'  => 20 ,
] ;

$plan = $users->explainList( $init ) ; // le même $init que vous passeriez à $users->list( $init )

$plan->usesIndex() ;       // mon filtre/tri touche-t-il un index ?
$plan->indexesUsed() ;     // lesquels
$plan->rules() ;           // ce qu'a fait l'optimiseur
```

`explainList()` construit la requête et l'explique — elle **n'exécute rien** et ne renvoie aucun document. À utiliser en développement, dans les tests ou un endpoint admin/debug pour valider que vos index déclarés couvrent vos vraies formes de requêtes.

La primitive bas niveau est disponible sur tout modèle via `explain( string $query, array $binds = [], array $options = [] ): ExplainResult` (depuis `ArangoTrait`), et sur le façade via `ArangoDB::explain()`.

## Exemple bout-en-bout

```php
use oihana\arango\db\ArangoDB ;
use oihana\arango\clients\collection\indexes\PersistentIndex ;

$db = new ArangoDB( $config ) ;

// 1. S'assurer que l'index existe.
$db->createIndex( 'users' , new PersistentIndex( fields : [ 'age' ] ) ) ;

// 2. Expliquer la requête qui vous intéresse.
$plan = $db->explain(
    'FOR u IN users FILTER u.age > @min SORT u.name LIMIT 5 RETURN u' ,
    [ 'min' => 30 ] ,
) ;

// 3. Vérifier qu'elle est accélérée par index et comment.
assert( $plan->usesIndex() ) ;
echo $plan->indexesUsed()[ 0 ]->fields[ 0 ] ;   // "age"
echo implode( ', ' , $plan->rules() ) ;          // "… use-indexes, remove-filter-covered-by-index …"
```

## Profiler une requête qui a tourné (`profile`)

`explain` vous donne le *plan* ; le **profiling** vous dit ce qui s'est réellement passé quand la requête a *tourné* — combien de lignes lues depuis des index vs des full scans, combien filtrées, combien de temps chaque phase a pris. Contrairement à `explain()`, le profiling **exécute la requête**.

### Depuis un modèle — `?profile` / `Arango::PROFILE`

Passez `Arango::PROFILE` (`'profile'`) dans l'entrée de `list()` / `get()`. La requête tourne normalement (vous récupérez vos documents), et les mesures sont capturées pour le prochain `getProfile()` / `getStats()` :

```php
use oihana\arango\enums\Arango ;

// Exécute la liste ET la profile (true → niveau 2 ; un entier fixe le niveau explicitement).
$rows = $users->list( [ 'filter' => [ 'age' => [ '$gte' => 30 ] ] , 'profile' => true ] ) ;

$stats = $users->getStats() ;     // ExecutionStats typé
$stats->scannedFull() ;           // 50  → aucun index utilisé (full scan)
$stats->scannedIndex() ;          // 0
$stats->filtered() ;              // 11  → lignes lues puis écartées par le FILTER
$stats->executionTime() ;        // 0.0004  (secondes)
$stats->peakMemoryUsage() ;      // octets

$users->getProfile()->totalTime() ;          // somme des timings de phases
$users->getProfile()->timings() ;            // ['parsing' => …, 'optimizing plan' => …, 'executing' => …, …]
```

`get( [ … , 'profile' => true ] )` profile une lecture de document unique de la même façon.

### L'objet `ExecutionStats`

[`oihana\arango\db\results\ExecutionStats`](../../../src/oihana/arango/db/results/ExecutionStats.php) :

| Méthode | Signification |
|---|---|
| `scannedFull()` | Documents lus par full scan de collection (le nombre à garder **bas**). |
| `scannedIndex()` | Documents lus via un index. |
| `filtered()` | Documents lus puis écartés par un `FILTER` (élevé = l'index ne couvre pas le filtre). |
| `executionTime()` | Temps total, en secondes. |
| `peakMemoryUsage()` | Pic mémoire, en octets. |
| `fullCount()` | Total ignorant `LIMIT` (`null` sauf si `fullCount` demandé). |
| `writesExecuted()` / `writesIgnored()` | Compteurs d'écritures pour les requêtes de modification. |
| `documentLookups()`, `httpRequests()`, `cacheHits()`, `cacheMisses()` | Compteurs plus fins. |
| `get($key, $default)` / `raw()` | N'importe quelle `Statistic` par clé / le tableau de stats brut. |

> `scannedFull()` élevé **et** `filtered()` élevé est le signal classique « cette requête a besoin d'un index » — confirmez avec `explainList()` / `explain()` ci-dessus.

### L'objet `ProfileResult`

[`oihana\arango\db\results\ProfileResult`](../../../src/oihana/arango/db/results/ProfileResult.php) enrobe tout le run profilé : `stats()` (un `ExecutionStats`), `timings()` (secondes par phase, indexées par nom de phase), `totalTime()` (leur somme), `warnings()`, `plan()` et `raw()`.

### Depuis le façade

Le façade expose la même chose, autour d'une requête préparée manuellement :

```php
use oihana\arango\clients\cursor\enums\CursorField ;

$db->prepare([
    CursorField::QUERY     => 'FOR u IN users FILTER u.age > @a RETURN u' ,
    CursorField::BIND_VARS => [ 'a' => 30 ] ,
    CursorField::PROFILE   => 2 ,
])->execute() ;

iterator_to_array( $db->getCursor() ) ;     // consommer le résultat

$db->getStats()->scannedFull() ;            // ExecutionStats typé
$db->getProfile()->timings() ;              // ProfileResult typé
```

## Câblage / DI

`ArangoDB::explain()` / `getProfile()` / `getStats()` ne nécessitent rien de plus qu'un façade configuré — la même instance `ArangoDB` que reçoivent déjà vos modèles (voir [Quickstart `ArangoDB`](quickstart.md) pour la construction et la DI). `Documents::explainList()` et l'option `'profile'` sur `list()` / `get()` sont disponibles sur chaque modèle `Documents` d'office. Rien à enregistrer.

## Voir aussi

- [Quickstart `ArangoDB`](quickstart.md) — construire et configurer le façade.
- [Index](../clients/indexes.md) — les types d'index dont `indexesUsed()` rapporte l'usage.
- [Construire une requête AQL pas à pas](../aql/aql-building-queries.md).
- [Documentation AQL officielle — Explaining queries](https://docs.arangodb.com/stable/aql/execution-and-performance/explaining-queries/).
