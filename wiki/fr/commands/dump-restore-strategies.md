# Stratégies de dump / restore

Cette page **relie les briques** de la commande `command:arangodb` en recettes
concrètes, bout en bout. Pour le détail de chaque option, voir la page de
référence [arangodb.md](arangodb.md).

> 💡 **Raccourcis composer** — les exemples ci-dessous utilisent la forme longue
> `php bin/console.php command:arangodb …`. La lib fournit des **alias composer**
> équivalents ; passe les options **après `--`** :
>
> ```bash
> composer arango:dump    -- --profile staging-extract --dry-run
> composer arango:restore -- --last --yes
> composer arango:list                          # = dump --list
> ```
>
> `composer arango:dump` ≡ `php bin/console.php command:arangodb dump` (idem pour
> `arango:restore`, `arango:views`, `arango:doctor`).

## Concepts clés

Quelques mots de vocabulaire utilisés partout ci-dessous :

- **Archive** — un fichier `.tar.gz` horodaté produit par un `dump`, nommé
  `{date}-{base}[-partial][-{label}].tar.gz` (ex. `2026-06-01T14:30:00-mydb.tar.gz`).
- **Bucket** — un groupe d'archives **de même nature** (même base, même ciblage,
  même label). La rotation raisonne **par bucket** : les sauvegardes complètes de
  `mydb` forment un bucket, les extractions `mydb-partial-staging` un autre, etc.
- **Élagage (rotation)** — la **suppression des vieilles archives** selon une
  politique de rétention. « Élaguer » = supprimer les archives qui sortent de la
  politique (trop nombreuses et/ou trop vieilles). Rien n'est jamais supprimé tant
  qu'aucune politique n'est configurée : la rotation est **opt-in**.
- **`auto = true`** — déclenche l'élagage **automatiquement après chaque dump
  réussi**. Sans cette option, l'élagage ne se produit que si tu lances
  explicitement `dump --prune`.
- **`--dry-run`** — affiche le plan (ce qui serait dumpé / restauré / élagué) **sans
  rien exécuter**. Disponible sur `dump`, `restore` et `dump --prune`.

## Où sont écrites les archives ?

Trois sources possibles, de la moins à la plus prioritaire :

