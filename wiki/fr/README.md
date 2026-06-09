# oihana/php-arango — Framework ArangoDB pour PHP

![Langue](https://img.shields.io/badge/langue-Français-blue)

`oihana/php-arango` est une bibliothèque PHP qui industrialise le travail avec [ArangoDB](https://arangodb.com) : client HTTP natif (Guzzle), *builder* AQL composable, modèles haut-niveau (`Documents`, `Edges`) par composition de traits, contrôleurs Slim CRUD, adaptateur Casbin RBAC et commandes Symfony Console.

![Oihana PHP Arango](https://raw.githubusercontent.com/BcommeBois/oihana-php-arango/main/assets/images/oihana-php-arango-logo-inline-512x160.png)

## À qui s'adresse cette documentation

Aux développeurs PHP qui construisent une API ou un service au-dessus d'ArangoDB et qui veulent :

- éviter d'écrire de l'AQL à la main via du `sprintf` — helpers fonctionnels composables, zéro *magic string* ;
- exposer rapidement des routes HTTP CRUD complètes (filtrage, pagination, recherche, projection, *skins*) sans réinventer la couche modèle pour chaque ressource ;
- intégrer ArangoDB dans un conteneur PHP-DI, une application Slim et des commandes Symfony Console avec une API cohérente de bout en bout.

## Démarrage rapide

```php
use oihana\arango\enums\AQL      ;
use oihana\arango\enums\Arango   ;
use oihana\arango\enums\Filter   ;
use oihana\arango\models\Documents ;

$users = new Documents( $container ,
[
    AQL::COLLECTION => 'users'              ,
    AQL::DATABASE   => 'default'            ,
    AQL::SCHEMA     => User::class          ,
    AQL::FIELDS     =>
    [
        Prop::_KEY  => Filter::DEFAULT ,
        Prop::EMAIL => Filter::DEFAULT ,
    ] ,
]) ;

$list  = $users->list( [ Arango::LIMIT => 50    ] ) ;
$first = $users->get ( [ Arango::ID    => 'abc' ] ) ;
```

Pour le détail (instanciation du client `ArangoDB`, options de requête, projection, edges, contrôleurs Slim, commandes CLI), voir le sommaire ci-dessous.

## Sommaire

### Démarrer — [`getting-started/`](getting-started/)

- [Introduction](getting-started/introduction.md) — qu'est-ce qu'ArangoDB, l'intérêt de la technologie, la philosophie `oihana`, et pourquoi cette bibliothèque existe.
- [Dépendances](getting-started/dependencies.md) — packages `oihana/php-*` requis, mapping *namespace* → package, snippet `composer require` minimal pour un usage autonome.
- [Glossaire](getting-started/glossary.md) — termes clés du framework : *bind variable*, *document reference*, *skin*, *facet*, *traversal*, *edge*.

### Client HTTP — [`clients/`](clients/)

- [Vue d'ensemble du client HTTP](clients/README.md) — `ArangoClient`, `Database`, architecture, quand utiliser le client vs la façade.
- [Démarrer](clients/getting-started.md) — votre premier `ArangoClient`, votre premier document, en sept petites étapes. **Nouveau sur ArangoDB ? Commencez ici.**
- [Collections et documents](clients/documents.md) — CRUD complet, opérations en batch, import JSON-Lines en masse, edges.
- [Requêtes AQL et Cursors](clients/aql.md) — helper `aql()`, `AqlQuery`, `Cursor` paresseux avec `map` / `forEach` / `reduce` / `flatMap`.
- [Graphes](clients/graphs.md) — graphes *gharial* nommés, `EdgeDefinition`, insertions d'arêtes type-safe, traversal AQL.
- [Transactions](clients/transactions.md) — transactions streaming, `withTransaction()` auto-commit/abort.
- [Indexes](clients/indexes.md) — sept classes d'index typées (`PersistentIndex`, `GeoIndex`, `TtlIndex`, `MDIIndex`, `VectorIndex`, `InvertedIndex`, `FulltextIndex`).
- [ArangoSearch](clients/arangosearch.md) — analyzers et views pour la recherche full-text.
- [Résilience et authentification](clients/resilience.md) — modes d'auth et auto-refresh sur 401, politique de retry, failover multi-hôtes, timeouts.

### AQL — [`aql/`](aql/)

- [Construire une requête AQL pas à pas](aql/aql-building-queries.md) — enchaînement `FOR → FILTER → SORT → LIMIT → RETURN`, avec diagramme et exemples.
- [Opérations AQL `db/operations/`](aql/aql-operations.md) — les 21 opérations natives (`aqlFor`, `aqlFilter`, `aqlReturn`, `aqlInsert`, `aqlTraversal`, ...).
- [Opérateurs `db/operators/`](aql/aql-operators.md) — les 42 comparateurs (logiques, quantifiés, *range*, *ternary*).
- [Fonctions de chaînes](aql/aql-functions-strings.md) — 37 fonctions AQL côté chaînes.
- [Fonctions de dates](aql/aql-functions-dates.md) — 30 fonctions AQL côté dates.
- [Fonctions numériques](aql/aql-functions-numerics.md) — 31 fonctions AQL côté nombres.
- [Fonctions de tableaux](aql/aql-functions-arrays.md) — 19 fonctions AQL côté tableaux.
- [Fonctions géospatiales](aql/aql-functions-geo.md) — 17 fonctions AQL de géolocalisation (points, polygones, distances, prédicats).
- [Fonctions documents et vérifications](aql/aql-functions-checks.md) — 45 fonctions : *type-checks*, *casts*, opérations sur documents (l'ensemble complet de `documents/`), informations de la base.

### Couche db — [`db/`](db/)

- [Vue d'ensemble de la couche db](db/README.md) — la façade `ArangoDB`, quand l'utiliser vs le client HTTP, cartographie du dossier `db/`.
- [Quickstart `ArangoDB`](db/quickstart.md) — instancier la façade, configurer (clés `ArangoConfig`, DI), exécuter AQL, hydrater les résultats, gérer collections et indexes.
- [Helpers AQL `db/helpers/`](db/helpers.md) — `aqlExpression`, `aqlDocument`, `aqlValue`, *field builders* et compagnie.
- [Bind variables `db/binds/`](db/binds.md) — `aqlBind`, validation et formatage des valeurs injectées.
- [Filtres HTTP `?filter=`](db/filter.md) — syntaxe `?filter=`, opérateurs (`eq`, `ne`, `like`, `in`, ...), transformations `alt`, chaînage, `FilterType::*`.
- [Filtrage interne — `AQL::CONDITIONS` + `AQL::BINDS`](db/filter-internal.md) — conditions serveur-only, `FilterType::VIRTUAL`, règle de choix URL vs interne.

### Options et configuration

- [Référence des options AQL](options.md) — `QueryOptions`, `InsertOptions`, `UpdateOptions`, `TraversalOptions`, options d'index, sérialisation.
- [Référence des enums](enums.md) — `Operator`, `Comparator`, `Clause`, `Logic`, `Node`, `Traversal`, `IndexType`, `DateUnit`.

### Couche métier

- [Modèles `Documents` et `Edges`](models.md) — architecture des traits, catalogue des clés `AQL::*`, méthodes CRUD, hooks de cycle de vie, cascade via *signals*.
- [Projection des edges et joins](edges-joins-projection.md) — `Field::SKINS`, `AQL::SKIN`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`, pattern `CapabilityAuthorizerTrait`.
- [Indexes et gestion des collections](indexes.md) — `CollectionManagementTrait`, types d'index (`Persistent`, `TTL`, `Geo`, `MDI`, `Vector`).

### Contrôleurs Slim — [`controllers/`](controllers/)

- [Vue d'ensemble des contrôleurs](controllers/README.md) — `DocumentsController`, `EdgesController`, `PropertyController`, injection DI, hooks `beforeModelCall` / `afterModelCall`, `InjectFilterTrait`.
- [Payloads](controllers/payloads.md) — extraction du *body* HTTP, catalogue `AQLType`, validation i18n pré-extraction, `COMPRESS` sur PATCH.
- [Rules](controllers/rules.md) — validation après payload, `Arango::RULES` + `CUSTOM_RULES`, helpers `rules() / min() / max() / between()`, catalogue vendor + projet, format 422.
- [Skins](controllers/skins.md) — projection en sortie, catalogue des 12 *skins* canoniques, `Skin::INTERNAL` strictement serveur.
- [Capabilities](controllers/capabilities.md) — gating fin sur la **valeur** d'un paramètre ou d'un champ, 7 traits Capability, pattern *authorizer* injecté vers le modèle.

### CLI et tests

- [Commandes Symfony Console](commands.md) — `DocumentsCommand` et ses actions (`insert`, `upsert`, `harvest`, `list`, `count`, `get`, `update`, `replace`, `delete`, `truncate`).
- [Smoke tests live](testing.md) — `./bin/console.php arango:test:clients` (lib bas-niveau) et `./bin/console.php arango:test:facade` (façade `ArangoDB`), exécutés contre une base de données éphémère pour ne jamais toucher la prod.

### Modules spécialisés

- [Adaptateur Casbin RBAC](casbin.md) — `ArangoCasbinAdapter`, *batch* et *filtered* adapters, synchronisation edges → *policies*, pièges connus.
- [Helpers racine `oihana\arango\helpers`](helpers.md) — grammaire de tri, parsing d'identifiants, encodage de révisions `_rev`.

### Transverse

- [Tips et pièges](tips.md) — règles d'or à respecter ; page enrichie au fil des incidents.

## Code source

Le code du framework vit sous [`src/oihana/arango/`](../../src/oihana/arango/).

## Voir aussi

- [Packagist `oihana/php-arango`](https://packagist.org/packages/oihana/php-arango) — page du package.
- [Tips & best practices](tips.md) — conventions et pièges courants.
