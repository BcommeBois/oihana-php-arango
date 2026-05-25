# Glossaire

Cette page définit les termes clés rencontrés dans la documentation du framework. Elle ne cherche pas à remplacer la [documentation officielle ArangoDB](https://docs.arangodb.com/stable/) — chaque entrée renvoie au concept de référence quand cela s'applique — mais à fixer un vocabulaire commun.

## Vocabulaire ArangoDB

### ACID

Acronyme pour Atomicité, Cohérence, Isolation, Durabilité. ArangoDB garantit ces quatre propriétés sur ses transactions, y compris quand elles touchent plusieurs documents et plusieurs collections.

### AQL

*ArangoDB Query Language*. Langage déclaratif inspiré de SQL qui exprime à la fois des requêtes documentaires (`FOR doc IN users FILTER ... RETURN doc`) et des traversées de graphe (`FOR v, e IN 1..3 OUTBOUND start GRAPH 'g' ...`). Toute la couche `db/` du framework produit de l'AQL.

### Bind variable

Variable injectée dans une requête AQL sous la forme `@var` (valeur) ou `@@coll` (nom de collection), avec sa valeur fournie séparément en `bindVars`. Garantit l'absence d'injection AQL et permet la réutilisation du *query cache*. Le helper `aqlBind()` (sous `db/binds/`) automatise la déclaration sûre.

### Collection

Conteneur de documents partageant la même nature. Deux types : **collection de documents** (par défaut) et **collection d'arêtes** (*edge collection*). Une collection vit dans une `database`.

### Cursor

Itérateur côté client sur le résultat d'une requête AQL. ArangoDB stream les résultats par lots — le *cursor* expose les méthodes pour avancer (`hasNext`, `next`, `fetch`).

### Database

Espace de noms de premier niveau dans un serveur ArangoDB. Une *database* contient des collections, des graphes, des vues, des index, des fonctions AQL définies par l'utilisateur. Les requêtes AQL sont scopées à une *database*.

### Document

Unité de stockage : un objet JSON identifié par `_key`, `_id` et `_rev`. ArangoDB est *schemaless* par défaut ; un validateur JSON Schema peut être attaché à une collection si on veut imposer une forme.

### Edge

Document spécial stocké dans une **collection d'arêtes**, qui contient obligatoirement les champs `_from` et `_to`. Chacun référence l'`_id` d'un document (le sommet de l'arête). Permet de construire des graphes natifs.

### Foxx

Microservices JavaScript embarqués dans la base, exécutés par le moteur V8 d'ArangoDB. Permet d'exposer une API HTTP directement depuis le serveur sans couche applicative externe. `oihana/arango` ne s'appuie pas sur Foxx.

### Graph

Définition logique qui regroupe une ou plusieurs collections d'arêtes et leurs collections de sommets, et permet les traversées AQL via `GRAPH 'nom'`. Sur petite échelle, les traversées *ad hoc* sur collections d'arêtes anonymes suffisent souvent.

### Index

