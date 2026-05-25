# Commandes Symfony Console

Le dossier [`src/oihana/arango/commands/`](../../src/oihana/arango/commands/) expose la couche métier [`Documents`](models.md) côté **ligne de commande**. Mêmes opérations CRUD que les contrôleurs HTTP, accessibles via `php bin/console.php <name>` ou un alias `bun` côté projet.

Deux classes pivots :

| Classe | Rôle | Actions exposées |
|---|---|---|
| `ArangoCommand` | Maintenance de la base (dump, restore, list dumps). | `dump`, `restore`, `list-dumps` |
| `DocumentsCommand` | CRUD complet sur une collection. | `get`, `list`, `count`, `exist`, `last`, `insert`, `update`, `replace`, `upsert`, `delete`, `truncate`, `harvest` |

Les deux héritent du squelette [`oihana/php-commands`](getting-started/dependencies.md#oihanaphp-commands), qui fournit la gestion des arguments, options, formats de sortie (JSON, table, raw) et codes de retour.

## `ArangoCommand`

### Actions disponibles

| Action | Classe | Sortie typique |
|---|---|---|
| `dump` | `ArangoDumpAction` | Export d'une ou plusieurs collections vers un dossier d'*archives* (`.dump.json`). |
| `restore` | `ArangoRestoreAction` | Réinjection d'un dump précédent dans la base. |
| `list-dumps` | `ArangoListDumpsAction` | Liste les dumps disponibles dans le dossier d'archives. |

Configurées via le tableau d'options [`ArangoDumpOption`](../../src/oihana/arango/commands/options/ArangoDumpOption.php), [`ArangoRestoreOption`](../../src/oihana/arango/commands/options/ArangoRestoreOption.php), et les communes [`ArangoCommonOption`](../../src/oihana/arango/commands/options/ArangoCommonOption.php) (chemin de dossier, verbosité, mode `--dry-run`, ...).

### Définition DI

```php
use DI\Container ;
use oihana\arango\commands\ArangoCommand ;

return
[
    Commands::ARANGODB => fn( Container $c ) => new ArangoCommand( $c ,
    [
        ArangoCommandParam::NAME    => 'arangodb' ,
        ArangoCommandParam::OPTIONS =>
        [
            ArangoCommonOption::DUMPS_DIR => '/var/data/arango/dumps' ,
            // ...
        ] ,
    ]) ,
] ;
```

### Usage CLI

```bash
# Dump
php api/bin/console.php arangodb dump --collection=users --collection=roles

# Restore
php api/bin/console.php arangodb restore --dump=users-2026-05-17.json

# Liste les dumps
php api/bin/console.php arangodb list-dumps

# Alias bun (côté projet hôte)
bun arangodb dump --collection=users
```

## `DocumentsCommand`

### Actions disponibles

`DocumentsCommand` expose une commande par collection. Chaque commande accepte une **action** (premier argument positionnel) qui détermine l'opération à effectuer.

| Action | Classe | Équivalent modèle | Exemple usage |
|---|---|---|---|
| `get` | `DocumentsCommandGet` | `get()` | Récupère un document par clé. |
| `list` | `DocumentsCommandList` | `list()` | Liste paginée. |
| `count` | `DocumentsCommandCount` | `count()` | Compte les documents matchant. |
| `exist` | `DocumentsCommandExist` | `exist()` | Test d'existence (code de retour 0/1). |
| `last` | `DocumentsCommandLast` | `last()` | Dernier document selon le `SORT_DEFAULT`. |
| `insert` | `DocumentsCommandInsert` | `insert()` | Insertion d'un nouveau document depuis JSON. |
| `update` | `DocumentsCommandUpdate` | `update()` | Mise à jour partielle. |
| `replace` | `DocumentsCommandReplace` | `replace()` | Remplacement complet. |
| `upsert` | `DocumentsCommandUpsert` | `upsert()` | Insert ou update. |
| `delete` | `DocumentsCommandDelete` | `delete()` | Suppression (avec cascade *edges*). |
| `truncate` | `DocumentsCommandTruncate` | `truncate()` | Vide la collection. |
| `harvest` | `DocumentsCommandHarvest` | Custom | Cycle d'importation périodique depuis une source externe. |

### Définition DI

```php
use DI\Container ;
use oihana\arango\commands\DocumentsCommand ;

return
[
    Commands::USERS => fn( Container $c ) => new DocumentsCommand( $c ,
    [
        DocumentsCommandParam::NAME  => 'users'         ,
        DocumentsCommandParam::MODEL => Models::USERS    ,
    ]) ,
] ;
```

Une définition = une commande = une collection. Le `MODEL` est l'identifiant DI du modèle [`Documents`](models.md) sous-jacent. La commande hérite automatiquement de toute la configuration (filtres, *skins*, *edges*) du modèle.

### Usage CLI

```bash
# Récupérer un document
php api/bin/console.php users get --key=abc123

# Lister 20 premiers
php api/bin/console.php users list --limit=20

# Compter
php api/bin/console.php users count

# Insertion depuis JSON
php api/bin/console.php users insert --data='{"_key":"john","email":"john@example.com","active":true}'

# Mise à jour partielle
php api/bin/console.php users update --key=john --data='{"active":false}'

# Suppression
php api/bin/console.php users delete --key=john

# Truncate (avec confirmation interactive)
php api/bin/console.php users truncate

# Bypass confirmation
php api/bin/console.php users truncate --force

# Alias bun (côté projet hôte)
bun users list --limit=20
bun users count
```

### Options globales

| Option | Description |
|---|---|
| `--verbose`, `-v` | Verbosité accrue. `-vv` et `-vvv` pour plus de détails. |
| `--quiet`, `-q` | Aucune sortie hors erreurs. |
| `--dry-run` | Affiche l'AQL qui serait exécuté, sans le faire. |
| `--force` | Bypass les confirmations interactives (utile pour `truncate`, `delete` en masse). |
| `--format=json\|table\|raw` | Format de sortie. `json` par défaut. |
| `--filter=<json>` | Conditions de filtrage (même syntaxe que [`?filter=` HTTP](db/filter.md)). |
| `--sort=<expr>` | Tri (grammaire `[-]field1,[-]field2`). |
| `--limit=<n>` | Limite de pagination. |
| `--offset=<n>` | Décalage de pagination. |
| `--skin=<name>` | *Skin* de projection. |

## Pattern d'injection bulk

Pour le *seeding* en développement, le pattern recommandé est un *fixture* JSON consommé par une boucle *shell* + l'action `insert` :

```bash
jq -c '.[]' fixtures/users.json | while read -r doc; do
    bun users insert --data="$doc"
done
```

Une action `import` native (consommant un tableau JSON en une seule commande, avec transaction) est sur la roadmap.

## Action `harvest`

`DocumentsCommandHarvest` n'est pas un CRUD : c'est un **point d'extension** pour synchroniser une collection depuis une source externe (ERP, API tierce, fichier plat). Le pattern standard consiste à sous-classer `DocumentsCommand` et à fournir une implémentation custom de l'action `harvest`.

Cas d'usage typique :

```bash
# Harvest les produits depuis l'ERP (ODBC)
bun proginov:harvest:products

# Harvest les offres tarifaires
bun proginov:harvest:products:offers
```

Voir les commandes `Acme\commands\proginov\*` pour des implémentations complètes côté projet.

## Catalogue des enums

| Enum | Rôle |
|---|---|
| `ArangoAction` | Actions disponibles sur `ArangoCommand` (`dump`, `restore`, `list-dumps`). |
| `ArangoCommandParam` | Clés de configuration DI de `ArangoCommand` (`NAME`, `OPTIONS`, ...). |
| `DocumentsCommandAction` | Actions disponibles sur `DocumentsCommand` (les 12 listées plus haut). |
| `DocumentsCommandParam` | Clés de configuration DI de `DocumentsCommand` (`NAME`, `MODEL`). |
| `DocumentsCommandOption` | Options CLI spécifiques (`--data`, `--key`, ...). |
| `ArangoCommandOption` | Énumération globale d'options CLI partagées. |
| `ArangoCommonOption` | Options communes à toutes les commandes Arango. |
| `ArangoDumpOption` / `ArangoRestoreOption` | Options spécifiques au dump et restore. |

Tous ces enums consomment `ConstantsTrait` ([`oihana/php-enums`](getting-started/dependencies.md#oihanaphp-enums)) et peuvent être inspectés à l'exécution via `keys()` / `values()`.

## Traits utilitaires

| Trait | Apport |
|---|---|
| `ArangoConfigTrait` | Hydratation de la configuration `ArangoConfig` côté commande. |
| `ArangoDumpTrait` | Mécanique de sérialisation d'une collection vers un dump. |
| `ArangoRestoreTrait` | Mécanique de réinjection d'un dump. |
| `DocumentsCommandTrait` | Mutualise les comportements partagés entre actions Documents. |
| `DocumentsCommandParamTrait` | Parsing des paramètres CLI spécifiques à `DocumentsCommand`. |

## Enregistrement dans le registre des commandes

Chaque commande définie en DI doit être référencée dans `definitions/commands.php` pour être chargée par `bin/console.php`. Convention du projet :

```php
// api/definitions/commands.php
return
[
    Commands::ARANGODB ,
    Commands::USERS    ,
    Commands::ROLES    ,
    Commands::PRODUCTS ,
    // ...
] ;
```

Oublier ce registre = commande introuvable côté CLI (« command "users" is not defined »). Pour les commandes `bun`, il faut aussi enregistrer l'alias dans `package.json`.

## Voir aussi

- [Modèles `Documents` et `Edges`](models.md) — la couche métier consommée par les commandes.
- [Contrôleurs Slim](controllers/README.md) — exposition HTTP parallèle des mêmes opérations.
- [Filtres HTTP `?filter=`](db/filter.md) — syntaxe `--filter=<json>` côté CLI.
- [Dépendances — `oihana/php-commands`](getting-started/dependencies.md#oihanaphp-commands) — squelette Symfony Console.
