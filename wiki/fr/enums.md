# Référence des enums

Le dossier [`api/src/oihana/arango/db/enums/`](../../../api/src/oihana/arango/db/enums/) regroupe **28 enums et traits** qui exposent les constantes typées du framework. Tous suivent la convention `oihana/php-enums` (`ConstantsTrait` pour `keys()` / `values()` / introspection), ce qui en fait des registres consultables à l'exécution.

> Convention transverse : aucune chaîne brute dans le code applicatif. Toute clé de configuration, tout opérateur AQL, tout type d'index passe par une constante d'enum — discipline détaillée dans l'[Introduction](introduction.md#la-philosophie-doihanaarango).

## Sommaire

| Section | Enums |
|---|---|
| Centraux | `AQL`, `ArangoConfig` |
| Grammaire AQL | `Operation`, `Clause`, `Operator`, `Comparator`, `ArrayComparator`, `Logic` |
| Types | `IndexType`, `OverwriteMode`, `UpsertType`, `Traversal` |
| Temporels | `DateUnit`, `DateFormat`, `WeekDay` |
| Options | `CollectionOption`, `TraversalOption`, `TraversalOrder`, `TraversalUniqueEdges`, `TraversalUniqueVertices`, `FaithParam`, `PercentileMethod` |
| Statistiques et plan | `Extra`, `Statistic`, `Node` |
| Traits utilitaires | `ArangoConfigTrait`, `IndexOptionsTrait`, `QueryOptionsTrait` |

## Enums centraux

### `AQL`

L'enum le plus utilisé du framework. Liste toutes les clés conventionnelles consommées par les opérations, les modèles, les contrôleurs et les commandes. C'est le **vocabulaire commun** entre la couche bas niveau et la couche métier.

Clés principales par catégorie :

| Famille | Constantes |
|---|---|
| Collection et schéma | `COLLECTION`, `DATABASE`, `SCHEMA`, `DOCUMENT`, `DOC_REF`, `DOC` |
| Itération | `IN`, `START`, `GRAPH`, `VERTEX`, `EDGE`, `PATH`, `MIN`, `MAX`, `DIRECTION` |
| Modèle | `FIELDS`, `FILTERS`, `FILLABLE`, `ALTERS`, `SEARCHABLE`, `SORTABLE`, `SORT_DEFAULT` |
| Relations | `EDGES`, `JOINS`, `FROM`, `TO`, `RESOLVE`, `REQUIRES` |
| Recherche | `SEARCH`, `FACETS` |
| Projection | `SKIN`, `SKIN_FIELDS`, `SKIN_METHODS`, `INDEXES` |
| Modification | `KEY`, `WITH`, `OPTIONS`, `CONDITIONS`, `BINDS` |
| Filtrage interne | `RAW_KEYS`, `RAW_VALUES`, `USE_SPACE` |
| Authorisation | `AUTHORIZER` |

Constantes utilisées dans plusieurs exemples du framework — voir [Construire une requête AQL pas à pas](aql/aql-building-queries.md), [Projection des edges et joins](edges-joins-projection.md).

### `ArangoConfig`

