# Introduction

## Qu'est-ce qu'ArangoDB ?

[ArangoDB](https://arangodb.com) est une base de données **multi-modèle** : un seul moteur stocke à la fois des **documents** (JSON), des **graphes** (sommets et arêtes typés) et des paires **clé-valeur**, et expose ces trois modèles via un langage de requête unifié, [**AQL** (ArangoDB Query Language)](https://docs.arangodb.com/stable/aql/).

Les caractéristiques principales :

- Stockage sur le moteur **RocksDB** (transactions ACID multi-documents, persistance fiable).
- **AQL** : langage déclaratif inspiré de SQL, capable d'exprimer à la fois des requêtes documentaires (`FOR doc IN users FILTER ... RETURN doc`) et des traversées de graphe (`FOR v, e, p IN 1..3 OUTBOUND start GRAPH 'g' ...`).
- **Indexes variés** : *persistent*, *TTL* (expiration automatique), *geo* (requêtes géospatiales), *fulltext* (recherche textuelle), *vector* (recherche par similarité), *MDI* (multi-dimensionnel).
- **Cluster horizontal** : *sharding* des collections, réplication, *smart graphs* (édition Entreprise).
- **Foxx** : microservices JavaScript embarqués dans la base, exécutés par le moteur V8.

ArangoDB est utilisable en source ouverte (Community Edition) ou en édition commerciale.

## Pourquoi ArangoDB

Le principal apport de la techno se résume en une phrase : **on n'a plus besoin de jongler entre une base documentaire et une base graphe**. Les architectures classiques empilent souvent MongoDB (pour les documents) et Neo4j (pour le graphe), avec leurs deux pilotes, deux langages de requête, deux modèles cognitifs et la corvée de garder les deux univers cohérents par du code applicatif.

ArangoDB tient les deux promesses dans un seul moteur :

- **Un seul magasin de données** : un document peut être un sommet d'un graphe, l'arête référence directement les documents par leur identifiant natif (`_from`, `_to`).
- **Une seule requête** peut mélanger filtrage documentaire et traversée de graphe.
- **Transactions ACID** sur plusieurs documents et plusieurs collections, y compris en cluster.
- **Performance compétitive** sur les deux modèles, sans la dette d'une couche d'abstraction.
- **Schéma optionnel** : on peut commencer sans schéma puis introduire des validateurs JSON Schema progressivement.

Le compromis : la communauté est plus restreinte que celles de MongoDB ou PostgreSQL, et l'écosystème de clients officiels n'est pas aussi soigné — ce qui motive entre autres l'existence de `oihana/php-arango`.

## La philosophie d'`oihana/php-arango`

Le framework suit cinq principes qui se déclinent dans tout le code :

1. **Fonctions standalone composables, pas un ORM lourd.** La couche AQL est faite de centaines de petites fonctions PHP autoloadées (`aqlFor`, `aqlFilter`, `aqlReturn`, `aqlValue`, `aqlBind`, ...) qu'on assemble par composition. Pas d'objet géant `QueryBuilder` à apprendre — on lit l'AQL produit en regardant son code PHP.
2. **Zéro *magic string*.** Chaque clé d'option, chaque opérateur AQL, chaque type de filtre est exposé comme constante d'un enum typé (`AQL::COLLECTION`, `Operator::EQ`, `Filter::DATETIME`). Les chaînes brutes (`'-' . 'created'`, `'eq'`, ...) sont systématiquement remplacées par des helpers (`descKey()`, `Comparator::EQ`, ...). Cette discipline rend les renommages refactorables et la recherche IDE fiable.
3. **Composition de traits fins.** Un modèle `Documents` n'est pas une classe géante : il est construit par composition d'une cinquantaine de traits à responsabilité unique (`DocumentsGetTrait`, `DocumentsInsertTrait`, `FilterTrait`, `SortTrait`, `SearchTrait`, ...). On peut consommer un trait isolé pour une *use case* spécifique sans hériter de tout le reste.
4. ***Container-friendly*.** Tout est conçu pour vivre derrière un conteneur PSR-11 (PHP-DI, Symfony DI, ...). Les modèles, les contrôleurs et les commandes acceptent un `ContainerInterface` au constructeur et résolvent leurs dépendances (base ArangoDB, schémas, logger, signaux) par identifiant de service.
5. **Intégration Slim et Symfony Console *out of the box*.** `DocumentsController` produit en quelques lignes une route CRUD complète avec filtrage, pagination, tri, recherche, *skins* et projection. `DocumentsCommand` expose les mêmes opérations en CLI pour le *seeding*, le *harvest* et la maintenance.

## Pourquoi cette bibliothèque

Le besoin est né d'un constat simple : **l'écosystème PHP autour d'ArangoDB est en sous-équipement**.

- Le [driver officiel `triagens/ArangoDb`](https://github.com/arangodb/arangodb-php) n'a plus reçu de mise à jour majeure depuis plusieurs années et accuse une dette importante (pas d'enums typés, signature PHP < 8, pas d'AQL builder ergonomique, dépendances vieillissantes).
- Il n'existe pas, à notre connaissance, de *query builder* AQL composable côté PHP. Les utilisateurs écrivent l'AQL à la main par `sprintf`, avec les risques d'injection et la fragilité que cela implique.
- Les couches d'intégration usuelles (DI PSR-11, Slim, Symfony Console) ne sont pas fournies — chaque projet refait sa colle.
- Les besoins transverses (projection par *skin*, *edges* et *joins* avec gating par permission, adaptateur Casbin, *signals* pour la cascade des relations) sont absents et chacun les ré-implémente.

`oihana/php-arango` adresse les quatre points. Le dossier [`client/`](../../src/oihana/arango/client/) est lui-même un fork du driver officiel, conservé tel quel et juste patché pour fonctionner en PHP 8.4 — il est appelé à être remplacé par un client moderne réécrit aux standards de la bibliothèque.

## Public et prérequis

Cette documentation suppose que le lecteur :

- maîtrise PHP 8.4 ou supérieur (l'utilisation systématique d'enums, de *readonly properties* et de *first-class callable syntax* est centrale dans le code) ;
- a une compréhension basique d'ArangoDB — la notion de **collection** (document ou edge), la structure d'un document (`_key`, `_id`, `_rev`), et la lecture d'une requête AQL simple ;
- est à l'aise avec un conteneur PSR-11 (PHP-DI utilisé dans les exemples, mais le code n'y est pas couplé).

La connaissance de Slim ou Symfony Console n'est pas requise : ces deux intégrations sont des modules indépendants, on peut consommer la couche AQL et les modèles sans toucher au reste.

## Positionnement vis-à-vis des alternatives PHP

| Solution | Statut | AQL builder | Intégration DI / Slim / CLI | Projection / Skins | Casbin |
|---|---|---|---|---|---|
| [`triagens/ArangoDb`](https://github.com/arangodb/arangodb-php) (officiel) | maintenance minimale | non | non | non | non |
| Drivers communautaires divers | sporadiques | non | non | non | non |
| `oihana/php-arango` | actif | oui | oui | oui (`Field::SKINS`, `AQL::SKIN_FIELDS`, `AQL::REQUIRES`) | oui (`ArangoCasbinAdapter`) |

Le tableau n'est pas une revue exhaustive mais résume le constat qui motive le projet : aucune alternative PHP ne couvre l'ensemble des besoins, et beaucoup d'équipes finissent par écrire leur propre couche.

## Aller plus loin

- [Dépendances](dependencies.md) — packages `oihana/php-*` requis et snippet `composer require`.
- [Glossaire](glossary.md) — termes clés (*bind variable*, *skin*, *facet*, *traversal*, *edge*).
- [Quickstart `ArangoDB`](quickstart.md) — instancier le client et exécuter une première requête.
- [Documentation officielle ArangoDB](https://docs.arangodb.com/stable/) — référence pour AQL, indexes, cluster.
