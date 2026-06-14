# Recherche View (ArangoSearch) — `?search=` classé par pertinence

Déclarez une **View ArangoSearch** sur un modèle `Documents` (le bloc `AQL::VIEW`) et le paramètre [`?search=`](search.md) bascule, automatiquement et **sans aucun changement d'URL**, du simple balayage `LIKE` vers une recherche **accélérée par index et classée par pertinence** : matching linguistique (tokenisation, stemming, accents), boosts par champ, bonus d'expression exacte, tolérance aux fautes, et un score `BM25` qui classe les meilleurs résultats d'abord.

> ArangoSearch est nouveau pour vous (Analyzers, Views, scoring) ? Commencez par lire notre page dédiée [Comprendre ArangoSearch](../getting-started/arangosearch.md).

## Déclaration du modèle

```php
use oihana\arango\models\Documents ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\models\enums\Search ;

$places = new Documents( $container ,
[
    AQL::COLLECTION => 'places' ,
    AQL::VIEW =>
    [
        Search::NAME     => 'placesView' ,   // le nom de la View (requis)
        Search::ANALYZER => 'text_fr' ,      // Analyzer des champs cherchés
        Search::FIELDS   =>
        [
            'name'        => 3 ,             // champ => boost (le nom pèse 3×)
            'description' => 1 ,
        ] ,
        Search::PHRASE   => true ,           // bonus d'expression exacte (boost ×2)
        Search::FUZZY    => 1 ,              // tolérance Levenshtein (0 = off)
    ] ,
]) ;
```

| Clé | Type | Rôle |
|---|---|---|
| `Search::NAME` | `string` | **Requis** — le nom de la View. Sans lui le bloc est inerte et `?search=` reste le balayage `LIKE`. |
| `Search::ANALYZER` | `string` | Analyzer utilisé pour indexer **et** interroger les champs (défaut `identity` — déclarez un Analyzer texte pour la recherche linguistique). Surchargeable par champ — voir ci-dessous. |
| `Search::FIELDS` | `array` | Map `champ => boost` (ou `champ => [ Search::BOOST => n, Search::FUZZY => d ]` pour des options par champ). Chemins pointés supportés. Fallback sur `AQL::SEARCHABLE` (boost 1). |
| `Search::PHRASE` | `bool` | Ajoute un bonus d'expression exacte : un match `PHRASE()` pèse `boost × 2`. |
| `Search::FUZZY` | `int` | Tolérance aux fautes globale : `LEVENSHTEIN_MATCH` avec cette distance d'édition maximale (valeur valide `0`–`4`, `0` = off). Surchargeable par champ — voir ci-dessous. |

### Tolérance aux fautes par champ

`Search::FUZZY` peut être déclaré **par champ** dans une entrée tableau de `Search::FIELDS`, en miroir exact de `Search::BOOST`. Une même View peut alors tolérer les fautes sur les champs texte tout en restant **exacte** sur les codes ou identifiants (où une tolérance ramènerait le mauvais enregistrement) :

```php
Search::FIELDS =>
[
    'name' => [ Search::BOOST => 3 , Search::FUZZY => 1 ] , // texte : tolérant
    'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] , // code : exact
    'slogan' => 2 ,                                          // forme courte conservée (boost 2)
] ,
Search::FUZZY => 1 , // défaut au niveau de la View
```

Règle de résolution : un champ qui déclare `Search::FUZZY` l'emporte (un **`0` explicite désactive** la tolérance pour ce champ) ; un champ sans clé `FUZZY` hérite du `Search::FUZZY` de la View ; sans valeur globale, la tolérance est désactivée. Comportement **100 % rétro-compatible** : une déclaration sans fuzzy par champ produit exactement l'AQL d'avant.

### Analyzer par champ

De la même façon, `Search::ANALYZER` peut être déclaré **par champ**. Une même View peut alors indexer (et interroger) un champ français avec `text_fr` et un champ anglais avec `text_en` :

