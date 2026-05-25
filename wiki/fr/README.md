# oihana/arango — Framework ArangoDB pour PHP

![Langue](https://img.shields.io/badge/langue-Français-blue)

`oihana/arango` est un framework PHP qui industrialise le travail avec [ArangoDB](https://arangodb.com) : client natif, *builder* AQL composable, modèles haut-niveau (`Documents`, `Edges`) par composition de traits, contrôleurs Slim CRUD, adaptateur Casbin RBAC et commandes Symfony Console.

> Cette documentation est **en construction active**. Le sommaire ci-dessous reflète l'avancement réel : les pages marquées *prévu* sont planifiées mais pas encore rédigées. Voir la section [Statut du chantier](#statut-du-chantier).

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
    AQL::DATABASE   => Databases::ARANGO    ,
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

### Fondations

- [Introduction](introduction.md) — *disponible* — qu'est-ce qu'ArangoDB, l'intérêt de la technologie, la philosophie `oihana`, et pourquoi cette bibliothèque existe.
- [Dépendances](dependencies.md) — *disponible* — packages `oihana/php-*` requis, mapping *namespace* → package, snippet `composer require` minimal pour un usage autonome.
- [Glossaire](glossary.md) — *disponible* — termes clés du framework : *bind variable*, *document reference*, *skin*, *facet*, *traversal*, *edge*.

### Démarrer

- [Quickstart `ArangoDB`](quickstart.md) — *disponible* — instancier le client, exécuter une requête AQL brute, traits de base (`ArangoTrait`).
- [Helpers AQL `db/helpers/`](db-helpers.md) — *disponible* — `aqlExpression`, `aqlDocument`, `aqlValue`, *field builders* et compagnie.
- [Bind variables `db/binds/`](db-binds.md) — *disponible* — `aqlBind`, validation et formatage des valeurs injectées.

### Construire des requêtes AQL

- [Construire une requête AQL pas à pas](aql/aql-building-queries.md) — *disponible* — enchaînement `FOR → FILTER → SORT → LIMIT → RETURN`, avec diagramme et exemples.
- [Opérations AQL `db/operations/`](aql/aql-operations.md) — *disponible* — les 21 opérations natives (`aqlFor`, `aqlFilter`, `aqlReturn`, `aqlInsert`, `aqlTraversal`, ...).
- [Opérateurs `db/operators/`](aql/aql-operators.md) — *disponible* — les 42 comparateurs (logiques, quantifiés, *range*, *ternary*).
- [Fonctions de chaînes](aql/aql-functions-strings.md) — *disponible* — 37 fonctions AQL côté chaînes.
- [Fonctions de dates](aql/aql-functions-dates.md) — *disponible* — 30 fonctions AQL côté dates.
- [Fonctions numériques](aql/aql-functions-numerics.md) — *disponible* — 31 fonctions AQL côté nombres.
- [Fonctions de tableaux](aql/aql-functions-arrays.md) — *disponible* — 19 fonctions AQL côté tableaux.
- [Fonctions documents et vérifications](aql/aql-functions-checks.md) — *disponible* — 28 fonctions : *type-checks*, *casts*, opérations sur documents, informations de la base.

### Options et configuration

- [Référence des options AQL](options.md) — *disponible* — `QueryOptions`, `InsertOptions`, `UpdateOptions`, `TraversalOptions`, options d'index, sérialisation.
- [Référence des enums](enums.md) — *disponible* — `Operator`, `Comparator`, `Clause`, `Logic`, `Node`, `Traversal`, `IndexType`, `DateUnit`.

### Couche métier

- [Modèles `Documents` et `Edges`](models.md) — *disponible* — architecture des traits, catalogue des clés `AQL::*`, méthodes CRUD, hooks de cycle de vie, cascade via *signals*.
- [Projection des edges et joins](edges-joins-projection.md) — *disponible* — `Field::SKINS`, `AQL::SKIN`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`, pattern `CapabilityAuthorizerTrait`.
- [Filtres HTTP `?filter=`](filter.md) — *disponible* — syntaxe `?filter=`, opérateurs (`eq`, `ne`, `like`, `in`, ...), transformations `alt`, chaînage, `FilterType::*`.
- [Filtrage interne — `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md) — *disponible* — conditions serveur-only, `FilterType::VIRTUAL`, règle de choix URL vs interne.
- [Contrôleurs Slim](controllers/README.md) — *disponible* — `DocumentsController`, `EdgesController`, `PropertyController`, injection DI, hooks `beforeModelCall` / `afterModelCall`, `InjectFilterTrait`.
  - [Payloads](controllers/payloads.md) — extraction du *body* HTTP, catalogue `AQLType`, validation i18n pré-extraction, `COMPRESS` sur PATCH.
  - [Rules](controllers/rules.md) — validation après payload, `Arango::RULES` + `CUSTOM_RULES`, helpers `rules() / min() / max() / between()`, catalogue vendor + projet, format 422.
  - [Skins](controllers/skins.md) — projection en sortie, catalogue des 12 *skins* canoniques, `Skin::INTERNAL` strictement serveur.
  - [Capabilities](controllers/capabilities.md) — gating fin sur la **valeur** d'un paramètre ou d'un champ, 7 traits Capability, pattern *authorizer* injecté vers le modèle.
- [Commandes Symfony Console](commands.md) — *disponible* — `DocumentsCommand` et ses actions (`insert`, `upsert`, `harvest`, `list`, `count`, `get`, `update`, `replace`, `delete`, `truncate`).
- [Indexes et gestion des collections](indexes.md) — *disponible* — `CollectionManagementTrait`, types d'index (`Persistent`, `TTL`, `Geo`, `MDI`, `Vector`).
- [Smoke tests live](testing.md) — *disponible* — `bun arango:test:clients` (lib bas-niveau) et `bun arango:test:facade` (façade `ArangoDB`), exécutés contre une base de données éphémère pour ne jamais toucher la prod.

### Modules spécialisés

- [Adaptateur Casbin RBAC](casbin.md) — *disponible* — `ArangoCasbinAdapter`, *batch* et *filtered* adapters, synchronisation edges → *policies*, pièges connus.
- [Client ArangoDB *legacy*](client.md) — *disponible* — fork du driver officiel, *caveat* PHP 8.4, *roadmap* de réécriture moderne.
- [Helpers racine `oihana\arango\helpers`](helpers.md) — *disponible* — grammaire de tri, parsing d'identifiants, encodage de révisions `_rev`.

### Transverse

- [Tips et pièges](tips.md) — *disponible* — règles d'or à respecter ; page enrichie au fil des incidents.

## Statut du chantier

| Phase | Description | État |
|---|---|---|
| 0 | Fondations — introduction, dépendances, glossaire | *disponible* |
| 1 | Démarrer — quickstart, `db/helpers`, `db/binds` | *disponible* |
| 2 | Cœur AQL — *operations*, *operators*, *functions* | *disponible* |
| 3 | Options et enums | *disponible* |
| 4 | Couche métier — modèles, contrôleurs, commandes | *disponible* |
| 5 | Modules spécialisés — Casbin, client *legacy* | *disponible* |

## Code source

Le code du framework vit sous [`api/src/oihana/arango/`](../../../api/src/oihana/arango/).

> `oihana/arango` est destiné à être extrait en bibliothèque open-source autonome. Sa documentation est aujourd'hui hébergée dans `oihana-odbc-php` et déménagera lors de l'extraction.

## Voir aussi

- [Tips d'authentification](../auth/tips.md) — conventions Casbin `safeSubject` consommées par `ArangoCasbinAdapter`.
- [`CLAUDE.md`](../../../CLAUDE.md) — conventions générales du projet.
