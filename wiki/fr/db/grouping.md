# Regroupement HTTP `?groupBy=` / `?group=`

Le regroupement transforme une requête `list` en **agrégation** : au lieu de renvoyer les documents, ArangoDB les **regroupe** par une ou plusieurs clés et renvoie une ligne par groupe (compte, somme, moyenne…). C'est l'équivalent du `GROUP BY` SQL, bâti sur la clause AQL [`COLLECT`](../aql/aql-operations.md#aqlcollect).

C'est le levier idéal pour les **tableaux de bord** et les **compteurs** : « combien d'articles par catégorie », « chiffre d'affaires par année », « note moyenne par auteur ».

## Regroupement vs facettes vs filtres

| Levier | Effet | Renvoie |
|---|---|---|
| `?filter=` / `?search=` | restreint l'ensemble | les **documents** |
| `?facets=` | restreint via relations/agrégats | les **documents** |
| `?groupBy=` / `?group=` | **regroupe et agrège** | une **ligne par groupe** |

> ⚠️ Sous `COLLECT`, la variable `doc` disparaît : la projection (`fields`, `skin`) et le tri document (`?sort=`) ne s'appliquent plus. Le tri des groupes se fait via `Group::SORT` (voir plus bas).

## Syntaxe URL

Deux paramètres, combinables :

### `?groupBy=` — le raccourci

CSV de champs ; **implique un comptage par groupe** (le cas « facettes » courant) :

```
GET /sales?groupBy=category
// COLLECT category = doc.category WITH COUNT INTO count
// → [ {"category":"A","count":3}, {"category":"B","count":2} ]
```

### `?group=` — la spec JSON complète

Objet JSON (URL-encodé) avec des clés courtes :

| Clé | Rôle | Exemple |
|---|---|---|
| `by` | champ(s) de regroupement | `"category"` · `"category,status"` · `{"year":"created"}` |
| `agg` | agrégats | `{"total":"sum:amount","moy":"avg:amount"}` |
| `count` | comptage par groupe | `true` ou `"n"` (nom de variable) |
| `sort` | tri sur les variables de groupe/agrégat | `"-count"` · `"category,-total"` |
| `alt` | transformations de clé | `{"year":"dateYear"}` |

> ⚠ Un paramètre de transformation venu de l'URL est **lié**, pas écrit dans la requête — et ne peut donc plus désigner un autre champ. Voir [Un paramètre venu d'une requête est une valeur](filter.md#-un-paramètre-venu-dune-requête-est-une-valeur).

> ⚠ Un `alt` nommant une fonction que le catalogue ne porte pas est **refusé** (`400`), et non appliqué en silence sur la clé brute. Voir [Un nom que le catalogue ne porte pas est refusé](filter.md#un-nom-que-le-catalogue-ne-porte-pas-est-refusé).


```
GET /sales?group={"by":{"year":"created"},"alt":{"year":"dateYear"},"agg":{"total":"sum:amount"},"sort":"-total"}
// COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount) SORT total DESC RETURN {year, total}
```

Les fonctions d'agrégat disponibles (`agg`) sont `sum`, `avg`, `min`, `max` (catalogue `FacetAggregator`, partagé avec les facettes). La forme `"func:field"` est équivalente à `["func","field"]`.

Les **champs** qu'un client peut agréger se restreignent, eux, via [`Arango::AGGREGATABLE`](#restreindre-les-champs-agrégeables).

## Côté modèle

Sans HTTP, on passe la même spec via la clé `Arango::GROUP`, en utilisant le vocabulaire [`Group`](../../../src/oihana/arango/models/enums/Group.php) :

```php
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;

$model->list
([
    Arango::GROUP =>
    [
        Group::BY    => 'category' ,
        Group::AGG   => [ 'total' => 'sum:amount' ] ,
        Group::COUNT => 'n' ,
        Group::SORT  => '-total' ,
    ] ,
]) ;
// COLLECT category = doc.category AGGREGATE total = SUM(doc.amount), n = LENGTH(1) SORT total DESC RETURN {category, total, n}
```

## Les trois usages

### 1. Valeurs distinctes

```php
$model->list([ Arango::GROUP => [ Group::BY => 'status' ] ]) ;
// COLLECT status = doc.status RETURN { status }
```

### 2. Comptage par groupe (compteurs de facettes)

```php
$model->list([ Arango::GROUP => [ Group::BY => 'category' , Group::COUNT => true , Group::SORT => '-count' ] ]) ;
// COLLECT category = doc.category WITH COUNT INTO count SORT count DESC RETURN { category, count }
```

### 3. Agrégation / reporting

```php
$model->list
([
    Arango::GROUP =>
    [
        Group::BY  => [ 'year' => 'created' ] ,
        Group::ALT => [ 'year' => 'dateYear' ] ,
        Group::AGG => [ 'total' => 'sum:amount' , 'moy' => 'avg:amount' ] ,
    ] ,
]) ;
// COLLECT year = DATE_YEAR(doc.created) AGGREGATE total = SUM(doc.amount), moy = AVERAGE(doc.amount) RETURN { year, total, moy }
```

> **Comptage + agrégats.** `AGGREGATE` et `WITH COUNT INTO` sont mutuellement exclusifs en AQL. Quand un `count` accompagne des agrégats, il est émis comme `n = LENGTH(1)` (et non `WITH COUNT`).

## Ce que rend une requête groupée

Un `COLLECT` remplace les documents par des lignes que la requête a inventées : les dimensions, les agrégats, le compteur. **Une ligne groupée n'est donc pas un document**, et ni le schéma du modèle ni ses `alters` ne s'y appliquent — leurs noms sont ceux de la collection, ceux de la ligne sont ceux que le client a demandés.

`list()` et `stream()` lisent ces lignes **telles quelles**. Elles reviennent en objets simples portant exactement les variables que le `COLLECT` a émises :

```php
$model->list([ Arango::GROUP => [ Group::BY => 'year' , Group::AGG => [ 'total' => 'sum:speed.value' ] ] ]) ;
// [ { "year": 2023, "total": 150.5 } , { "year": 2024, "total": 20.5 } ]
```

Une liste **sans** regroupement ne change pas d'un caractère : ses documents traversent le schéma et les `alters` comme avant.

### ⚠ Pourquoi ce n'est pas un détail

Tant que ces lignes traversaient le schéma, elles y perdaient tout ce que la requête avait inventé. Le constructeur d'un `Thing` ne recopie que les clés correspondant à une **propriété publique déclarée** — et une classe de collection ne déclare ni `year`, ni `total`. Mesuré, avec un schéma qui n'en déclare aucun :

| ce que le serveur a calculé | ce qui arrivait au lecteur |
|---|---|
| `{ "year": 2023, "total": 150.5 }` | `{ "@type": "Thing" }` |

Pas « l'agrégat manque » : **tout manque**, la dimension comprise. Et la réponse revenait en `200`, bien formée, avec un `@type` que la classe portait alors que la ligne n'était plus un document.

🚨 **Le cas le plus dangereux est celui où le nom *correspond*.** Ces propriétés sont typées, et PHP convertit :

| agrégat demandé | calculé | propriété heurtée | ce qui arrivait |
|---|---|---|---|
| `"name": "sum:speed.value"` | `150.5` | `name` (`string\|int\|null`) | **`150`** |
| `"active": "sum:amount"` | `10` | `active` (`?bool`) | **`true`** |

Une somme de `10` rendue `true`. Une valeur fausse ne se voit pas, là où une clé absente se remarque.

### La bascule suit le `COLLECT` émis, pas le `?group=` demandé

Une demande dont **toutes** les dimensions sautent — non déclarées dans `Arango::GROUPABLE`, ou fermées par la garde de permission — et qui ne porte aucun agrégat n'émet aucun `COLLECT` : la requête rend toujours des documents, et ils sont toujours hydratés.

```php
// 'unknown' n'est pas déclaré groupable et il n'y a pas d'agrégat :
$model->list([ Arango::GROUP => [ Group::BY => 'unknown' ] ]) ;
// aucun COLLECT → des documents, hydratés comme d'habitude
```

La [spec brute `Arango::COLLECT`](#spec-brute-arangocollect) est l'autre porte vers la même clause : elle bascule la lecture de la même façon.

## Champs à points et nommage

Un champ imbriqué devient une variable à underscore (identifiant AQL valide) :

```php
$model->list([ Arango::GROUP => [ Group::BY => 'address.city' ] ]) ;
// COLLECT address_city = doc.address.city RETURN { address_city }
```

Pour nommer explicitement la variable, utiliser la forme `{ varName: field }` : `Group::BY => [ 'city' => 'address.city' ]`.

## Tri des groupes

Le tri document (`?sort=`) ne fonctionne pas sous `COLLECT`. On trie sur les **variables de groupe/agrégat** via `Group::SORT` (ou `sort` en JSON), CSV avec `-` pour décroissant :

```
?group={"by":"category","count":true,"sort":"-count"}   // SORT count DESC
```

## Surcharge du `RETURN`

La projection est dérivée automatiquement (clés de groupe + agrégats + comptage). Pour un `RETURN` sur-mesure, fournir `Arango::RETURN` :

```php
$model->list
([
    Arango::GROUP  => [ Group::BY => [ 'y' => 'created' ] , Group::ALT => [ 'y' => 'dateYear' ] , Group::AGG => [ 't' => 'sum:amount' ] ] ,
    Arango::RETURN => '{ year: y, revenue: t }' ,
]) ;
```

## Spec brute `Arango::COLLECT`

Pour un contrôle total (expressions AQL libres, `INTO`, `KEEP`, projection), on court-circuite le vocabulaire `Group` avec la spec brute consommée par [`aqlCollect()`](../aql/aql-operations.md#aqlcollect) :

```php
$model->list
([
    Arango::COLLECT =>
    [
        AQL::ASSIGN => [ 'author' => 'doc.authorId' ] ,
        AQL::INTO   => 'docs' ,
    ] ,
    Arango::RETURN => '{ author, count: LENGTH(docs), articles: docs }' ,
]) ;
```

> La spec brute est une **expression AQL de confiance** : elle n'est pas validée. Ne jamais y injecter d'entrée utilisateur non assainie.

## Sécurité et injection AQL

Un champ de regroupement devient `doc.<champ>` *littéralement*. Tous les champs de la couche `Group` (`by` et `agg`) sont donc validés par [`assertAttributeName()`](helpers.md) : une valeur non conforme (`category) RETURN doc //`) lève une `ValidationException`.

### Restreindre les champs groupables

En option, définir une whitelist/mapping `groupable` sur le modèle (comme `sortable`) : `clé-URL → champ réel`. Seules les clés whitelistées sont groupables, et la clé publique est découplée du champ interne. Elle se déclare dans le `$init` du modèle, comme tous les autres réglages :

```php
$articles = new Documents( $container ,
[
    Arango::COLLECTION => 'articles' ,
    Arango::GROUPABLE  => [ 'cat' => 'category' , 'year' => 'created' ] ,
]) ;
// ?groupBy=cat       → COLLECT cat = doc.category ...
// ?groupBy=secret    → ignoré (non whitelisté)
```

Quand `groupable` est `null` (défaut), le regroupement est **fail-closed** : rien n'est groupable (voir *Permission* ci-dessous).

### Restreindre les champs agrégeables

**La situation.** Les deux moitiés de `?group=` n'obéissaient pas à la même loi : `by` est **fermé** (rien n'est groupable sans whitelist), `agg` était **ouvert** — tout chemin projeté pouvait être agrégé. Or un agrégat sur un sous-champ qu'aucun document ne porte ne lève **ni erreur ni avertissement** : en AQL, `SUM` sur des `null` vaut `0`.

```
?group={"by":"sensor","agg":{"total":"sum:pressure.value"}}
→ [ {"sensor":"A","total":0}, {"sensor":"B","total":0} ]
```

La réponse est bien formée, en `200`, et **fausse** : rien ne distingue « la somme vaut zéro » de « ce champ n'a pas de total ». `Arango::AGGREGATABLE` ferme cette porte.

⚠ Elle s'indexe sur le **jeton de champ**, pas sur le nom de sortie — celui-ci (`total`) est choisi librement par le client, le whitelister n'aurait aucun sens :

```php
use oihana\arango\models\enums\AggregatablePolicy;

$measures = new Documents( $container ,
[
    Arango::COLLECTION          => 'measures' ,
    Arango::AGGREGATABLE        => [ [ 'speed' => 'speed.value' ] , 'weight' ] ,
    Arango::AGGREGATABLE_POLICY => AggregatablePolicy::STRICT ,
]) ;
// ?group={"agg":{"t":"sum:speed"}}           → AGGREGATE t = SUM(doc.speed.value)
// ?group={"agg":{"t":"sum:pressure.value"}}  → selon la politique (ci-dessous)
```

Les trois notations de [`AQL::SORTABLE`](sort.md) sont acceptées et mélangeables : le raccourci indexé (`'weight'`, jeton = champ), l'alias indexé (`[ 'speed' => 'speed.value' ]`) et l'associatif historique.

#### La politique

| `Arango::AGGREGATABLE_POLICY` | Un agrégat non déclaré | Pour qui |
|---|---|---|
| `AggregatablePolicy::OPEN` | **passe** sur son chemin brut (un alias déclaré résout quand même) | une rampe de migration : les alias d'abord, la fermeture ensuite |
| `AggregatablePolicy::DROP` *(défaut à la déclaration)* | **écarté**, comme une dimension non déclarée | une API publique, où une colonne absente se voit tout de suite |
| `AggregatablePolicy::STRICT` | `ValidationException` **nommant le jeton refusé** | une API interne, où l'on préfère un refus franc |

Un agrégat écarté n'emporte rien avec lui : la dimension, le compteur et le tri des groupes restent intacts (le tri ne nomme jamais une variable que le `COLLECT` n'a pas émise).

> **Rétrocompatibilité.** Sans `AGGREGATABLE` déclaré, tout chemin projeté reste agrégeable et la requête AQL émise est identique **au caractère près**. **Déclarer la whitelist est ce qui ferme la porte** ; sans politique nommée, elle ferme en `DROP`. Un code de politique inconnu ferme aussi, pour qu'une faute de frappe ne rouvre rien.

> 🚨 **`STRICT` s'arrête à la whitelist, jamais à la permission.** Un champ déclaré mais refusé par `Field::REQUIRES` reste écarté **en silence**, même sous `STRICT` : une erreur nommant un champ protégé dirait au client que ce champ existe — l'oracle même que le portail de permission est là pour fermer.

## Permission (`REQUIRES`)

`?groupBy=` est **fail-closed** : sans `AQL::GROUPABLE` déclaré, **rien n'est groupable** (comme le tri). Et une dimension whitelistée sur un champ **caché à la lecture** (`Field::REQUIRES`) reste un **oracle** : `COLLECT` révèle ses valeurs distinctes et leurs comptes ; un agrégat (`MAX/MIN/AVG/SUM`) fuit une **borne**.

La permission **hérite** du champ homonyme de `$fields` ; refusée, la **dimension ou l'agrégat est écarté** (retire une sortie, n'assouplit rien).

```php
public array  $fields    = [ 'category' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
public ?array $groupable = [ 'category' => 'category' , 'salary' => 'salary' ] ;
```

| Utilisateur **avec** `hr:read` | Utilisateur **sans** `hr:read` |
|---|---|
| `?group[group_by]=salary` → `COLLECT salary = doc.salary` | dimension **écartée** |
| `?group[group_agg][m]=max:salary` → `MAX(doc.salary)` | agrégat **écarté** |

> **Migration (BC).** `groupable = null` ne signifie plus « tout groupable » mais **rien** : déclare `AQL::GROUPABLE` avec les clés que le client peut grouper (celles du `SORT_DEFAULT` incluses). **Fail-open** : sans `REQUIRES`/*authorizer*, un champ whitelisté se groupe normalement. **Profondeur** : un chemin profond (`address.city`) est **contrôlé au sous-champ exact**, pas seulement à la racine. Voir [La projection des champs](../projection.md) et [Tri](sort.md#permission-de-tri).

## Voir aussi

- [Helpers : `aqlCollect()` / `aqlCollectReturn()`](../aql/aql-operations.md#agrégation) — les briques AQL bas niveau.
- [Facettes HTTP `?facets=`](facets.md) — filtrer via relations/agrégats (renvoie les documents).
- [Recherche & filtrage](search-and-filtering.md) — vue d'ensemble des leviers.
