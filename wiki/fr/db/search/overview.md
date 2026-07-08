# Recherche View (ArangoSearch) — `?search=` classé par pertinence

Déclarez une **View ArangoSearch** sur un modèle `Documents` (le bloc `AQL::VIEW`) et le paramètre [`?search=`](README.md) bascule, automatiquement et **sans aucun changement d'URL**, du simple balayage `LIKE` vers une recherche **accélérée par index et classée par pertinence** : matching linguistique (tokenisation, racinisation, accents), boosts par champ, bonus d'expression exacte, tolérance aux fautes, et un score `BM25` qui classe les meilleurs résultats d'abord.

> ArangoSearch est nouveau pour vous (Analyzers, Views, scoring) ? Commencez par lire notre page dédiée [Comprendre ArangoSearch](../../getting-started/arangosearch.md).

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
| `Search::ANALYZER` | `string` | Analyzer utilisé pour indexer **et** interroger les champs (défaut `identity` — déclarez un Analyzer texte pour la recherche linguistique). Surchargeable par champ — voir [Options par champ](per-field-options.md). |
| `Search::FIELDS` | `array` | Map `champ => boost` (ou `champ => [ Search::BOOST => n, Search::FUZZY => d ]` pour des options par champ). Chemins pointés supportés, ainsi que les **sous-champs de tableaux d'objets** via `[*]` ([voir](array-fields.md)). Fallback sur `AQL::SEARCHABLE` (boost 1). |
| `Search::PHRASE` | `bool` | Ajoute un bonus d'expression exacte : un match `PHRASE()` pèse `boost × 2`. |
| `Search::FUZZY` | `int` | Tolérance aux fautes globale : `LEVENSHTEIN_MATCH` avec cette distance d'édition maximale (valeur valide `0`–`4`, `0` = off). Surchargeable par champ — voir [Options par champ](per-field-options.md). |

> **Configurer chaque champ** — au-delà du boost, chaque entrée de `Search::FIELDS` accepte des options **par champ** (tolérance aux fautes, Analyzer, plusieurs Analyzers/autocomplétion, langue, expression exacte, permissions). Voir la page dédiée [Options par champ](per-field-options.md). Pour indexer un sous-champ de tableau d'objets, voir [Champs de tableaux d'objets](array-fields.md).

## Provisioning automatique

