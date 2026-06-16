# La couche `db/` — la façade `ArangoDB`

La couche `db/` se situe **au-dessus** du [client HTTP](../clients/README.md) et expose une API plus haut-niveau, taillée pour le code applicatif : hydratation des résultats en objets typés, exception wrapping, préparation de requêtes avec des défauts raisonnables, logger PSR-3 optionnel, et un enchaînement fluide `prepare → execute → consume`.

La pièce maîtresse est la classe [`ArangoDB`](../../../src/oihana/arango/db/ArangoDB.php) — un délégateur autour d'`ArangoClient` qui porte l'état de requête, met en cache le `Cursor` courant, configure batch size et limites d'exécution, et hydrate les documents via la famille `SchemaResolver | Closure | string | null`.

| Couche | Ce que vous obtenez | Quand l'utiliser |
|---|---|---|
| [Client HTTP](../clients/README.md) (`clients/`) | Transport bas niveau, `Document` immuable, `Cursor` paresseux, `Database::query()` brut. Autonome, sans PSR-11. | Scripts, workers, tests d'intégration, outillage custom. |
| **Façade `ArangoDB` (`db/`)** | Mêmes opérations, plus l'hydratation dans vos classes, `prepare/execute`, helpers de métadonnées de cursor, logger optionnel, exception wrapping. | Modèles métier, contrôleurs, services dans un conteneur. |

Si vous avez juste besoin de **lancer une requête et lire du JSON**, prenez le client. Si vous voulez voir les documents hydratés en `User`, `Order` ou n'importe quelle classe que vous contrôlez, prenez la façade.

## Apprendre la couche `db/` pas à pas

| # | Page | Ce qu'on y trouve |
|---|---|---|
| 1 | [Quickstart `ArangoDB`](quickstart.md) | Instancier, configurer (clés `ArangoConfig`, DI), exécuter de l'AQL brute, récupérer les résultats (`getDocuments` / `getFirstResult` / `getObject` / `getResult` / `streamDocuments`), hydratation par schéma, métadonnées de cursor, gestion des collections et des index. |
| 2 | [Helpers AQL `db/helpers/`](helpers.md) | Composer des fragments de texte AQL — `aqlValue`, `aqlExpression`, `aqlDocument`, field builders, helpers de projection par *skin*. |
| 3 | [Bind variables `db/binds/`](binds.md) | Injection sûre de valeurs — `aqlBind`, validation et formatage des placeholders. |
| 4 | [Recherche & filtrage](search-and-filtering.md) | **Vue d'ensemble** des 3 leviers (`?search` / `?filter` / `?facets`) : modèle mental, tableau comparatif, socle commun (`op`, `alt`, binds, sécurité), « quand utiliser quoi ». |
| 5 | [Recherche HTTP `?search=`](search.md) | Recherche multi-champs `LIKE` (insensible à la casse), déclaration `searchable`, combinaison, limites (vs ArangoSearch). |
| 5b | [Recherche View (ArangoSearch)](search-views.md) | La déclaration `AQL::VIEW` : `?search=` bascule vers une recherche accélérée par index et **classée par pertinence** (boosts, bonus phrase, fuzzy, clé de tri `score`, View auto-provisionnée). |
| 5c | [Analyzers](analyzers.md) | La **recette de préparation du texte** : les analyzers intégrés (`identity`, `text_fr`, …), les 4 types fabricables (`Identity`/`Norm`/`Stem`/`TextAnalyzer`), les features (`BM25`/`PHRASE`/highlight), et comment créer un analyzer custom proprement. |
| 6 | [Filtres HTTP `?filter=`](filter.md) | Syntaxe URL `?filter=`, comparateurs, transformations `alt`, chaînage, `FilterType::*`. |
| 7 | [Filtrage interne — `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md) | Conditions serveur-only, `FilterType::VIRTUAL`, règle de choix URL vs interne. |
| 8 | [Facettes HTTP `?facets=`](facets.md) | Syntaxe URL `?facets=`, déclaration `Arango::FACETS` / `Facet::TYPE`, catalogue des types (FIELD, IN, EDGE, JOIN, *_COMPLEX, *_AGGREGATE), opérateurs, négation, sécurité, **compteurs de facettes `?facetCounts=`**. |
| 9 | [Regroupement HTTP `?groupBy=` / `?group=`](grouping.md) | `GROUP BY` via `COLLECT` : syntaxe URL (`?groupBy=` CSV + `?group=` JSON), vocabulaire `Arango::GROUP` / `Group`, les trois usages (distinct / comptage / agrégats), tri des groupes, spec brute `Arango::COLLECT`, whitelist `groupable` et sécurité. |
| 10 | [Expliquer et profiler les requêtes](explain-and-profiling.md) | `explain()` / `explainList()` typés → `ExplainResult` (règles d'optimiseur, **quels index la requête utilise réellement**) et profiling via l'option `'profile'` → `getProfile()` / `getStats()` → `ProfileResult` / `ExecutionStats` (scanné / filtré / temps / timings par phase). |

## Cartographie du dossier `db/`

Le dossier `db/` est volumineux — voici ce qui vit où, avec un pointeur vers la page qui le couvre.

| Sous-dossier | Quoi | Documenté dans |
|---|---|---|
| `db/helpers/` | Constructeurs d'expressions AQL (29 fichiers) | [`helpers.md`](helpers.md) |
| `db/binds/` | Formateurs et validateurs de bind variables (5 fichiers) | [`binds.md`](binds.md) |
| `db/operations/` | Constructeurs de clauses AQL — `FOR`, `FILTER`, `RETURN`, `INSERT`, `UPSERT`, `TRAVERSE`, … (21 fichiers) | [`../aql/aql-operations.md`](../aql/aql-operations.md) |
| `db/operators/` | Comparateurs et quantificateurs — `allEqual`, `anyIn`, `ternary`, … (42 fichiers) | [`../aql/aql-operators.md`](../aql/aql-operators.md) |
| `db/functions/` | Wrappers des fonctions AQL natives — chaînes, dates, nombres, tableaux, vérifications (~144 fichiers) | [`../aql/aql-functions-strings.md`](../aql/aql-functions-strings.md) et les quatre pages sœurs |
| `db/options/` | DTOs d'options — `QueryOptions`, `ForOptions`, `*IndexOptions`, etc. | [`../options.md`](../options.md) |
| `db/enums/` | Constantes — `ArangoConfig`, `AQL`, `Clause`, `Comparator`, `IndexType`, … | [`../enums.md`](../enums.md) |
| `db/traits/` | `CollectionManagementTrait` (CRUD de collections + index) | [`quickstart.md`](quickstart.md#gérer-les-collections) |
| `db/commands/` | Commande de smoke-test de la façade `arango:test:facade` | [`../testing.md`](../testing.md) |

## Voir aussi

- [Vue d'ensemble du client HTTP](../clients/README.md) — la couche sur laquelle la façade s'appuie.
- [Modèles `Documents` et `Edges`](../models.md) — la couche métier qui consomme `ArangoDB`.
- [Contrôleurs Slim](../controllers/README.md) — exposition HTTP du modèle.
- [Commandes Symfony Console](../commands.md) — exposition CLI du modèle.
- [Tips et pièges](../tips.md) — règles d'or pour la production.
