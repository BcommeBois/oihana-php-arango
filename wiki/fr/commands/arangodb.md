# `command:arangodb` — dump / restore / collections / views / doctor / migrate

`ArangoCommand` est la commande de maintenance d'une base ArangoDB : **sauvegarde** (`dump`), **restauration** (`restore`), **inventaire des collections** (`collections`), **gestion des Views ArangoSearch** (`views`), **bilan de santé de la structure** (`doctor`) et **migrations versionnées des données** (`migrate`). Elle est livrée pré-câblée par la lib sous le nom `command:arangodb` ([`definitions/commands.php`](../../../definitions/commands.php)) et s'utilise via `php bin/console.php command:arangodb <action> [options]`.

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
| `views` | [`ArangoViewsAction`](../../../src/oihana/arango/commands/actions/ArangoViewsAction.php) | Gestion des Views ArangoSearch via l'API HTTP : liste (défaut), `--diff` / `--sync` contre les déclarations `AQL::VIEW` des modèles, `--drop` ciblé ou interactif. |
| `doctor` | [`ArangoDoctorAction`](../../../src/oihana/arango/commands/actions/ArangoDoctorAction.php) | Bilan de santé déclarations ↔ serveur pour toute la structure des modèles configurés (collections, index, Views) + orphelins. Rapport par défaut, `--apply` répare, `--prune` interactif. |
| `migrate` | [`ArangoMigrateAction`](../../../src/oihana/arango/commands/actions/ArangoMigrateAction.php) | Migrations versionnées des **données** : classes PHP `up()`/`down()` jouées une fois par base, suivi en base. `--status`, `--dry-run`, apply (avec confirmation), `--down[=n]`, `--forget`, `--create`. |

---

## Configuration

Deux sources alimentent la commande :

| Source | Clés | Lecture |
|---|---|---|
| `[arango]` de `configs/config.toml` | `database`, `endpoint`, `user`, `password`, `encrypt`, `passphrase` | [`definitions/config.php`](../../../definitions/config.php) → `arango.config` |
| `[arango.dump]` / `[arango.restore]` | Toute option `arangodump` / `arangorestore` (voir ci-dessous) | `ArangoOptionsTrait` via les clés d'init `dump` / `restore` |
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

### Configurer les défauts des options