```php
Search::FIELDS =>
[
    'name'    => 3 ,                                  // Analyzer de la View
    'summary' => [ Search::ANALYZER => 'text_en' ] ,  // surcharge par champ
] ,
Search::ANALYZER => 'text_fr' , // défaut au niveau de la View
```

Règle de résolution : un champ qui déclare `Search::ANALYZER` l'emporte ; sinon il hérite du `Search::ANALYZER` de la View (lui-même `identity` par défaut). L'Analyzer étant **figé à l'indexation**, une surcharge par champ se répercute des deux côtés : le link de la View indexe le champ avec son Analyzer, et la requête regroupe les expressions par Analyzer — un `ANALYZER(…, "<analyzer>")` par groupe, le tout en `OR`. Avec un seul Analyzer la sortie est strictement celle d'avant.

> **Drift** — changer l'Analyzer d'un champ modifie le link de la View. Comme tout changement de déclaration, il ne met pas à jour une View déjà créée : resynchronisez avec `$model->viewSync()` ou `arangodb views --sync`. Un analyzer (modèle ou par champ) inconnu du serveur est signalé par `$model->viewDiff()` (statut `INVALID`).

### Recherche localisée (`?lang=`)

Pour un attribut i18n stocké en objet `{ "fr": …, "en": … }`, indexez chaque sous-champ localisé (chemin pointé) avec son Analyzer **et** son marqueur de locale `Search::LANG` :

```php
Search::FIELDS =>
[
    'name'     => 3 ,                                                       // non localisé : toujours cherché
    'intro.fr' => [ Search::ANALYZER => 'text_fr' , Search::LANG => 'fr' ] ,
    'intro.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
] ,
```

Quand la requête porte une langue active (le paramètre [`?lang=`](search.md), déjà utilisé pour la projection `TRANSLATE()` au `RETURN`), la recherche s'y aligne : seuls les champs dont `Search::LANG` correspond — **plus** les champs non localisés (sans `LANG`) — participent au `SEARCH`. Sans `?lang=`, tous les champs sont cherchés.

- `?lang=fr` → cherche `name` + `intro.fr` (le côté anglais est écarté) ;
- `?lang=en` → cherche `name` + `intro.en` ;
- **garde-fou** : si la langue active ne correspond à **aucun** champ (ex. `?lang=de`), le filtre est ignoré et tous les champs sont cherchés — jamais de `SEARCH` vide.

Le `Search::LANG` (recherche) et le `?lang` de projection (`TRANSLATE` au `RETURN`) sont indépendants mais cohérents : la même langue active narrowe la recherche et localise la sortie. Rétro-compatible : sans aucun `Search::LANG`, `?lang=` n'a aucun effet sur la recherche.