- **Par défaut** : le dossier `[app].dumps` de `config.toml` (voir
  [Configuration](arangodb.md#configuration)).
- **Par profil** : un profil peut désormais porter sa propre clé `directory`
  (`[arango.profiles.X] directory = "/backups/X"`) — tout dump utilisant ce
  profil y écrit son archive, sans avoir à retaper `--directory`. Côté **dump
  uniquement** : le restore écrit toujours en local (cf
  [Garde-fous du restore](arangodb.md#garde-fous-du-restore)).
- **Par run** : l'option `--directory /chemin` surcharge tout le temps d'une
  commande.

**Précédence du dossier de sortie** (le plus prioritaire gagne) :

| Source | Exemple | Priorité |
|---|---|---|
| `--directory` (CLI) | `--directory /tmp/x` | la plus forte |
| `directory` du profil | `[arango.profiles.X] directory = "/backups/X"` | moyenne |
| `[app].dumps` (global) | `[app] dumps = "…/dumps"` | la plus faible |

```bash
# Range automatiquement le dump du profil dans /backups/staging :
php bin/console.php command:arangodb dump --profile staging-extract

# …sauf si --directory force un autre dossier le temps de ce run :
php bin/console.php command:arangodb dump --profile staging-extract --directory /tmp/oneshot
```

(Le dossier finalement retenu sert aussi de cible à la rotation de ce run, et à
`dump --prune --profile X`.)

## Quelle brique pour quel besoin ?

| Besoin | Brique | Détail |
|---|---|---|
| Des défauts d'options persistants (threads, vues, …) | `[arango.dump]` / `[arango.restore]` | [Configuration](arangodb.md#configuration) |
| Extraire toujours le même sous-ensemble | **Profil** (`--profile`) | [Profils](arangodb.md#profils) |
| Sauvegarde vraiment complète (+ `_analyzers`/`_graphs`) | `--complete` | [dump](arangodb.md#dump--sauvegarde) |
| Empêcher un restore d'écraser l'auth | `protected` + confirmation | [Garde-fous du restore](arangodb.md#garde-fous-du-restore) |
| Anonymiser les PII (RGPD) | **Masking** (moteur PHP, toutes éditions) | [Masking](arangodb.md#masking--anonymisation-au-dump) |
| Empêcher le dossier de dumps de grossir | **Rotation** (`[arango.dump.retention]`) | [Rotation des archives](arangodb.md#rotation-des-archives) |
| Tout rejouer sans interaction (CI / bun) | `--yes` (+ `auto = true`) | [Garde-fous du restore](arangodb.md#garde-fous-du-restore) |

---

## Recette A — Sauvegarde complète récurrente

Le cas « backup nightly » : une sauvegarde complète, chiffrée, dont les vieilles
archives sont élaguées toutes seules.

```toml
# config.toml
[arango.dump]
complete = true                 # toutes les collections + _analyzers / _graphs

[arango.dump.retention]
keep = 30                       # garder les 30 archives les plus récentes (par bucket)
auto = true                     # élaguer automatiquement après chaque dump réussi
```

```bash
# Sauvegarde chiffrée ; l'élagage s'exécute juste après, automatiquement :
php bin/console.php command:arangodb dump --encrypt --passphrase "$BACKUP_KEY"

# Restauration sur un serveur vierge — réinclure les collections système :
php bin/console.php command:arangodb restore --last --include-system --yes \
    --encrypt --passphrase "$BACKUP_KEY"
```

Ce qui se passe :

- `--complete` ajoute **uniquement** `_analyzers` et `_graphs` (jamais `_users`).
- Après l'écriture de l'archive du jour, `auto = true` lance l'élagage : il **garde
  les 30 archives les plus récentes** de ce bucket et **supprime les plus
  anciennes**. L'archive qu'on vient de créer n'est **jamais** supprimée, et il
  reste **toujours au moins 1** archive par bucket.
- Sans `[arango.dump.retention]`, **rien n'est élagué** (opt-in).

> **`keep` est un nombre d'archives, pas une durée.** En une sauvegarde par jour,
> `keep = 30` ≈ un mois ; en deux par jour, ≈ deux semaines. Pour raisonner en
> **âge**, utilise plutôt `max_age` (une durée ISO 8601) :
>
> ```toml
> [arango.dump.retention]
> max_age = "P1M"   # supprimer les archives de plus d'un mois (P30D, P6M, P1Y…)
> auto    = true
> ```
>
> Tu peux poser **les deux** : la règle est alors **conservatrice** — une archive
> n'est supprimée que si elle est **à la fois** au-delà de `keep` **et** plus vieille
> que `max_age` (donc on garde si elle est dans les N plus récentes **ou** plus jeune
> que `max_age`). Pratique pour un plancher double : « au moins 30 archives, et au
> moins un mois d'historique ».

---

## Recette B — Extraction de test (staging → local, anonymisée)

Le cas RGPD : pomper un sous-ensemble de **staging** vers le **local** pour tester
sur des données réalistes, **sans PII** et **sans toucher à l'authentification**.

```toml
# config.toml — le profil porte sa SOURCE (staging) et son anonymisation
[arango.profiles.staging-extract]
collections = ["thesaurus", "products", "clients"]
edges       = ["product_thesaurus"]
endpoint    = "tcp://staging.internal:8529"   # source : sert au dump UNIQUEMENT
database    = "app_staging"
user        = "readonly"
password    = ""

[arango.profiles.staging-extract.masking]
"clients.email"        = "email"
"clients.phone"        = "phone"
"clients.address.city" = "random"

# garde-fou : interdire l'écrasement de l'auth locale
[arango.restore]
protected = ["users", "sessions", "permissions"]
```

```bash
# 1) Dump anonymisé DEPUIS staging (le moteur PHP masque — toutes éditions) :
php bin/console.php command:arangodb dump --profile staging-extract

# 2) Restauration en LOCAL (la connexion du profil est ignorée au restore) :
php bin/console.php command:arangodb restore --last
```

Ce qui se passe :

- La connexion du profil est la **source** : le `dump` s'y connecte ; le `restore`
  l'**ignore** et écrit **toujours** dans la cible locale (`[arango]`). Un profil ne
  peut donc jamais repousser ses données sur le serveur d'où elles viennent.
- Le masking agit **au moment du dump** : l'archive est déjà propre, transportable
  sans risque RGPD.
- `protected` bloque tout écrasement de `users`/`sessions`/`permissions` au restore
  (sauf `--force`). Le restore demande aussi confirmation et avertit si la cible
  n'est pas locale.

> Vérifie à blanc avant d'exécuter : ajoute `--dry-run` au dump **comme** au restore.

---

## Recette C — Refresh local automatisé (CI / bun)

La **même extraction** que la recette B, mais **non interactive** et auto-élaguée —
pour un script qui rafraîchit la base locale régulièrement (intégration continue,
`bun pull`, …).

```toml
# Réutilise le profil staging-extract de la recette B.
# On ne garde que quelques refresh récents :
[arango.dump.retention]
keep = 3                        # garder les 3 dernières extractions (par bucket)
auto = true                     # même mécanisme d'élagage que la recette A
```

```bash
php bin/console.php command:arangodb dump    --profile staging-extract
php bin/console.php command:arangodb restore --last --yes
```

Ce qui se passe :

- `auto = true` est **exactement le même élagage** qu'en recette A — seule la
  politique change (ici `keep = 3` au lieu de 30) : on conserve les 3 dernières
  extractions et on supprime les plus anciennes.
- `--yes` saute la confirmation du restore. **Sans `--yes`, un run non interactif
  s'arrête** (par sécurité — jamais d'écrasement silencieux).
- Un avertissement s'affiche si la cible de restore n'est **pas locale**.

---

## Sûreté en un coup d'œil

- **Rotation opt-in** : aucune suppression sans politique de rétention (ni `--prune`).
- **Plancher ≥ 1 par bucket** + **jamais l'archive courante**.
- **Restore** : collections `protected` refusées sauf `--force` ; confirmation
  obligatoire (ou `--yes`) ; arrêt si non interactif sans `--yes` ; avertissement
  cible non locale ; l'archive source n'est consommée que sur **succès**.
- **`--dry-run` partout** (`dump`, `restore`, `dump --prune`) : montre le plan,
  n'exécute rien.