Au-delà de la connexion, les actions `dump` et `restore` acceptent **toutes**
les options d'`arangodump` / `arangorestore`. Les plus courantes ont un flag
CLI dédié (voir [Options CLI](#options-cli)) ; **toutes** peuvent être posées
comme défauts persistants dans deux sections facultatives de `config.toml` :

| Section | S'applique à | Exemples de clés |
|---|---|---|
| `[arango.dump]` | `dump` | `threads`, `overwrite`, `includeSystemCollections`, `dumpViews`, `compressOutput`, `splitFiles` |
| `[arango.restore]` | `restore` | `threads`, `includeSystemCollections`, `view`, `forceSameDatabase`, `numberOfShards` |

Les clés sont les **noms de propriété** des options de `ArangoDumpOptions` /
`ArangoRestoreOptions` (camelCase). Les clés inconnues sont ignorées silencieusement.

```toml
[arango.dump]
threads                  = 4
overwrite                = true
includeSystemCollections = false      # valeur par défaut

[arango.restore]
threads = 4
```

**Précédence** — chaque couche écrase la précédente :

| Couche | Source |
|---|---|
| 1. défaut du binaire | `arangodump` / `arangorestore` |
| 2. défaut de config | `[arango.dump]` / `[arango.restore]` |
| 3. flag CLI | `--threads`, `--include-system`, … |

Un lancement ponctuel affine donc un défaut configuré sans toucher à `config.toml`.

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
                ArangoAction::VIEWS       ,
            ] ,
            ArangoCommandParam::DIRECTORY => $c->get( 'paths.dumps' ) , // votre propre définition

            // Modèles dont l'action `views` inspecte les déclarations AQL::VIEW
            // (ids de conteneur, comme Arango::MODEL côté contrôleurs).
            ArangoCommandParam::MODELS    => [ Models::PLACES , Models::PRODUCTS ] ,
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
| `--complete` | — | dump | Backup complet : toutes les collections utilisateur **plus** `_analyzers` et `_graphs` — voir [Backup complet](#backup-complet). |
| `--label` | `-L` | dump, restore | Libellé optionnel ajouté au nom de l'archive (ex. `pre-migration`). |
| `--all` | — | collections | Liste **toutes** les collections (système + métier). |
| `--system` | — | collections | Liste **uniquement** les collections système (`_…`). |
| `--encrypt` | `-e` | dump, restore | Chiffrement AES de l'archive (dump) / déchiffrement (restore). |
| `--passphrase` | `-p` | dump, restore | Passphrase pour `--encrypt`. Prompt interactif sinon. |
| `--directory` | `-dir` | toutes | Override du dossier de dump/restore. |
| `--list` | `-l` | dump, restore | Liste les archives présentes au lieu d'agir. |
| `--include-system` | — | dump, restore | Inclut les collections système (`_analyzers`, `_graphs`, …). |
| `--no-views` | — | dump | Ignore les définitions de Views ArangoSearch (dumpées par défaut). |
| `--all-databases` | — | dump, restore | Dump / restaure **toutes** les bases au lieu d'une seule. |
| `--overwrite` | — | dump | Écrase le dossier de sortie s'il existe déjà. |
| `--threads` | — | dump, restore | Nombre de threads parallèles. |
| `--view` | — | restore | Restreint la restauration à ces Views (répétable / séparées par virgules). |
| `--profile` | — | dump, restore | Profil nommé (`[arango.profiles.<nom>]`) ou chemin vers un fichier de profil `.toml` — voir [Profils](#profils). |
| `--dry-run` | — | dump, restore, migrate | Affiche le plan résolu (et les migrations en attente) sans rien exécuter. |
| `--apply` | — | doctor | Répare : crée le manquant (collections, index, Views), resynchronise les Views. |
| `--force` | — | doctor | Avec `--apply` : autorise le drop + recreate des index driftés. |
| `--prune` | — | doctor | Sélection interactive des orphelins (collections, Views) à supprimer. |
| `--create` | — | migrate | Génère la coquille vide d'une migration avec cette description. |
| `--status` | — | migrate | Tableau des migrations appliquées / en attente pour cette base. |
| `--yes` | `-y` | migrate | Applique sans la demande de confirmation (scripts / CI). |
| `--down` | — | migrate | Annule les N dernières migrations appliquées, défaut 1 (rembobinage LIFO). |
| `--forget` | — | migrate | Secours : retire une ligne de suivi **sans** exécuter son `down()`. |
| `--diff` | — | views | Compare les déclarations `AQL::VIEW` des modèles configurés à l'état serveur (lecture seule). |
| `--sync[=a,b]` | — | views | Crée les Views manquantes et resynchronise les driftées — toutes, ou les noms donnés (virgules). |
| `--drop[=a,b]` | — | views | Supprime les Views nommées (virgules), ou sélection interactive sans valeur. |
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

### Backup complet — `--complete`

Un dump par défaut sauvegarde les collections utilisateur, leurs index et les
**définitions de Views** ArangoSearch — mais **pas** les **analyzers custom**
(`_analyzers`) ni les **graphes nommés** (`_graphs`), qui vivent dans des
collections système. Restauré sur un serveur *vierge*, ce manque peut casser une
View qui référence un analyzer custom.

`--complete` comble le trou de façon **chirurgicale** : il sauvegarde toutes les
collections utilisateur **plus** `_analyzers` et `_graphs` — et *uniquement* ces
deux collections système (jamais `_users`, `_jobs`, `_queues`, …).

```bash
php bin/console.php command:arangodb dump --complete
```

Ce peut être le défaut via la config :

```toml
[arango.dump]
complete = true
```

Ce que couvre un backup :

| Couche | Dump par défaut | `--complete` |
|---|---|---|
| Collections utilisateur + données | ✅ | ✅ |
| Index | ✅ | ✅ |
| Définitions de Views ArangoSearch | ✅ | ✅ |
| Analyzers custom (`_analyzers`) | ❌ | ✅ |
| Graphes nommés (`_graphs`) | ❌ | ✅ |
| Utilisateurs / permissions (`_users`) | ❌ | ❌ |
| Services Foxx (`_apps`) | ❌ | ❌ |

`--complete` nécessite l'API HTTP (il énumère les collections) et est mutuellement
exclusif de `--collection` / `--ignore-collection` / `--profile` (il sauvegarde
toute la base, pas un sous-ensemble). Pour le restaurer, ré-inclure les
collections système :

```bash
php bin/console.php command:arangodb restore --last --include-system
```

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

## Profils

Un **profil** est une sélection nommée et réutilisable — *quoi* extraire, et en
option *d'où*. Au lieu de retaper un long `--collection a --collection b …` à
chaque fois, on le nomme une fois et on passe `--profile <nom>`.

### La recette staging → local

Le cas motivant : récupérer un sous-ensemble du **staging** dans sa base
**locale** pour tester sur des données réelles — **sans jamais écraser ses
collections d'authentification locales**.

```toml
# config.toml (projet) — ou un fichier autonome (voir plus bas)
[arango.profiles.test-local]
collections = ["thesaurus", "produits", "clients", "commerciaux"]
edges       = ["produit_thesaurus", "client_commercial"]
exclude     = ["_users", "sessions"]
```

```bash
# Récupère le sous-ensemble depuis le staging :
php bin/console.php command:arangodb dump --profile test-local
# Restaure-le dans la base locale :
php bin/console.php command:arangodb restore --profile test-local --last
```

Comme le dump ne **contient** que les collections sélectionnées, la restauration
locale **ne peut pas** écraser ton `_users` local — la liste positive *est* la
protection.

### Clés d'un profil

| Clé | Signification |
|---|---|
| `collections` / `edges` | La sélection positive (fusionnées en une seule liste). |
| `exclude` | Noms retirés de l'ensemble résolu (soustraction). |
| `endpoint` / `database` / `user` / `password` | Une connexion **source** optionnelle — utilisée par `dump` uniquement (voir sécurité). |

La sélection se résout en `(collections + edges) − exclude`. Un profil avec
**seulement `exclude`** (sans liste positive) signifie *« tout sauf exclude »* —
l'univers est constitué des collections du serveur pour `dump`, et des
collections de l'archive pour `restore`.

### Nommé ou fichier externe

`--profile` accepte les deux formes :

- `--profile test-local` → la section `[arango.profiles.test-local]` de `config.toml`.
- `--profile ./profils/test-local.toml` (ou un chemin absolu) → un fichier
  autonome dont les clés racine *sont* le profil. Portable — pose-le sur un
  serveur ou partage-le entre machines.

```toml
# /srv/arango/profils/staging-extract.toml — auto-suffisant
collections = ["thesaurus", "produits"]
exclude     = ["_users"]
endpoint    = "tcp://staging.internal:8529"
database    = "app_staging"
user        = "readonly"
password    = "•••"
```

> Un fichier de profil peut porter des identifiants → garde-le **hors du dépôt**,
> avec des permissions restreintes, comme `config.toml`.

### Sécurité

- **La connexion d'un profil est la source.** `dump` l'utilise (pomper *depuis*
  là) ; `restore` l'**ignore** et écrit toujours dans la cible **locale**
  (`[arango]` / CLI). Un profil ne peut jamais repousser ses données sur le
  serveur dont elles viennent.
- **`--profile` est exclusif** de `--collection` / `--ignore-collection` —
  choisis un seul mode de sélection.
- **La précédence** reste `défaut binaire → [arango.dump]/[arango.restore] → profil → CLI`.

### Dry run

`--dry-run` affiche le plan résolu — connexion, archive et la liste exacte des
collections — et n'exécute **rien** :

```bash
php bin/console.php command:arangodb restore --profile test-local --last --dry-run
# Target  : app @ tcp://127.0.0.1:8529 (local)
# Collections : thesaurus, produits, clients, commerciaux
# [OK] Dry run — nothing was restored.
```

---

## `collections` — inventaire

```bash
php bin/console.php command:arangodb collections           # collections métier (non-système)
php bin/console.php command:arangodb collections --system  # uniquement système (_apps, _jobs, …)
php bin/console.php command:arangodb collections --all     # toutes
```

Lecture seule, via l'API HTTP. Pratique pour préparer un `--collection` / `--ignore-collection` correct.

---

## `views` — gestion des Views ArangoSearch

```bash
php bin/console.php command:arangodb views                    # liste les Views (nom, type, collections liées)
php bin/console.php command:arangodb views --diff             # compare les déclarations des modèles au serveur
php bin/console.php command:arangodb views --sync             # crée les manquantes, resynchronise les driftées
php bin/console.php command:arangodb views --sync=placesView  # synchronisation ciblée (virgules acceptées)
php bin/console.php command:arangodb views --drop=a,b         # suppression ciblée
php bin/console.php command:arangodb views --drop             # sélection interactive (multi-choix)
```

Raccourci composer : `composer arango:views` (drapeaux après `--`, ex. `composer arango:views -- --diff`).

### Pourquoi

Le provisioning des modèles ([recherche View](../db/search-views.md)) est *create-if-missing* : changer le bloc `AQL::VIEW` ne met **pas** à jour une View existante — un champ ajouté n'est silencieusement pas indexé, un Analyzer changé ne matche presque plus rien. `views --diff` détecte ce drift, `views --sync` le répare via `updateProperties()` : la View reste interrogeable pendant que l'index inversé se reconstruit en arrière-plan, les options de la View (`commitIntervalMsec`, …) et les links d'autres collections ne sont pas touchés.

### Câbler `--diff` / `--sync` dans un projet hôte (notice)

`--list` et `--drop` marchent sans aucune configuration (connexion `[arango]` / options CLI). En revanche, `--diff` / `--sync` lisent les déclarations `AQL::VIEW` de **vos modèles** — trois étapes dans le projet hôte :

1. **Activer l'action** — ajouter `ArangoAction::VIEWS` au tableau `CommandParam::ACTIONS` de la définition DI de la commande (voir [Câblage DI](#câblage-di) plus haut).
2. **Lister les modèles à inspecter** — `ArangoCommandParam::MODELS => [ Models::PLACES , Models::PRODUCTS ]` : les **ids de conteneur** des définitions `Documents`, exactement comme `Arango::MODEL` côté contrôleurs.
3. **Déclarer la View au modèle** — chaque modèle inspecté porte son bloc `AQL::VIEW` (`Search::NAME` / `Search::ANALYZER` / `Search::FIELDS`, voir [Recherche View](../db/search-views.md)).

Un modèle listé sans bloc `AQL::VIEW` est simplement signalé « no View declared » et ignoré. Chaque modèle est interrogé sur **sa** base (`AQL::DATABASE`).

```bash
$ php bin/console.php command:arangodb views --diff

 Diff the declared views
 -----------------------

 ~ placesView (models.places) — drifted
     · places.fields.description : not indexed on the server
 ✓ productsView (models.products) — in sync

 Orphan views (declared by no configured model) : legacyView
 Use `views --drop=name` to remove them explicitly.

$ php bin/console.php command:arangodb views --sync
 ✓ placesView (models.places) — resynchronized
 ✓ productsView (models.products) — in sync
```

### Statuts du rapport

| Statut | Signification | Effet de `--sync` |
|---|---|---|
| `inSync` | la View serveur correspond à la déclaration | aucun |
| `missing` | déclarée mais absente du serveur | création |
| `drifted` | champ non indexé, Analyzer différent, champ retiré encore indexé, … | `updateProperties()` |
| `invalid` | déclaration mal formée, Analyzer ou collection introuvable, conflit de type | jamais touché |
| `unreachable` | serveur injoignable | jamais touché |

À savoir :

- `--diff` est **sans effet de bord** : la commande coupe le provisioning lazy des modèles inspectés via l'entrée `lazy` du conteneur (`LazyTrait`) avant de les résoudre — rien n'est créé pendant un rapport.
- Les **Views orphelines** (sur le serveur, déclarées par aucun modèle configuré) sont listées en pied de rapport — rapport seul, la suppression passe toujours par un `--drop` explicite.
- Le code de sortie est en échec dès qu'un modèle est `unreachable` — intégrable tel quel dans un script de déploiement (`bun pull`, CI, …).
- En PHP, les mêmes primitives sont disponibles directement : `$model->viewDiff()` / `$model->viewSync()` (retournent un `DiffReport`), et côté façade `$db->viewDiff( $name , $links )` / `$db->viewSync( $name , $links )`.

---

## `doctor` — bilan de santé de la structure

```bash
php bin/console.php command:arangodb doctor                  # rapport : collections, index, Views + orphelins
php bin/console.php command:arangodb doctor --apply          # crée le manquant, resynchronise les Views
php bin/console.php command:arangodb doctor --apply --force  # + drop & recreate des index driftés
php bin/console.php command:arangodb doctor --prune          # suppression interactive des orphelins
```

Raccourci composer : `composer arango:doctor` (drapeaux après `--`, ex. `composer arango:doctor -- --apply`).

### Pourquoi

Le provisioning lazy des modèles est *create-if-missing* — et pour les index, il ne joue même **qu'à la création de la collection** : un index ajouté au bloc `AQL::INDEXES` d'un modèle dont la collection existe déjà n'est **jamais créé**, sur aucun environnement, sans aucune erreur (les requêtes passent en full scan en silence). `doctor` est la commande de mise en conformité : elle compare tout ce que les modèles déclarent (`AQL::COLLECTION` + type, `AQL::INDEXES`, `AQL::VIEW`) à l'état réel du serveur, et `--apply` répare.

```bash
$ php bin/console.php command:arangodb doctor

 Diagnose the declared structure
 -------------------------------

 models.places
   ✓ places [collection] — in sync
   ~ places [indexes] — drifted
       · byName : missing on the server
   ✓ placesView [view] — in sync

 Orphans (declared by no configured model) :
     · collection : old_imports
 Use `doctor --prune` (interactive) to remove them explicitly.

 1 model(s) — 2 in sync, 0 missing, 1 drifted, 0 invalid, 0 unreachable ; 1 orphan(s).
```

### Ce que vérifie le rapport

| Objet | Vérifications | Réparation `--apply` |
|---|---|---|
| Collection | existence, type (2 = document, 3 = edge) | création (avec ses index) ; un type drifté n'est **jamais** réparé (recréer = perdre les documents → migration) |
| Index | présence de chaque index déclaré, définition champ à champ (`fields` **ordonnés**, `unique`, `sparse`, …), index serveur non déclarés | création des manquants ; les driftés sont **annoncés** et rebâtis (drop + recreate) seulement avec `--force` |
| View | le rapport `views --diff` complet (champs, analyzers, cohérence de la déclaration) | `viewSync()` (`updateProperties()`) |
| Orphelins | collections (non-système) et Views du serveur déclarées par aucun modèle | jamais automatique — `--prune` interactif uniquement |

Pourquoi `--force` est séparé : un index est **immuable** — le réparer signifie le supprimer puis le recréer, avec une fenêtre où les requêtes le perdent, et un index `unique` peut échouer à se recréer si des doublons sont apparus entre-temps. On ne fait pas ça d'office dans un `--apply` de routine.

### Code de sortie « bilan de santé »

Le mode rapport échoue (exit ≠ 0) dès que quelque chose est `missing`, `drifted`, `invalid` ou `unreachable` — `doctor` vert garantit que la structure correspond aux déclarations (intégrable en CI). Les **orphelins ne font pas échouer** (c'est un avertissement). En mode `--apply`, la commande échoue seulement si quelque chose n'a pas pu être réparé.

### Câblage et usage en PHP

Le câblage est **le même que `views`** ([notice](#câbler---diff----sync-dans-un-projet-hôte-notice)) : `ArangoAction::DOCTOR` dans `ACTIONS`, et la même clé `ArangoCommandParam::MODELS` sert aux deux actions. Workflow de déploiement type : `git pull` → `composer install` → `arangodb doctor --apply` → la structure est conforme.

Les mêmes opérations sont disponibles directement sur les modèles — `$model->diagnose()` (lecture seule, liste de `DiffReport` : collection, index, View) et `$model->repair( force: bool )` — et sur la façade : `$db->collectionDiff()`, `$db->indexesDiff()` / `indexesSync()`, `$db->viewDiff()` / `viewSync()`.

---

## `migrate` — migrations versionnées des données

```bash
php bin/console.php command:arangodb migrate --create "description multilingue"  # génère une coquille
php bin/console.php command:arangodb migrate --status      # appliquées / en attente, pour CETTE base
php bin/console.php command:arangodb migrate --dry-run     # liste les en attente, sans rien exécuter
php bin/console.php command:arangodb migrate              # applique les en attente (avec confirmation)
php bin/console.php command:arangodb migrate --yes        # applique sans confirmation (bun pull / CI)
php bin/console.php command:arangodb migrate --down       # annule la dernière appliquée
php bin/console.php command:arangodb migrate --down=3     # annule les 3 dernières
php bin/console.php command:arangodb migrate --forget=20260612090000_AddKind   # secours
```

Raccourci composer : `composer arango:migrate -- --status`.

### `doctor` ou `migrate` ? La règle « vue de ta chaise »

Ce sont **deux mondes séparés qui ne se croisent jamais** :

| Tu modifies… | Outil | Migration ? |
|---|---|---|
| une déclaration DI — collection, index, Analyzer, bloc `AQL::VIEW` (la **structure**) | `doctor --apply` | **non**, jamais |
| le **contenu** de documents déjà en base — transformer, normaliser, dédoublonner, *backfill* | `migrate` | **oui**, une petite migration |

`doctor` ne réclame **jamais** une migration. Tu écris une migration uniquement le jour où tu dois retravailler des données existantes — en pratique, une poignée par an. Exemple : tu passes `description` de texte simple à `{ fr, en }`. Ajouter le champ multilingue à la déclaration DI → `doctor`. Transformer les vieux documents (`"texte"` → `{ fr: "texte", en: null }`) → une migration, parce qu'une transformation de données est un **algorithme**, pas un état descriptible en configuration.

### Anatomie d'une migration

`migrate --create "…"` génère une **coquille vide** — la classe, l'horodatage, des `up()`/`down()` vides — et affiche son chemin. L'outil ne devine rien : c'est toi qui écris l'intention dans `up()`.

```php
// api/src/fr/bouney/migrations/Version20260612090000_DescriptionMultilingue.php
namespace fr\bouney\migrations ;

use oihana\arango\migrations\Migration ;

class Version20260612090000_DescriptionMultilingue extends Migration
{
    public function description() : string { return 'description string → { fr, en }' ; }

    public function up() : void
    {
        // AQL libre — l'échappatoire pour toute transformation
        $this->query( 'FOR doc IN places FILTER TYPENAME(doc.description) == "string"
                       UPDATE doc WITH { description: { fr: doc.description, en: null } } IN places' ) ;
    }

    public function down() : void
    {
        $this->query( 'FOR doc IN places FILTER TYPENAME(doc.description) == "object"
                       UPDATE doc WITH { description: doc.description.fr } IN places' ) ;
    }
}
```

La classe `Migration` reçoit la façade `ArangoDB` (`$this->db`) — donc l'AQL libre via `$this->query()`, le CRUD des collections et même les primitives doctor — et une **boîte à outils** d'opérations courantes pour ne pas écrire d'AQL à la main :

```php
public function up() : void
{
    $this->renameField( 'contacts' , 'tel' , 'phone' ) ;   // "tel" → "phone" sur tous les documents
    $this->dropField( 'places' , 'legacy' ) ;              // retire un attribut obsolète
    $this->setDefault( 'orders' , 'status' , 'pending' ) ; // backfill là où le champ est absent / null
}
```

### La règle d'or sur l'édition

Une migration a deux vies :

- **pas encore appliquée** (chez toi, en local) → tu l'édites **librement** ; tu peux la tester en boucle (`migrate`, `migrate --down`, ré-édite, `migrate`) ;
- **déjà appliquée** (commitée, partie en préprod/prod) → **on ne la modifie plus jamais** : elle est marquée « faite » dans le suivi de chaque base, donc une ré-édition ne repartirait nulle part. Correction = **nouvelle migration**.

C'est ce qui garantit que prod = préprod = local.

### Où vivent les fichiers, et le câblage

Les fichiers `Version*.php` vivent dans **ton projet hôte** (ex. `api/src/fr/bouney/migrations/`, sous le namespace `fr\bouney\migrations`). La lib ne fournit que la classe mère `Migration` et le moteur. Trois clés d'init de la commande :

```php
// api/definitions/@commands/arangodb.php
ArangoCommandParam::MIGRATIONS_PATH      => $c->get( Paths::MIGRATIONS ) ,   // le dossier des Version*.php
ArangoCommandParam::MIGRATIONS_NAMESPACE => 'fr\\bouney\\migrations' ,        // leur namespace PHP
ArangoCommandParam::MIGRATIONS_COLLECTION => 'migrations' ,                   // la collection de suivi (défaut)
```

et `ArangoAction::MIGRATE` dans `CommandParam::ACTIONS`.

### Le suivi en base

Chaque migration appliquée écrit **une ligne** dans la collection de suivi (`migrations`, une par base — parce que préprod, prod et ton poste sont à des niveaux différents que le code seul ne peut pas connaître). Le document est un value-object schema.org (`MigrationAction` ⊂ `UpdateAction`) : `_key` = version, `actionStatus` (`active` → `completed` | `failed`), `startTime`/`endTime`, `agent` (`user@host`), `error`, plus le hash **`gitCommit`** du commit courant — le chaînon entre la base et le code. Un run nominal insère la ligne en `active`, exécute `up()`, passe en `completed` ; si `up()` lève, la ligne passe en `failed` et le run **s'arrête net** (jamais de base à moitié migrée).

> Le suivi est **partagé avec `doctor --apply`** : chaque objet de structure réellement créé/réparé y est journalisé en `CreateAction` (distingué des migrations par son `additionalType`). Une collection, un vocabulaire, deux familles d'événements — `migrate` ignore les lignes `doctor`.

### Sécurité

- **Confirmation** : un `migrate` interactif affiche les migrations en attente puis demande `[y/N]`. `--yes` saute la confirmation (scripts) ; un run **non interactif sans `--yes` s'arrête** (jamais de migration de données silencieuse).
- **Rollback LIFO** : `--down` rembobine **depuis la fin** (la pile), jamais une migration du milieu. Une migration sans `down()` (le défaut no-op) est dé-suivie sans effet sur les données.
- **`--forget`** est une opération de **secours** : elle retire une ligne de suivi **sans** exécuter le `down()` — pour réparer un suivi incohérent (migration défaite à la main). Dangereux : la migration redevient « en attente ».

### Méthode de déploiement type

```bash
git pull                                  # le code + les nouvelles migrations
composer install
arango doctor --apply                     # 1) la structure se met en conformité (déclaratif)
arango migrate --yes                      # 2) les données se transforment (versionné)
```

L'ordre est toujours **structure puis données** : `doctor` d'abord, `migrate` ensuite.

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