**Le provisioning est automatique** : comme la collection et ses `AQL::INDEXES`, la View est créée paresseusement à l'initialisation du modèle quand elle n'existe pas (champs cherchés liés avec l'Analyzer déclaré). Une View existante n'est **jamais modifiée automatiquement** — après un changement de déclaration, inspectez et resynchronisez explicitement : `$model->viewDiff()` détecte l'écart, `$model->viewSync()` le répare via `updateProperties()` (la View reste interrogeable pendant la ré-indexation), et l'[action `views` de la commande `arangodb`](../../commands/arangodb.md#views--gestion-des-views-arangosearch) fait la même chose en CLI (`--diff` / `--sync`), intégrable aux scripts de déploiement :

```bash
# après un changement de déclaration AQL::VIEW : voir l'écart, puis resynchroniser
composer arango:views -- --diff              # lecture seule : liste les Views à créer / driftées
composer arango:views -- --sync              # crée les manquantes + resynchronise toutes les driftées
composer arango:views -- --sync=placesView   # ciblé (plusieurs noms séparés par des virgules)
```

> Forme longue équivalente : `php bin/console.php command:arangodb views --sync`. Le `--sync` privilégie `updateProperties()` (mise à jour douce) plutôt qu'un drop + recreate.

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

Le contrat de `?search=` est inchangé : termes séparés par des virgules, **OR** partout — seul le moteur diffère. Et le reste du pipeline ([`?filter=`](../filter.md), [`?facets=`](../facets.md), `?limit`/`?offset`, skins, projections) continue de fonctionner comme avant : les filtres s'appliquent **après** le `SEARCH`, en `FILTER` de post-traitement.

### Pertinence et `?sort=`

Une recherche active expose la clé de tri synthétique **`score`** (le pendant pertinence de [`distance`](../sort.md#tri-par-distance-near) pour `?near=`) :

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

`total`, [`?count`](../../models.md) **et** [`?facetCounts=`](../facets.md) suivent tous le même `SEARCH` — la liste, les totaux et les buckets de facettes sont toujours d'accord sur l'ensemble matché.

## Recettes

**Barre de recherche avec pertinence** — la déclaration ci-dessus ; rien d'autre. `?search=scierie` renvoie les meilleurs résultats d'abord, tolère une faute (`scierei`), et survit aux accents/pluriels via l'Analyzer.

**Biais préfixe façon autocomplete** — gardez `Search::PHRASE => true` : en tapant des mots entiers, les expressions exactes remontent en tête. Pour une vraie autocomplétion par fragments, voir [plusieurs Analyzers par champ](per-field-options.md#plusieurs-analyzers-par-champ-autocomplétion).

**Sous-champ localisé** — les champs sont des chemins : `Search::FIELDS => [ 'description.fr' => 1 ]` cherche le côté français d'un attribut i18n `{ "fr": …, "en": … }`. Pour aller plus loin, un [Analyzer par champ](per-field-options.md#analyzer-par-champ) (français/anglais) et une [sélection pilotée par `?lang=`](per-field-options.md#recherche-localisée-lang) sont disponibles.

**Sous-champ d'un tableau d'objets** — `Search::FIELDS => [ 'contactPoints[*].email' => 1 ]` rend cherchable l'`email` de **chaque** élément du tableau `contactPoints` — voir [Champs de tableaux d'objets](array-fields.md).

**Imposer un tri classique** — la pertinence n'est que le tri *par défaut* : `?search=bois&sort=name` (ou n'importe quel `?sort=`) reprend entièrement la main, exactement comme avant.

**Revenir en arrière** — retirez le bloc `AQL::VIEW` (ou son `Search::NAME`) : `?search=` retombe instantanément sur le balayage `LIKE` historique sur `AQL::SEARCHABLE`. Aucun changement d'URL, de contrôleur ou de route dans un sens comme dans l'autre.

## Bon à savoir

- **Consistance différée** — un document fraîchement inséré devient cherchable dans la View après ~1 s (`commitIntervalMsec`). Les listes sans `?search=` ne sont pas concernées.
- **Exigences du scoring** — le score `BM25` exige la feature d'Analyzer `frequency` (les Analyzers texte built-in l'ont) ; `PHRASE` exige `position` + `frequency`.
- **La recherche est bindée** — les termes voyagent en variables `@search_N` ; les noms de champs viennent de la déclaration du modèle, jamais de l'URL.
- **Les Analyzers doivent exister d'abord** — une View référence ses Analyzers par leur **nom**, elle ne les crée pas. Les built-in (`text_fr`, `text_en`, `identity`…) sont toujours présents. Un Analyzer **maison** doit être déclaré dans le registre `analyzers` et créé en base (`composer arango:analyzers -- --sync` ou `composer arango:doctor -- --apply`) **avant** la View — sa définition (type, propriétés, features) n'est pas déductible du seul nom. Sinon la View est marquée `INVALID` et la création paresseuse échoue silencieusement (la recherche échouera ensuite au runtime). Diagnostiquez avec `composer arango:views -- --diff` ou `composer arango:doctor`. Voir [Analyzers](../analyzers.md).

## Voir aussi

- [Options par champ](per-field-options.md) — configurer chaque champ (boost, fuzzy, Analyzer, autocomplétion, langue, phrase, permissions).
- [Champs de tableaux d'objets](array-fields.md) — `contactPoints[*].email`.
- [Analyzers](../analyzers.md) — catalogue et création d'un Analyzer maison.
- [Recherche `?search=`](README.md) — le balayage `LIKE` (modèles sans View).
- [Recherche & filtrage](../search-and-filtering.md) — vue d'ensemble des leviers.
- [Fonctions ArangoSearch](../../aql/aql-functions-search.md) — les helpers `SEARCH` sous-jacents.
- [`aqlScoredSearch()`](../../aql/aql-operations.md) — le builder de requête scorée autonome.
- [Clients ArangoSearch](../../clients/arangosearch.md) — gestion des Views et Analyzers.
