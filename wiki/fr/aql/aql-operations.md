# Opérations AQL `db/operations/`

Le dossier [`src/oihana/arango/db/operations/`](../../../src/oihana/arango/db/operations/) fournit les **22 opérations** qui correspondent aux *high-level operations* du langage AQL. Chaque fonction produit le fragment de texte AQL correspondant et peut se concaténer librement avec les autres pour former une requête complète.

Pour la vue d'ensemble pédagogique de la composition, voir [Construire une requête AQL pas à pas](aql-building-queries.md). Cette page est la **référence** de chacune des opérations.

## Catégories

| Catégorie | Opérations |
|---|---|
| Itération | `aqlFor`, `aqlSearch` |
| Restriction | `aqlFilter`, `aqlPrune` |
| Variable intermédiaire | `aqlLet` |
| Agrégation | `aqlCollect`, `aqlCollectReturn`, `aqlWindow` |
| Tri | `aqlSort`, `aqlAsc`, `aqlDesc` |
| Pagination | `aqlLimit` |
| Retour | `aqlReturn` |
| Modification | `aqlInsert`, `aqlUpdate`, `aqlReplace`, `aqlUpsert`, `aqlRepsert`, `aqlRemove` |
| Traversée de graphe | `aqlTraversal`, `aqlTraversalRange` |
| Configuration | `aqlOptions`, `aqlWith` |

## Itération

### `aqlFor()`

```php
function aqlFor( array $init = [] ) : string
```

