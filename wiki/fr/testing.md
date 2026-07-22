# Tests

![Langue](https://img.shields.io/badge/langue-Français-blue)

Le projet a **deux couches de tests**, complémentaires :

| Couche | Outil | Serveur ArangoDB ? | Rôle |
|---|---|---|---|
| **Tests unitaires** | PHPUnit | Non (transport *mocké*) | Valident la logique pure : builders AQL, filtres, facettes, helpers, modèles via un transport simulé. Rapides, lancés à chaque commit. |
| **Smoke tests live** | Symfony Console (`arango:test:*`) | Oui (base éphémère) | Valident la stack de bout en bout contre un vrai `arangod`. |

Le workflow contributeur synthétique est résumé dans [CONTRIBUTING.md](../../CONTRIBUTING.md) ; cette page est la référence détaillée.

## Tests unitaires (PHPUnit)

La suite vit dans [`tests/`](../../tests) et se lance avec :

```shell
composer test                                       # = ./vendor/bin/phpunit
./vendor/bin/phpunit --filter FilterFunctionTest    # un seul cas
```

Configuration : [phpunit.xml](../../phpunit.xml). Points clés :

- **Périmètre de couverture** : `./src` uniquement (balise `<source>`).
- **Mode strict** : `failOnWarning`, `failOnRisky`, `failOnSkipped`, `beStrictAboutOutputDuringTests`… Un test « risqué » (sans assertion, qui produit de la sortie, etc.) fait **échouer** la suite. C'est voulu : un test qui ne vérifie rien ne protège de rien.
- **Groupe `integration` exclu** par défaut : les tests qui exigent un vrai serveur sont marqués `@group integration` et ne tournent pas avec `composer test`.

### Ce qu'on teste, et comment

| Tier | Cible | Technique |
|---|---|---|
| 1 | Builders AQL purs (`models/traits/aql/**`, `db/**`, `models/enums/**`) | Entrée → chaîne AQL attendue. Aucun mock. |
| 2 | Modèles / edges / contrôleurs | Transport HTTP **mocké** : on injecte un faux client, puis on vérifie la requête produite et le décodage de la réponse. |
| 3 | Commandes & actions | Dépendances DI *stubées*. |

> **Tests de caractérisation.** Quand on couvre du code existant, on écrit des tests qui décrivent ce que le code **fait réellement**, branche par branche (`if` / `else` / `match`). Ce travail de précision révèle régulièrement de vrais bugs (cas limite mal géré, valeur non filtrée…). **Règle d'or** : si un comportement surprenant pourrait être utilisé en aval par une autre lib, on le **gèle dans un test** et on le signale — on ne change pas une API publique sans validation explicite.

### Éviter les warnings et les *deprecations*

Le mode strict (`failOnWarning`, `failOnRisky`) transforme le moindre warning PHP en **échec de suite**. Un warning n'est donc jamais « toléré » : on le supprime à la source. Pièges récurrents rencontrés dans cette lib :

- **`Undefined array key "REQUEST_URI"`.** Passer `null` comme requête à un handler qui **construit une réponse** fait lire `$_SERVER['REQUEST_URI']` (absent en CLI) par `BaseUrlTrait`, d'où le warning. Correctif : dès qu'un test fournit une `Response`, passe une **vraie requête** via le helper — `$this->makeRequest( [] )` pour « aucun paramètre » — jamais `null`. Réserve `null` aux appels qui **ne produisent pas** de réponse (retour direct de données, ex. `get()`/`count()` testés sans réponse).
- **Distinguer nos *deprecations* de celles des dépendances.** Une *deprecation* déclenchée par **notre** code se corrige avant commit. Si elle vient d'une dépendance tierce, on la trace (issue/note) au lieu de la laisser masquer les nôtres.
- **Pas de test « risqué ».** Chaque test **affirme** quelque chose (au moins un `assert*`) ; un test sans assertion échoue en mode strict — et surtout ne protège de rien.
- **Aucune sortie pendant les tests.** Un `var_dump()` / `echo` oublié fait échouer `beStrictAboutOutputDuringTests`. Pour inspecter, on passe par des assertions ou `--debug`, jamais par une sortie directe.

## Couverture de code

PHPUnit mesure quelles lignes de `./src` sont exécutées par la suite. Il faut **activer le mode coverage de Xdebug** (ou PCOV) ; sinon PHPUnit affiche `No tests executed!` et un warning `XDEBUG_MODE=coverage … has to be set`. Les scripts `composer` ci-dessous positionnent la variable d'environnement pour toi :

```shell
composer coverage       # suite + couverture : texte au terminal, Clover + HTML sous build/coverage/
composer coverage:md    # régénère build/coverage/COVERAGE.md (résumé Markdown, zones rouges en tête)
```

Les sorties vont dans `build/coverage/` — **gitignoré, jamais commité** : un snapshot de chiffres se périme au commit suivant et pollue les diffs. On régénère à la demande. L'outil de conversion Clover → Markdown vit dans [`tools/clover-to-markdown.php`](../../tools/clover-to-markdown.php).

#### Évolution entre deux runs

Chaque génération horodate le rapport (`Generated at AAAA-MM-JJ HH:MM:SS`) et écrit un snapshot dans `build/coverage/history.json` (lui aussi gitignoré). Au run suivant, le résumé compare au **run précédent enregistré** et affiche un delta par métrique : `▲ +0.14 pts (+12 lines)` / `▼ -0.30 pts (-5 methods)` / `= ±0.00 pts (+0 lines)`.

L'horodatage écrit dans les données fait foi — on ne se fie **pas** à la date de modification du fichier (un `touch`, un `checkout` ou une régénération à blanc la fausseraient, et le fichier disparaît avec `build/`). `history.json` est borné aux 50 derniers runs. Comme tout vit sous `build/`, cette tendance est **purement locale** : pour un suivi partagé (équipe, CI), publier le rapport via un job CI plutôt que le committer.

### Lire le rapport

- **Lignes** = la métrique de référence (% de lignes exécutées).
- Une barre vide = code **jamais testé** → bug potentiel non détecté.
- ⚠️ **100 % ≠ zéro bug.** Une ligne « traversée » sans assertion solide est *couverte* mais pas vraiment *vérifiée*. On vise donc des tests qui **affirment un résultat précis**, pas qui passent simplement à travers le code.

État au 2026-06-05 : **~61 % de lignes** (≈ 5200 / 8480), 2177 tests verts. Plus gros chantiers ouverts : `auth/traits/**` (0 %), `controllers/**` (0 %), `commands/actions` (~1 %).

## Smoke tests live (ArangoDB réel)

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
| 7 | **Surface d'exception** : une AQL invalide doit remonter en `oihana\arango\clients\exceptions\ArangoException`, avec l'exception clients/ sous-jacente chaînée via `$previous`. |

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
- [Le client HTTP](clients/README.md) — couche bas niveau exercée par `arango:test:clients`.
