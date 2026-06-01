# `command:arangodb` — dump / restore / collections

`ArangoCommand` est la commande de maintenance d'une base ArangoDB : **sauvegarde** (`dump`), **restauration** (`restore`) et **inventaire des collections** (`collections`). Elle est livrée pré-câblée par la lib sous le nom `command:arangodb` ([`definitions/commands.php`](../../../definitions/commands.php)) et s'utilise via `php bin/console.php command:arangodb <action> [options]`.

Elle hérite du squelette [`oihana/php-commands`](../getting-started/dependencies.md#oihanaphp-commands) (gestion des arguments/options/sorties via **Symfony Console**) : `Kernel` (classe de base), `CommandArg` (arguments), `CommandOption` (options communes : `--clear`, `--passphrase`, …), `ExitCode`, et des traits utilitaires (`IOTrait`, `EncryptTrait`). Le contexte est donc une application Symfony Console standard, alimentée par un conteneur **PHP-DI**.

> **Prérequis** : les binaires `arangodump` et `arangorestore` (fournis avec ArangoDB) doivent être dans le `$PATH` du processus PHP. macOS / Homebrew : `brew install arangodb`. La sous-commande `collections` et la validation des collections passent, elles, par l'**API HTTP** d'ArangoDB (client interne), pas par les binaires.

---

## Actions disponibles

| Action | Trait | Description |
|---|---|---|
| `dump` | [`ArangoDumpAction`](../../../src/oihana/arango/commands/actions/ArangoDumpAction.php) | Archive `arangodump` horodatée. Base complète ou sous-ensemble (`--collection` / `--ignore-collection`), chiffrée AES si `--encrypt`. |
| `restore` | [`ArangoRestoreAction`](../../../src/oihana/arango/commands/actions/ArangoRestoreAction.php) | Réinjection via `arangorestore` depuis une archive sélectionnée par `--last`, `--date`, `--file` ou interactivement. Restauration de tout ou d'un sous-ensemble (`--collection`). |
| `collections` | [`ArangoListCollectionsAction`](../../../src/oihana/arango/commands/actions/ArangoListCollectionsAction.php) | Liste les collections de la base via l'API HTTP. Portées : métier (défaut), `--system`, `--all`. |
| `listDumps` (`--list`) | [`ArangoListDumpsAction`](../../../src/oihana/arango/commands/actions/ArangoListDumpsAction.php) | Liste les fichiers d'archive présents dans le dossier de dumps. |

---

## Configuration

Deux sources alimentent la commande :

| Source | Clés | Lecture |
|---|---|---|
| `[arango]` de `configs/config.toml` | `database`, `endpoint`, `user`, `password`, `encrypt`, `passphrase` | [`definitions/config.php`](../../../definitions/config.php) → `arango.config` |
| `[app].dumps` de `configs/config.toml` | Dossier des archives | [`definitions/config.php`](../../../definitions/config.php) → `app.dumps` |

Résolution du dossier de dumps : chemin **absolu** → tel quel ; **relatif** → résolu contre la racine de la lib (`__LIB__`) ; **absent** → `<racine-lib>/dumps`.

```toml
[app]
# dumps = "/var/data/arango/dumps"   # absolu — sinon résolu contre la racine de la lib

[arango]
database   = "my_db"
endpoint   = "tcp://127.0.0.1:8529"
user       = "root"
password   = "secret"
passphrase = ""                       # passphrase par défaut pour --encrypt
encrypt    = false                    # true pour chiffrer par défaut
```

---

## Câblage DI

La lib bootstrappe un conteneur **PHP-DI** depuis [`definitions/`](../../../definitions/) et [`configs/`](../../../configs/) via [`bin/console.php`](../../../bin/console.php) :

- [`definitions/config.php`](../../../definitions/config.php) — clés `arango.config` (section `[arango]`) et `app.dumps`.
- [`definitions/commands.php`](../../../definitions/commands.php) — enregistre `ArangoCommand::NAME` avec son tableau `init` (description, `CommandParam::ACTIONS`, `ArangoCommandParam::DIRECTORY`, `--encrypt` / `--passphrase`, et le déballage de `[arango]`).
- [`definitions/application.php`](../../../definitions/application.php) — ajoute la commande à l'`Application` Symfony Console.

### Intégration dans un projet hôte

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
            CommandParam::DESCRIPTION     => 'Manage the project ArangoDB database.' ,
            CommandParam::ACTIONS         =>
            [
                ArangoAction::COLLECTIONS ,
                ArangoAction::DUMP        ,
                ArangoAction::RESTORE     ,
            ] ,
            ArangoCommandParam::DIRECTORY => $c->get( 'paths.dumps' ) , // votre propre définition
            CommandOption::ENCRYPT        => true ,
            CommandOption::PASS_PHRASE    => $c->get( 'arango.config' )[ 'passphrase' ] ?? null ,

            // Déballage de [arango] (database, endpoint, user, password).
            ...$c->get( 'arango.config' ) ,
        ]
    ) ,
] ;
```

```php
// puis, côté Application Symfony Console :
$application->addCommand( $container->get( ArangoCommand::NAME ) ) ;
```

> La lib ne fait **aucune** substitution de tokens type `{{{projectPath}}}`. Si le chemin de dumps doit être assemblé, c'est à la définition `paths.dumps` du projet hôte de le faire (constantes PHP, env vars, `realpath`).

---

## Options CLI

| Option | Raccourci | Action(s) | Description |
|---|---|---|---|
| `--collection` | `-c` | dump, restore | Restreint à ces collections. **Répétable** *ou* séparé par des virgules (ou les deux). |
| `--ignore-collection` | — | dump | Exclut ces collections du dump (répétable / virgules). Résolu côté client (voir plus bas). |
| `--label` | `-L` | dump, restore | Libellé optionnel ajouté au nom de l'archive (ex. `pre-migration`). |
| `--all` | — | collections | Liste **toutes** les collections (système + métier). |
| `--system` | — | collections | Liste **uniquement** les collections système (`_…`). |
| `--encrypt` | `-e` | dump, restore | Chiffrement AES de l'archive (dump) / déchiffrement (restore). |
| `--passphrase` | `-p` | dump, restore | Passphrase pour `--encrypt`. Prompt interactif sinon. |
| `--directory` | `-dir` | toutes | Override du dossier de dump/restore. |
| `--list` | `-l` | dump, restore | Liste les archives présentes au lieu d'agir. |
| `--last` | `-la` | restore | Sélectionne l'archive la plus récente. |
| `--date` | `-d` | restore | Sélectionne l'archive correspondant à une date ISO 8601. |
| `--file` | `-f` | restore | Chemin explicite vers une archive. |
| `--database` | — | toutes | Override de `[arango].database`. |
| `--endpoint` | — | toutes | Override de `[arango].endpoint`. |
| `--user` | — | toutes | Override de `[arango].user`. |
| `--password` | — | toutes | Override de `[arango].password`. |

---

## `dump` — sauvegarde

### Base complète

```bash
php bin/console.php command:arangodb dump
# → <dumps>/2026-06-01T14:30:00-my_db.tar.gz

