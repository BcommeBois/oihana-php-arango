# Commandes Symfony Console

Le dossier [`src/oihana/arango/commands/`](../../src/oihana/arango/commands/) expose la couche métier [`Documents`](models.md) côté **ligne de commande**. Mêmes opérations CRUD que les contrôleurs HTTP, accessibles via `php bin/console.php <name>` ou un alias `bun` côté projet.

Deux classes pivots :

| Classe | Rôle | Actions exposées |
|---|---|---|
| `ArangoCommand` | Maintenance de la base : dump, restore, listing des dumps. | `dump`, `restore`, `dump --list` |
| `DocumentsCommand` | CRUD complet sur une collection. | `get`, `list`, `count`, `exist`, `last`, `insert`, `update`, `replace`, `upsert`, `delete`, `truncate`, `harvest` |

Les deux héritent du squelette [`oihana/php-commands`](getting-started/dependencies.md#oihanaphp-commands), qui fournit la gestion des arguments, options, formats de sortie (JSON, table, raw) et codes de retour.

## `ArangoCommand`

`ArangoCommand` est livrée câblée par la lib et enregistrée sous le nom `command:arangodb` par [`definitions/commands.php`](../../definitions/commands.php). Elle est immédiatement utilisable via `php bin/console.php command:arangodb …` une fois `configs/config.toml` créé.

### Actions disponibles

| Action | Trait | Sortie typique |
|---|---|---|
| `dump` | [`ArangoDumpAction`](../../src/oihana/arango/commands/actions/ArangoDumpAction.php) | Archive `arangodump` horodatée (`YYYY-MM-DDTHH:MM:SS-<db>.tar.gz`), chiffrée AES si `--encrypt`. |
| `restore` | [`ArangoRestoreAction`](../../src/oihana/arango/commands/actions/ArangoRestoreAction.php) | Réinjection via `arangorestore` à partir d'une archive sélectionnée par fichier, date, ou interactivement. |
| `listDumps` (`--list`) | [`ArangoListDumpsAction`](../../src/oihana/arango/commands/actions/ArangoListDumpsAction.php) | Liste les dumps présents dans le dossier de dumps. |

> Les binaires `arangodump` et `arangorestore` (livrés avec ArangoDB) doivent être présents dans le `$PATH` du processus PHP. Sur macOS via Homebrew : `brew install arangodb`.

### Configuration

Deux sources alimentent la commande :

| Source | Clés consommées | Lecture |
|---|---|---|
| `[arango]` de `configs/config.toml` | `database`, `endpoint`, `user`, `password`, `encrypt`, `passphrase` | [`definitions/config.php`](../../definitions/config.php) → `arango.config` |
| `[app].dumps` de `configs/config.toml` | Chemin du dossier de dumps | [`definitions/config.php`](../../definitions/config.php) → `app.dumps` |

Résolution du dossier de dumps :

- chemin **absolu** (`/var/data/arango/dumps`) → utilisé tel quel ;
- chemin **relatif** (`dumps`, `var/dumps`) → résolu contre la racine de la lib (`__LIB__`) ;
- clé **absente ou vide** → défaut `<racine-lib>/dumps`.

> Le dossier [`dumps/`](../../dumps/) est livré tracké avec un `.gitignore` interne qui ignore les archives — le default fonctionne immédiatement (`--list` renvoie un message vide propre, et le premier `dump` n'a pas besoin de créer le dossier).

Exemple minimal de `configs/config.toml` :

```toml
[app]
# dumps = "/var/data/arango/dumps"   # absolu — sinon résolu contre la racine de la lib

[arango]
database   = "my_db"
endpoint   = "tcp://127.0.0.1:8529"
user       = "root"
password   = "secret"
passphrase = ""                       # passphrase par défaut pour --encrypt
encrypt    = false                    # bascule en true pour chiffrer par défaut
```

### Usage CLI (lib en standalone)

```bash
# Dump (par défaut : section [arango], dossier [app].dumps)
php bin/console.php command:arangodb dump

# Lister les dumps présents
php bin/console.php command:arangodb dump --list

# Dump chiffré (passphrase prompt interactif si non fournie)
php bin/console.php command:arangodb dump --encrypt
php bin/console.php command:arangodb dump --encrypt --passphrase mysecret

# Override de la base ou de l'endpoint
php bin/console.php command:arangodb dump --database other_db --endpoint tcp://10.0.0.5:8529

# Override du dossier de sortie
php bin/console.php command:arangodb dump --directory /tmp/snapshots

# Restore — sélection interactive parmi les archives présentes
php bin/console.php command:arangodb restore

# Restore — dernière archive en date
php bin/console.php command:arangodb restore --last

# Restore — par date
php bin/console.php command:arangodb restore --date 2026-05-17T18:14:22

# Restore — fichier explicite
php bin/console.php command:arangodb restore --file /var/data/arango/dumps/2026-05-17T18:14:22-my_db.tar.gz.enc

# Restore d'une archive chiffrée
php bin/console.php command:arangodb restore --last --encrypt --passphrase mysecret
```

### Définition DI (intégration dans un projet hôte)

La lib expose la commande sous le nom `ArangoCommand::NAME` (= `command:arangodb`). Quand vous l'intégrez dans **votre propre** application, ignorez la clé `[app].dumps` de la lib et câblez votre propre `directory` :

```php
// api/definitions/commands.php  (projet hôte)
use DI\Container ;
use oihana\arango\commands\ArangoCommand ;
use oihana\arango\commands\enums\ArangoAction ;
use oihana\arango\commands\enums\ArangoCommandParam ;
use oihana\commands\enums\CommandParam ;
use oihana\commands\options\CommandOption ;

return
[
    ArangoCommand::NAME => fn( Container $c ) => new ArangoCommand
    (
        name      : ArangoCommand::NAME ,
        container : $c ,
        init      :
        [
            CommandParam::DESCRIPTION    => 'Manage the project ArangoDB database.' ,
            CommandParam::ACTIONS        =>
            [
                ArangoAction::DUMP ,
                ArangoAction::RESTORE ,
            ] ,
            ArangoCommandParam::DIRECTORY => $c->get( 'paths.dumps' ) , // votre propre définition
            CommandOption::ENCRYPT        => true ,
            CommandOption::PASS_PHRASE    => $c->get( 'arango.config' )[ 'passphrase' ] ?? null ,

            // Déballage de la section [arango] (database, endpoint, user, password).
            ...$c->get( 'arango.config' ) ,
        ]
    ) ,
] ;
```

Puis ajoutez la commande à votre `Application` Symfony Console :

```php
$application->addCommand( $container->get( ArangoCommand::NAME ) ) ;
```

> **Note** — la lib opensource ne gère **aucune** substitution de tokens type `{{{projectPath}}}` dans le TOML. Si votre projet a besoin d'une telle injection, c'est à la définition `paths.dumps` du projet hôte d'assembler le chemin final (à partir de constantes PHP, d'env vars, ou de `realpath`), pas à la lib.

### Options CLI

| Option | Raccourci | Description |
|---|---|---|
| `--directory` | `-dir` | Override du dossier de dump/restore. |
| `--encrypt` | `-e` | Active le chiffrement AES de l'archive (dump) ou la déchiffre (restore). |
| `--passphrase` | `-p` | Passphrase pour `--encrypt` / restore d'archive chiffrée. Prompt interactif sinon. |
| `--list` | `-l` | Sur `dump` ou `restore` : liste les archives présentes au lieu d'exécuter l'action. |
| `--last` | `-la` | Sur `restore` : sélectionne automatiquement l'archive la plus récente. |
| `--date` | `-d` | Sur `restore` : sélectionne l'archive correspondant à une date ISO 8601. |
| `--file` | `-f` | Sur `restore` : chemin explicite vers une archive (court-circuite la sélection). |
| `--database` | — | Override de `[arango].database`. |
| `--endpoint` | — | Override de `[arango].endpoint`. |
| `--user` | — | Override de `[arango].user`. |
| `--password` | — | Override de `[arango].password`. |

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
| `DocumentsCommandParamTrait` | Registre de constantes partagé (`ConstantsTrait` + `CommandParamTrait`) pour `DocumentsCommandParam`. |

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
