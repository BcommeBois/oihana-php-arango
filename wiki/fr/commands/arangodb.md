# `command:arangodb` — dump / restore / collections / views / doctor / migrate

`ArangoCommand` est la commande de maintenance d'une base ArangoDB : **sauvegarde** (`dump`), **restauration** (`restore`), **inventaire des collections** (`collections`), **gestion des Views ArangoSearch** (`views`), **bilan de santé de la structure** (`doctor`) et **migrations versionnées des données** (`migrate`). Elle est livrée pré-câblée par la lib sous le nom `command:arangodb` ([`definitions/commands.php`](../../../definitions/commands.php)) et s'utilise via `php bin/console.php command:arangodb <action> [options]`.

Elle hérite du squelette [`oihana/php-commands`](../getting-started/dependencies.md#oihanaphp-commands) (gestion des arguments/options/sorties via **Symfony Console**) : `Kernel` (classe de base), `CommandArg` (arguments), `CommandOption` (options communes : `--clear`, `--passphrase`, …), `ExitCode`, et des traits utilitaires (`IOTrait`, `EncryptTrait`). Le contexte est donc une application Symfony Console standard, alimentée par un conteneur **PHP-DI**.

> **Prérequis** : les binaires `arangodump` et `arangorestore` (fournis avec ArangoDB) doivent être dans le `$PATH` du processus PHP. macOS / Homebrew : `brew install arangodb`. La sous-commande `collections` et la validation des collections passent, elles, par l'**API HTTP** d'ArangoDB (client interne), pas par les binaires.

> 💡 **Pressé ?** La page [Stratégies de dump/restore](dump-restore-strategies.md) relie toutes ces briques en recettes prêtes à l'emploi (backup complet, extraction de test anonymisée, refresh local automatisé).

---

## Sommaire

- [Démarrage rapide](#démarrage-rapide) — scripts composer, le plus court chemin
- [Vocabulaire ArangoDB](#vocabulaire-arangodb) — collection, edge, View, analyzer…
- [Actions disponibles](#actions-disponibles)
- [Configuration](#configuration) — `[arango.dump]` / `[arango.restore]`, précédence des options
- [Câblage DI](#câblage-di)
- [Options CLI](#options-cli) — toutes les options en un tableau
- [`dump` — sauvegarde](#dump--sauvegarde) — base complète, sous-ensemble, label, exclusion, `--complete`
- [`restore` — restauration](#restore--restauration) — sélection d'archive, ciblage, garde-fous
- [Profils](#profils) — sélections nommées & externes (staging → local)
- [Masking — anonymisation au dump](#masking--anonymisation-au-dump) — anonymiser les PII, **tableau des maskers**
- [Rotation des archives](#rotation-des-archives) — élagage par rétention (`keep` / `max_age` / `max_total`)
- [`collections` — inventaire](#collections--inventaire)
- [`views` — gestion des Views ArangoSearch](#views--gestion-des-views-arangosearch)
- [`analyzers` — gestion des analyzers custom](#analyzers--gestion-des-analyzers-custom)
- [`doctor` — bilan de santé de la structure](#doctor--bilan-de-santé-de-la-structure)
- [`migrate` — migrations versionnées des données](#migrate--migrations-versionnées-des-données)
- [Scénario : migration sécurisée](#scénario--migration-sécurisée)
- [Base de jeu](#base-de-jeu--scriptsseed-playgroundphp)

> 👉 Pour des **recettes bout-en-bout**, voir [Stratégies de dump/restore](dump-restore-strategies.md).

---

## Démarrage rapide

> **Première fois ici ?** Le plus court chemin : la lib expose des **scripts
> composer** qui appellent la commande pour toi. Pour sauvegarder la base
> configurée :
>
> ```bash
> composer arango:dump
> # → <dumps>/2026-06-01T14:30:00-my_db.tar.gz
> ```

Chaque script composer est un **alias** de `php bin/console.php command:arangodb <action>` :

| Script composer | Équivaut à | Fait quoi |
|---|---|---|
| `composer arango` | `command:arangodb` | Point d'entrée (affiche l'aide / l'action demandée). |
| `composer arango:dump` | `command:arangodb dump` | Sauvegarde la base (archive horodatée). |
| `composer arango:restore` | `command:arangodb restore` | Restaure depuis une archive. |
| `composer arango:list` | `command:arangodb dump --list` | Liste les archives présentes. |
| `composer arango:views` | `command:arangodb views` | Gère les Views ArangoSearch. |
| `composer arango:analyzers` | `command:arangodb analyzers` | Gère les analyzers custom. |
| `composer arango:doctor` | `command:arangodb doctor` | Bilan de santé de la structure. |

Pour passer des **options** à un script composer, place-les **après `--`** :

```bash
composer arango:dump    -- --profile staging-extract --dry-run
composer arango:restore -- --last --yes
```

> Les deux formes sont strictement équivalentes. Cette page utilise surtout la
> forme longue `php bin/console.php command:arangodb …` (explicite) ; remplace-la
> par `composer arango:<action> -- …` quand c'est plus pratique.

---

## Vocabulaire ArangoDB

Quelques termes employés dans cette page, pour un lecteur qui découvre ArangoDB :

| Terme | Définition courte |
|---|---|
| **Collection** | Un ensemble de documents JSON — l'équivalent d'une « table ». |
| **Collection *document* / *edge*** | Une collection *document* stocke des objets ; une collection *edge* stocke des **liens** (`_from` → `_to`) entre documents, pour les graphes. |
| **Collection système** (`_…`) | Collections internes d'ArangoDB préfixées par `_` : `_users` (comptes), `_analyzers` (analyseurs de recherche), `_graphs` (graphes nommés)… **Exclues d'un dump par défaut** (voir [`--complete`](#backup-complet----complete)). |
| **View ArangoSearch** | Un index de recherche plein-texte au-dessus d'une ou plusieurs collections (action [`views`](#views--gestion-des-views-arangosearch)). |
| **Analyzer** | Une règle de découpage/normalisation du texte utilisée par les Views (stockée dans `_analyzers`). |
| **Archive / bucket** | Le fichier `.tar.gz` produit par un dump, et le groupe de rotation auquel il appartient ; voir le glossaire de la [page stratégies](dump-restore-strategies.md#concepts-clés). |

---

## Actions disponibles

| Action | Trait | Description |
|---|---|---|
| `dump` | [`ArangoDumpAction`](../../../src/oihana/arango/commands/actions/ArangoDumpAction.php) | Archive `arangodump` horodatée. Base complète ou sous-ensemble (`--collection` / `--ignore-collection`), chiffrée AES si `--encrypt`. |
| `restore` | [`ArangoRestoreAction`](../../../src/oihana/arango/commands/actions/ArangoRestoreAction.php) | Réinjection via `arangorestore` depuis une archive sélectionnée par `--last`, `--date`, `--file` ou interactivement. Restauration de tout ou d'un sous-ensemble (`--collection`). |
| `collections` | [`ArangoListCollectionsAction`](../../../src/oihana/arango/commands/actions/ArangoListCollectionsAction.php) | Liste les collections de la base via l'API HTTP. Portées : métier (défaut), `--system`, `--all`. |
| `listDumps` (`--list`) | [`ArangoListDumpsAction`](../../../src/oihana/arango/commands/actions/ArangoListDumpsAction.php) | Liste les fichiers d'archive présents dans le dossier de dumps. |
| `views` | [`ArangoViewsAction`](../../../src/oihana/arango/commands/actions/ArangoViewsAction.php) | Gestion des Views ArangoSearch via l'API HTTP : liste (défaut), `--diff` / `--sync` contre les déclarations `AQL::VIEW` des modèles, `--drop` ciblé ou interactif. |
| `analyzers` | [`ArangoAnalyzersAction`](../../../src/oihana/arango/commands/actions/ArangoAnalyzersAction.php) | Gestion des analyzers **custom** depuis le registre `analyzers` : liste (défaut), `--diff` / `--sync` (crée les manquants, signale les driftés), `--sync --force` (répare un drift en place avec cascade sur les Views). |
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
# maskings               = "/etc/oihana/maskings.json"   # fichier natif (voir « Masking »)

[arango.restore]
threads   = 4
protected = ["users", "sessions"]    # garde-fou, pas une option — voir « Garde-fous du restore »
```

> `protected` (restore) et `masking` (`[arango.dump.masking]`, table compilée) sont
> des clés **qui ne sont pas** des options des binaires : la première est une
> politique de sécurité (voir [Garde-fous du restore](#garde-fous-du-restore)), la
> seconde une forme conviviale (voir [Masking](#masking--anonymisation-au-dump)) —
> toutes deux retirées avant le lancement du binaire.

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
| `--complete` | — | dump | Backup complet : toutes les collections utilisateur **plus** `_analyzers` et `_graphs` — voir [Backup complet](#backup-complet----complete). |
| `--label` | `-L` | dump, restore | Libellé optionnel ajouté au nom de l'archive (ex. `pre-migration`). |
| `--all` | — | collections | Liste **toutes** les collections (système + métier). |
| `--system` | — | collections | Liste **uniquement** les collections système (`_…`). |
| `--encrypt` | `-e` | dump, restore | Chiffrement AES de l'archive (dump) / déchiffrement (restore). |
| `--passphrase` | `-p` | dump, restore | Passphrase pour `--encrypt`. Prompt interactif sinon. |
| `--directory` | `-dir` | toutes | Override du dossier de dump/restore. |
| `--list` | `-l` | dump, restore | Liste les archives présentes au lieu d'agir. |
| `--include-system` | — | dump, restore | Inclut les collections système (`_analyzers`, `_graphs`, …). |
| `--maskings` | — | dump | Chemin d'un fichier maskings JSON natif `arangodump` — anonymise le dump (gagne sur tout masking configuré). Voir [Masking](#masking--anonymisation-au-dump). |
| `--no-views` | — | dump | Ignore les définitions de Views ArangoSearch (dumpées par défaut). |
| `--all-databases` | — | dump, restore | Dump / restaure **toutes** les bases au lieu d'une seule. |
| `--overwrite` | — | dump | Écrase le dossier de sortie s'il existe déjà. |
| `--threads` | — | dump, restore | Nombre de threads parallèles. |
| `--view` | — | restore | Restreint la restauration à ces Views (répétable / séparées par virgules). |
| `--profile` | — | dump, restore | Profil nommé (`[arango.profiles.<nom>]`) ou chemin vers un fichier de profil `.toml` — voir [Profils](#profils). |
| `--dry-run` | — | dump, restore, migrate | Affiche le plan résolu (et les migrations en attente) sans rien exécuter. |
| `--apply` | — | doctor | Répare : crée le manquant (collections, index, Views), resynchronise les Views. |
| `--force` | — | doctor, restore, analyzers | doctor : avec `--apply`, autorise le drop + recreate des index driftés. analyzers : avec `--sync`, répare un analyzer drifté en place (drop + recreate + reconstruction des Views dépendantes) ; avec `--prune`, supprime aussi les orphelins encore utilisés par une View. restore : écrase les collections **protégées** (voir [Garde-fous du restore](#garde-fous-du-restore)). |
| `--fix` | — | analyzers | Génère une migration de réparation prête à relire par analyzer drifté (chemin B, même nom) au lieu de toucher la base — nécessite `migrationsPath`. |
| `--prune` | — | doctor, analyzers, dump | doctor : sélection interactive des orphelins à supprimer. analyzers : supprime les analyzers custom orphelins déclarés par personne (les utilisés nécessitent `--force` ; confirmation, `--yes` pour sauter). dump : élague les vieilles archives selon la politique de rétention (run élagage-seul ; combiner avec `--dry-run`) — voir [Rotation des archives](#rotation-des-archives). |
| `--create` | — | migrate | Génère la coquille vide d'une migration avec cette description. |
| `--status` | — | migrate | Tableau des migrations appliquées / en attente pour cette base. |
| `--yes` | `-y` | migrate, restore, analyzers | Saute la demande de confirmation (applique les migrations / restaure / élague les analyzers). |
| `--down` | — | migrate | Annule les N dernières migrations appliquées, défaut 1 (rembobinage LIFO). |
| `--forget` | — | migrate | Secours : retire une ligne de suivi **sans** exécuter son `down()`. |
| `--diff` | — | views, analyzers | Compare les déclarations (`AQL::VIEW` des modèles / registre `analyzers`) à l'état serveur (lecture seule). |
| `--sync[=a,b]` | — | views, analyzers | views : crée les Views manquantes et resynchronise les driftées. analyzers : crée les analyzers manquants, signale les driftés (réparation avec `--force` ou `--fix`). |
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
php bin/console.php command:arangodb dump          # ou : composer arango:dump
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
# Lister d'abord les archives disponibles :
php bin/console.php command:arangodb dump --list             # ou : composer arango:list

php bin/console.php command:arangodb restore                 # menu interactif
php bin/console.php command:arangodb restore --last          # la plus récente — ou : composer arango:restore -- --last
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

### Garde-fous du restore

Le `restore` est la **seule action destructrice** : il écrit dans une base réelle.
Quatre garde-fous l'encadrent.

**1. Collections protégées (`[arango.restore] protected`).** Liste déclarée côté
déploiement — le restore **refuse** d'écraser ces collections **sauf `--force`**.
À mettre vos collections d'authentification, pour qu'un restore complet lancé par
erreur ne les écrase jamais.

```toml
# config.toml
[arango.restore]
protected = ["users", "sessions", "permissions"]
```

```bash
php bin/console.php command:arangodb restore --last
# [ERROR] Refusing to overwrite protected collection(s): users — rerun with --force to override.

php bin/console.php command:arangodb restore --last --force --yes
# [WARNING] --force: this WILL overwrite protected collection(s): users
```

> `protected` est une **politique**, jamais une option d'`arangorestore` : la clé
> est retirée des options passées au binaire. C'est `--force` qui la lève, par run.

**2. Confirmation (`--yes`).** Avant l'écriture, le restore demande confirmation
(comme `migrate`). `--yes` saute le prompt (CI, `bun pull`). Un run
**non-interactif sans `--yes`** s'arrête, par sécurité — jamais d'écrasement
silencieux.

```bash
php bin/console.php command:arangodb restore --last          # demande « Restore into 'app' ? [y/N] »
php bin/console.php command:arangodb restore --last --yes    # sans prompt
```

**3. Avertissement cible non-locale.** Si l'endpoint cible n'est pas local
(`localhost` / `127.0.0.1` / `::1`), un avertissement est affiché — pour éviter de
restaurer sur staging/prod en croyant viser local. C'est un **avertissement**, pas
un blocage (on restaure parfois à distance volontairement).

```
[WARNING] The target endpoint is NOT local: tcp://staging.internal:8529 — make sure
you are not overwriting a staging/production database.
```

**4. Validation de la sélection.** Une collection demandée (`--collection` ou profil)
**absente de l'archive** déclenche un avertissement (typo / mauvaise archive). Non
bloquant.

> **L'archive source n'est consommée qu'en cas de succès.** Un restore refusé par un
> garde-fou (protégé, confirmation déclinée) laisse la sauvegarde **intacte**.

Tous ces garde-fous sont visibles à blanc avec `--dry-run` (cible, liste, conflits
protégés connus, avertissement non-local), sans rien écrire.

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
| `directory` | Un dossier de sortie optionnel — où `dump` écrit son archive (voir ci-dessous). `dump` uniquement. |
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

### Dossier de sortie par profil

Un profil peut fixer **son propre dossier de dump** via la clé `directory` : tout
`dump` utilisant ce profil y range son archive, sans avoir à retaper
`--directory`.

```toml
[arango.profiles.staging-extract]
directory   = "/backups/staging"
collections = ["thesaurus", "produits"]
exclude     = ["secrets"]
```

```bash
# Écrit l'archive dans /backups/staging :
php bin/console.php command:arangodb dump --profile staging-extract
```

**Précédence du dossier de sortie** (le plus prioritaire gagne) :

| Source | Priorité |
|---|---|
| `--directory` (CLI) | la plus forte |
| `directory` du profil | moyenne |
| `[app].dumps` (global) | la plus faible |

C'est une option **dump uniquement** : le `restore` écrit toujours dans la cible
locale (cf [Garde-fous du restore](#garde-fous-du-restore)) et ignore le
`directory` du profil. Le dossier finalement retenu sert aussi de cible à la
rotation post-dump, à `dump --prune --profile <nom>` et au listing
`dump --list --profile <nom>` (qui affiche donc les archives **de ce profil**,
même précédence `--directory` CLI > profil > global).

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

## Masking — anonymisation au dump

Extraire un sous-ensemble **staging → local** (les profils), c'est manipuler des
**PII réelles**. Le masking anonymise les données au moment du dump : l'archive
elle-même est propre, donc transportable et restaurable sans risque. **Au dump
uniquement.**

Deux voies indépendantes :

1. **Forme conviviale → moteur PHP intégré** (recommandé). La table `[…masking]`
   est appliquée par un moteur de masking **en PHP**, en post-traitement des
   fichiers du dump. Il fonctionne sur **toutes les éditions d'ArangoDB**
   (Community comprise) — c'est le cas courant.
2. **Fichier natif `--maskings`** → le masking natif d'`arangodump`.
   > ⚠ Le data masking natif d'`arangodump` nécessite l'édition **Enterprise**.

Si un fichier natif est présent (CLI `--maskings` ou `[arango.dump] maskings`), il
prend le dessus et le moteur PHP est désactivé.

### Forme conviviale (le cas courant, toutes éditions)

Une table à clés plates pointées, dans un profil ou dans `[arango.dump.masking]` :

```toml
[arango.profiles.test-local.masking]
"clients"       = "masked"                                   # mode collection (optionnel)
"clients.email" = "email"                                    # masker simple → implique « masked »
"clients.phone" = "phone"
"clients.card"  = { type = "xifyFront", unmaskedLength = 4 } # masker paramétré (inline table)
"clients.address.city" = "random"                            # chemin imbriqué
```

- Clé **sans point** = `<collection>` (ou `*`, défaut pour toutes) → **mode**. Le
  moteur PHP traite le mode `masked` ; pour exclure des collections, utilise plutôt
  la sélection (`--collection` / le profil) — un mode `structure`/`exclude`/`full`
  déclaré ici lève une erreur claire.
- Clé **avec point** = `<collection>.<chemin>` (1er segment = collection, le reste =
  chemin, imbriqué possible) → règle d'attribut ; la collection passe en `masked`.
- Valeur = nom de masker (voir le tableau **Les maskers en détail** plus bas), ou
  inline-table `{ type = …, param = … }` pour passer des paramètres.
- Chemins : `email` (feuille au sommet), `a.b` (chemin exact), `.email` (à toute
  profondeur), `*` (toutes les feuilles), tableaux masqués par élément. Les attributs
  système `_key`/`_id`/`_rev`/`_from`/`_to` ne sont **jamais** masqués.
- Un masker/mode inconnu → erreur claire listant le vocabulaire valide.

#### Les maskers en détail

Chaque règle d'attribut applique un **masker** : il remplace la valeur réelle par une
valeur factice mais **plausible** (même forme / même type), de sorte que les données
restent réalistes pour des tests tout en étant anonymisées. Les paramètres se passent
via l'inline-table (ex. `{ type = "xifyFront", unmaskedLength = 4 }`).

| Masker | Ce qu'il fait | Paramètres (défaut) | Exemple |
|---|---|---|---|
| `email` | Remplace par un email aléatoire **non routable** (TLD `.invalid`). | — | `jean@ex.com` → `aZ12.bY34@cX56.invalid` |
| `phone` | Garde la forme : chaque **chiffre** → chiffre aléatoire, chaque **lettre** → lettre aléatoire (casse gardée), le reste inchangé. Valeur non-string → `default`. | `default` (`"+1234567890"`) | `+33 6 12 34` → `+71 4 88 09` |
| `creditCard` | Numéro de carte aléatoire **valide selon Luhn** (16 chiffres, renvoyé en entier). | — | `4111-1111-…` → `4143300214110028` |
| `zip` | Code postal aléatoire de même forme (chiffre→chiffre, lettre→lettre, casse gardée). Non-string → `default`. | `default` (`"12345"`) | `SA34-EA` → `OW91-JI` |
| `randomString` | Chaîne aléatoire de longueur proche de l'originale. **Strings uniquement** — nombres/booléens/null **inchangés**. | — | `"Jean Dupont"` → `"x7Bqz9aK1m"` |
| `random` | Valeur aléatoire **du même type** : string→chaîne, entier→`[-1000,1000]`, flottant→idem, booléen→aléatoire, `null`→`null`. | — | `42` → `-738` · `true` → `false` |
| `xifyFront` | Masque l'**avant de chaque mot** par `x`, en gardant les derniers caractères. Non-string → `"xxxx"`, `null`→`null`. | `unmaskedLength` (`2`), `hash` (`false`), `seed` (`0`) | `"secret"` → `"xxxxet"` |
| `datetime` | Date/heure **aléatoire** entre `begin` et `end`, formatée selon `format` (jetons AQL `DATE_FORMAT` : `%yyyy`/`%mm`/`%dd`/`%hh`/`%ii`/`%ss`). `format` vide → chaîne vide. | `begin` (`1970-01-01…`), `end` (maintenant), `format` (`""`) | `"2001-09-11"` → `"2019-06-17"` (format `%yyyy-%mm-%dd`) |
| `integer` | Entier aléatoire dans `[lower, upper]`. Remplace **quel que soit le type** d'origine. | `lower` (`-100`), `upper` (`100`) | `9999` → `42` |
| `decimal` | Flottant aléatoire dans `[lower, upper]`, arrondi à `scale` décimales. Remplace quel que soit le type. | `lower` (`-1`), `upper` (`1`), `scale` (`2`) | `3.14159` → `-0.42` |

> ℹ️ Le moteur PHP vise l'**équivalence sémantique** (retirer la PII en valeurs valides
> et typées), pas une sortie identique au binaire Enterprise — les valeurs factices sont
> aléatoires à chaque exécution.

```bash
php bin/console.php command:arangodb dump --profile test-local
# … Masking : 3 data file(s) anonymized (PHP engine).
```

### Fichier natif (échappatoire Enterprise, pleine puissance)

Pour la pleine puissance d'`arangodump` (sur Enterprise), on passe directement le
fichier JSON natif d'ArangoDB :

```bash
php bin/console.php command:arangodb dump --maskings /etc/oihana/maskings.json
```

```toml
[arango.dump]
maskings = "/etc/oihana/maskings.json"
```

`--dry-run` indique la voie de masking retenue (fichier natif, ou N entrées via le
moteur PHP) sans rien écrire.

---

## Rotation des archives

Rien ne purge le dossier de dumps : il grossit indéfiniment. La rotation supprime
les vieilles archives selon une politique configurable. C'est une **action
destructrice** et **opt-in total** : sans politique de rétention configurée (et
sans `--prune`), **rien n'est jamais supprimé**.

**Bucket** = la *signature du suffixe* de l'archive (`{database}[-partial][-{label}]`,
soit le nom de fichier sans la date ISO ni l'extension). Les archives de même nature
rotent ensemble : les fulls de `mydb`, les `mydb-partial-pre-migration`, les dumps
labellisés d'un profil… sont des buckets distincts.

### Politique de rétention

```toml
[arango.dump.retention]
keep      = 7            # garder les 7 plus récentes par bucket
max_age   = "P30D"       # durée ISO 8601 : supprimer au-delà de cet âge (P30D / P6M / P1Y)
max_total = "5G"         # plafond disque global (taille), appliqué en dernier
auto      = true         # élaguer automatiquement après chaque dump réussi (défaut : off)

[arango.dump.retention.buckets]   # surcharges par bucket (clé = signature suffixe)
"mydb-partial-pre-migration" = 3
```

- **`keep`** : nombre d'archives récentes gardées **par bucket** (surchargé par `[…buckets]`).
- **`max_age`** : une **durée ISO 8601** (`P30D` = 30 jours, `P6M` = 6 mois, `P1Y` = 1 an…).
  Quand `keep` **et** `max_age` sont posés, la règle est **conservatrice** : une archive
  n'est supprimée que si elle est **à la fois** au-delà de `keep` **et** plus vieille que `max_age`.
- **`max_total`** : plafond de taille total (tous buckets), `"5G"` / `"500M"` ou un nombre
  d'octets. Appliqué **en dernier** : si le total dépasse, on supprime les plus vieilles
  globalement jusqu'à repasser sous le plafond.

**Garde-fous** : **plancher ≥ 1 par bucket** (jamais la dernière archive d'un bucket),
**jamais l'archive qu'on vient de créer**, et `--dry-run` qui liste sans rien supprimer.

### Déclencher la rotation

```bash
# Élagage seul (ne crée pas de dump) :
php bin/console.php command:arangodb dump --prune
php bin/console.php command:arangodb dump --prune --dry-run   # liste ce qui serait supprimé

# Automatique après chaque dump réussi (avec [arango.dump.retention] auto = true) :
php bin/console.php command:arangodb dump
```

> Sans critère (`keep` / `max_age` / `max_total`), `--prune` prévient et ne supprime rien ;
> `auto = true` seul (sans critère) n'élague rien non plus.

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

### Vues `search-alias` fédérées

Au-delà des vues `arangosearch` pilotées par les modèles, `--diff` / `--sync` réconcilient aussi le registre niveau-base des vues **`search-alias`** — des vues qui agrègent un index `inverted` par collection et n'appartiennent à aucun modèle (le substrat d'une recherche fédérée multi-collections, voir [client ArangoSearch](../clients/arangosearch.md)). On les déclare via la clé d'init `searchAliasViews` (`ArangoCommandParam::SEARCH_ALIAS_VIEWS`), une liste de `SearchAliasView` :

```php
use oihana\arango\db\options\views\SearchAliasView ;

ArangoCommandParam::SEARCH_ALIAS_VIEWS =>
[
    new SearchAliasView( 'global_search' , [ 'customers' => 'inv_search' , 'products' => 'inv_search' ] ) ,
] ,
```

`--diff` signale chaque vue search-alias déclarée (manquante / en phase / driftée sur son ensemble `{collection, index}`, ou `invalid` si une vue du serveur de ce nom est d'un autre type) ; `--sync` crée les manquantes et répare un drift par **drop + recreate** (sûr — l'alias ne porte aucune donnée, les index inversés sous-jacents survivent). L'action tourne avec le registre seul (sans modèles), et les noms search-alias déclarés sont exclus de la note des orphelins. L'index `inverted` de chaque collection se provisionne comme tout index (`collectionIndexes` / `InvertedIndex`).

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

## `analyzers` — gestion des analyzers custom

Gère les analyzers **custom** déclarés dans le registre `analyzers` (clé
`ArangoCommandParam::ANALYZERS` — voir [le câblage hôte](#câblage-côté-projet-hôte)).
Pour comprendre ce qu'est un analyzer et comment le déclarer, voir la page
dédiée [Analyzers](../db/analyzers.md).

```bash
php bin/console.php command:arangodb analyzers                  # liste les analyzers custom (built-in comptés à part)
php bin/console.php command:arangodb analyzers --diff           # compare le registre déclaré à l'état serveur
php bin/console.php command:arangodb analyzers --sync           # crée les manquants, signale les driftés
php bin/console.php command:arangodb analyzers --sync --force   # répare aussi les driftés en place (cascade Views)
php bin/console.php command:arangodb analyzers --fix            # génère une migration de réparation par analyzer drifté (ne touche aucune base)
php bin/console.php command:arangodb analyzers --prune          # supprime les analyzers custom orphelins déclarés par personne (confirmation)
php bin/console.php command:arangodb analyzers --prune --force  # supprime aussi les orphelins encore utilisés par une View (la laisse pendante)
# raccourci composer : composer arango:analyzers -- --diff
```

- **Liste (défaut)** : affiche les analyzers custom (ceux préfixés `dbname::`),
  les built-in (`identity`, `text_*`) étant résumés par un compteur.
- **`--diff`** : pour chaque `AnalyzerDefinition` déclaré, un statut
  (`in sync` / `missing` / `drifted` / `invalid` / `unreachable`) + les
  analyzers custom **orphelins** (sur le serveur, déclarés par personne) en pied.
- **`--sync`** : crée les analyzers **manquants** ; un analyzer **drifté** est
  seulement **signalé** — il est immuable, sa correction (drop + recreate +
  reconstruction des Views dépendantes) est une opération consciente.
- **`--sync --force`** : exécute cette réparation **en place**. ⚠️ Non
  transactionnel et la recherche des Views dépendantes est dégradée le temps de
  la reconstruction — le chemin sans casse reste une migration « nouveau nom ».
- **`--fix`** : pour chaque analyzer **drifté**, génère une **migration de
  réparation** prête à relire (le drop + recreate même nom, chemin B) — la forme
  différée et versionnée de `--sync --force`. Il écrit des fichiers et **ne
  touche jamais** la base : on relit, puis on lance `migrate`. Nécessite
  `migrationsPath` configuré (la même clé que `migrate`). Le `up()` de la
  migration reconstruit l'analyzer déclaré avec un `RawAnalyzer` et appelle
  `analyzerSync( $def , force: true )` ; son `down()` reste un commentaire (une
  réparation n'est pas auto-réversible).
- **`--prune`** : supprime les analyzers custom **orphelins** (sur le serveur,
  déclarés par personne), après **confirmation** (`--yes` saute la demande ; un
  run non interactif sans `--yes` refuse). Un orphelin encore **utilisé** par une
  View n'est supprimé qu'avec `--force` (il laisse la View pendante) — sinon il
  est seulement signalé. Les built-in et les analyzers déclarés ne sont **jamais**
  élagués.
  > ⚠️ Sur une base **partagée**, un analyzer orphelin peut appartenir à une
  > autre application — c'est pourquoi `--prune` est opt-in. Voir la page
  > [Analyzers](../db/analyzers.md).

> En PHP, les mêmes primitives sont sur la façade : `$db->analyzerDiff( $def )` /
> `$db->analyzerSync( $def , force: … )` et `$db->analyzerDependentViews( $name )`.

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
| Analyzers custom | chaque `AnalyzerDefinition` du registre `analyzers` : présence + définition (`type` / `properties` / `features`) | création des **manquants** ; un analyzer **drifté** n'est **jamais** réparé ici (immuable → sa cascade est réservée à `arango:analyzers --fix` / `--force`), seulement signalé |
| Orphelins | collections (non-système) et Views du serveur déclarées par aucun modèle | jamais automatique — `--prune` interactif uniquement |

> La **collection de suivi des migrations** (`migrationsCollection`, défaut `migrations`) n'est **jamais** un orphelin : aucun modèle ne la déclare, mais `migrate` **et** `doctor --apply` y écrivent leur journal. Elle est exclue par son **nom configuré** — renommer la collection de suivi est honoré, et elle est ainsi tenue hors de la sélection `--prune`. Le renommage est un changement de configuration, pas une migration de données : l'ancienne collection reste côté serveur sous son ancien nom et redevient alors un orphelin légitime (à supprimer ou migrer à la main si on ne veut pas la voir listée).

Pourquoi `--force` est séparé : un index est **immuable** — le réparer signifie le supprimer puis le recréer, avec une fenêtre où les requêtes le perdent, et un index `unique` peut échouer à se recréer si des doublons sont apparus entre-temps. On ne fait pas ça d'office dans un `--apply` de routine.

### Code de sortie « bilan de santé »

Le mode rapport échoue (exit ≠ 0) dès que quelque chose est `missing`, `drifted`, `invalid` ou `unreachable` — `doctor` vert garantit que la structure correspond aux déclarations (intégrable en CI). Les **orphelins ne font pas échouer** (c'est un avertissement). En mode `--apply`, la commande échoue seulement si quelque chose n'a pas pu être réparé.

### Câblage et usage en PHP

Le câblage est **le même que `views`** ([notice](#câbler---diff----sync-dans-un-projet-hôte-notice)) : `ArangoAction::DOCTOR` dans `ACTIONS`, et la même clé `ArangoCommandParam::MODELS` sert aux deux actions. Workflow de déploiement type : `git pull` → `composer install` → `arangodb doctor --apply` → la structure est conforme.

Les mêmes opérations sont disponibles directement sur les modèles — `$model->diagnose()` (lecture seule, liste de `DiffReport` : collection, index, View) et `$model->repair( force: bool )` — et sur la façade : `$db->collectionDiff()`, `$db->indexesDiff()` / `indexesSync()`, `$db->viewDiff()` / `viewSync()`.

### Index déclarés par collection (registre autonome)

Quand plusieurs modèles pointent la **même** collection, déclarer un index sur chacun via `AQL::INDEXES` est fragile : `doctor` traite chaque modèle séparément, et tout index serveur non déclaré par un modèle compte comme une dérive — des déclarations divergentes sur une collection partagée ne peuvent donc **jamais** toutes être « in sync ». Par ailleurs, `diagnose()` ne contrôle les index d'un modèle **que** s'il en déclare : un seul porteur par collection suffit.

La clé d'init `ArangoCommandParam::COLLECTION_INDEXES` déclare les index **par collection**, indépendamment des modèles — une map `nomCollection => IndexOptions[]` que `doctor` réconcilie **une fois par collection** (`indexesDiff` en rapport, `indexesSync` sous `--apply`). Chaque valeur est **la même liste `IndexOptions[]` que `AQL::INDEXES`** (objets `IndexOptions` *ou* définitions brutes), donc un helper d'index existant se réutilise tel quel. Par commodité, un `IndexOptions` **seul** est aussi accepté à la place d'une liste à un élément (un tableau brut reste, lui, toujours la liste) :

```php
// définition de la commande, à côté de MODELS
ArangoCommandParam::COLLECTION_INDEXES =>
[
    'places' => [ new PersistentIndexOptions([ IndexOptions::NAME => 'id' , IndexOptions::FIELDS => [ 'id' ] , IndexOptions::UNIQUE => true ]) ] , // liste
    'people' => new PersistentIndexOptions([ IndexOptions::NAME => 'id' , IndexOptions::FIELDS => [ 'id' ] , IndexOptions::UNIQUE => true ]) ,      // un seul index : l'enveloppe liste est optionnelle
] ,
```

Les modèles qui pointent une collection couverte par le registre **ne déclarent plus** `AQL::INDEXES` (ils ne sont alors vérifiés que sur l'existence de la collection). Les collections du registre rejoignent l'ensemble déclaré — jamais signalées orphelines — et `doctor` accepte un run **registre seul** (sans `MODELS`). Rétro-compatible : un modèle qui déclare encore ses index continue de fonctionner inchangé.

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

> **Migrations pré-remplies (générées automatiquement).** Au-delà de la coquille
> vide, `MigrationGenerator::create()` accepte un corps `up()` / `down()` déjà
> rempli : `create( $description, null, $up, $down )` injecte ce code PHP dans la
> migration générée. Un paramètre `uses` (liste de noms de classes complets)
> ajoute les `use …;` en tête du fichier, pour que le corps injecté référence
> ses classes par leur nom court (`Migration` est toujours importé ; les imports
> sont dédupliqués et triés). C'est le mécanisme qu'utilise `arango:analyzers --fix`
> pour **écrire pour toi** une migration de réparation prête à relire (drop +
> recreate d'un analyzer drifté + reconstruction des Views dépendantes), au lieu
> de te laisser la rédiger à la main. Tu relis la migration générée, puis tu
> l'appliques avec `arango:migrate` — rien n'est exécuté à la génération.

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