php bin/console.php command:arangodb dump --encrypt --passphrase 's3cret'
# → <dumps>/2026-06-01T14:30:00-my_db.tar.gz.enc
```

### Sous-ensemble — `--collection`

Les deux syntaxes sont acceptées (et mélangeables) :

```bash
php bin/console.php command:arangodb dump --collection=users,products,customers
php bin/console.php command:arangodb dump -c users -c products -c customers
php bin/console.php command:arangodb dump -c users,products -c customers
# → <dumps>/2026-06-01T14:30:00-my_db-partial.tar.gz
```

Un dump ciblé porte automatiquement le marqueur **`-partial`** dans son nom, pour le distinguer d'une sauvegarde complète.

### Libellé — `--label`

```bash
php bin/console.php command:arangodb dump -c users,products --label pre-migration
# → <dumps>/2026-06-01T14:30:00-my_db-partial-pre-migration.tar.gz

php bin/console.php command:arangodb dump --label nightly        # complet + label
# → <dumps>/2026-06-01T14:30:00-my_db-nightly.tar.gz
```

Le label n'accepte que `[A-Za-z0-9._-]` (sûreté du nom de fichier).

### Exclusion — `--ignore-collection`

`arangodump` **n'a pas** d'option d'exclusion. La commande la résout **côté client** : elle liste les collections métier via l'API HTTP, retire celles à exclure, et passe le **complément** en `--collection` à `arangodump`.

```bash
php bin/console.php command:arangodb dump --ignore-collection audit_logs,sessions
#  Ignored collections : audit_logs, sessions
#  → 63 collection(s) will be dumped.
#  → <dumps>/2026-06-01T14:30:00-my_db-partial.tar.gz
```

> Conséquence : `--ignore-collection` **exige** que l'API HTTP soit joignable (il faut connaître la liste complète pour calculer le complément). Si elle ne l'est pas, la commande **échoue** avec un message explicite.
> `--collection` et `--ignore-collection` sont **mutuellement exclusifs**.

### Validation des collections (best-effort)

Avant un dump ciblé, les noms demandés sont vérifiés contre la base :

```bash
php bin/console.php command:arangodb dump -c users,prodcts
# [ERROR] Unknown collection(s): prodcts. Available collections: users, products, …
```

Si l'API HTTP est **injoignable** pour un `--collection` (inclusion), la validation est **sautée** avec un avertissement et le dump se poursuit (`arangodump` peut réussir malgré tout). Pour `--ignore-collection`, l'API est en revanche obligatoire (cf. ci-dessus).

---

## `restore` — restauration

### Sélection de l'archive

```bash
php bin/console.php command:arangodb restore                 # menu interactif
php bin/console.php command:arangodb restore --last          # la plus récente
php bin/console.php command:arangodb restore --date 2026-06-01T14:30:00
php bin/console.php command:arangodb restore --file /var/data/arango/dumps/2026-06-01T14:30:00-my_db.tar.gz
php bin/console.php command:arangodb restore --last --encrypt --passphrase 's3cret'
```

### Restauration ciblée — `--collection`

```bash
php bin/console.php command:arangodb restore --last --collection users,products
```

### Sémantique d'ajout / suppression des collections

C'est le point essentiel à comprendre — il vaut autant pour le **dump** que pour le **restore** :

- **`--collection` est un filtre.**
  - Au **dump**, il restreint ce qui est **écrit** dans l'archive.
  - Au **restore**, il restreint ce qui est **lu** depuis l'archive — **indépendamment de son contenu**.
- **On peut donc restaurer une seule collection depuis une archive complète.** Si l'archive contient les 66 collections et que vous lancez `restore --collection users`, **seule** `users` est réinjectée ; les autres collections présentes dans l'archive sont **ignorées**, et les collections de la base **non ciblées ne sont pas touchées**.
- **`--create-collection` est actif par défaut** : si la collection ciblée a été **supprimée** (et pas seulement vidée), elle est **recréée** par le restore.
- **Pas de doublons** : `arangorestore` réinsère les documents par `_key` ; les documents déjà présents avec la même clé ne sont pas dupliqués.

#### Démonstration

Dump **complet** des 4 collections, puis on casse `users` et `products`, puis on ne restaure **que** `users` :

| Étape | users | products | customers | orders |
|---|:--:|:--:|:--:|:--:|
| départ | 3 | 4 | 3 | 2 |
| on supprime `users/carol` + `products/p4` | **2** | **3** | 3 | 2 |
| `restore --last --collection users` | **3** ✅ | **3** ❌ | 3 | 2 |

```
# Successfully restored document collection 'users'
Processed 1 collection(s) from 1 database(s)
```

→ Une **seule** collection traitée. `users` est restauré ; `products` reste cassé (donc **non touché**) bien qu'il soit présent dans l'archive complète.

### Restaurer un dump *partiel* par date

`restore --date` reconstruit le nom du fichier. Pour un dump partiel, il faut **repasser le même ciblage** (qui déclenche `-partial`) **et le même `--label`** :

```bash
# dump partiel : 2026-06-01T14:30:00-my_db-partial-pre-migration.tar.gz
php bin/console.php command:arangodb restore --date 2026-06-01T14:30:00 -c users --label pre-migration
```

> Plus simple pour les dumps partiels : `--last` ou `--file` (qui n'ont pas besoin de reconstruire le nom).

---

## `collections` — inventaire

```bash
php bin/console.php command:arangodb collections           # collections métier (non-système)
php bin/console.php command:arangodb collections --system  # uniquement système (_apps, _jobs, …)
php bin/console.php command:arangodb collections --all     # toutes
```

Lecture seule, via l'API HTTP. Pratique pour préparer un `--collection` / `--ignore-collection` correct.

---

## Scénario : migration sécurisée

```bash
# 1) Filet de sécurité : dump complet
php bin/console.php command:arangodb dump