Structure d'accélération attachée à une collection. Types principaux : `persistent` (lookups et contraintes d'unicité), `ttl` (expiration automatique d'un document), `geo` (requêtes géospatiales), `fulltext` (déprécié au profit des vues *ArangoSearch*), `vector` (recherche par similarité, ArangoDB 3.12+), `mdi` (multidimensionnel). Voir [`indexes.md`](indexes.md).

### MVCC

*Multi-Version Concurrency Control*. Mécanisme de concurrence d'ArangoDB : chaque écriture sur un document crée une nouvelle révision (`_rev`), les lectures concurrentes voient une *snapshot* cohérente. Le champ `_rev` permet la détection optimiste des conflits d'écriture.

### RocksDB

Moteur de stockage par défaut d'ArangoDB depuis la version 3.4. Persistant sur disque, transactions ACID, compression. Maintenu par Meta.

### Traversal

Parcours de graphe en AQL via `FOR v, e, p IN min..max <DIRECTION> <startVertex> GRAPH 'g'`. La `DIRECTION` est `OUTBOUND`, `INBOUND` ou `ANY`. `v` est le sommet visité, `e` l'arête empruntée, `p` le chemin complet (utile pour filtrer sur un chemin).

### Vertex

Sommet d'un graphe. Dans ArangoDB, un *vertex* est simplement un document d'une collection (par opposition à une *edge* qui vit dans une collection d'arêtes). Le terme est utilisé dans le contexte des traversées.

### View

Vue logique au-dessus d'une ou plusieurs collections, principalement utilisée pour la recherche textuelle avancée via *ArangoSearch* (analyseurs linguistiques, BM25, *facets*).

### `_from` / `_to`

Champs obligatoires d'une *edge*. `_from` contient l'`_id` du document source, `_to` l'`_id` du document cible. La présence des deux est validée par ArangoDB à l'insertion dans une collection d'arêtes.

### `_id`

Identifiant complet d'un document, de la forme `<collection>/<_key>` (par exemple `users/42`). Utilisé pour les références entre documents (notamment `_from` et `_to` sur les *edges*).

### `_key`

Clé primaire interne d'un document dans sa collection. Soit fournie à l'insertion, soit générée automatiquement par ArangoDB. Unique par collection mais pas par *database*.

### `_rev`

Identifiant de révision MVCC d'un document. Change à chaque écriture. Permet la détection optimiste des conflits (`If-Match` HTTP, ou clause `OPTIONS { ignoreRevs: false }` en AQL).

## Vocabulaire `oihana/arango`

### Alteration (`alt`)

Fonction de transformation appliquée à la valeur d'un champ **avant** comparaison dans un filtre. Exposée côté HTTP via `?filter={"key":"name","val":"john","alt":"lower"}`, ce qui produit `FILTER LOWER(doc.name) == "john"`. Voir [`filter.md`](filter.md).

### Authorizer

*Callable* de la forme `Closure(string $subject): bool` injecté dans `$init[Arango::AUTHORIZER]`. Consulté par le framework pour décider si une projection contrôlée par `AQL::REQUIRES` doit être incluse. Reste agnostique du système d'autorisation utilisé (Casbin, OPA, contrôle maison).

### Capability

Permission fine portée par une valeur de paramètre URL (par exemple `?skin=full`) ou par une clé de champ, plutôt que par un verbe HTTP. Le framework expose le pattern `Capability::PARAMS` pour rattacher une permission Casbin à une valeur. Voir [`CapabilityGuardTrait`](../../../api/src/oihana/api/controllers/traits/CapabilityGuardTrait.php).

### Cascade

Propagation automatique d'une suppression aux *edges* liés à un document. Émise par `Documents::delete()` via un signal `afterDelete` que les traits `EdgesFromTrait` et `EdgesToTrait` interceptent. Voir aussi [Signal](#signal).

### Composition de traits

Pattern d'architecture central du framework : les classes `Documents` et `Edges` ne contiennent presque pas de code propre — elles agrègent une cinquantaine de traits à responsabilité unique (`DocumentsGetTrait`, `FilterTrait`, `SortTrait`, ...). Permet de consommer un sous-ensemble du framework sans hériter du reste.

### Conteneur (DI)

Conteneur d'injection de dépendances conforme à PSR-11 (`Psr\Container\ContainerInterface`). Le framework accepte un conteneur au constructeur des modèles, contrôleurs et commandes, et résout ses dépendances (connexion ArangoDB, schémas, logger, signaux) par identifiant de service. PHP-DI est utilisé dans les exemples mais le code n'y est pas couplé.

### Definition

Fichier PHP qui retourne un tableau de définitions DI consommé par le conteneur. Sous `oihana-odbc-php`, les *definitions* `oihana/arango` vivent sous `api/definitions/@arango/`. Convention : un fichier par modèle, un fichier par contrôleur.

### Facet

Agrégation par valeur exposée à l'API HTTP : pour un champ donné, retourne la liste des valeurs distinctes et leur compte. Déclarée via `AQL::FACETS` sur un modèle `Documents`. Utile pour les UI de filtrage à cases à cocher.

### Field

Descripteur d'un champ d'un modèle `Documents`. Configurable via les marqueurs `Field::FILTER` (type de filtre), `Field::SKINS` (liste des *skins* qui activent le champ), `Field::FIELDS` (sous-projection si le champ est un document imbriqué).

### Filter

Descripteur d'un champ filtrable depuis l'URL. Déclaré dans `AQL::FILTERS` avec un `FilterType::*`. Le client envoie `?filter={"key":"...","val":"...","op":"...","alt":"..."}` ; le framework produit le `FILTER` AQL correspondant.

### `FilterType::VIRTUAL`

Type de filtre spécial : la clé est acceptée depuis l'URL, mais le framework **n'émet aucune clause AQL**. Utilisé quand un contrôleur veut accepter un filtre sans exposer le champ sous-jacent (champ sensible, champ calculé). Le contrôleur injecte alors la vraie condition via `AQL::CONDITIONS` + `AQL::BINDS`.

### Modèle (`Documents`, `Edges`)

Classe haut-niveau qui représente une collection ArangoDB et expose les opérations CRUD + listage + recherche + projection. `Documents` pour une collection de documents, `Edges` pour une collection d'arêtes. Configurée par un tableau de clés `AQL::*` au constructeur.

### Projection

Sélection des champs renvoyés par le framework pour un document donné, dépendante du *skin* courant et des marqueurs `Field::SKINS` posés sur les champs. Voir [`edges-joins-projection.md`](edges-joins-projection.md).

### Signal

Événement applicatif émis par un modèle (par exemple `afterDelete`) et propagé via le bus `oihana/php-signals`. Permet le découplage entre l'action principale (supprimer un document) et ses effets de bord (purger les *edges*).

### Skin

Projection nommée d'un document, transmise via le paramètre URL `?skin=`. Valeurs canoniques : `default` (liste légère), `full` (fiche complète), `main` (skin minimal pour couper les cycles INBOUND), `internal` (projection serveur uniquement, jamais exposée HTTP — voir [`tips.md`](tips.md)). Les contrôleurs ajoutent leurs *skins* métier (par exemple `image`, `offers`).

## Voir aussi

- [Introduction](introduction.md) — vue d'ensemble du framework.
- [Dépendances](dependencies.md) — packages requis.
- [Documentation officielle ArangoDB](https://docs.arangodb.com/stable/concepts/data-structure/) — référence canonique pour les concepts ArangoDB.
