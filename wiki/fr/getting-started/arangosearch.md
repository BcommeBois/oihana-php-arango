# Comprendre ArangoSearch

ArangoSearch est le **moteur de recherche intégré** d'ArangoDB — l'équivalent d'un petit Elasticsearch vivant dans votre base, sans serveur supplémentaire. Cette page explique les concepts (Analyzer → View → `SEARCH` → scoring), ce qu'ils rendent possible, et comment chaque étage de cette bibliothèque s'y rattache. C'est le point de départ recommandé avant les pages [recette](../db/search-views.md) et [référence](../aql/aql-functions-search.md).

## Pourquoi — les quatre plafonds du `LIKE`

Le balayage [`?search=`](../db/search.md) classique (`LIKE '%terme%'` sur quelques champs) est simple et sain, mais il bute sur quatre plafonds de verre :

1. **Pas de pertinence.** `LIKE` répond oui ou non. Si 200 documents contiennent *bois*, ils sortent triés par nom — pas du meilleur au moins bon match.
2. **Pas de tolérance.** `behuard` ne trouve pas *Béhuard* (accent), `Behuart` non plus (faute de frappe).
3. **Pas de notion de mots.** Chercher `scierie rivière` exige cette sous-chaîne exacte quelque part ; deux mots présents dans deux champs, ou dans le désordre, ne matchent jamais.
4. **Pas d'index.** `LIKE '%…%'` ne peut pas être indexé : chaque recherche lit toute la collection. Invisible à 5 000 documents, douloureux à 500 000.

ArangoSearch lève les quatre — en déplaçant l'intelligence **au moment de l'indexation**.

## Les quatre briques

### L'Analyzer — la moulinette à texte

Un Analyzer transforme le texte en **jetons normalisés**, à l'indexation *et* à la requête, donc les deux côtés se rencontrent toujours sur le même terrain :

```
"Les Scieries de l'Évre !"   --text_fr-->   [ scieri, evre ]
```

Minuscules, accents enlevés, mots vides supprimés, et *racinisation* (« stemming » : réduction d'un mot à sa racine, *scieries* → `scieri`). C'est ce qui rend la recherche linguistique : `evre` trouve *l'Évre*. ArangoDB fournit des Analyzers texte pour ~10 langues (`text_fr`, `text_en`, …) ; on peut aussi fabriquer ses propres Analyzers. Tout est expliqué dans la page dédiée **[Analyzers](../db/analyzers.md)** (types, features, création d'un analyzer custom).

> Un Analyzer est **figé à l'indexation**. Le surcharger à la requête seule ne sert à rien (vous chercheriez des jetons racinisés à l'anglaise dans un index racinisé à la française) — ce qu'on change, c'est le *champ* cherché, et le bon Analyzer suit le champ.

### La View — l'index de recherche

Une View est une *collection virtuelle* qui indexe une ou **plusieurs** collections, champ par champ, chacun à travers un Analyzer :

```
View "thingsView"
 ├── places   : name → text_fr, description → text_fr
 └── products : name → text_fr
```

Sous le capot, c'est un **index inversé** : jeton → documents (l'inverse d'une collection). Vous l'interrogez comme une collection (`FOR doc IN thingsView`) et elle renvoie des documents de n'importe quelle collection liée — la réponse native à la recherche multi-collections.

### L'opération `SEARCH` — interroger via l'index

`SEARCH` est l'opération AQL qui interroge une View **à travers** son index inversé — contrairement à `FILTER`, qui post-traite. Son expression est le terrain des fonctions de recherche :

| Fonction | Sert à | Usage typique |
|---|---|---|
| `PHRASE` | jetons **adjacents, dans l'ordre** | matching « expression exacte » |
| `STARTS_WITH` | préfixe(s) | autocomplete |
| `LEVENSHTEIN_MATCH` | distance d'édition | tolérance aux fautes |
| `NGRAM_MATCH` / `MINHASH_MATCH` | similarité | quasi-doublons, codes approchés (Analyzers custom) |
| `MIN_MATCH` | au moins *n* sous-expressions | « 2 mots sur 3 suffisent » |
| `EXISTS` / `IN_RANGE` | présence / intervalle | tests structurés, accélérés par index |
| `BOOST` / `ANALYZER` | contexte | pondération, portée d'Analyzer |

### Les scorers — la pertinence

`BM25(doc)` (recommandé) et `TFIDF(doc)` donnent à chaque match un **score** (fréquence du terme, rareté, longueur du texte, boosts). `SORT BM25(doc) DESC` met les meilleurs résultats d'abord — la pièce **sans équivalent** dans un monde `LIKE`.

```aql
FOR doc IN placesView
  SEARCH ANALYZER( BOOST(PHRASE(doc.name, @q), 3) OR doc.description IN TOKENS(@q, "text_fr") , "text_fr")
  SORT BM25(doc) DESC
  LIMIT 20
  RETURN doc
```

Une requête : insensibilité accents/casse, matching par mots, le nom pesant 3× la description, les meilleurs d'abord, accélérée par index.

## Ce que ça rend possible

