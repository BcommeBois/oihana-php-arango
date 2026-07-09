# Bornes HTTP `?bounds=`

Le framework expose, à côté des [compteurs de facettes `?facetCounts=`](facets.md#compteurs-de-facettes-facetcounts), un système de **bornes** sur les routes `GET` adossées à un modèle [`Documents`](../models.md).

Une **borne** est l'étendue d'un champ numérique : les deux valeurs qui l'encadrent, sa plus petite (`min`) et sa plus grande (`max`) sur l'ensemble affiché. Là où un compteur de facette renvoie une **ventilation par valeur** (« Cuisine (42), Voyage (17) ») — utile pour une dimension discrète —, une borne renvoie **deux scalaires** — ce qu'il faut pour dimensionner un **curseur d'intervalle** (min/max) sur une mesure continue comme une largeur, un poids ou un prix.

Le client nomme les champs à borner dans le paramètre URL `?bounds=`, le framework agrège leur `MIN` / `MAX` sur **le même ensemble filtré** que la liste, et joint le résultat à la réponse, à côté des documents.

Cette page documente :

1. [Bornes vs compteurs de facettes](#bornes-vs-compteurs-de-facettes) — laquelle utiliser.
2. La [syntaxe URL](#syntaxe-url) `?bounds=`.
3. La [déclaration côté modèle](#déclaration-côté-modèle) (`AQL::BOUNDS`).
4. L'[AQL généré](#aql-généré) (champs plats fusionnés, mesures imbriquées).
5. La [permission `REQUIRES`](#permission-requires) (anti-oracle).
6. Les [bornes sans les documents](#les-bornes-sans-les-documents-metaonly) (`?metaOnly=`).

## Bornes vs compteurs de facettes

Les deux se calculent à côté de la liste, sur le **même ensemble filtré**, et **ne la restreignent jamais** — ils décrivent ce que la liste affiche déjà. Ils diffèrent par la **forme de sortie** :

| | Compteur de facette (`?facetCounts=`) | Borne (`?bounds=`) |
|---|---|---|
| Sortie | une **ventilation** `[ {valeur, compte}, … ]` | **deux scalaires** `{ min, max }` |
| Champ visé | **discret** (catégorie, statut, mot-clé) | **continu** (largeur, poids, prix) |
| Usage UI | une liste de cases à cocher | un curseur d'intervalle min/max |

On demande une ventilation sur un prix continu donnerait des milliers de lignes `{valeur, compte}` — inexploitable. La borne répond à la vraie question : « entre quelles valeurs mon curseur doit-il s'étendre ? ». Les deux se **combinent** dans le même appel (facettes discrètes + bornes numériques, un seul aller-retour).

## Syntaxe URL

Le paramètre `?bounds=` est une **liste de noms de champs séparés par des virgules** ; chaque nom doit être une **borne déclarée** sur le modèle :

```
GET /products?bounds=width,height,weight
```

- Une clé **absente de la déclaration** est silencieusement ignorée (aucune borne non whitelistée n'est calculable).
- Les bornes héritent des **mêmes filtres** que la liste (`?filter=` / `?facets=` / `?search=`) : ajouter un filtre resserre les bornes sur ce sous-ensemble.

Les bornes sont renvoyées sous la clé `bounds` de l'enveloppe de succès standard, à côté de `total`, **sans modifier** la liste de documents :

```json
{
  "status": "success",
  "url": "https://api.example.org/products?bounds=width,height",
  "count": 50,
  "total": 120,
  "bounds": {
    "width":  { "min": 5,  "max": 240 },
    "height": { "min": 10, "max": 300 }
  },
  "result": [ /* …documents filtrés… */ ]
}
```

`MIN` / `MAX` **ignorent les valeurs nulles** : un champ absent d'un document ne fausse pas son étendue ; un champ sans aucune valeur non nulle dans l'ensemble renvoie `{ "min": null, "max": null }`.

## Déclaration côté modèle

Chaque champ bornable est déclaré sous la clé **`AQL::BOUNDS`** (= `'bounds'`) à la construction du modèle. La liste blanche est **fermée par défaut** (*fail-closed*) : un `$bounds` nul ne rend **rien** bornable, exactement comme `$sortable` / `$groupable`.

Deux formes d'entrée :

```php
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Field ;
use oihana\arango\models\enums\Facet ;

$products = new Documents
([
    AQL::BOUNDS =>
    [
        'width'  ,                                              // champ plat de premier niveau
        'height' ,
        'weight' => [ Facet::PROPERTY => 'grossWeight' ] ,      // champ plat, propriété renommée
        'price'  => [ Facet::PROPERTY => 'offers[*].price' ] ,  // mesure imbriquée dans un tableau d'objets
    ]
]) ;
```

- Un **nom nu** (`'width'`) borne le champ scalaire homonyme de premier niveau.
- Une **définition** `[ Facet::PROPERTY => '…' ]` vise une propriété au nom différent, ou une **mesure imbriquée** atteinte par un marqueur d'expansion `[*]` (`offers[*].price`) — le même marqueur que les [facettes](facets.md#compter-un-sous-champ-dun-tableau-dobjets-) et les [filtres](filter.md) acceptent déjà.

Clés de configuration :

| Clé | Rôle | Défaut |
|---|---|---|
| `Facet::PROPERTY` | La propriété document visée (alias du nom d'URL, chemin `[*]` accepté). | le nom de la borne |
| `Field::REQUIRES` | Le(s) sujet(s) de permission gardant la borne. | *hérité de `AQL::FIELDS`* |

## AQL généré

**Les champs plats partagent un seul `COLLECT AGGREGATE`** : une seule passe sur l'ensemble filtré encadre toutes les mesures plates d'un coup.

```aql
FOR doc IN @@products FILTER <mêmes filtres>
COLLECT AGGREGATE width_min = MIN(doc.width), width_max = MAX(doc.width),
                  height_min = MIN(doc.height), height_max = MAX(doc.height)
RETURN { width: { min: width_min, max: width_max }, height: { min: height_min, max: height_max } }
```

Une **mesure imbriquée** `[*]` doit déplier son tableau, donc elle ne peut pas partager la boucle `FOR` racine : elle reçoit sa propre sous-requête `LET`, fusionnée au bloc plat par `MERGE` :

```aql
LET __bounds = FIRST(( FOR doc IN @@products FILTER <mêmes filtres> COLLECT AGGREGATE … RETURN { … } ))
LET price    = FIRST(( FOR doc IN @@products FILTER <mêmes filtres> FOR item IN doc.offers COLLECT AGGREGATE lo = MIN(item.price), hi = MAX(item.price) RETURN { min: lo, max: hi } ))
RETURN MERGE( __bounds, { price: price } )
```

- Les comptes sont **conjonctifs** : calculés sur l'ensemble **déjà filtré** (mêmes `?filter` / `?facets` / `?search` que la liste). Avec une [recherche View](search/overview.md) active, la sous-requête itère la View avec le **même `SEARCH`** que la liste, donc les bornes reflètent exactement l'ensemble affiché.
- **Chaque `[*]` est une boucle `FOR`** ; les tableaux imbriqués se déplient d'un cran par marqueur (`offers[*].tiers[*].amount`).
- Le conteneur et le sous-champ sont gardés par [`assertAttributeName`](helpers.md#garde-anti-injection--isattributename--assertattributename) : un chemin dangereux fait échouer la borne, sans jamais atteindre l'AQL.

> C'est l'optimum atteignable par `COLLECT` : borner six champs plats coûte **une** passe, pas six. Pour transformer la liste elle-même en agrégation, voir le [Regroupement `?groupBy=`](grouping.md).

## Permission (`REQUIRES`)

Une borne sur un champ **caché à la lecture** (`Field::REQUIRES`) fuit **plus fort** qu'un compteur : un `{ min, max }` **est** une valeur réelle du champ (le prix le plus bas, la dimension d'un produit confidentiel), pas seulement un dénombrement. La garde n'est donc **pas optionnelle**.

La permission se résout par **héritage** du champ homonyme de `AQL::FIELDS`, **ou** par un `Field::REQUIRES` posé directement sur la définition de borne :

```php
public array $fields = [ 'price' => [ Field::REQUIRES => 'sales:read' ] ] ;
public array $bounds = [ 'price' ] ; // hérite de $fields
// ou explicite : 'price' => [ Facet::PROPERTY => 'offers[*].price' , Field::REQUIRES => 'sales:read' ]
```

Une borne refusée est **écartée** de la requête (elle retire une sortie, n'assouplit rien). La résolution marche **au sous-champ exact** (via [`isPathAuthorized`](../projection.md)) : un `dimensions.width` verrouillé profondément est attrapé, pas seulement sa racine.

> **Fail-open** identique aux facettes : aucun `REQUIRES` ou aucun *authorizer* injecté → borne normale. Voir [La projection des champs](../projection.md), [Permission des facettes](facets.md#permission-requires) et [Permission de tri](sort.md#permission-de-tri).

## Les bornes sans les documents (`?metaOnly=`)

Une barre latérale de recherche n'a souvent besoin **que des métadonnées** — bornes, compteurs, `total` — les documents étant chargés par un appel paginé séparé. Ajoutez `?metaOnly=true` pour **sauter entièrement la requête documents** : le tableau `result` revient vide, tandis que les bornes, les compteurs de facettes et un **`total` exact** sont quand même calculés.

```
GET /products?facetCounts=category&bounds=width,height&metaOnly=true
```

```json
{
  "status": "success",
  "count": 0,
  "total": 120,
  "facets": { "category": [ {"value":"tools","count":80}, {"value":"garden","count":40} ] },
  "bounds": { "width": {"min":5,"max":240}, "height": {"min":10,"max":300} },
  "result": []
}
```

- `?metaOnly=` est le signal **générique** « la barre latérale, pas les documents » : il couvre les facettes **et** les bornes en un seul aller-retour sans documents.
- Il **supplante** l'ancien `?facetsOnly=` (limité aux compteurs), conservé comme **alias** vrai (déprécié) — le contrôleur combine les deux drapeaux, donc les appels existants ne changent pas.
- Accepte toute forme booléenne : `true`, `1`, `yes`, `on`.

## Voir aussi

- [Facettes HTTP `?facets=`](facets.md) — filtrage relationnel/multi-valeurs et compteurs `?facetCounts=`.
- [Filtres HTTP `?filter=`](filter.md) — appliquer l'intervalle choisi (`{"all":[{"key":"width","op":"ge","val":100},{"key":"width","op":"le","val":500}]}`).
- [Regroupement HTTP `?groupBy=`](grouping.md) — transformer la liste en agrégation.
- [Modèles `Documents` et `Edges`](../models.md) — déclaration `AQL::BOUNDS`.