Clés de configuration du constructeur [`ArangoDB`](quickstart.md#configuration--clés-arangoconfig). Une vingtaine de constantes qui mappent sur les options de connexion : `ENDPOINT`, `DATABASE`, `TYPE`, `USER`, `PASSWORD`, `CONNECTION`, `TIMEOUT`, `CREATE`, `RECONNECT`, `DEBUG`, `BATCH_SIZE`, `MAX_RUNTIME`.

## Grammaire AQL

| Enum | Rôle | Exemples de constantes |
|---|---|---|
| `Operation` | Les opérations *high-level* AQL | `FOR`, `FILTER`, `SORT`, `LIMIT`, `RETURN`, `INSERT`, `UPDATE`, `REPLACE`, `UPSERT`, `REMOVE`, `COLLECT`, `LET`, `SEARCH`, `PRUNE`, `WITH` |
| `Clause` | Sous-clauses internes | `INTO`, `OPTIONS`, `WITH`, `OLD`, `NEW`, `CURRENT`, `DISTINCT`, `AGGREGATE` |
| `Operator` | Opérateurs et mots-clés AQL | `IN`, `NOT IN`, `ALL`, `ANY`, `NONE`, `LIKE`, `DISTINCT`, `AND`, `OR`, `NOT` |
| `Comparator` | Comparateurs scalaires | `EQUAL` (`==`), `NOT_EQUAL` (`!=`), `GREATER_THAN` (`>`), `LESS_THAN_OR_EQUAL` (`<=`), `IN`, `LIKE`, `MATCH` (`=~`) |
| `ArrayComparator` | Variantes quantifiées | `ALL`, `ANY`, `NONE` (préfixes des opérateurs `db/operators/`) |
| `Logic` | Opérateurs logiques | `AND` (`&&`), `OR` (`\|\|`), `NOT` (`!`) |

Ces enums sont consommés en interne par les fonctions de [`db/operations/`](aql/aql-operations.md) et [`db/operators/`](aql/aql-operators.md). En usage applicatif, on les manipule rarement directement : ce sont les fonctions qui les utilisent pour produire le bon texte AQL.

## Types

| Enum | Rôle | Constantes |
|---|---|---|
| `IndexType` | Types d'index ArangoDB | `PERSISTENT`, `TTL`, `GEO`, `FULLTEXT` (deprecated), `MDI`, `VECTOR`, `EDGE`, `PRIMARY` |
| `OverwriteMode` | Comportement d'`INSERT` en cas de conflit de `_key` | `REPLACE`, `UPDATE`, `IGNORE`, `CONFLICT` |
| `UpsertType` | Branche choisie par `UPSERT` à l'exécution | `INSERT`, `UPDATE` (utilisé pour analyser le résultat d'un upsert) |
| `Traversal` | Direction d'une traversée de graphe | `OUTBOUND`, `INBOUND`, `ANY` |

Exemples d'usage : voir [`InsertOptions`](options.md#options-par-opération), [`aqlTraversal`](aql/aql-operations.md#aqltraversal).

## Temporels