**Le provisioning est automatique** : comme la collection et ses `AQL::INDEXES`, la View est créée paresseusement à l'initialisation du modèle quand elle n'existe pas (champs cherchés liés avec l'Analyzer déclaré). Une View existante n'est **jamais modifiée automatiquement** — après un changement de déclaration, inspectez et resynchronisez explicitement : `$model->viewDiff()` détecte l'écart, `$model->viewSync()` le répare via `updateProperties()` (la View reste interrogeable pendant la ré-indexation), et l'[action `views` de la commande `arangodb`](../commands/arangodb.md#views--gestion-des-views-arangosearch) fait la même chose en CLI (`--diff` / `--sync`), intégrable aux scripts de déploiement.

## URLs et comportement

```
GET /places?search=scierie
```

génère (termes bindés — l'entrée utilisateur n'atteint jamais le texte AQL) :

```aql
FOR doc IN placesView
  SEARCH ANALYZER(
       BOOST(doc.name IN TOKENS(@search_0, "text_fr"), 3)
    OR BOOST(PHRASE(doc.name, @search_0), 6)
    OR LEVENSHTEIN_MATCH(doc.name, @search_0, 1)
    OR doc.description IN TOKENS(@search_0, "text_fr")
    OR BOOST(PHRASE(doc.description, @search_0), 2)
    OR LEVENSHTEIN_MATCH(doc.description, @search_0, 1)
  , "text_fr")
  SORT BM25(doc) DESC
  LIMIT 0, 50
  RETURN { ... }
```

Le contrat de `?search=` est inchangé : termes séparés par des virgules, **OR** partout — seul le moteur diffère. Et le reste du pipeline ([`?filter=`](filter.md), [`?facets=`](facets.md), `?limit`/`?offset`, skins, projections) continue de fonctionner comme avant : les filtres s'appliquent **après** le `SEARCH`, en `FILTER` de post-traitement.

### Pertinence et `?sort=`

Une recherche active expose la clé de tri synthétique **`score`** (le pendant pertinence de [`distance`](search-and-filtering.md) pour `?near=`) :

| Requête | Ordre |
|---|---|
| `?search=scierie` | `score` DESC (défaut — le plus pertinent d'abord, prime sur `SORT_DEFAULT`) |
| `?search=scierie&sort=-score,name` | pertinence, puis nom |
| `?search=scierie&sort=name` | nom seul — la pertinence n'est **pas** ajoutée (le `?sort` explicite décide) |
| `?sort=score` sans `?search=` | droppé (pas de recherche active) |

### Réponses

L'enveloppe JSON est **identique** à une liste classique (l'enveloppe de succès standard `status` / `url` / `count` / `total` / `result` des contrôleurs) — seuls l'ordre (et la qualité du matching) changent :

```json
{
  "status": "success",
  "url": "https://api.example.org/places?search=bois",
  "count": 2,
  "total": 2,
  "result":
  [
    { "name": "Atelier du bois" , "description": "menuiserie fine" } ,
    { "name": "Scierie de la Loire" , "description": "le bois de chêne et de sapin" }
  ]
}
```

`total`, [`?count`](../models.md) **et** [`?facetCounts=`](facets.md) suivent tous le même `SEARCH` — la liste, les totaux et les buckets de facettes sont toujours d'accord sur l'ensemble matché.

## Recettes

**Barre de recherche avec pertinence** — la déclaration ci-dessus ; rien d'autre. `?search=scierie` renvoie les meilleurs résultats d'abord, tolère une faute (`scierei`), et survit aux accents/pluriels via l'Analyzer.

**Biais préfixe façon autocomplete** — gardez `Search::PHRASE => true` : en tapant des mots entiers, les expressions exactes remontent en tête.

**Sous-champ localisé** — les champs sont des chemins : `Search::FIELDS => [ 'description.fr' => 1 ]` cherche le côté français d'un attribut i18n `{ "fr": …, "en": … }` (Analyzers par champ et sélection pilotée par `?lang=` sont des évolutions prévues).

**Imposer un tri classique** — la pertinence n'est que le tri *par défaut* : `?search=bois&sort=name` (ou n'importe quel `?sort=`) reprend entièrement la main, exactement comme avant.

**Revenir en arrière** — retirez le bloc `AQL::VIEW` (ou son `Search::NAME`) : `?search=` retombe instantanément sur le balayage `LIKE` historique sur `AQL::SEARCHABLE`. Aucun changement d'URL, de contrôleur ou de route dans un sens comme dans l'autre.

## Bon à savoir

- **Consistance différée** — un document fraîchement inséré devient cherchable dans la View après ~1 s (`commitIntervalMsec`). Les listes sans `?search=` ne sont pas concernées.
- **Exigences du scoring** — le score `BM25` exige la feature d'Analyzer `frequency` (les Analyzers texte built-in l'ont) ; `PHRASE` exige `position` + `frequency`.
- **La recherche est bindée** — les termes voyagent en variables `@search_N` ; les noms de champs viennent de la déclaration du modèle, jamais de l'URL.

## Voir aussi

- [Recherche `?search=`](search.md) — le balayage `LIKE` (modèles sans View).
- [Recherche & filtrage](search-and-filtering.md) — vue d'ensemble des leviers.
- [Fonctions ArangoSearch](../aql/aql-functions-search.md) — les helpers `SEARCH` sous-jacents.
- [`aqlScoredSearch()`](../aql/aql-operations.md) — le builder de requête scorée autonome.
- [Clients ArangoSearch](../clients/arangosearch.md) — gestion des Views et Analyzers.