# 2) Dump ciblé des collections NON touchées par la migration, chiffré + labellisé
php bin/console.php command:arangodb dump -c users,settings,customers -e -p 's3cret' -L pre-migration
#   → <dumps>/2026-06-01T14:32:10-my_db-partial-pre-migration.tar.gz.enc

# 3) … migration … en cas de pépin, on réinjecte SEULEMENT ces collections :
php bin/console.php command:arangodb restore --last --collection users,settings,customers
```

Le dump complet de l'étape 1 reste un filet universel : on peut en réextraire **n'importe quelle** collection à la carte (`restore --file <complet> --collection X`) sans toucher au reste.

---

## Base de jeu — `scripts/seed-playground.php`

Pour tester `dump` / `restore` / `collections` **sans toucher** votre base habituelle, la lib fournit un script qui crée et peuple une base jetable.

```bash
# crée/peuple la base "dump_playground" (4 collections : users, products, orders, customers)
php scripts/seed-playground.php

# nom de base personnalisé
php scripts/seed-playground.php ma_base_de_test
```

Caractéristiques :

- lit la connexion dans `[arango]` de `configs/config.toml` (même serveur que la commande), mais cible une **base séparée** ;
- **idempotent** : à chaque exécution les collections sont (re)créées et (re)peuplées ;
- n'est **pas** un point d'entrée applicatif — c'est un utilitaire de développement ([`scripts/seed-playground.php`](../../../scripts/seed-playground.php)).

Puis on teste en ciblant cette base via `--database` :

```bash
php bin/console.php command:arangodb collections --database dump_playground
php bin/console.php command:arangodb dump        --database dump_playground -c users,products -L test
php bin/console.php command:arangodb restore     --database dump_playground --last --collection users
```

> Les archives de toutes les bases partagent le même dossier de dumps. `--last` / `--list` ne filtrent pas par base : pour cibler précisément une archive, préférez `--file`, ou un `--label` distinctif.
