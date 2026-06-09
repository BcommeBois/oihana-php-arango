# Opérations AQL `db/operations/`

Le dossier [`src/oihana/arango/db/operations/`](../../../src/oihana/arango/db/operations/) fournit les **22 opérations** qui correspondent aux *high-level operations* du langage AQL. Chaque fonction produit le fragment de texte AQL correspondant et peut se concaténer librement avec les autres pour former une requête complète.

Pour la vue d'ensemble pédagogique de la composition, voir [Construire une requête AQL pas à pas](aql-building-queries.md). Cette page est la **référence** de chacune des opérations.

## Catégories

| Catégorie | Opérations |
|---|---|
| Itération | `aqlFor`, `aqlSearch` |
| Restriction | `aqlFilter`, `aqlPrune` |
| Variable intermédiaire | `aqlLet` |
| Agrégation | `aqlCollect`, `aqlCollectReturn` |
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

Construit la clause `SEARCH <expression>` utilisée à l'intérieur d'un `FOR` qui itère sur une vue ArangoSearch. Le filtrage est résolu par l'index inversé de la vue, plus rapide qu'un `FILTER` classique sur grandes volumétries. Appelée en interne par `aqlFor()` quand `AQL::SEARCH` est fourni, mais peut être utilisée seule.

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

> **Note :** `AQL::AGGREGATE` et `AQL::WITH_COUNT` sont mutuellement exclusifs en AQL. Si les deux sont fournis, `AGGREGATE` est prioritaire et `WITH COUNT INTO` est ignoré. Pour compter en présence d'autres agrégats, exprimer le comptage comme un agrégat (`['n' => 'LENGTH(1)']`).

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

Construit `UPSERT { search } INSERT { ... } UPDATE { ... } IN collection [OPTIONS { ... }]`. Insère si le document de recherche n'existe pas, sinon met à jour avec la clause `UPDATE` (partielle).

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

Construit la clause de traversée complète `FOR v[, e[, p]] IN <range> <DIRECTION> <start> GRAPH '<name>'`. Consomme les clés `AQL::VERTEX`, `AQL::EDGE`, `AQL::PATH`, `AQL::MIN`, `AQL::MAX`, `AQL::DIRECTION` (`Traversal::OUTBOUND`, `INBOUND`, `ANY`), `AQL::START`, `AQL::GRAPH`. Le paramètre `$binds` est passé par référence pour accumuler les *bind variables* internes.

```php
aqlTraversal
([
    AQL::VERTEX    => 'v' ,
    AQL::MIN       => 1   ,
    AQL::MAX       => 3   ,
    AQL::DIRECTION => Traversal::OUTBOUND ,
    AQL::START     => '@startVertex'       ,
    AQL::GRAPH     => 'social'             ,
]) ;
// "FOR v IN 1..3 OUTBOUND @startVertex GRAPH 'social'"
```

Doc officielle : [Graph traversals](https://docs.arangodb.com/stable/aql/graphs/traversals/).

### `aqlTraversalRange()`

```php
function aqlTraversalRange( ... ) : string
```

Construit le fragment `<min>..<max>` (par exemple `1..1`, `1..5`, `..2`, `3..`). Utilisé en interne par `aqlTraversal()` pour la portée du parcours ; exposé indépendamment pour les cas où l'on a besoin de calculer la range séparément.

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
