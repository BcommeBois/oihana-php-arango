# Feuille de route

Ce document est **prospectif** : il décrit où en est la bibliothèque aujourd'hui
et ce qui est prévu ou à l'étude pour la suite. Il est indicatif, pas
contractuel — les priorités peuvent évoluer.

Le relevé exhaustif et daté de ce qui a été livré vit dans
[`CHANGELOG.md`](../../CHANGELOG.md) ; ce fichier ne le **duplique** délibérément
pas. La bibliothèque est versionnée par *tags* git (pas de champ `version` dans
`composer.json`) et suit le [versionnage sémantique](https://semver.org).

> English version: [`wiki/en/roadmap.md`](../en/roadmap.md).

## Où en est-on

À la version **1.5.0** (publiée le 2026-07-18), la bibliothèque couvre
l'essentiel de la surface AQL, une chaîne d'outils opérationnelle complète et un
moteur de recherche fédérée :

- **Les 22 opérations haut-niveau** (`FOR`, `FILTER`, `SORT`, `LIMIT`, `LET`,
  `COLLECT`, `WINDOW`, `RETURN`, `INSERT`, `UPDATE`, `REPLACE`, `UPSERT`,
  `REMOVE`, traversée de graphe, `PRUNE`, `SEARCH`, `WITH`, `OPTIONS`, …).
- **~190 fonctions AQL** réparties entre chaînes, nombres (dont distances
  vectorielles/ANN), dates, tableaux, documents, binaire et géospatial.
- **ArangoSearch** : les clients View/Analyzer, le DSL `SEARCH` / recherche
  scorée, un bloc `AQL::VIEW` au niveau modèle (recherche classée par
  pertinence), et un **DSL de recherche par champ** (boost / fuzzy / analyzer /
  langue / phrase / permissions, **plusieurs analyzers par champ** pour
  l'autocomplétion, et **sous-champs de tableaux d'objets** via `[*]`, ex.
  `contactPoints[*].email` — par champ et au niveau de la View). Types
  d'analyzers constructibles : `identity` / `norm` / `stem` / `text` / `ngram`.
- **Recherche fédérée multi-collections** : vues `search-alias` au-dessus d'un
  index inversé par collection, le moteur autonome `FederatedSearch` (deux phases
  *find* → *rebuild*, contrôle des permissions par collection), et un triplet
  HTTP en lecture seule (`SearchRoute` → `FederatedSearchController` → moteur).
- **DSL de projection de champs** : projection des métadonnées d'arête
  (`Field::SCOPE`), enveloppement d'une référence sous une clé avec ses propres
  sous-arêtes/jointures (`Filter::WRAP`), routage d'URL par type (`Field::PATHS`)
  et projection conditionnelle (`Field::WHEN` / `Field::ELSE`).
- **Diagnostics de requête** : `explain()` typé et profilage sur la façade et le
  modèle.
- **Outillage opérationnel** : dump / restore (profils, masquage, rotation),
  commandes de maintenance (`views`, `doctor`, `migrate`) et le cycle de vie des
  analyzers personnalisés (`analyzers` : diff / sync / fix / prune, intégré à
  `doctor`).
- **Relations polymorphes & contrôle d'accès en profondeur** : jointures et
  arêtes dont la collection cible est choisie à la volée selon un champ
  discriminant du document (`Arango::DISCRIMINATOR` / `MAP` / `FALLBACK`, gating
  *fail-closed* par branche), ancrage d'une relation à un chemin absolu
  (`Arango::SOURCE`, découplé du nom de sortie), un scope serveur qui peut être
  une disjonction (`injectFilterGroup()`), et un verrouillage de permissions
  *fail-closed* descendu au **sous-champ exact** sur `?filter=` / `?sort=` /
  `?facets=` / `?groupBy=`.
- **Thésaurus & hiérarchies** : projection d'une relation auto-référente à
  **profondeur variable** (`AQL::MIN_DEPTH` / `MAX_DEPTH`), métadonnées de chemin
  (`_parent` / `_depth` via `AQL::WITH_PATH`), reconstruction d'arbre
  `buildTree()`, et deux contrôleurs HTTP génériques — navigation d'arête
  auto-référente (`TraversalController`) et exposition SKOS `ConceptScheme`
  (`ConceptSchemeController`), tous deux avec enveloppe `count` / `total`.
- **Transactions**, **18+ types d'index** (dont un index vectoriel), le moteur
  complet de filtres / facettes / regroupement (y compris les quantificateurs
  `quant` de tableaux **et** de relations — `any` / `all` / `none` / `n` — sur
  les filtres d'arêtes et de jointures), le masquage extrait dans
  [`oihana/php-masking`](https://github.com/BcommeBois/oihana-php-masking), et une
  couverture de tests de 100 % en lignes/méthodes.

## Stratégie de versionnage

La `1.0.0` est sortie avec toutes les opérations AQL haut-niveau prises en charge
et une API publique stable. Tout ce qui a suivi est **additif** — nouvelles
fonctions, opérations, clients et outillage sont non cassants et sortent en
versions **mineures**. Seule exception : un durcissement de sécurité peut changer
un défaut (ainsi le passage de `?sort=` en liste blanche *fail-closed* en
`1.5.0`) — ces cas sont signalés `(BREAKING)` dans le `CHANGELOG`.

- **`1.0.0`** — publiée le 2026-06-09 (toutes les opérations AQL haut-niveau).
- **`1.1.0`** — publiée le 2026-06-10 (vectoriel/ANN, analyse de requête,
  consolidation des fonctions).
- **`1.2.0`** — publiée le 2026-06-14 (DSL ArangoSearch, dump/restore, commandes
  de maintenance).
- **`1.3.0`** — publiée le 2026-06-20 (DSL de recherche View par champ, cycle de
  vie des analyzers personnalisés, DSL de projection de champs, vues
  `search-alias`, recherche fédérée).
- **`1.4.0`** — publiée le 2026-06-21 (quantificateurs `quant` de relations sur
  les filtres d'arêtes et de jointures).
- **`1.5.0`** — publiée le 2026-07-18 (jointures & arêtes **polymorphes** ;
  ancrage de relation `Arango::SOURCE` ; verrouillage de permissions
  *fail-closed* généralisé sur `?filter=` / `?sort=` / `?facets=` / `?groupBy=`,
  descendu au sous-champ exact — le passage de `?sort=` en liste blanche stricte
  est **cassant** ; traversées hiérarchiques à profondeur variable + `buildTree()`
  et métadonnées de chemin ; contrôleurs génériques `TraversalController` /
  `ConceptSchemeController` ; scope serveur disjonctif `injectFilterGroup()` ;
  `PipelineAnalyzer` typé ; champs de recherche sur tableaux d'objets (`[*]`),
  type d'analyzer `ngram`, plusieurs analyzers par champ, et recherche n-gram par
  seuil de similarité).
- **Prochaine mineure** — rien n'est encore cumulé sous `[Unreleased]` ; les
  prochains ajouts y seront regroupés. Coupée quand Marc le décide.

## Backlog (à trier)

Éléments prospectifs pas encore planifiés, regroupés grossièrement par thème.

### Filtrage & DSL de requête

- **Condition multi-attributs `match` sur arêtes & jointures** — la surface
  tableau prend en charge une condition `match` (plusieurs sous-champs sur le même
  objet, ex. `members[*]` + `match {active:true, role:'admin'}`) ; l'étendre aux
  traversées de relations pour qu'un seul filtre d'arête/jointure puisse
  contraindre le sommet lié sur plusieurs attributs à la fois. Complément naturel
  de la généralisation `quant` (ex. « aucun membre avec
  `{active:false, role:'admin'}` »). Les comparaisons d'agrégat (sum/avg/min/max)
  et l'appartenance par clé sur une relation ne sont **volontairement pas** dans
  ce périmètre — elles vivent déjà dans `?facets` (`EDGE_AGGREGATE` /
  `JOIN_AGGREGATE`, et la négation `"-key"`).

### Recherche fédérée & ArangoSearch

- **Parité `[*]` sur les compteurs de facettes** (effort **S**, *prochain*) — les
  sous-champs avec expansion de tableau sont pris en charge par `?filter=` et par
  la recherche View, mais **pas par les compteurs de facettes** (`?facetCounts=`) :
  `FacetCountsQueryTrait` valide un attribut simple via `assertAttributeName`, ce
  qui rejette `offers[*].priceCurrency`. Étendre la sous-requête de comptage avec
  la même convention `Operator::ARRAY_EXPANSION` + `stripArrayExpansion()` — émettre
  `FOR item IN doc.offers COLLECT value = item.priceCurrency …`. C'est de la pure
  parité de notation (le côté comptage atteint les mêmes chemins que les côtés
  filtre et recherche acceptent déjà) ; cela ne **couple pas** les facettes aux
  filtres.
- **Suites du moteur fédéré** (effort **M**, à cadrer) — les lots délibérément
  différés pendant la conception C1–C5 :
  - *Contrôles de classement / tri plus riches* — `find()` est figé sur un `BM25`
    global `DESC`. Trois leviers, par utilité : **boost par source** (faire
    ressortir un client avant un produit), choix du *scorer* + réglage `k`/`b`
    (le bas-niveau `aqlScoredSearch()` le supporte déjà), et une clé de tri
    secondaire.
  - *Provenance par type* (`additionalType` → modèle) — `rebuild()` résout
    **collection → un modèle** ; pour une collection polymorphe (ex. `places`),
    router `collection:additionalType → modèle`. À ne construire que lorsqu'une
    collection réelle en a besoin.
- **Réconciliation `search-alias` dans `doctor`** (effort **S/M**) — refléter la
  réconciliation du registre analyzers/index (le pattern A5) pour le registre
  niveau-base `searchAliasViews`, afin que `doctor` signale/répare les vues
  search-alias manquantes ou dérivées.
- **Recherche corrélée sur tableaux d'objets** (effort : doc **S**) — la recherche
  sur tableau d'objets d'aujourd'hui (`contactPoints[*].email`) est **non
  corrélée** : elle trouve un document dont *un* élément contient un *token*, mais
  ne peut pas exiger que *le même* élément satisfasse deux conditions. La réponse
  supportée est **uniquement documentaire** : utiliser `?filter=`
  (`contactPoints[*]` + `match`/`quant`), qui corrèle déjà *en dehors* de l'index.
  L'alternative au niveau de l'index (champs `nested` d'ArangoSearch) est réservée
  à l'édition Enterprise — voir la section Enterprise ci-dessous.
- **Contrôles de scoring** (effort **S/M**) — `Search::SCORE` est figé sur
  `BM25(doc)` ; exposer le réglage `k`/`b` de `BM25` (et `TFIDF`) (les helpers
  `bm25()` / `tfidf()` et `aqlScoredSearch()` les acceptent déjà, seul le DSL
  niveau modèle fige le *scorer*). Nécessite d'abord une introduction en langage
  clair (BM25 vs TFIDF) — pas urgent.
- **Surlignage / `OFFSET_INFO`** (effort : helper **S**, pipeline **M/L**) —
  renvoyer les positions des correspondances pour surligner des extraits. Deux
  étapes : livrer le helper `offsetInfo()` manquant (l'enum
  `SearchFunction::OFFSET_INFO` existe, pas le helper), puis brancher les positions
  dans le pipeline de résultats du modèle (requiert la *feature* d'analyzer
  `offset`).
- **Types d'analyzers supplémentaires** (effort **S** par type simple) —
  `delimiter`, `stopwords`, `pipeline`, `aql`, `geo_*`, `segmentation`, `minhash`,
  … en classes `AnalyzerOptions` dédiées (aujourd'hui `identity` / `norm` / `stem`
  / `text` / `ngram` sont exposés). Ce n'est pas un manque de capacité :
  `RawAnalyzer($type, $properties)` crée déjà n'importe quel type de manière
  générique — les classes dédiées sont du confort de constructeur typé. À
  prioriser selon le besoin réel.
- **Options de View typées** (effort **S**) — `primarySortCompression` /
  `optimizeTopK` sont passés non typés aujourd'hui ; les modéliser comme le reste
  des options de View.
- **Petits trous du DSL** (effort **S–M** chacun, opportunistes) — **`SEARCH`
  *wildcard*** (`atel*`, son propre lot quand il sera planifié), une facette
  `MINHASH_MATCH` (le helper existe, pas de facette DSL), proximité de phrase /
  **slop**, une facette `MIN_MATCH`, et l'accélération `primarySort` dans le DSL
  `AQL::VIEW`.

### Enterprise (hors périmètre open-source)

Fonctionnalités liées à l'**édition Enterprise** d'ArangoDB. La bibliothèque
open-source ne les modélise **pas** aujourd'hui ; si un projet adopte Enterprise,
on ajoutera les classes dédiées à ce moment-là.

- **Champs `nested` sur les links de View** — indexer un tableau d'objets de sorte
  que *le même* élément puisse être contraint à satisfaire plusieurs conditions
  (vraie recherche corrélée à l'intérieur de l'index). Le contournement non
  Enterprise est ci-dessus (*Recherche corrélée* → `?filter=`).
- **Option `cache` des links** — le cache de valeurs par link/champ d'Enterprise
  (`ArangoSearchLink` ne modélise pas `cache` aujourd'hui).
- **SmartGraphs / SatelliteGraphs** — stratégies de *sharding* de graphe
  d'Enterprise.

### Plateforme & opérations

- **Helpers de transactions stream / JavaScript plus riches.**
- **Diagnostics de cluster / shard.**
- **Hook de sauvegarde avant migration** — un `--backup` / `backupBeforeMigrate`
  optionnel qui prend un instantané avant d'appliquer une migration (différé du
  chantier dump/restore).
- **Pregel** (analyse de graphe distribuée) — large surface, niche ;
  probablement hors périmètre.