Construit la clause `FOR <var> IN <expression>` avec optionnellement `SEARCH` et `OPTIONS`. Consomme les clés `AQL::DOC_REF` (nom de la variable d'itération), `AQL::IN` (collection ou sous-requête), `AQL::SEARCH` (filtre indexé via vue ArangoSearch), `AQL::OPTIONS` (hydraté en `ForOptions`).

```php
aqlFor( [ AQL::DOC_REF => 'doc' , AQL::IN => 'users' ] ) ;
// "FOR doc IN users"
```

Doc officielle : [`FOR`](https://docs.arangodb.com/stable/aql/high-level-operations/for/).

### `aqlSearch()`

```php
function aqlSearch( array $init = [] ) : string
```

Construit la clause `SEARCH <expression>` utilisée à l'intérieur d'un `FOR` qui itère sur une vue ArangoSearch. Le filtrage est résolu par l'index inversé de la vue, plus rapide qu'un `FILTER` classique sur grandes volumétries, et permet le scoring (BM25/TFIDF) et les *analyzers*. Appelée en interne par `aqlFor()` quand `AQL::SEARCH` est fourni, mais peut être utilisée seule.

Clés de `$init` :

| Clé | Type | Description |
|---|---|---|
| `AQL::SEARCH` | `string\|array` | L'expression de recherche (requise — sans elle tout le reste est ignoré). |
| `AQL::ANALYZER` | `string` | Nom d'Analyzer optionnel : l'expression est wrappée en `ANALYZER(expr, "name")` via le helper [`analyzer()`](aql-functions-search.md). |
| `AQL::SEARCH_OPTIONS` | `array\|SearchOptions\|object\|string` | Objet `SEARCH … OPTIONS { … }` optionnel : `collections`, `conditionOptimization` (`ConditionOptimization::AUTO/NONE`), `countApproximate` (`CountApproximate::EXACT/COST`), `parallelism`. Les tableaux sont hydratés en `SearchOptions` (clés inconnues filtrées, propriétés nulles omises). |

> `AQL::SEARCH_OPTIONS` (les options du `SEARCH`, pour les Views) est distinct d'`AQL::OPTIONS` (les options du `FOR` — `indexHint`, `useCache`, … — pour les collections). `aqlFor()` transmet son `$init` entier, donc les trois clés fonctionnent directement à travers lui.

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\db\enums\ConditionOptimization ;
use function oihana\arango\db\functions\search\phrase ;
use function oihana\arango\db\operations\aqlSearch ;

aqlSearch
([
    AQL::SEARCH         => phrase( 'doc.body' , 'quick fox' ) ,
    AQL::ANALYZER       => 'text_fr' ,
    AQL::SEARCH_OPTIONS => [ 'conditionOptimization' => ConditionOptimization::NONE ] ,
]) ;
// SEARCH ANALYZER(PHRASE(doc.body,"quick fox"),"text_fr") OPTIONS {"conditionOptimization":"none"}
```

```aql
FOR doc IN articlesView
  SEARCH ANALYZER( doc.body IN TOKENS( @q , 'text_fr' ) , 'text_fr' )
  SORT BM25( doc ) DESC
  RETURN doc
```

> `SEARCH` ne s'applique qu'à une *View*, pas à une collection. La gestion des vues et des analyzers est décrite dans [`clients/arangosearch.md`](../clients/arangosearch.md) ; les helpers d'expressions de recherche (`phrase`, `levenshteinMatch`, `bm25`, …) sur la page [Fonctions ArangoSearch](aql-functions-search.md).

Doc officielle : [`SEARCH`](https://docs.arangodb.com/stable/aql/high-level-operations/search/).

## Restriction

### `aqlFilter()`

```php
function aqlFilter
(
    string|array|null $conditions      = null         ,
    string            $logicalOperator = Logic::AND   ,
    bool              $useParentheses  = false
) : ?string
```

Construit la clause `FILTER <expression>`. Une chaîne unique est insérée telle quelle. Un tableau de conditions est joint avec le séparateur `$logicalOperator` (défaut `&&`, on peut passer `||` ou autre). `$useParentheses` enrobe le résultat de parenthèses (utile pour combiner plusieurs `FILTER` mixant `AND` / `OR`).

```php
aqlFilter( 'doc.active == true' ) ;
// "FILTER doc.active == true"

aqlFilter( [ 'doc.x > 5' , 'doc.y < 10' ] ) ;
// "FILTER doc.x > 5 && doc.y < 10"

aqlFilter( [ 'doc.x > 5' , 'doc.y < 10' ] , '||' ) ;
// "FILTER doc.x > 5 || doc.y < 10"
```

Doc officielle : [`FILTER`](https://docs.arangodb.com/stable/aql/high-level-operations/filter/).

### `aqlPrune()`

```php
function aqlPrune( ... ) : string
```

Variante de `FILTER` dédiée aux traversées de graphe. Stoppe l'expansion du chemin courant dès que la condition `PRUNE` est satisfaite. Sémantiquement très différent d'un `FILTER` qui filtre simplement les résultats sortis. À utiliser à l'intérieur d'un `aqlTraversal`.

Doc officielle : [`PRUNE`](https://docs.arangodb.com/stable/aql/graphs/traversals/#pruning).

## Variable intermédiaire

### `aqlLet()`

```php
function aqlLet( ... ) : string
```

Construit la clause `LET <name> = <expression>`. Définit une variable locale au scope du `FOR` courant, évaluée une seule fois par tour de boucle. Pratique pour factoriser une sous-expression réutilisée plusieurs fois, ou pour stocker le résultat d'une sous-requête.

Doc officielle : [`LET`](https://docs.arangodb.com/stable/aql/high-level-operations/let/).

## Agrégation

### `aqlCollect()`

```php
function aqlCollect( array $init = [] ) : string
```

Construit la clause `COLLECT` pour le regroupement, l'agrégation et le comptage. Équivalent du `GROUP BY` SQL avec en plus le support des fonctions agrégées AQL (`AGGREGATE total = SUM(doc.amount)`).

À l'inverse de `WINDOW` (qui conserve toutes les lignes), `COLLECT` **réduit** le résultat : N lignes → une ligne par groupe distinct.

```php
// Total des ventes par catégorie
aqlCollect([ AQL::ASSIGN => [ 'category' => 'doc.category' ] , AQL::AGGREGATE => [ 'total' => 'SUM(doc.amount)' ] ]) ;
// COLLECT category = doc.category AGGREGATE total = SUM(doc.amount)

// Comptage par groupe
aqlCollect([ AQL::ASSIGN => [ 'status' => 'doc.status' ] , AQL::WITH_COUNT => 'count' ]) ;
// COLLECT status = doc.status WITH COUNT INTO count
```

> **Note :** `AQL::AGGREGATE` et `AQL::WITH_COUNT` sont mutuellement exclusifs en AQL. Si les deux sont fournis, `AGGREGATE` est prioritaire et `WITH COUNT INTO` est ignoré. Pour compter en présence d'autres agrégats, exprimer le comptage comme un agrégat (`['n' => 'LENGTH(1)']`).

Le câblage haut niveau (`?groupBy=` / `Arango::GROUP`) est décrit dans le guide [Regroupement](../db/grouping.md).

Doc officielle : [`COLLECT`](https://docs.arangodb.com/stable/aql/high-level-operations/collect/).

### `aqlCollectReturn()`

```php
function aqlCollectReturn( array $spec = [] , ?string $explicit = null ) : string
```

Construit la clause `RETURN` qui suit un `COLLECT` produit par `aqlCollect()`. Après un `COLLECT`, la variable d'itération (`doc`) sort du scope : seules restent les variables de regroupement, les agrégats et l'éventuelle variable `WITH COUNT`. Ce helper dérive une projection valide à partir de la **même** `$spec` que `aqlCollect()`, garantissant que les deux restent synchronisés.

- Une expression `$explicit` non vide l'emporte (`RETURN <expr>`).
- Sinon la projection est dérivée : clés de regroupement (`array_keys(AQL::ASSIGN)`) + clés d'agrégats (`array_keys(AQL::AGGREGATE)`) + variable `AQL::WITH_COUNT`.
- Un comptage pur (sans regroupement ni agrégat) renvoie le compte **scalaire** (`RETURN length`), pas un objet.
- `AQL::AGGREGATE` et `AQL::WITH_COUNT` étant exclusifs, la variable de comptage est ignorée quand un agrégat est présent.

```php
aqlCollectReturn( [ AQL::ASSIGN => [ 'status' => 'doc.status' ] ] ) ;
// RETURN { status }

aqlCollectReturn( [ AQL::ASSIGN => [ 'category' => 'doc.category' ] , AQL::WITH_COUNT => 'count' ] ) ;
// RETURN { category, count }

aqlCollectReturn( [ AQL::WITH_COUNT => 'length' ] ) ;
// RETURN length

aqlCollectReturn( [ AQL::ASSIGN => [ 'y' => 'DATE_YEAR(doc.created)' ] ] , '{ year: y }' ) ;
// RETURN { year: y }
```

> Le câblage haut niveau côté modèle (clé `Arango::GROUP` / vocabulaire `Group`, et la clé brute `Arango::COLLECT` dans une requête `list`) est décrit dans le guide [Regroupement](../db/grouping.md).

### `aqlWindow()`

```php
function aqlWindow( array $init = [] ) : string
```

#### À quoi sert `WINDOW` ?

`WINDOW` calcule une **agrégation glissante** : pour **chaque** ligne du résultat, il agrège les quelques lignes *voisines* (précédentes et/ou suivantes) et attache le résultat à cette ligne.

La différence clé avec `COLLECT` :

- `COLLECT` **réduit** le résultat : N lignes → quelques lignes de groupes. On perd le détail ligne à ligne.
- `WINDOW` **conserve toutes les lignes** : N lignes en entrée → N lignes en sortie, chacune enrichie d'une valeur agrégée calculée sur sa fenêtre.

C'est l'outil des **totaux courants** (running total), **moyennes mobiles**, classements glissants, comparaisons « cette ligne vs la moyenne des 7 derniers jours », etc. — tout ce qui a besoin du détail **et** d'un agrégat contextuel sur la même ligne.

#### Exemple concret de bout en bout

Une collection `ventes` (une ligne par jour) :

| jour | montant |
|---|---|
| 1 | 10 |
| 2 | 20 |
| 3 | 30 |

On veut, pour chaque jour, le montant **et** le cumul depuis le début (total courant) :

```aql
FOR v IN ventes
  SORT v.jour
  WINDOW { preceding: 'unbounded', following: 0 }   // toutes les lignes précédentes + la courante
  AGGREGATE cumul = SUM(v.montant)
  RETURN { jour: v.jour, montant: v.montant, cumul }
```

Résultat — **une ligne par jour conservée**, avec le cumul qui s'accumule :

| jour | montant | cumul |
|---|---|---|
| 1 | 10 | 10 |
| 2 | 20 | 30 |
| 3 | 30 | 60 |

> Avec `COLLECT`, on n'aurait obtenu qu'**une seule** ligne (`60`), en perdant le détail par jour. C'est tout l'intérêt de `WINDOW` : garder chaque ligne tout en y ajoutant un agrégat contextuel.

Changer la fenêtre change le calcul : `{ preceding: 1, following: 1 }` donnerait la **moyenne mobile** sur la ligne précédente, la courante et la suivante (`AGGREGATE moy = AVG(v.montant)`).

Côté PHP, le même `WINDOW` se construit avec `aqlWindow()` :

```php
aqlWindow([ AQL::PRECEDING => 'unbounded' , AQL::FOLLOWING => 0 , AQL::AGGREGATE => [ 'cumul' => 'SUM(v.montant)' ] ]) ;
// WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE cumul = SUM(v.montant)
```

#### Référence

Construit la clause `WINDOW` d'agrégation par **fenêtre glissante** (totaux courants, moyennes mobiles, et autres statistiques sur des lignes voisines). Deux formes, selon la présence de `AQL::RANGE_VALUE` :

- **Row-based** (nombre fixe de lignes adjacentes) — sans `rangeValue` : `WINDOW { preceding: N, following: M } AGGREGATE …`
- **Range-based** (plage de valeur ou de durée autour de `rangeValue`) — avec `rangeValue` : `WINDOW <rangeValue> WITH { preceding: …, following: … } AGGREGATE …`

> Le mot-clé `WITH` de la forme range-based appartient à la syntaxe de `WINDOW` et n'a **rien à voir** avec l'opération `WITH` de déclaration de collections ([`aqlWith()`](#aqlwith)).

Clés de `$init` : `AQL::AGGREGATE` (requis), `AQL::PRECEDING`, `AQL::FOLLOWING`, `AQL::RANGE_VALUE`. Les bornes numériques sont émises telles quelles, les bornes string sont mises entre quotes simples (durées ISO 8601 comme `PT1H`). Une borne `null` est omise de l'objet `{ … }`.

```php
// Moyenne mobile sur 3 lignes (précédente, courante, suivante)
aqlWindow([ AQL::PRECEDING => 1 , AQL::FOLLOWING => 1 , AQL::AGGREGATE => [ 'rollingAvg' => 'AVG(doc.val)' ] ]) ;
// WINDOW { preceding: 1, following: 1 } AGGREGATE rollingAvg = AVG(doc.val)

// Total courant (cumulatif) depuis le début du résultat
aqlWindow([ AQL::PRECEDING => 'unbounded' , AQL::FOLLOWING => 0 , AQL::AGGREGATE => [ 'runningTotal' => 'SUM(doc.val)' ] ]) ;
// WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE runningTotal = SUM(doc.val)

// Fenêtre par plage de durée
aqlWindow([ AQL::RANGE_VALUE => 'doc.time' , AQL::PRECEDING => 'PT1H' , AQL::FOLLOWING => 0 , AQL::AGGREGATE => [ 'total' => 'SUM(doc.val)' ] ]) ;
// WINDOW doc.time WITH { preceding: 'PT1H', following: 0 } AGGREGATE total = SUM(doc.val)
```

> Pour un total courant illimité, ArangoDB attend la **string** `"unbounded"` (un bareword serait interprété comme un nom de collection). La forme range-based impose un tri par la valeur de plage : l'optimiseur AQL insère automatiquement un `SORT` devant le `WINDOW`.

Doc officielle : [`WINDOW`](https://docs.arangodb.com/stable/aql/high-level-operations/window/).

#### `aqlWindowBounds()`

```php
function aqlWindowBounds( int|float|string|null $preceding , int|float|string|null $following ) : string
```

Helper bas niveau qui sérialise l'objet de bornes `{ preceding: …, following: … }` d'une clause `WINDOW`. Utilisé en interne par `aqlWindow()`, mais exposé séparément (un fichier = un helper) pour réutilisation. Les bornes numériques sont émises telles quelles, les bornes string entre quotes simples (durées ISO 8601, mot-clé `'unbounded'`) ; une borne `null` est omise.

```php
aqlWindowBounds( 1 , 1 ) ;            // { preceding: 1, following: 1 }
aqlWindowBounds( 'unbounded' , 0 ) ;  // { preceding: 'unbounded', following: 0 }
aqlWindowBounds( 0 , null ) ;         // { preceding: 0 }
```

## Tri

### `aqlSort()`

```php
function aqlSort( string|array|null $expression ) : string
```

Construit la clause `SORT <expression>`. Accepte une chaîne AQL brute (`'doc.created DESC, doc.name ASC'`) ou un tableau d'expressions à joindre par virgule.

```php
aqlSort( 'doc.created DESC' )           ;  // "SORT doc.created DESC"
aqlSort( [ 'doc.created DESC' , 'doc.name' ] ) ;  // "SORT doc.created DESC, doc.name"
```

Doc officielle : [`SORT`](https://docs.arangodb.com/stable/aql/high-level-operations/sort/).

### `aqlAsc()` et `aqlDesc()`

```php
function aqlAsc ( string $key , ?string $prefix = null ) : string
function aqlDesc( string $key , ?string $prefix = null ) : string
```

Helpers de bas niveau qui produisent respectivement `"<prefix>.<key> ASC"` et `"<prefix>.<key> DESC"`. Évitent la concaténation manuelle et le mélange constantes typées / chaînes brutes (cf. [Helpers racine](../helpers.md) pour la grammaire de tri textuelle côté HTTP).

```php
aqlAsc ( 'name'   , 'doc' ) ;  // "doc.name ASC"
aqlDesc( 'created', 'doc' ) ;  // "doc.created DESC"
```

## Pagination

### `aqlLimit()`

```php
function aqlLimit( ... ) : string
```

Construit la clause `LIMIT <count>` (depuis le début) ou `LIMIT <offset>, <count>` (avec décalage). Les deux formes sont gérées par les arguments.

```php
aqlLimit( 50      ) ;  // "LIMIT 50"
aqlLimit( 0  , 50 ) ;  // "LIMIT 0, 50"
```

Doc officielle : [`LIMIT`](https://docs.arangodb.com/stable/aql/high-level-operations/limit/).

## Retour

### `aqlReturn()`

```php
function aqlReturn( mixed $expression , bool $distinct = false ) : string
```

Construit la clause `RETURN <expression>`. Le second paramètre ajoute le mot-clé `DISTINCT` pour dédupliquer les résultats.

```php
aqlReturn( 'doc'           ) ;        // "RETURN doc"
aqlReturn( 'doc.email' , true ) ;     // "RETURN DISTINCT doc.email"
aqlReturn( '{ name: doc.name }' ) ;   // "RETURN { name: doc.name }"
```

Mot-clés AQL spéciaux utilisables : `OLD` et `NEW` après une modification, `CURRENT` dans certains contextes.

Doc officielle : [`RETURN`](https://docs.arangodb.com/stable/aql/high-level-operations/return/).

## Modification

Les six opérations de modification suivent la même mécanique : un tableau de clés `AQL::*` (`KEY`, `DOCUMENT`, `WITH`, `COLLECTION`, `OPTIONS`) configure l'instruction produite.

### `aqlInsert()`

```php
function aqlInsert( ... ) : string
```

Construit `INSERT { ... } INTO collection [OPTIONS { ... }]`. Insère un nouveau document. Lève un conflit si la clé existe déjà (sauf `OPTIONS { ignoreErrors: true }`).

Doc officielle : [`INSERT`](https://docs.arangodb.com/stable/aql/high-level-operations/insert/).

### `aqlUpdate()`

```php
function aqlUpdate( ... ) : string
```

Construit `UPDATE key WITH { ... } IN collection [OPTIONS { ... }]`. Mise à jour **partielle** : fusionne les attributs fournis avec ceux du document existant. Les attributs absents de `WITH` sont préservés.

Doc officielle : [`UPDATE`](https://docs.arangodb.com/stable/aql/high-level-operations/update/).

### `aqlReplace()`

```php
function aqlReplace( array $init = [] ) : string
```

Construit `REPLACE key WITH { ... } IN collection [OPTIONS { ... }]`. Remplace **intégralement** le document : tout attribut absent de `WITH` est perdu. À utiliser uniquement quand le document complet est connu.

Doc officielle : [`REPLACE`](https://docs.arangodb.com/stable/aql/high-level-operations/replace/).

### `aqlUpsert()`

```php
function aqlUpsert( array $init = [] ) : string
```

Construit `UPSERT { search } INSERT { ... } UPDATE { ... } IN collection [OPTIONS { ... }]`. Insère si le document de recherche n'existe pas, sinon met à jour avec la clause `UPDATE` (partielle). C'est l'écriture **idempotente** par excellence : rejouer la même requête ne crée pas de doublon.

Chaque bloc (`search` / `insert` / `update`) est une **liste de paires `[clé, valeur]`** :

```php
aqlUpsert
([
    'search' => [ [ 'foo' , 'bar' ] ] ,
    'insert' => [ [ 'foo' , 'bar' ] ] ,
    'update' => [ [ 'foo' , 'baz' ] ] ,
]) ;
// UPSERT {foo:'bar'} INSERT {foo:'bar'} UPDATE {foo:'baz'} IN @@collection RETURN NEW
```

La clé `return` accepte `Clause::WITH_STATUS` pour distinguer insertion et mise à jour dans le `RETURN`.

Doc officielle : [`UPSERT`](https://docs.arangodb.com/stable/aql/high-level-operations/upsert/).

### `aqlRepsert()`

```php
function aqlRepsert( array $init = [] ) : string
```

Variante d'`UPSERT` où la branche `UPDATE` est remplacée par un `REPLACE`. Insère si absent, remplace intégralement sinon. Utile quand l'application a toujours la version complète du document.

### `aqlRemove()`

```php
function aqlRemove( array $init = [] ) : string
```

Construit `REMOVE key IN collection [OPTIONS { ... }]`. Supprime un document. `OPTIONS { ignoreRevs: false }` active la vérification de révision MVCC.

Doc officielle : [`REMOVE`](https://docs.arangodb.com/stable/aql/high-level-operations/remove/).

## Traversée de graphe

### `aqlTraversal()`

```php
function aqlTraversal( array $init = [] , ?array &$binds = null ) : string
```

Construit la clause de traversée complète `FOR v[, e[, p]] IN <range> <DIRECTION> <start> GRAPH '<name>'` (ou, sans graphe nommé, `… <start> <edgeCollection>`). Consomme les clés `AQL::VERTEX_REF`, `AQL::EDGE_REF`, `AQL::PATH_REF`, `AQL::MIN_DEPTH`, `AQL::MAX_DEPTH`, `AQL::DIRECTION` (`Traversal::OUTBOUND`, `INBOUND`, `ANY`), `AQL::START_VERTEX`, `AQL::GRAPH` ou `AQL::EDGE_COLLECTION`. Le paramètre `$binds` est passé par référence pour accumuler les *bind variables* internes.

```php
// Traversée sur graphe nommé, profondeur 1..3
aqlTraversal
([
    AQL::VERTEX_REF   => 'v' ,
    AQL::MIN_DEPTH    => 1   ,
    AQL::MAX_DEPTH    => 3   ,
    AQL::DIRECTION    => Traversal::OUTBOUND ,
    AQL::START_VERTEX => '@startVertex'      ,
    AQL::GRAPH        => 'social'            ,
]) ;
// "FOR v IN 1..3 OUTBOUND @startVertex GRAPH 'social'"

// Traversée INBOUND sur collection d'arêtes anonyme, avec variable d'arête
aqlTraversal
([
    AQL::VERTEX_REF      => 'v' ,
    AQL::EDGE_REF        => 'e' ,
    AQL::DIRECTION       => Traversal::INBOUND ,
    AQL::START_VERTEX    => 'comments/42' ,
    AQL::EDGE_COLLECTION => 'authored' ,
]) ;
// "FOR v, e IN INBOUND 'comments/42' authored"
```

> Sur les modèles `Edges`, les méthodes `getInboundVertices()` / `getOutboundVertices()` / `getAnyVertices()` enveloppent `aqlTraversal()` et ajoutent automatiquement le `FILTER`, le `SORT`, le `LIMIT` et la clause `WITH` (cluster). Voir [`clients/graphs.md`](../clients/graphs.md).

Doc officielle : [Graph traversals](https://docs.arangodb.com/stable/aql/graphs/traversals/).

### `aqlTraversalRange()`

```php
function aqlTraversalRange( ... ) : string
```

Construit le fragment `<min>..<max>` (par exemple `1..1`, `1..5`, `..2`, `3..`). Utilisé en interne par `aqlTraversal()` pour la portée du parcours ; exposé indépendamment pour les cas où l'on a besoin de calculer la range séparément.

## Recherche vectorielle

### `aqlVectorSearch()` — Recherche approximative de plus proches voisins

```php
function aqlVectorSearch(
    string  $collection ,
    string  $attribute ,
    string  $vector ,
    int     $limit ,
    string  $metric  = 'cosine' , // 'cosine' ou 'l2'
    ?int    $nProbe  = null ,
    string  $docRef  = 'doc' ,
    ?string $return  = null ,
) : string
```

Construit une requête complète de plus-proches-voisins approchés (ANN) sur un [index `vector`](../clients/indexes.md), dans la forme canonique `FOR … SORT APPROX_NEAR_…(…) [DESC|ASC] LIMIT … RETURN …`. Elle compose `aqlFor()`, les fonctions numériques `approxNear*`, `aqlSort()`, `aqlLimit()` et `aqlReturn()`.

La `$metric` sélectionne **à la fois** la fonction et le sens du tri — c'est le piège classique :

| `$metric` | Fonction | Tri | Le plus proche est |
|---|---|---|---|
| `'cosine'` (défaut) | `APPROX_NEAR_COSINE` | `DESC` | proche de `1` |
| `'l2'` | `APPROX_NEAR_L2` | `ASC` | proche de `0` |

Elle doit correspondre à la métrique du `VectorIndex` couvrant `$attribute`, sinon l'optimiseur ne peut pas accélérer la requête. Une métrique non supportée lève `InvalidArgumentException`. Les index vectoriels sont une fonctionnalité **expérimentale** d'ArangoDB (serveur démarré avec `--experimental-vector-index`).

```php
use function oihana\arango\db\operations\aqlVectorSearch ;

// Top-10 voisins cosine, vecteur de requête lié via @query :
aqlVectorSearch( collection: 'items', attribute: 'embedding', vector: '@query', limit: 10 ) ;
// "FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query) DESC LIMIT 10 RETURN doc"

// Métrique L2, nProbe personnalisé, variable d'itération et projection :
aqlVectorSearch(
    collection: 'items', attribute: 'embedding', vector: '@query',
    limit: 5, metric: 'l2', nProbe: 20, docRef: 'd',
    return: '{ key: d._key, score: APPROX_NEAR_L2(d.embedding, @query) }' ,
) ;
// "FOR d IN items SORT APPROX_NEAR_L2(d.embedding,@query,{"nProbe":20}) ASC LIMIT 5
//   RETURN { key: d._key, score: APPROX_NEAR_L2(d.embedding, @query) }"
```

Bout-en-bout (client PHP), documents les plus proches d'un embedding :

```php
$aql  = aqlVectorSearch( collection: 'items', attribute: 'embedding', vector: '@query', limit: 10 ) ;
$rows = iterator_to_array( $db->query( $aql , [ 'query' => $embedding ] ) , false ) ;
```

Les helpers de plus bas niveau `approxNearCosine()` / `approxNearL2()` / `l1Distance()` / `l2Distance()` sont documentés dans [Fonctions numériques › Vecteurs](aql-functions-numerics.md#vecteurs).

## Recherche scorée (ArangoSearch)

### `aqlScoredSearch()` — Recherche classée par pertinence sur une View

```php
function aqlScoredSearch(
    string                   $view ,
    string|array             $search ,
    int                      $limit ,
    ?string                  $analyzer  = null ,
    array|object|string|null $options   = null ,
    string                   $scorer    = SearchScorer::BM25 , // 'bm25' ou 'tfidf'
    ?float                   $k         = null ,               // BM25 seulement
    ?float                   $b         = null ,               // BM25 seulement
    ?bool                    $normalize = null ,               // TFIDF seulement
    int                      $offset    = 0 ,
    string                   $docRef    = 'doc' ,
    string                   $scoreRef  = 'score' ,
    ?string                  $return    = null ,
) : string
```

Construit une requête de recherche complète classée par pertinence sur une View ArangoSearch, dans la forme canonique `FOR … SEARCH … [OPTIONS { … }] LET score = BM25(…)|TFIDF(…) SORT score DESC LIMIT … RETURN …`. Elle compose `aqlFor()` / `aqlSearch()` (donc `$analyzer` et `$options` se comportent exactement comme `AQL::ANALYZER` / `AQL::SEARCH_OPTIONS`), les [helpers scorers](aql-functions-search.md) `bm25()` / `tfidf()`, `aqlLet()`, `aqlSort()`, `aqlLimit()` et `aqlReturn()`.

Les deux scorers classent les meilleurs matchs **plus haut**, donc le tri est toujours `DESC` — pas de direction à se tromper. Le score vit dans une variable `LET` (`$scoreRef`), donc un `$return` custom peut l'exposer. Les gardes lèvent `InvalidArgumentException` : `$scorer` inconnu, `$k`/`$b` avec TFIDF, `$normalize` avec BM25.

```php
use function oihana\arango\db\functions\search\phrase ;
use function oihana\arango\db\operations\aqlScoredSearch ;

// Top-20 par pertinence BM25, analyzer français :
aqlScoredSearch( view: 'placesView', search: phrase('doc.name', 'scierie'), limit: 20, analyzer: 'text_fr' ) ;
// "FOR doc IN placesView SEARCH ANALYZER(PHRASE(doc.name,\"scierie\"),\"text_fr\")
//   LET score = BM25(doc) SORT score DESC LIMIT 20 RETURN doc"

// TF-IDF, pagination, et le score dans la sortie :
aqlScoredSearch(
    view: 'articlesView', search: 'doc.text IN TOKENS(@q, "text_fr")',
    limit: 10, offset: 20, scorer: SearchScorer::TFIDF,
    return: '{ doc: doc, score: score }' ,
) ;
```

Bout-en-bout (client PHP), les documents les plus pertinents d'abord :

```php
$aql  = aqlScoredSearch( view: 'articlesView', search: 'doc.text IN TOKENS(@q, "text_fr")', limit: 10 ) ;
$rows = iterator_to_array( $db->query( $aql , [ 'q' => 'quick fox' ] ) , false ) ;
```

> Les scorers exigent que les Analyzers des champs indexés aient la feature `"frequency"` activée (et `"norm"` pour une normalisation de longueur BM25 pertinente), sinon tous les scores valent `0`. Les helpers d'expressions de recherche (`phrase`, `levenshteinMatch`, `boost`, …) sont documentés sur la page [Fonctions ArangoSearch](aql-functions-search.md).

## Configuration

### `aqlOptions()`

```php
function aqlOptions( ... ) : string
```

Construit la clause `OPTIONS { ... }` qui annote une opération AQL (FOR, INSERT, UPDATE, ...) avec des options spécifiques : `indexHint`, `forceIndexHint`, `useCache`, `ignoreErrors`, `waitForSync`, etc. La nature des options dépend de l'opération hôte ; la fonction délègue à une classe d'`Options` typée pour valider (voir [Référence des options AQL](../options.md)).

### `aqlWith()`

```php
function aqlWith( string ...$collections ) : string
```

Construit la clause `WITH coll1, coll2, ...` qui déclare explicitement les collections référencées par la requête. Utile en cluster quand le planificateur ne peut pas inférer les dépendances (par exemple via des sous-requêtes dynamiques).

```php
aqlWith( 'users' , 'orders' , 'products' ) ;
// "WITH users, orders, products"
```

> **Émission automatique sur les traversals anonymes.** Les méthodes de traversal d'arêtes (`getOutboundVertices()`, `getInboundVertices()`, `getAnyVertices()`, `countVertices()` et leurs variantes) préfixent désormais la requête d'un `WITH` lorsqu'elles parcourent un **graphe anonyme** (collection d'arêtes, sans graphe nommé). Les collections de sommets atteignables sont déclarées selon la direction : `OUTBOUND` → collection `_to`, `INBOUND` → collection `_from`, `ANY` → les deux (dédupliquées). C'est indispensable pour éviter les *deadlocks* en cluster, et sans effet sur un serveur unique. Aucune émission pour un traversal de **graphe nommé** (les collections y sont déjà connues). On peut surcharger les collections déclarées via la clé `AQL::WITH` du tableau d'options de la méthode.

Doc officielle : [`WITH`](https://docs.arangodb.com/stable/aql/high-level-operations/with/).

## Voir aussi

- [Construire une requête AQL pas à pas](aql-building-queries.md) — narrative pédagogique d'assemblage.
- [Opérateurs `db/operators/`](aql-operators.md) — catalogue des 42 opérateurs consommés par `aqlFilter`.
- [Helpers AQL `db/helpers/`](../db/helpers.md) — encodage de valeurs, sous-expressions CUD, *field builders*.
- [Bind variables `db/binds/`](../db/binds.md) — injection sûre.
- [Documentation officielle AQL — high-level operations](https://docs.arangodb.com/stable/aql/high-level-operations/).
