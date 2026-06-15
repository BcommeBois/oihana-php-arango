# Recherche `?search=`

Le paramètre `?search=` est la **recherche plein texte simple** d'un modèle : un (ou plusieurs) terme(s) comparés en `LIKE` sur un ensemble de champs déclarés `searchable`. C'est l'un des trois leviers de [recherche & filtrage](search-and-filtering.md), le plus large et le plus simple à utiliser côté client.

## En bref

```
?search=marc
```
balaie chaque champ déclaré `searchable` et garde les documents dont **au moins un** champ **contient** `marc` :

```aql
(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true))
```

- **Insensible à la casse** (le 3ᵉ argument `true` de `LIKE` est le mode *case-insensitive* d'ArangoDB) : `marc` matche `Marc`, `MARC`, `Marco`…
- **`contient`** : le terme est entouré de `%` (`%marc%`), donc il matche n'importe où dans la valeur.
- **OR partout** : un document matche dès qu'un terme apparaît dans un champ.

## Plusieurs termes

Les termes sont **séparés par des virgules**. Chaque terme est lié séparément et testé sur chaque champ ; le tout est combiné en `OR` :

```
?search=marc,marco
```
```aql
(LIKE(doc.name,@search_0,true) || LIKE(doc.firstName,@search_0,true)
 || LIKE(doc.name,@search_1,true) || LIKE(doc.firstName,@search_1,true))
// @search_0 = "%marc%" , @search_1 = "%marco%"
```

> La sémantique est un **OU** entre les termes (« contient marc OU marco »). Pour exiger plusieurs conditions simultanées (ET), utilisez plutôt [`?filter=`](filter.md).

## Déclaration côté modèle

Les champs balayés sont déclarés dans la liste **`AQL::SEARCHABLE`** (= `'searchable'`) à la construction du modèle :

```php
use oihana\arango\models\Documents ;
use oihana\arango\db\enums\AQL ;

$users = new Documents
([
    AQL::COLLECTION => 'users' ,
    AQL::SEARCHABLE => [ 'name' , 'firstName' , 'email' ] , // champs balayés par ?search
]) ;
```

- Sans liste `searchable` (ou vide), `?search` ne produit rien (`null`) — la recherche est inopérante tant qu'aucun champ n'est déclaré.
- Les champs sont des **chemins relatifs au document** (`name`, `address.city`…), interpolés tels quels — ils proviennent de la **déclaration du modèle**, pas de l'URL (aucune injection possible côté `?search`).

## Permissions — champs gardés

Un champ cherchable peut être **gardé par permission** : la liste reste homogène, et une entrée tableau porte son nom sous `Search::KEY` plus le(s) sujet(s) requis sous `Search::REQUIRES` (une chaîne ou une liste, OR) :

```php
use oihana\arango\models\enums\Search ;

AQL::SEARCHABLE =>
[
    'name' ,                                                          // public
    [ Search::KEY => 'salary' , Search::REQUIRES => 'hr.salary:search' ] , // gardé
] ,
```

Le champ gardé n'est balayé que si l'**autorizer** de la requête (closure `Arango::AUTHORIZER`, injecté par le contrôleur, consulté par `isAuthorized()`) accorde un sujet — exactement comme le [gating de projection](edges-joins-projection.md) (`Field::REQUIRES`) et la [recherche View](search-views.md#permissions-de-recherche). Sans autorizer, la couche est désactivée (fail-open). **Si tous les champs cherchables sont refusés**, la recherche ne ramène **rien** (`FILTER false`) — elle n'est jamais silencieusement ignorée (ce qui renverrait tout).

## Cas limites

| Entrée | Résultat |
|---|---|
| `?search=` (vide) | aucun fragment (`null`) — la recherche est ignorée |
| `?search` absent | aucun fragment (`null`) |
| modèle sans `searchable` | aucun fragment (`null`) |
| `?search=marc` | `(LIKE(doc.<f1>,@search_0,true) || LIKE(doc.<f2>,@search_0,true) || …)` |
| `?search=marc`, tous les champs gardés refusés | `false` (0 résultat) — voir [Permissions](#permissions--champs-gardés) |

Comme les autres leviers, un `?search` inopérant ne casse jamais la requête : il n'ajoute simplement aucune condition.

## Combiner avec les filtres et les facettes

`?search` se cumule (logique **ET**) avec `?filter` et `?facets` dans la même requête — il forme son propre groupe `OR` interne, ANDé au reste :

```
?search=marc&filter={"key":"active","val":true}&facets={"role":"admin"}
// → (… LIKE …) && doc.active == @v && (… role facet …)
```

Voir [Recherche & filtrage](search-and-filtering.md) pour le tableau comparatif complet.

## Limites — quand passer à ArangoSearch

`?search` est un **`LIKE` multi-champs** : pas de pertinence/scoring, pas de tokenisation, pas de stemming ni d'accents-insensibilité, pas de recherche par préfixe optimisée par index. C'est volontaire — c'est la recherche « suffisante » du quotidien.

Pour du **vrai full-text** (analyseurs, scoring `BM25`/`TFIDF`, tokenisation, fuzzy), déclarez une **View ArangoSearch sur le modèle** (le bloc `AQL::VIEW`) : le même paramètre `?search=` bascule alors vers une recherche accélérée par index et classée par pertinence — voir [Recherche View (ArangoSearch)](search-views.md). Les deux sont complémentaires : `?search` sur `searchable` pour une barre de recherche simple, la déclaration View pour un moteur de recherche à part entière — sans changement d'URL entre les deux.

## Voir aussi

- [Recherche & filtrage](search-and-filtering.md) — vue d'ensemble des 3 leviers.
- [Filtres `?filter=`](filter.md) — pour des conditions précises (ET/OU/NON, comparateurs, dates).
- [Facettes `?facets=`](facets.md) — pour la multi-sélection et les relations.
- [ArangoSearch (vues)](../clients/arangosearch.md) — full-text avancé.