| Besoin | Ingrédients | Dans cette bibliothèque |
|---|---|---|
| Barre de recherche avec pertinence | match `TOKENS` + `BM25` | [bloc `AQL::VIEW`](../db/search-views.md), automatique |
| Priorité à l'expression exacte | `PHRASE` + `BOOST` | `Search::PHRASE => true` (global ou par champ) |
| Tolérance aux fautes | `LEVENSHTEIN_MATCH` | `Search::FUZZY => 1` (global ou par champ) |
| Pondération par champ | `BOOST` | `Search::FIELDS => ['name' => 3]` |
| Autocomplete | `STARTS_WITH` (tableau de préfixes) | helper [`startsWith()`](../aql/aql-functions-strings.md) |
| Requête scorée sur mesure | toute la grammaire | builder [`aqlScoredSearch()`](../aql/aql-operations.md) |
| Champs localisés (i18n) | sous-champ + Analyzer + locale par champ, recherche pilotée par `?lang=` | `'intro.en' => [Search::ANALYZER => 'text_en', Search::LANG => 'en']` |
| Recherche fédérée multi-collections | une View, plusieurs collections | prévu (modèle dédié read-only) |

## Comment vit une View, côté serveur

- **Une entité de première classe** — comme une collection : visible dans le web UI, gérée via `/_api/view`, créée une fois.
- **Elle se synchronise toute seule** — les inserts/updates/deletes des collections liées se propagent à l'index en arrière-plan (`commitIntervalMsec`, ~1 s) : *eventual consistency*. Un document frais est lisible instantanément, cherchable ~1 s après. La consolidation des segments et le nettoyage internes sont automatiques — vous ne videz ni ne reconstruisez jamais rien.
- **Indexation initiale** — créer une View sur une collection existante l'indexe en arrière-plan ; la View est interrogeable immédiatement mais incomplète tant que ce n'est pas fini.
- **Coût** — c'est un index : disque et RAM proportionnels aux champs indexés. Déclarez les champs utiles ; évitez `includeAllFields` sur de gros documents.
- **Dump/restore** — `arangodump` sauve les *définitions* des Views par défaut (l'index inversé est reconstruit au restore, en arrière-plan). Les Analyzers custom vivent dans la collection **système** `_analyzers`, exclue sauf `--include-system-collections` — les built-in (`text_fr`, …) sont toujours disponibles.

## La bibliothèque, étage par étage

| Étage | Ce qu'il vous donne | Page |
|---|---|---|
| Helpers de fonctions (`db/functions/search/`) | un helper PHP par fonction ArangoSearch (`phrase`, `boost`, `bm25`, …) | [Fonctions ArangoSearch](../aql/aql-functions-search.md) |
| Opération `aqlSearch()` | la clause `SEARCH … OPTIONS { … }` avec wrap Analyzer | [Opérations AQL](../aql/aql-operations.md) |
| Builder `aqlScoredSearch()` | la requête scorée complète, autonome | [Opérations AQL](../aql/aql-operations.md) |
| Bloc `AQL::VIEW` du modèle | `?search=` bascule sur la View, auto-provisioning, clé de tri `score`, totaux & facet counts synchronisés | [Recherche View](../db/search-views.md) |
| Clients Views & Analyzers | créer/mettre à jour/supprimer Views, Analyzers custom | [Clients ArangoSearch](../clients/arangosearch.md) |

## Bonnes pratiques et pièges

- **Jamais `==` avec un Analyzer texte** — les jetons indexés sont racinisés, un littéral brut ne l'est pas : `ANALYZER(doc.name == "bois", "text_fr")` ne matche rien. Utilisez `doc.name IN TOKENS(@q, "text_fr")` (les deux côtés analysés) ou `PHRASE` — la grammaire des modèles le fait pour vous.
- **Les features d'Analyzer comptent** — `BM25` exige `frequency` (+ `norm` pour la normalisation de longueur), `PHRASE` exige `position` + `frequency`. Les Analyzers texte built-in les ont ; les custom doivent les déclarer.
- **`FILTER` après `SEARCH` est légal mais non accéléré** — très bien quand le `SEARCH` a déjà réduit le set ; préférez les prédicats `SEARCH` (`EXISTS`, `IN_RANGE`) pour les grosses conditions structurées.
- **Drift de déclaration** — le provisioning des modèles est create-if-missing : changer le bloc `AQL::VIEW` ne met **pas** à jour une View existante (un champ ajouté n'est silencieusement pas indexé). Détectez l'écart avec `$model->viewDiff()` et réparez-le avec `$model->viewSync()` — ou en CLI : `command:arangodb views --diff` / `--sync` ([doc](../commands/arangodb.md#views--gestion-des-views-arangosearch)).

## Évolutions prévues

- **Recherche fine i18n** au-delà du `?lang=` déjà disponible ([Analyzer par champ](../db/search-views.md#analyzer-par-champ) et [recherche localisée `?lang=`](../db/search-views.md#recherche-localisée-lang)) : par exemple un même champ indexé avec plusieurs Analyzers.
- **Recherche fédérée multi-collections** — une View sur plusieurs collections, exposée par un triplet modèle/contrôleur/route dédié, read-only.

Le gating de la recherche par permissions est désormais [disponible](../db/search-views.md#permissions-de-recherche).

> La commande de gestion des Views, longtemps listée ici, est livrée : voir l'[action `views` de `command:arangodb`](../commands/arangodb.md#views--gestion-des-views-arangosearch) (lister, `--diff`, `--sync`, `--drop`).

## Voir aussi

- [Recherche View (ArangoSearch)](../db/search-views.md) — la recette modèle (`AQL::VIEW`, URLs, réponses JSON).
- [Fonctions ArangoSearch](../aql/aql-functions-search.md) — la référence des helpers.
- [Clients ArangoSearch](../clients/arangosearch.md) — gestion des Views et Analyzers.
- [Recherche `?search=`](../db/search.md) — le balayage `LIKE` simple, toujours le bon outil pour les petits modèles.
- [Documentation officielle — ArangoSearch](https://docs.arangodb.com/stable/index-and-search/arangosearch/).