| Enum | Rôle | Constantes |
|---|---|---|
| `DateUnit` | Unité d'arithmétique de dates | `YEAR`, `MONTH`, `WEEK`, `DAY`, `HOUR`, `MINUTE`, `SECOND`, `MILLISECOND` |
| `DateFormat` | Tokens de format pour [`dateFormat()`](aql/aql-functions-dates.md#conversion-et-format) | `Y` (année), `M` (mois), `D` (jour), `H` (heure), `MI` (minute), `S` (seconde), `MS` (milliseconde), plus les variantes longues |
| `WeekDay` | Jours de la semaine | `MONDAY`, `TUESDAY`, `WEDNESDAY`, `THURSDAY`, `FRIDAY`, `SATURDAY`, `SUNDAY` |

`DateUnit::DAY` est l'argument par défaut de [`dateAdd()`](aql/aql-functions-dates.md#arithmétique), [`dateSubstract()`](aql/aql-functions-dates.md#arithmétique), [`dateDiff()`](aql/aql-functions-dates.md#arithmétique) et [`dateTrunc()`](aql/aql-functions-dates.md#arithmétique).

## Options — `enums/options/`

Sous-dossier dédié aux clés et valeurs consommées par les classes [`*Options`](options.md).

| Enum | Rôle | Constantes principales |
|---|---|---|
| `CollectionOption` | Clés acceptées par `collectionCreate()` | `TYPE`, `WAIT_FOR_SYNC`, `IS_SYSTEM`, `KEY_OPTIONS`, `NUMBER_OF_SHARDS`, `REPLICATION_FACTOR`, `WRITE_CONCERN`, `SHARD_KEYS`, `SCHEMA` |
| `TraversalOption` | Clés de `TraversalOptions` | `ORDER`, `UNIQUE_VERTICES`, `UNIQUE_EDGES`, `BFS` (deprecated) |
| `TraversalOrder` | Stratégie de parcours | `BFS` (largeur), `DFS` (profondeur), `WEIGHTED` |
| `TraversalUniqueEdges` | Politique d'unicité des arêtes | `NONE`, `PATH`, `GLOBAL` |
| `TraversalUniqueVertices` | Politique d'unicité des sommets | `NONE`, `PATH`, `GLOBAL` |
| `FaithParam` | Paramètres Faiss consommés par `VectorIndexOptions` | `NLISTS`, `NPROBE`, `M`, `EFCONSTRUCTION`, etc. (variables selon la version ArangoDB) |
| `PercentileMethod` | Méthode de calcul utilisée par [`percentile()`](aql/aql-functions-numerics.md#agrégation-sur-tableau) | `RANK`, `INTERPOLATION` |

## Statistiques et plan d'exécution

Trois enums décrivent la structure des métadonnées retournées par le serveur après l'exécution d'une requête. Ils permettent d'analyser le `cursor->getExtra()` (voir [Quickstart — métadonnées du cursor](quickstart.md#métadonnées-du-cursor)).

| Enum | Rôle |
|---|---|
| `Extra` | Attributs racines des extras renvoyés par le *cursor* (stats, *warnings*, *plan*, *profile*). |
| `Statistic` | Attributs individuels du bloc `stats` (`writesExecuted`, `scannedFull`, `scannedIndex`, ...). |
| `Node` | Attributs d'un nœud du plan d'exécution AQL (`type`, `dependencies`, `id`, `estimatedCost`, ...). |

Surtout utiles pour du *profiling* avancé ou la construction d'un outil d'inspection — on ne les manipule presque jamais en production.

## Traits utilitaires

Trois traits transverses partagés entre plusieurs classes Options.

| Trait | Consommé par | Apport |
|---|---|---|
| `ArangoConfigTrait` | `ArangoDB` (constructeur) | Hydratation et validation des clés `ArangoConfig::*`. |
| `QueryOptionsTrait` | `QueryOptions`, `ForOptions`, `CollectOptions`, etc. | Sérialisation `JsonSerializable` standard, filtrage des `null`, fusion. |
| `IndexOptionsTrait` | `PersistentIndexOptions`, `TTLIndexOptions`, ... | Idem `QueryOptionsTrait` pour les classes d'index ; ajoute la résolution du `type` AQL à partir de la classe. |

Ces traits ne sont pas un point d'extension applicatif — ils sont consommés en interne. Documentés ici par cohérence du catalogue.

## Inspection à l'exécution

Tous les enums du framework héritent de `ConstantsTrait` (via [`oihana/php-enums`](dependencies.md#oihanaphp-enums)), ce qui leur donne deux méthodes d'introspection utiles :

```php
use oihana\arango\db\enums\Operation ;

Operation::keys()   ;   // [ 'FOR', 'FILTER', 'SORT', 'LIMIT', 'RETURN', ... ]
Operation::values() ;   // [ 'FOR', 'FILTER', 'SORT', 'LIMIT', 'RETURN', ... ]
Operation::has( 'FILTER' ) ;  // true
```

Pratique pour les outils de validation, les pages de debug, ou la génération de listes déroulantes côté admin.

## Voir aussi

- [Référence des options AQL](options.md) — les enums `OverwriteMode`, `TraversalOrder` et compagnie en contexte.
- [Construire une requête AQL pas à pas](aql/aql-building-queries.md) — usage des constantes `AQL::*` et `Traversal::*`.
- [Dépendances](dependencies.md#oihanaphp-enums) — `oihana/php-enums` et le pattern `ConstantsTrait`.
