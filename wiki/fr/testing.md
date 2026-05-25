# Commandes de smoke tests live

![Langue](https://img.shields.io/badge/langue-Français-blue)

Deux commandes Symfony Console permettent de valider la stack ArangoDB de bout en bout, contre un serveur réel, **sans jamais toucher à la base de production**.

| Commande | Cible testée |
|---|---|
| `./bin/console.php arango:test:clients` | Lib bas-niveau `oihana\arango\clients\` (`ArangoClient`, `Database`, `Collection`, `EdgeCollection`, `Cursor`, `AqlQuery`, exceptions, indexes typés). |
| `./bin/console.php arango:test:facade` | Façade haut-niveau `oihana\arango\db\ArangoDB` (et son `CollectionManagementTrait`) : les 19 méthodes publiques que les modèles et contrôleurs consomment. |

Les deux commandes :

1. créent une **base de données éphémère** au démarrage (`arango_clients_test_<random>` ou `arangodb_facade_test_<random>`) ;
2. exécutent toutes leurs assertions sur cette base ;
3. droppent la base au cleanup (bloc `finally`, même en cas d'exception inattendue).

L'option `--no-cleanup` permet de garder la base autour pour inspection post-mortem.

## Quand les utiliser

- Après une modification de la lib `clients/` → `./bin/console.php arango:test:clients`.
- Après une modification de la façade `db/ArangoDB` ou du trait `CollectionManagementTrait` → `./bin/console.php arango:test:facade`.
- Avant un commit qui touche au cursor, aux options de query, à la grammaire d'index ou aux exceptions → les deux.
- Sur un nouvel environnement (poste développeur, CI, préprod) pour valider la configuration `[arango]` du `config.toml`.

## `arango:test:clients`

### Périmètre — 8 *steps*, 49 assertions

| Step | Surface testée |
|---:|---|
| 1 | Connexion serveur : `version()`, `listDatabases()` |
| 2 | Database : `exists()`, `collections()` vide |
| 3 | Collection lifecycle : `create()`, `properties()`, `rename()`, `drop()` + `exists()` à chaque étape |
| 4 | Documents CRUD : `insert/returnNew`, `document`, `documentExists`, `count`, `update/returnNew` (PATCH), `replace` (PUT), `remove/returnOld`, `truncate` |
| 5 | Edge collection : `inEdges()`, `outEdges()`, `edges()` (implémentées via AQL) |
| 6 | AQL + Cursor : query simple, *lazy multi-batch* avec `batchSize`, `count: true` côté root, `fullCount: true` côté `options.{...}` |
| 7 | Indexes : `PersistentIndex` (unique sparse), `TtlIndex`, `dropIndex(fullHandle)`, `index()` plein handle et clé nue |
| 8 | Mapping d'erreurs : `HttpException` sur 404 *document-not-found* (`errorNum: 1202`), `ConflictException` sur 409 *unique-constraint* (`errorNum: 1210`) |

### Usage

```shell
# Tous les steps
./bin/console.php arango:test:clients

# Sélection
./bin/console.php arango:test:clients --step=1-3        # steps 1 à 3
./bin/console.php arango:test:clients --step=6          # juste le step 6
./bin/console.php arango:test:clients --step=1,3,5      # liste explicite

# Inspection
./bin/console.php arango:test:clients --no-cleanup      # garde la base éphémère
./bin/console.php arango:test:clients --endpoint=tcp://127.0.0.1:8529 --user=root --password=…
```

Code : [src/oihana/arango/clients/commands/tests/ArangoTestClientsCommand.php](../../src/oihana/arango/clients/commands/tests/ArangoTestClientsCommand.php).

## `arango:test:facade`

### Périmètre — 7 *steps*, 36 assertions

| Step | Surface testée |
|---:|---|
| 1 | Collection lifecycle (`CollectionManagementTrait`) : `collectionCreate / Exists / Rename / Truncate / Drop` |
| 2 | Index ops : `createIndex(IndexOptions)` (DTO *legacy*), `createIndex(array)` (corps brut), `getIndex(collection, fullHandle)`, `getIndexes(name)`, `dropIndex(fullHandle)` |
| 3 | Query (`ArangoDB`) : `prepare`, `execute`, `getCursor`, `getDocuments`, **`count($cursor)` via `count: true`** (preuve du dispatch des *root options*), **multi-`execute()`** (un deuxième `execute()` doit bien remplacer le cursor précédent) |
| 4 | Single result : `getFirstResult`, `getObject`, `getResult`, **`INSERT … RETURN NEW`** (aller-retour explicite) |
| 5 | Streaming : `streamDocuments()` (Generator) |
| 6 | **Nesting `fullCount`** : `getFoundRows()` + `getExtra()` avec `fullCount: true` passé à plat dans `prepare()`. La cible de régression critique de Lot 6.1 — si le *nesting* sous `options.{...}` est cassé, `getFoundRows()` retourne silencieusement `0`. |
| 7 | **Exception wrapping** : une AQL invalide doit lever une `oihana\arango\client\Exception` (*legacy*) avec la nouvelle exception `oihana\arango\clients\exceptions\ArangoException` chaînée via `$previous`. C'est ce qui permet aux ~50 sites de *catch* du projet de continuer à matcher pendant la transition. |

### Usage

```shell
# Tous les steps
./bin/console.php arango:test:facade

# Sélection
./bin/console.php arango:test:facade --step=1-3
./bin/console.php arango:test:facade --step=6
./bin/console.php arango:test:facade --step=1,3,5

# Inspection
./bin/console.php arango:test:facade --no-cleanup
./bin/console.php arango:test:facade --endpoint=tcp://127.0.0.1:8529 --user=root --password=…
```

Code : [src/oihana/arango/db/commands/tests/ArangoFacadeTestCommand.php](../../src/oihana/arango/db/commands/tests/ArangoFacadeTestCommand.php).

## Options communes

Les deux commandes partagent le trait [`ArangoClientTestTrait`](../../src/oihana/arango/clients/commands/tests/traits/ArangoClientTestTrait.php), qui définit les options de connexion (avec *override* CLI) :

| Option | Effet | Default |
|---|---|---|
| `--endpoint <url>` | Endpoint ArangoDB | `[arango].endpoint` du `config.toml` |
| `--user <name>` | Utilisateur | `[arango].user` |
| `--password <pw>` | Mot de passe | `[arango].password` |
| `--database <db>` | DB de *fallback* (les commandes utilisent leur propre éphémère de toute façon) | `[arango].database` |
| `--step <range>` | Sous-ensemble de steps (`1-3`, `1,3,5`, `all`) | `all` |
| `--no-cleanup` | Garder la base éphémère après le *run* | (drop) |

## Garantie « jamais en prod »

Le contrat de sécurité est triple :

1. Les deux commandes **calculent leur propre nom de base** au démarrage avec un suffixe aléatoire (`bin2hex(random_bytes(4))`).
2. Toutes les opérations (CRUD, indexes, AQL) ciblent **uniquement cette base éphémère**.
3. Le cleanup est en `finally` block ; il s'exécute même si une assertion casse en cours de route ou si une exception inattendue remonte.

Le `config.toml` du projet sert uniquement à fournir le **serveur** et les **credentials** ; le nom de base configuré n'est jamais ciblé.

## Pour les contributeurs

Les deux commandes sont **wirées via PHP-DI** dans la bibliothèque, prêtes à être exécutées avec `bin/console.php` :

- Bootstrap CLI : [`bin/console.php`](../../bin/console.php) — entry point Symfony Console.
- Définitions DI : [`definitions/commands.php`](../../definitions/commands.php) (registre + factories) + [`definitions/config.php`](../../definitions/config.php) (clé `arango.config`) + [`definitions/application.php`](../../definitions/application.php) (`Application::class`).
- Configuration : [`configs/config.example.toml`](../../configs/config.example.toml) (à copier en `configs/config.toml` localement avant la première exécution).

Une commande de test ajoutée ultérieurement doit suivre la même chaîne : ajouter sa factory dans `definitions/commands.php` puis l'enregistrer dans `definitions/application.php` via `$application->add(...)`.

## Voir aussi

- [Commandes Symfony Console](commands.md) — `DocumentsCommand` et ses actions métier (CRUD, harvest, …).
- [Indexes et gestion des collections](indexes.md) — la grammaire d'index et le `CollectionManagementTrait`.
- [Client ArangoDB *legacy*](clients/README.md) — *contexte* de la réécriture en cours.
