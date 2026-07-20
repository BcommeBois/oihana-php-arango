# Filtres HTTP `?filter=`

> `?filter=` est l'un des trois leviers de filtrage d'un modèle. Pour la vue d'ensemble (différences et socle commun avec [`?search=`](search/README.md) et [`?facets=`](facets.md), « quand utiliser quoi »), voir [**Recherche & filtrage**](search-and-filtering.md).

Le framework expose un système de filtrage déclaratif sur les routes `GET` adossées à un modèle [`Documents`](../models.md). Le client envoie son intention sous forme de JSON dans le paramètre URL `?filter=`, le framework la convertit en une clause `FILTER` AQL avec *bind variables*, et l'exécute sur la collection cible.

Cette page documente :

1. La **syntaxe URL** du paramètre `?filter=`.
2. Les **opérateurs** et **types de filtres** disponibles.
3. Les **transformations `alt`** appliquées à la valeur avant comparaison (catalogue complet par type).
4. Les **conditions complexes** (tableau, AND/OR, imbrication).
5. La **déclaration côté DI** (`AQL::FILTERS` sur un modèle).
6. Les **bonnes pratiques** et pièges (index, performance).

Pour le filtrage **serveur-only** (jamais exposé à l'URL — champs sensibles, conditions internes), voir [Filtrage interne `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md).

## Syntaxe URL

### Format de base

Chaque condition est un objet JSON aux quatre clés suivantes :

| Clé | Obligatoire | Description |
|---|---|---|
| `key` | oui | Nom du champ à filtrer (doit être présent dans `AQL::FILTERS` du modèle). |
| `val` | oui | Valeur de comparaison (scalaire ou tableau selon l'opérateur). |
| `op` | non | Opérateur (`eq` par défaut). |
| `alt` | non | Transformation appliquée à `doc.<key>` avant comparaison ; la forme objet `{key,val}` l'applique aussi à la valeur (voir plus bas). |

```
?filter={"key":"email","val":"john@example.com"}
```

Une seule condition est suffisante. Pour combiner plusieurs conditions, voir [Conditions complexes](#conditions-complexes).

### URL encoding

Le JSON doit être URL-encodé. La plupart des clients HTTP le font automatiquement :

```bash
# Exemple cURL
curl 'https://api.example.com/users?filter=%7B%22key%22%3A%22email%22%2C%22val%22%3A%22john%40example.com%22%7D'
```

En PHP :

```php
$filter = [ 'key' => 'email' , 'val' => 'john@example.com' ] ;
$url    = '/users?filter=' . urlencode( json_encode( $filter ) ) ;
```

### Le pipeline

```
URL ?filter={"key":"email","val":"john"}
  → décodage JSON
  → validation contre AQL::FILTERS (whitelist par clé)
  → résolution du FilterType pour la clé
  → application de l'alt si présent
  → génération du prédicat AQL : FILTER LOWER(doc.email) == @email
  → ajout du bind : { email: 'john' }
  → exécution
```

Toute clé absente de `AQL::FILTERS` est **silencieusement ignorée** (sécurité — aucune injection possible sur un champ non whitelisté).

## Opérateurs

Les valeurs de `op` sont définies par l'enum `FilterComparator`.

| `op` | Sémantique | Sortie AQL équivalente |
|---|---|---|
| `eq` (défaut) | Égal à | `doc.x == @val` |
| `ne` | Différent de | `doc.x != @val` |
| `gt` | Supérieur à | `doc.x > @val` |
| `ge` | Supérieur ou égal | `doc.x >= @val` |
| `lt` | Inférieur à | `doc.x < @val` |
| `le` | Inférieur ou égal | `doc.x <= @val` |
| `like` | Correspondance avec *wildcards* (`%`, `_`) | `LIKE(doc.x, @val, false)` |
| `sw` | Commence par (préfixe **littéral**, sans wildcard) | `STARTS_WITH(doc.x, @val)` |
| `nsw` | Ne commence pas par | `!(STARTS_WITH(doc.x, @val))` |
| `ew` | Finit par (suffixe **littéral**, sans wildcard) | `RIGHT(doc.x, CHAR_LENGTH(@val)) == @val` |
| `new` | Ne finit pas par | `!(RIGHT(doc.x, CHAR_LENGTH(@val)) == @val)` |
| `contains` | Contient la sous-chaîne (littérale) | `CONTAINS(doc.x, @val)` |
| `ncontains` | Ne contient pas | `!(CONTAINS(doc.x, @val))` |
| `regex` | Correspond à l'expression régulière | `REGEX_TEST(doc.x, @val)` |
| `nregex` | Ne correspond pas à l'expression régulière | `!(REGEX_TEST(doc.x, @val))` |
| `in` | Dans la liste fournie | `doc.x IN @val` |
| `nin` | Pas dans la liste | `doc.x NOT IN @val` |
| `between` | Plage inclusive (clés `min`/`max` au lieu de `val`) | `(doc.x >= @min && doc.x <= @max)` |
| `distance` | Rayon géo (type `geo` ; `val` = point, `min`/`max` = rayon en mètres) | `DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) <= @max` |

> `sw`, `ew` et `contains` sont des **formes fonction** (pas des comparateurs infixes) et comparent **littéralement** : les `%`/`_` ne sont pas des jokers (contrairement à `like`), donc rien à échapper. AQL n'a pas de `ENDS_WITH` natif → `ew` s'écrit `RIGHT(doc.x, CHAR_LENGTH(@val)) == @val`. Ces trois-là sont insensibles à la casse via le miroir `alt` : `{"op":"sw","alt":{"key":"lower","val":true}}` → `STARTS_WITH(LOWER(doc.x), LOWER(@val))` (idem `ew`/`contains`).
>
> `regex` teste une **expression régulière** (ICU). La valeur est **bindée** (`@val`) : aucune injection AQL possible. Pour l'insensibilité à la casse, préfixe ton motif d'un flag *inline* `(?i)` (ex. `"(?i)^eka.*on$"`) — ne passe **pas** par le miroir `alt`, qui abaisserait aussi les classes de caractères (`[A-Z]`). ⚠️ Un motif fourni par le client peut être coûteux (*ReDoS*) : valide/limite-le côté application si l'entrée n'est pas de confiance.
>
> **Formes négatives** : chaque opérateur fonction a sa négation préfixée `n` qui enveloppe la forme positive dans `!( … )` : `nsw` (ne commence pas par), `new` (ne finit pas par), `ncontains` (ne contient pas), `nregex` (ne correspond pas). Ex. `{"op":"ncontains","val":"mele"}` → `!(CONTAINS(doc.x, @val))`.

Exemples :

```
?filter={"key":"status","val":"closed","op":"ne"}
?filter={"key":"price","val":100,"op":"gt"}
?filter={"key":"name","val":"%john%","op":"like"}
?filter={"key":"name","val":"eka","op":"sw"}            // STARTS_WITH(doc.name, "eka")
?filter={"key":"name","val":"leon","op":"ew"}           // RIGHT(doc.name, CHAR_LENGTH("leon")) == "leon"
?filter={"key":"name","val":"mele","op":"contains"}     // CONTAINS(doc.name, "mele")
?filter={"key":"name","val":"(?i)^eka.*on$","op":"regex"} // REGEX_TEST(doc.name, "(?i)^eka.*on$")
?filter={"key":"role","val":["admin","owner"],"op":"in"}
```

### Opérateur `between` (plage)

`between` compare un champ à une **plage inclusive** via les clés **`min`** et **`max`** (pas de `val`). Disponible pour les types **number**, **string** et **date** :

```jsonc
{"key":"price","op":"between","min":10,"max":50}
// FILTER (doc.price >= @min && doc.price <= @max)
```

**Omission de borne — la sémantique dépend du type :**
- **number / string** : la borne omise est **abandonnée** → comparaison unilatérale.
  ```jsonc
  {"key":"price","op":"between","max":50}   // FILTER doc.price <= @max
  {"key":"price","op":"between","min":10}   // FILTER doc.price >= @min
  ```
- **date** : la borne omise vaut **la date courante** (`now`) → la plage reste bilatérale.
  ```jsonc
  {"key":"created","op":"between","min":"2024-01-01"}
  // FILTER (doc.created >= @min && doc.created <= DATE_ISO8601(DATE_NOW()))
  ```

**Fuseau (`tz`)** : pour une date, le `tz` du JSON s'applique **aux deux bornes** :
```jsonc
{"key":"created","op":"between","min":"2024-01-01","max":"2024-12-31","tz":"Europe/Paris"}
// FILTER (doc.created >= DATE_LOCALTOUTC(@min,@tz) && doc.created <= DATE_LOCALTOUTC(@max,@tz))
```

Le champ comparé reste compatible `alt` (ex. `"alt":"abs"` → `(ABS(doc.price) >= @min && …)`).

### Opérateur `distance` (géolocalisation)

Réservé au type [`FilterType::GEO`](#types-de-filtres). Il filtre les documents par leur **distance** (en mètres) à un point de référence. La valeur `val` porte le **point** au format Schema.org `GeoCoordinates` (`{ latitude, longitude }`, alias courts `lat`/`lng`/`lon` acceptés) ; le **rayon** réutilise les clés **`min`/`max`** — exactement comme `between` :

```jsonc
// dans un rayon de 5 km
{"key":"geo","op":"distance","val":{"latitude":48.8566,"longitude":2.3522},"max":5000}
// FILTER DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) <= @max
```
```jsonc
// anneau entre 1 km et 5 km
{"key":"geo","op":"distance","val":{"latitude":48.8566,"longitude":2.3522},"min":1000,"max":5000}
// FILTER (DISTANCE(...) >= @min && DISTANCE(...) <= @max)
```

`op` peut être omis (le type `geo` implique `distance`). **Un rayon est requis** : sans `min` ni `max`, aucune clause n'est émise. Les sous-attributs lus sont `<key>.latitude` / `<key>.longitude` (Schema.org) ; `DISTANCE` opère sur deux scalaires, donc le prédicat est **index-accéléré** dès qu'un [`GeoIndex`](../clients/indexes.md) à deux champs couvre ces attributs. Voir le catalogue des [fonctions géospatiales](../aql/aql-functions-geo.md).

> **Filtrer ≠ trier.** `distance` **borne** (rayon) mais n'**ordonne** pas. Pour classer du plus proche au plus loin, utilise le paramètre dédié [`?near=`](sort.md#tri-par-distance-near) — combinable avec ce filtre.

## Types de filtres

Chaque champ filtrable est typé via `FilterType::*` dans la déclaration `AQL::FILTERS` du modèle. Le type **détermine** comment la valeur est validée et quel sous-ensemble des `alt` est compatible.

| Type | Validation côté serveur | Opérateurs utiles | Exemples d'`alt` compatibles |
|---|---|---|---|
| `FilterType::STRING` | Chaîne non vide | `eq`, `ne`, `like`, `in`, `nin` | `lower`, `upper`, `trim`, `substring`, `length`, `md5` |
| `FilterType::NUMBER` | Entier ou réel | `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `in`, `nin` | `abs`, `round`, `ceil`, `floor` |
| `FilterType::DATE` | ISO 8601 ou timestamp Unix ms | `eq`, `ne`, `gt`, `ge`, `lt`, `le` | `dateYear`, `dateMonth`, `dateDayOfWeek`, `dateFormat` |
| `FilterType::BOOL` | `true` / `false` | `eq`, `ne` | Aucun (booléen non transformable) |
| `FilterType::ARRAY` | Tableau JSON | `eq`, `in`, `nin` ; opérateurs quantifiés | `count`, `length`, `first`, `last`, `sum`, `avg` |
| `FilterType::GEO` | Objet `{ latitude, longitude }` (Schema.org) | `distance` (`val` = point, `min`/`max` = rayon m) | Aucun (voir [opérateur `distance`](#opérateur-distance-géolocalisation)) |
| `FilterType::VIRTUAL` | Aucune clause AQL émise | — | Cas spécial : voir [filter-internal.md](filter-internal.md) |

```php
// Déclaration côté DI
AQL::FILTERS =>
[
    Prop::ACTIVE     => FilterType::BOOL   ,
    Prop::CLIENT_ID  => FilterType::STRING ,
    Prop::CREATED    => FilterType::DATE   ,
    Prop::IDENTIFIER => FilterType::STRING ,
    Prop::PRICE      => FilterType::NUMBER ,
    Prop::VALUES     => FilterType::ARRAY  ,
]
```

## Transformations `alt`

La clé `alt` applique une fonction AQL à `doc.<key>` **avant** la comparaison. C'est l'équivalent HTTP des [fonctions AQL côté PHP](../aql/aql-functions-strings.md) — mais exposé sous forme de chaîne courte.

### Syntaxes supportées

```jsonc
// Fonction simple sans paramètre
{"key":"name","val":"john","alt":"lower"}
// FILTER LOWER(doc.name) == "john"

// Fonction avec paramètres
{"key":"code","val":"ABC","alt":["substring", 0, 3]}
// FILTER SUBSTRING(doc.code, 0, 3) == "ABC"

// Chaîne de fonctions (de gauche à droite, inner to outer)
{"key":"email","val":"john","alt":["trim","lower"]}
// FILTER LOWER(TRIM(doc.email)) == "john"

// Chaîne mixte (fonctions paramétrées + fonctions simples)
{"key":"code","val":"ABC","alt":["trim",["substring",0,3],"upper"]}
// FILTER UPPER(SUBSTRING(TRIM(doc.code), 0, 3)) == "ABC"
```

L'ordre d'évaluation est **inner-to-outer** : le premier élément du tableau est appliqué en premier, le dernier en dernier.

### Appliquer `alt` à la valeur (comparaison symétrique)

Par défaut, `alt` n'enveloppe que le **champ** (côté gauche) : `LOWER(doc.email) == @v`. La valeur reste brute — ce qui empêche, par exemple, une égalité insensible à la casse. La **forme objet** applique la transformation des **deux côtés** :

```jsonc
// Forme objet : une chaîne par côté
{"key":"email","val":"JEAN@X.COM","alt":{"key":"lower","val":"lower"}}
// FILTER LOWER(doc.email) == LOWER(@v)

// val:true → miroir : applique au côté valeur la même chaîne que le champ
{"key":"email","val":"JEAN@X.COM","alt":{"key":"lower","val":true}}
// FILTER LOWER(doc.email) == LOWER(@v)

// le miroir fonctionne aussi sur une chaîne de fonctions
{"key":"name","val":" John ","alt":{"key":["trim","lower"],"val":true}}
// FILTER LOWER(TRIM(doc.name)) == LOWER(TRIM(@v))

// chaque côté est indépendant : ici, seule la valeur est transformée
{"key":"email","val":"JEAN@X.COM","alt":{"val":"lower"}}
// FILTER doc.email == LOWER(@v)
```

Quand la **valeur est un tableau** (ex. `op:in`), la chaîne est appliquée à **chaque élément** via une projection inline, sans modifier le *bind* (qui reste le tableau complet) :

```jsonc
{"key":"category","op":"in","val":["TECH","NEWS"],"alt":{"key":"lower","val":true}}
// FILTER LOWER(doc.category) IN @v[* RETURN LOWER(CURRENT)]
```

> ⚠️ **Extracteurs vs normaliseurs.** Pour un **extracteur** (`dateYear`, `count`, `length`…), la valeur fournie est *déjà* la cible (`val:2024`) : gardez la forme chaîne `alt:"dateYear"` (côté champ seul). Pour un **normaliseur symétrique** (`lower`, `trim`, `abs`, `dateDay`…), utilisez la forme objet ou `val:true`. C'est **vous** qui décidez via la forme — aucune classification automatique.

100 % rétrocompatible : les formes chaîne et tableau (`"lower"`, `["trim","lower"]`) continuent de n'agir que sur le champ.

### Sur les filtres imbriqués (expansion `[*]` et `match`)

`alt` s'applique aussi **à l'intérieur** des expansions de tableau. Pour une clé `champ[*].sousChamp`, il enveloppe la condition inline `CURRENT.<sousChamp>` (et sa valeur) :

```
?filter={"key":"contactPoint[*].email","val":"ADMIN@ACME.COM","alt":{"key":"lower","val":true}}
// LENGTH(doc.contactPoint[* FILTER LOWER(CURRENT.email) == LOWER(@v)]) > 0
```

Pour une condition `match` (plusieurs sous-champs sur le même élément), `alt` s'applique **globalement à tous les sous-champs** (même règle que les facettes complexes) :

```
?filter={"key":"additionalProperty[*]","match":{"propertyID":"X","value":"Y"},"alt":{"key":"lower","val":true}}
// LENGTH(doc.additionalProperty[* FILTER LOWER(CURRENT.propertyID) == LOWER(@0) && LOWER(CURRENT.value) == LOWER(@1)]) > 0
```

Les feuilles des traversées **edge / join / document** (`vendeur.nom`, etc.) héritent déjà de `alt` via le filtre plat sous-jacent. La **clé de jointure structurelle** (`j.id == doc.x`) reste, elle, **brute**.

### Catalogue par catégorie

> Pour la signature et la sémantique détaillée de chaque fonction, voir les pages [Fonctions de chaînes](../aql/aql-functions-strings.md), [Fonctions de dates](../aql/aql-functions-dates.md), [Fonctions numériques](../aql/aql-functions-numerics.md), [Fonctions de tableaux](../aql/aql-functions-arrays.md). Cette page liste leurs versions exposées côté URL.

#### Chaînes

| `alt` | Effet | Paramètres |
|---|---|---|
| `lower` | Minuscules | — |
| `upper` | Majuscules | — |
| `trim` | Supprime les espaces des deux côtés | `type` optionnel (0=both, 1=left, 2=right) |
| `ltrim` | Supprime à gauche | `chars` optionnel |
| `rtrim` | Supprime à droite | `chars` optionnel |
| `substring` | Sous-chaîne | `start`, `length` (optionnel) |
| `left` | N caractères de gauche | `length` |
| `right` | N caractères de droite | `length` |
| `concat` | Concatène | `...strings` |
| `concatSeparator` | Concatène avec séparateur | `separator`, `...strings` |
| `length` | Longueur de la chaîne | — |
| `charLength` | Nombre de caractères (UTF-8) | — |
| `contains` | Contient la sous-chaîne | `search`, `caseInsensitive` |
| `startsWith` | Commence par | `prefix` |
| `findFirst` | Position de la première occurrence | `search`, `start`, `end` |
| `findLast` | Position de la dernière occurrence | `search`, `start`, `end` |
| `split` | Découpe en tableau | `separator`, `limit` |
| `md5` / `sha1` / `sha256` / `sha512` | *Hash* hexadécimal | — |
| `crc32` / `fnv64` | Empreinte hexadécimale | — |
| `toBase64` | Encode en Base64 | — |
| `toHex` | Encode en hexadécimal | — |
| `encodeURIComponent` | Encode pour URL | — |
| `soundex` | Empreinte phonétique anglais | — |
| `levenshtein` | Distance de Levenshtein | `compare` |

#### Numériques

| `alt` | Effet | Paramètres |
|---|---|---|
| `abs` | Valeur absolue | — |
| `ceil` | Arrondi supérieur | — |
| `floor` | Arrondi inférieur | — |
| `round` | Arrondi | — |
| `sqrt` | Racine carrée | — |
| `pow` | Puissance | `exponent` |
| `exp` / `exp2` | Exponentielle | — |
| `log` / `log10` / `log2` | Logarithmes | — |
| `sin` / `cos` / `tan` | Trigonométrie | — |
| `asin` / `acos` / `atan` | Trigonométrie inverse | — |
| `atan2` | Arc tangente à 2 arguments | `x` |
| `degrees` | Convertit radians → degrés | — |
| `radians` | Convertit degrés → radians | — |

#### Tableaux

| `alt` | Effet | Paramètres |
|---|---|---|
| `count` / `length` | Nombre d'éléments | — |
| `countDistinct` | Nombre d'éléments uniques | — |
| `sum` | Somme | — |
| `average` | Moyenne | — |
| `min` / `max` | Min / Max | — |
| `median` | Médiane | — |
| `percentile` | Percentile | `position`, `method` |
| `product` | Produit | — |
| `first` / `last` | Premier / dernier élément | — |
| `nth` | Élément à la position N | `position` |
| `pluck` | Projette un tableau d'objets sur un seul sous-champ | `champ` |
| `position` | Position d'une valeur | `search`, `returnIndex` |
| `reverse` | Inverse l'ordre | — |
| `sorted` | Trie | — |
| `sortedUnique` | Trie et déduplique | — |
| `unique` | Déduplique sans trier | — |
| `slice` | Extrait une portion | `start`, `length` |

##### `pluck` — agréger un champ d'un tableau d'objets

Les agrégats (`avg`, `sum`, `min`, `max`, `count`…) opèrent sur un tableau de **scalaires**. Mais une propriété est souvent un tableau d'**objets** (lignes de commande, mesures…). `pluck` projette ce tableau d'objets sur **un seul sous-champ** avant l'agrégat. Il s'appuie sur la projection inline native d'AQL `tableau[* RETURN CURRENT.<champ>]` — la cousine en lecture du filtre inline `[* FILTER …]`.

Chaîné à un agrégat, il permet par exemple de filtrer sur le **panier moyen** d'une commande dont les lignes sont des objets `{price, quantity}` :

```jsonc
// "commandes dont le prix moyen des lignes ≥ 100"
{"key":"items","op":"ge","val":100,"alt":[["pluck","price"],"avg"]}
// FILTER AVERAGE(doc.items[* RETURN CURRENT.price]) >= @v
```

Quelques variations, pour montrer la souplesse (on combine `pluck` avec n'importe quel agrégat) :

```jsonc
{"key":"items","op":"gt","val":1000,"alt":[["pluck","price"],"sum"]}    // CA total de la commande > 1000
{"key":"items","op":"le","val":5,"alt":[["pluck","quantity"],"max"]}    // aucune ligne de plus de 5 unités
{"key":"readings","op":"ge","val":18,"alt":[["pluck","temp"],"median"]} // température médiane des relevés ≥ 18
```

Le sous-champ peut être un **chemin d'objets imbriqués** (notation pointée), par ex. quand chaque ligne porte un sous-objet `offer` :

```jsonc
{"key":"items","op":"ge","val":100,"alt":[["pluck","offer.price"],"avg"]}
// FILTER AVERAGE(doc.items[* RETURN CURRENT.offer.price]) >= @v
```

> 🔒 Le nom du sous-champ (`price`, `offer.price`…) vient de l'URL : il est validé par [`assertAttributeName`](helpers.md#garde-anti-injection--isattributename--assertattributename) avant interpolation — un nom dangereux fait échouer le filtre, rien n'atteint l'AQL. *(Un sous-champ qui est lui-même un tableau — `offers[*].price` — n'est pas encore géré.)*

#### Dates

| `alt` | Effet | Paramètres |
|---|---|---|
| `dateYear` / `dateMonth` / `dateDay` | Composant de date | — |
| `dateHour` / `dateMinute` / `dateSecond` / `dateMillisecond` | Composant de temps | — |
| `dateDayOfWeek` | Jour de la semaine (0-6, dimanche=0) | — |
| `dateDayOfYear` | Jour de l'année (1-366) | — |
| `dateDaysInMonth` | Nombre de jours dans le mois | — |
| `dateIsoWeek` | Semaine ISO (1-53) | — |
| `dateIsoWeekYear` | Année de la semaine ISO | — |
| `dateQuarter` | Trimestre (1-4) | — |
| `dateLeapYear` | Année bissextile | — |
| `dateAdd` | Ajoute une durée | `amount`, `unit` |
| `dateSubtract` | Soustrait une durée | `amount`, `unit` |
| `dateDiff` | Différence entre deux dates | `date2`, `unit` |
| `dateTrunc` | Tronque à l'unité | `unit` |
| `dateFormat` | Formate la date | `format`, `useUTC` |
| `dateISO8601` | Format ISO 8601 | — |
| `dateTimeStamp` | Timestamp Unix (ms) | — |
| `dateTimezone` | Change de fuseau | `timezone` |
| `dateLocalToUTC` / `dateUTCToLocal` | Conversion de fuseau | `timezone` |
| `yesterday` / `tomorrow` | Date relative | — |

Unités acceptées par `dateAdd`, `dateSubtract`, `dateDiff`, `dateTrunc` : `year`, `month`, `week`, `day`, `hour`, `minute`, `second`, `millisecond` (correspondent à l'enum `DateUnit`).

#### Conditionnelles

| `alt` | Effet | Paramètres |
|---|---|---|
| `coalesce` / `notNull` | Première valeur non `null` (= `COALESCE` SQL) | `...valeurs par défaut` |

`coalesce` (alias `notNull`) enveloppe le champ d'un `NOT_NULL(...)` AQL pour **substituer une valeur par défaut quand le champ est absent ou `null`**, avant la comparaison :

```jsonc
// "remise == 0" en traitant un champ absent comme 0
{"key":"discount","op":"eq","val":0,"alt":[["coalesce",0]]}
// FILTER NOT_NULL(doc.discount, 0) == @v   →  les documents sans `discount` matchent 0
```

On peut fournir **plusieurs** valeurs de repli (la première non-`null` gagne) : `alt:[["coalesce", "doc.fallback", "N/A"]]`… mais attention :

> 🔒 Les valeurs par défaut viennent de l'URL : elles sont **toujours inlinées comme littéraux AQL stricts** (via `json_encode` — chaînes quotées/échappées, jamais de passthrough brut). Une valeur par défaut **ne peut donc pas** référencer un autre champ (`doc.autre`) ni une fonction : elle est traitée comme une donnée littérale. C'est volontaire (anti-injection).

## Conditions complexes

### Tableau de conditions (AND par défaut)

```jsonc
?filter=[
    {"key":"active","val":true},
    {"key":"role","val":"admin"},
    {"key":"created","val":"2026-01-01","op":"ge"}
]
// FILTER doc.active == @active && doc.role == @role && doc.created >= @created
```

Toutes les conditions du tableau sont jointes par `&&`. C'est la forme la plus courante.

### Logique OR — clé `logic`

Pour combiner avec `||`, ajouter une clé `logic` dans une condition de niveau racine :

```jsonc
?filter={
    "logic":"or",
    "conditions":[
        {"key":"role","val":"admin"},
        {"key":"role","val":"owner"}
    ]
}
// FILTER (doc.role == @role_1 || doc.role == @role_2)
```

Les valeurs acceptées de `logic` sont définies par l'enum `FilterLogic` : `and` (défaut) et `or`.

### Imbrication

Les groupes `{logic, conditions}` s'imbriquent récursivement :

```jsonc
?filter=[
    {"key":"active","val":true},
    {
        "logic":"or",
        "conditions":[
            {"key":"role","val":"admin"},
            {"key":"role","val":"owner"}
        ]
    }
]
// FILTER doc.active == @active && (doc.role == @role_1 || doc.role == @role_2)
```

## Déclaration côté DI (`AQL::FILTERS`)

Chaque modèle `Documents` déclare les clés filtrables dans `AQL::FILTERS`. Une clé absente de cette liste est **silencieusement ignorée** côté URL — c'est la garantie qu'un client ne peut pas filtrer sur un champ que le développeur n'a pas explicitement exposé.

```php
use oihana\arango\models\enums\filters\FilterType ;

AQL::FILTERS =>
[
    Prop::ACTIVE     => FilterType::BOOL   ,
    Prop::CLIENT_ID  => FilterType::STRING ,
    Prop::CREATED    => FilterType::DATE   ,
    Prop::IDENTIFIER => FilterType::STRING ,
    Prop::PRICE      => FilterType::NUMBER ,
    Prop::VALUES     => FilterType::ARRAY  ,
]
```

Convention : `AQL::FILTERS` est un sous-ensemble de `AQL::FIELDS` — on ne filtre que sur des champs réellement exposés. Une asymétrie (`FIELDS` sans `FILTERS`) est légale : un champ retourné mais non filtrable.

## Filtrer à travers les relations (edges / joins / documents imbriqués)

`?filter=` ne se limite pas aux champs scalaires du document racine. Une **clé hiérarchique** (segments séparés par des `.`) permet de descendre dans un **sous-document**, ou de **traverser une relation** — `edge` de graphe ou `join` par clé — pour ne garder que les documents racine dont **au moins un** document lié satisfait la condition.

> **Portée — à lire avant tout.** Le filtre porte sur le **document au bout de la relation** (le *vertex* cible pour un edge, le document référencé pour un join), **jamais** sur les **métadonnées de l'arête** elle-même. On peut filtrer sur `employee[*].salary` (un attribut de l'employé), mais **pas** sur un attribut stocké dans la collection d'edges (`role`, `weight`, `since`…).

### Le modèle mental

| Forme de clé | Type déclaré | Sens | AQL généré (simplifié) |
|---|---|---|---|
| `address.city` | `Filter::DOCUMENT` | descend dans un objet imbriqué | `doc.address.city == @v` |
| `company.name` | `Filter::JOIN` | suit une référence par clé | `LENGTH(FOR j IN companies FILTER j._key == doc.company && j.name == @v LIMIT 1 RETURN 1) > 0` |
| `employee[*].name` | `Filter::EDGES` | traverse une arête de graphe | `LENGTH(FOR v IN OUTBOUND doc employee_edges FILTER v.name == @v LIMIT 1 RETURN 1) > 0` |

Un edge/join est une **vérification d'existence** : « garde `doc` s'il existe au moins un lié qui matche » (`LIMIT 1 … RETURN 1 … > 0`). Un document imbriqué reste, lui, une simple condition plate sur `doc`.

### Déclaration côté DI

Au lieu d'un simple `FilterType`, une clé filtrable peut recevoir une **configuration imbriquée** : `AQL::TYPE` dit comment traverser, `AQL::FILTERS` **déclare et whiteliste** les sous-champs filtrables au niveau suivant. Les cartes de relations du modèle (`AQL::EDGES` / `AQL::JOINS`) fournissent, elles, le modèle cible et la direction (edge) ou la clé (join).

```php
use oihana\arango\db\enums\Traversal ;
use oihana\arango\enums\Filter ;
use oihana\arango\models\enums\filters\FilterType ;
use org\schema\constants\Schema ;
```

#### Document imbriqué — `Filter::DOCUMENT`

```php
AQL::FILTERS =>
[
    'name'    => FilterType::STRING ,
    'address' =>
    [
        AQL::TYPE    => Filter::DOCUMENT ,        // objet imbriqué dans le même document
        AQL::FILTERS =>
        [
            'city'       => FilterType::STRING ,
            'postalCode' => FilterType::STRING ,
        ],
    ],
]
```

```jsonc
{"key":"address.city","val":"Paris"}
// FILTER doc.address.city == @v

// op et alt fonctionnent normalement sur la feuille :
{"key":"address.city","op":"like","val":"Paris%"}
// FILTER doc.address.city LIKE @v
```

La descente est récursive — `company.headquarters.address.country` est valide tant que chaque niveau est déclaré en `Filter::DOCUMENT` avec ses `AQL::FILTERS`.

#### Join (référence par clé) — `Filter::JOIN` / `Filter::JOINS`

```php
// Modèle « customers » (chaque customer porte doc.company = _key d'une société)
AQL::FILTERS =>
[
    'company' =>
    [
        AQL::TYPE    => Filter::JOIN ,            // 1 référence  → pas de [*]
        AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
    ],
],
AQL::JOINS =>
[
    'company' => [ AQL::MODEL => CompanyModel::class , AQL::KEY => Schema::_KEY ] ,
],
```

```jsonc
{"key":"company.name","val":"Acme"}
// LENGTH(FOR j IN companies FILTER j._key == doc.company && j.name == @v LIMIT 1 RETURN 1) > 0
```

- `AQL::KEY` (défaut `_key`) = le champ comparé **dans la collection cible** ; `doc.company` (= le nom du segment) = le champ portant la référence **sur le document racine**.
- `AQL::MODEL` est **obligatoire** : son absence lève une `RuntimeException` (erreur de configuration, pas une entrée client).
- Variante **N références** : `Filter::JOINS` + notation `[*]` (voir la cardinalité ci-dessous).

#### Edge (arête de graphe) — `Filter::EDGE` / `Filter::EDGES`

```php
// Modèle « companies » relié à ses employés par une collection d'edges
AQL::FILTERS =>
[
    'employee' =>
    [
        AQL::TYPE    => Filter::EDGES ,           // N liés → notation [*]
        AQL::FILTERS =>
        [
            'name'   => FilterType::STRING ,
            'salary' => FilterType::NUMBER ,
        ],
    ],
],
AQL::EDGES =>
[
    'employee' => [ AQL::MODEL => EmployeeEdge::class , AQL::DIRECTION => Traversal::OUTBOUND ] ,
],
```

```jsonc
{"key":"employee[*].salary","op":"ge","val":50000}
// LENGTH(FOR v IN OUTBOUND doc employee_edges FILTER v.salary >= @v LIMIT 1 RETURN 1) > 0
```

- `AQL::DIRECTION` (défaut `Traversal::OUTBOUND`) choisit le sens ; `Traversal::INBOUND` traverse vers le sommet d'origine (`from`) au lieu du sommet d'arrivée (`to`).
- La condition s'applique au **vertex** traversé (`v.salary`), pas à l'arête.

### Cardinalité & notation `[*]`

| Type | Cardinalité | Notation de clé |
|---|---|---|
| `Filter::DOCUMENT` | 1 (objet imbriqué) | `address.city` — **sans** `[*]` |
| `Filter::JOIN` | 1 référence | `company.name` — **sans** `[*]` |
| `Filter::JOINS` | N références | `companies[*].name` — **avec** `[*]` |
| `Filter::EDGE` | 1 lié | `manager.name` — **sans** `[*]` |
| `Filter::EDGES` | N liés | `employee[*].name` — **avec** `[*]` |

> **Règle stricte.** La présence du `[*]` doit correspondre au type pluriel (`EDGES`, `JOINS`, expansion de tableau). Un décalage — `employee.name` pour un `EDGES`, ou `company[*].name` pour un `JOIN` — fait **silencieusement ignorer** le filtre (cohérent avec le reste de l'API : aucune erreur 400).

### Multi-niveaux

Les **cartes de relations** des modèles d'arrivée sont résolues automatiquement : si le modèle cible déclare lui-même ses `AQL::EDGES` / `AQL::JOINS`, on peut enchaîner les traversées (`employee[*].department.name`). Il suffit de déclarer l'**arbre des `AQL::FILTERS`** à chaque niveau traversé — les cartes `AQL::EDGES`/`AQL::JOINS` profondes, elles, sont héritées du modèle cible et **n'ont pas à être redéclarées**.

### Combiner avec AND / OR

Les conditions hiérarchiques se combinent comme les autres — tableau (AND par défaut) ou clé `logic` (OR) :

```jsonc
// customers chez « Acme » AYANT au moins un employé payé ≥ 50000
[{"key":"company.name","val":"Acme"},{"key":"employee[*].salary","op":"ge","val":50000}]
```

### Quantificateurs sur edges/joins — clé `quant`

Par défaut, un filtre de relation teste l'**existence** : « garde le document s'il existe **au moins un** lié (satisfaisant la feuille) » (`LENGTH(...) > 0`). La clé [`quant`](#quantificateurs-sur-tableaux--clé-quant) — le **même vocabulaire que sur les tableaux** — étend cette logique à la **cardinalité** de la relation :

| `quant` | Sens | AQL généré |
|---|---|---|
| *(absent)* / `any` | au moins un lié | `LENGTH(FOR v IN … doc … [FILTER <feuille>] LIMIT 1 RETURN 1) > 0` |
| `none` | aucun lié | `… LIMIT 1 RETURN 1) == 0` |
| `n` (entier ≥ 1) | au moins n liés | `LENGTH(FOR v IN … doc … [FILTER <feuille>] RETURN 1) >= n` |
| `all` | tous les liés satisfont | `LENGTH(FOR v IN … doc … FILTER !(<feuille>) LIMIT 1 RETURN 1) == 0` |

**Rien à configurer en plus.** `quant` est une clé **purement URL** : la relation se déclare exactement comme dans [Edge](#edge-arête-de-graphe--filteredge--filteredges) / [Join](#join-référence-par-clé--filterjoin--filterjoins) ci-dessus — `quant` ne touche **pas** la déclaration DI. Rappel de la déclaration utilisée par les exemples qui suivent :

```php
AQL::FILTERS =>
[
    'members' =>
    [
        AQL::TYPE    => Filter::EDGES ,
        AQL::FILTERS => [ 'active' => FilterType::BOOL ] ,
    ] ,
],
AQL::EDGES =>
[
    'members' => [ AQL::MODEL => MemberEdge::class , AQL::DIRECTION => Traversal::OUTBOUND ] ,
],
```

```jsonc
// organisations SANS aucun membre (absence pure — pas de feuille)
{"key":"members[*]","quant":"none"}
// LENGTH(FOR v IN OUTBOUND doc member_edges LIMIT 1 RETURN 1) == 0

// organisations sans aucun membre actif
{"key":"members[*].active","val":true,"quant":"none"}
// LENGTH(FOR v IN OUTBOUND doc member_edges FILTER v.active == @v LIMIT 1 RETURN 1) == 0

// organisations ayant au moins 3 membres actifs
{"key":"members[*].active","val":true,"quant":3}
// LENGTH(FOR v IN OUTBOUND doc member_edges FILTER v.active == @v RETURN 1) >= 3

// organisations dont TOUS les membres sont actifs
{"key":"members[*].active","val":true,"quant":"all"}
// LENGTH(FOR v IN OUTBOUND doc member_edges FILTER !(v.active == @v) LIMIT 1 RETURN 1) == 0
```

> **`all` est vrai par défaut sans relation.** Une organisation **sans aucun** membre satisfait `all` (rien ne contredit « tous actifs ») — cohérent avec `[] ALL …` en AQL. Pour « tous actifs **et** au moins un membre », combine `all` + `any` : `[{"key":"members[*].active","val":true,"quant":"all"},{"key":"members[*]"}]`.

**Sémantique & garde-fous :**

- `any` / `none` / `all` gardent le court-circuit `LIMIT 1` ; `n` **compte** les liés (pas de `LIMIT`).
- `n` signifie « **au moins** n » et doit être **≥ 1** — « au moins 0 » est toujours vrai ; pour « aucun », utilise `none`. Un `n < 1` est **rejeté** (`ValidationException`).
- `all` exige une **condition de feuille** (sinon « tous satisfont *quoi* ? ») — sans feuille, il est **rejeté** (`ValidationException`).
- Un `quant` inconnu est **rejeté**.
- Côté **join**, la clé de jointure structurelle (`j._key == doc.x`) reste toujours positive ; seule la feuille est niée pour `all`.

> Pour des **agrégats** sur une relation (« moyenne des reliés ≥ … », comptages d'UI), c'est le rôle de [`?facets=`](facets.md), pas de `quant`.

### `alt` sur les feuilles

Les transformations [`alt`](#transformations-alt) s'appliquent normalement à la **feuille** comparée ; la **clé de jointure structurelle** (`j._key == doc.x`) reste, elle, **brute**.

```jsonc
{"key":"company.name","val":"acme","alt":{"key":"lower","val":true}}
// … FILTER j._key == doc.company && LOWER(j.name) == LOWER(@v) …
```

### Performance & tolérance

- Chaque edge/join génère une **sous-requête par document racine évalué**. À réserver aux relations indexées (`_key` pour un join, l'index d'arête pour un edge) et à combiner avec des filtres scalaires sélectifs **en amont** pour réduire l'ensemble parcouru.
- Mêmes garde-fous que partout : un sous-champ absent des `AQL::FILTERS` du niveau est **ignoré et journalisé** (`warning`) ; seules les **valeurs** sont bindées.
- Besoin de **facettes d'UI** sur une relation (cases à cocher « lié à X », agrégats « moyenne des reliés ≥ … ») ? C'est le rôle de [`?facets=`](facets.md) — voir [Recherche & filtrage](search-and-filtering.md#quand-utiliser-quoi).

## Cas pratiques

### Recherche insensible à la casse

```jsonc
{"key":"email","val":"john.doe@example.com","alt":["trim","lower"]}
// FILTER LOWER(TRIM(doc.email)) == @email
```

### Validation de format

```jsonc
{"key":"postalCode","val":5,"alt":"length"}
// FILTER LENGTH(doc.postalCode) == 5

{"key":"sku","val":"PRD","alt":["substring",0,3]}
// FILTER SUBSTRING(doc.sku, 0, 3) == "PRD"
```

### Filtres temporels

```jsonc
// Documents créés en 2026
{"key":"created","val":2026,"alt":"dateYear"}

// Documents créés le lundi
{"key":"created","val":1,"alt":"dateDayOfWeek"}

// Documents créés dans les 30 derniers jours
{"key":"created","val":"2026-04-17","op":"ge","alt":["dateSubtract",30,"day"]}
```

### Filtres sur tableau

```jsonc
// Documents ayant au moins 3 tags
{"key":"tags","val":3,"op":"ge","alt":"count"}

// Documents dont la première étiquette est "featured"
{"key":"tags","val":"featured","alt":"first"}

// Documents dont la somme des scores dépasse 100
{"key":"scores","val":100,"op":"gt","alt":"sum"}
```

### Quantificateurs sur tableaux — clé `quant`

Sur un champ tableau, deux axes **orthogonaux** se combinent :

| Axe | Question | Où | Valeurs |
|---|---|---|---|
| **Comparaison** | comment comparer un élément ? | `op` (+ `val`) ou `match` | `eq`, `ge`, `in`… |
| **Quantificateur** | combien d'éléments doivent matcher ? | `quant` | `any` *(défaut)*, `all`, `none`, `n` (≥ n) |

Le comparateur reste dans `op` ; `quant` dit **combien d'éléments** du tableau doivent le satisfaire. La même clé `quant` couvre les **deux familles** de tableaux :

- **tableaux de scalaires** (nombres, chaînes) → opérateur AQL de **comparaison de tableau** (`doc.scores ALL >= @v`) ;
- **tableaux d'objets** (`reviews[*].rating`, `contactPoint[*]` + `match`) → opérateur AQL **« question-mark »** (`doc.reviews[? ALL FILTER CURRENT.rating >= @v]`).

| `quant` | Sens | Scalaire (AQL) | Objet (AQL) |
|---|---|---|---|
| `"any"` *(défaut)* | au moins **1** | `… ANY <cmp> @v` | `…[? ANY FILTER …]` |
| `"all"` | **tous** | `… ALL <cmp> @v` | `…[? ALL FILTER …]` |
| `"none"` | **aucun** | `… NONE <cmp> @v` | `…[? NONE FILTER …]` |
| `n` *(entier)* | **au moins n** | `… AT LEAST (n) <cmp> @v` | `…[? AT LEAST (n) FILTER …]` |

> **Scalaires vs objets — deux opérateurs AQL, un seul vocabulaire.**
> Sur un tableau de **scalaires**, `quant` produit l'opérateur de **comparaison de tableau** (`doc.scores AT LEAST (2) >= @v`). Sur un tableau d'**objets**, il produit l'opérateur **« question-mark »** (`doc.reviews[? AT LEAST (3) FILTER CURRENT.rating >= @v]`), qui exige un `FILTER`/`CURRENT`. Tu écris le même `quant` ; le framework choisit la bonne forme selon la clé.

#### Exemple complet — des produits avec leurs notes clients (tableau scalaire)

```jsonc
// collection "products"
{ "_key":"A", "name":"Casque",  "ratings":[5,4,4,2] }
{ "_key":"B", "name":"Clavier", "ratings":[5,3,2]   }
{ "_key":"C", "name":"Souris",  "ratings":[4,4]     }
```

Besoin : « les produits qui ont **au moins 3 notes de 4 étoiles ou plus** ».

```jsonc
?filter={"key":"ratings","op":"ge","val":4,"quant":3}
// FILTER doc.ratings AT LEAST (3) >= @value   (@value = 4)
```

| Produit | ratings | notes ≥ 4 | gardé ? |
|---|---|---|---|
| **A** | `[5,4,4,2]` | 5, 4, 4 → **3** | ✅ |
| B | `[5,3,2]` | 5 → 1 | ❌ |
| C | `[4,4]` | 4, 4 → 2 | ❌ |

→ Résultat : **uniquement A**.

Le même filtre avec les autres quantificateurs montre l'intérêt du quantificateur numérique :

| `quant` | Sens | AQL | Résultat |
|---|---|---|---|
| `"any"` | au moins **1** note ≥ 4 | `doc.ratings ANY >= 4` | A, B, C |
| `"all"` | **toutes** les notes ≥ 4 | `doc.ratings ALL >= 4` | C |
| `3` | **au moins 3** notes ≥ 4 | `doc.ratings AT LEAST (3) >= 4` | **A** |

`ANY` est trop large, `ALL` trop strict : `n` (au moins n) exprime exactement « assez d'éléments qualifiants ».

```jsonc
// au moins 3 valeurs parmi celles fournies
{"key":"scores","op":"in","val":[1,2,3],"quant":3}
// FILTER doc.scores AT LEAST (3) IN @value
```

#### Tableaux d'objets — `quant` + opérateur « question-mark »

Quand chaque élément est un **objet**, la condition porte sur un sous-champ (`reviews[*].rating`) ou sur un `match` multi-sous-champs (`contactPoint[*]`). `quant` enveloppe alors l'opérateur question-mark — ce que `ALL`/`NONE`/`AT LEAST` rendent enfin possible (impossible avec le seul `LENGTH(...) > 0`) :

```jsonc
// au moins 3 reviews notées ≥ 4
{"key":"reviews[*].rating","op":"ge","val":4,"quant":3}
// doc.reviews[? AT LEAST (3) FILTER CURRENT.rating >= @v]

// tous les contacts vérifiés
{"key":"contactPoint[*]","match":{"verified":true},"quant":"all"}
// doc.contactPoint[? ALL FILTER CURRENT.verified == @v]

// aucune variante en rupture de stock
{"key":"variants[*]","match":{"stock":0},"quant":"none"}
// doc.variants[? NONE FILTER CURRENT.stock == @v]
```

> Sans `quant`, un tableau d'objets garde son comportement historique **existentiel** — `LENGTH(doc.reviews[* FILTER CURRENT.rating >= @v]) > 0` (au moins un). Ajouter `quant` ne casse rien (rétro-compatible). `quant` ne s'applique qu'aux tableaux d'objets de **1er niveau** ; sur un tableau imbriqué (`employee[*].contactPoint[*]`) il est **ignoré** (le niveau de liaison serait ambigu) et le comportement existentiel est conservé.

#### Compatibilité & notations

- **Forme recommandée** : `quant` (uniforme scalaire + objet).
- **Alias historiques** (toujours valides) sur les tableaux scalaires : `op:"all.ge"` / `"any.ge"` / `"none.ge"` et `op:["atLeast.ge", n]` (forme tableau, élément 0 = code, élément 1 = seuil).
- `quant` **absent** = comportement legacy inchangé (défaut `==` / existentiel `LENGTH(...) > 0`).
- `n` est converti en **entier** (anti-injection) ; un `quant` inconnu **rejette** le filtre (`ValidationException`). Le champ reste compatible `alt`.

#### Les deux formes de `match` — et les trois pièges rejetés

Un `match` sur un tableau d'objets s'écrit de **deux façons**. La forme choisie change ce que tu as le droit d'exprimer.

**Forme simple** — chaque clé est un sous-champ, chaque valeur est ce qu'il doit **égaler** (`==` sous-entendu, le tout en `AND`). Réservée aux **scalaires** :

```jsonc
{"key":"contactPoint[*]","match":{"verified":true,"type":"home"}}
// → CURRENT.verified == true  AND  CURRENT.type == "home"
```

**Forme `all` / `any` / `none`** — dès qu'il faut un **opérateur** (autre que `==`), une **valeur non scalaire** (objet, plage), ou une logique `OR` / `NOT` :

```jsonc
{"key":"offers[*]","match":{"all":[{"key":"price","op":"gt","val":0}]}}
// → CURRENT.price > 0
```

> **Pourquoi c'est important.** Trois écritures malformées produisaient autrefois une AQL **valide mais toujours fausse** → `total: 0` **en silence**. « 0 » ressemble à une vraie réponse métier : impossible de distinguer « il n'y a rien » de « j'ai mal écrit le filtre ». Ces trois formes **lèvent désormais une `ValidationException`** (cohérent avec le virage *fail-closed* de `sort` / `group` / facettes). À ne pas confondre avec une **clé inconnue**, qui reste **silencieusement ignorée** (le filtre ne s'applique pas → renvoie *plus* de résultats, comportement voulu et sûr).

**Piège 1 — sous-champ d'objet imbriqué après `[*]`** *(désormais corrigé automatiquement)*

**La situation.** Chaque `offer` contient un objet `seller`, et on veut filtrer sur `seller.id`.

```jsonc
{"key":"offers[*].seller.id","val":"org-42"}
```

Avant, le chemin pointé après `[*]` n'était pas reconnu et retombait sur `doc.offers[*].seller.id == @v` — une **projection de tableau** comparée à un scalaire, **jamais vraie**. Il construit maintenant la bonne forme inline :

```aql
LENGTH(doc.offers[* FILTER CURRENT.seller.id == @v]) > 0
```

*(Un sous-champ **direct** — `offers[*].priceCurrency` — fonctionnait déjà ; seul le sous-chemin **pointé** était cassé.)*

**Piège 2 — un opérateur glissé dans la forme simple** *(rejeté)*

```jsonc
// ✗ ce qu'on écrit par erreur
{"key":"offers[*]","match":{"price":{"op":"gt","val":0}}}
// l'objet {op,val} est pris comme « valeur à égaler » → CURRENT.price == @{op,val} → jamais vrai

// ✓ la forme correcte
{"key":"offers[*]","match":{"all":[{"key":"price","op":"gt","val":0}]}}
```

En forme simple, **toute valeur non scalaire** (objet ou tableau) est refusée. Même l'**égalité contre un objet** (`{"geo":{"latitude":48.85,"longitude":2.35}}`) doit passer par la forme `all` (`{"all":[{"key":"geo","op":"eq","val":{…}}]}`), où une `val` non scalaire est légitime.

**Piège 3 — un opérateur non câblé dans un `match` (ex. `between`)** *(rejeté)*

```jsonc
// ✗ ce qu'on écrit par erreur
{"key":"offers[*]","match":{"all":[{"key":"price","op":"between","val":[10,100]}]}}
// `between` (et tout opérateur non reconnu : contains, sw, regex, ou une faute de frappe)
// retombait sur `==` contre le tableau brut → CURRENT.price == @[10,100] → jamais vrai

// ✓ la forme correcte — deux conditions bornant la plage
{"key":"offers[*]","match":{"all":[{"key":"price","op":"ge","val":10},{"key":"price","op":"le","val":100}]}}
```

À l'intérieur d'un `match`, seuls les opérateurs **inline reconnus** sont acceptés : `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `in`, `nin`, `like`, `nlike`, `match`, `nmatch`. Tout autre lève une `ValidationException` au lieu de dégrader silencieusement en `==`.

### Combinaisons hash

```jsonc
{"key":"password","val":"5f4dcc3b5aa765d61d8327deb882cf99","alt":"md5"}
// FILTER MD5(doc.password) == @password
```

## Permission (`REQUIRES`)

Whitelister *ce qu'on peut filtrer* (`AQL::FILTERS`) ne dit rien sur *ce qu'on a le droit de lire*. Un champ **caché à la lecture** par une permission (`Field::REQUIRES` dans la projection) mais laissé filtrable devient un **oracle** : `?filter={"key":"salary","op":"gt","val":1000}` inclut ou exclut le document selon une valeur qu'on n'a pas le droit de voir — et un prédicat bindable permet de la retrouver par dichotomie.

**La situation.** `salary` est protégé dans la projection, tout en restant déclaré filtrable.

```php
public array $fields  = [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
public array $filters = [ 'name' => FilterType::STRING , 'salary' => FilterType::NUMBER ] ;
```

Le filtre **hérite** de la permission du champ homonyme de `$fields`. Refusé, le prédicat est **neutralisé en `false`** — jamais retiré (retirer une condition d'un `ET` élargirait le résultat, autre fuite). Il compose donc sans risque dans `and`/`or`/`not` :

| Contexte | Refusé donne | Fuite ? |
|---|---|---|
| `a && false` | branche vide | non |
| `a \|\| false` | `a` | non |
| `NOT false` | `true` (n'exclut rien) | non |

| Utilisateur **avec** `hr:read` | Utilisateur **sans** `hr:read` |
|---|---|
| `?filter=…salary…` → prédicat normal | `?filter=…salary…` → **neutralisé** (aucun résultat trahi) |

**Relations.** Une relation verrouillée à sa définition (`AQL::REQUIRES` sur l'edge/join) ne peut pas être filtrée à travers : la traversée entière est neutralisée. Les deux gates composent (logique ET).

**Sur la définition du filtre.** Le gate ci-dessus *hérite* de la permission de la projection (le champ homonyme de `$fields`). Mais parfois la projection est gardée par un **autre mécanisme** — ou volontairement toujours affichée — et ne porte donc pas de `Field::REQUIRES` : le filtre resterait alors ouvert. On déclare alors la permission **directement sur la définition du filtre**, exactement comme une facette porte la sienne.

**La situation.** `items` est toujours projeté (aucun `Field::REQUIRES` côté `$fields`), mais on ne veut le laisser filtrer qu'aux porteurs de `items:filter`.

```php
public array $filters =
[
    'items' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , Field::REQUIRES => 'items:filter' , AQL::FILTERS => [ 'ref' => FilterType::STRING ] ] ,
] ;
```

Le `Field::REQUIRES` se déclare **à la racine de la définition**, y compris pour les formes imbriquées (`AQL::TYPE` / `AQL::FILTERS`). Refusé, le prédicat est **neutralisé en `false`** comme le gate hérité — jamais retiré. Les deux gates composent (logique ET) : un filtre s'applique seulement si la projection **et** sa propre définition l'autorisent.

| Utilisateur **avec** `items:filter` | Utilisateur **sans** `items:filter` |
|---|---|
| `?filter=…items[*].ref…` → prédicat normal | `?filter=…items[*].ref…` → **neutralisé** |

> Une définition **string** (`FilterType::STRING`, …) ou **callable** ne porte pas de `Field::REQUIRES` : ce gate ne s'y applique pas (rien à lire). C'est la **symétrie** avec les facettes, qui lisent déjà le `REQUIRES` de leur propre définition en plus de celui hérité de la projection.

> **Fail-open.** Aucun `Field::REQUIRES`, ou aucun *authorizer* branché → le filtre s'applique normalement (sémantique des `fields`). Un champ **non projeté** (absent de `$fields`) reste librement filtrable — c'est le cas d'usage « filtrer sur une donnée qu'on n'affiche pas ». Voir [La projection des champs](../projection.md) et [Tri](sort.md#permission-de-tri).
>
> **Profondeur.** Le gate descend au **champ feuille exact**, pas seulement à la racine : un sous-champ d'un document imbriqué (`address.city`), une feuille **à travers** un edge/join (`employee[*].salary` hérite du `Field::REQUIRES` du **modèle cible**) et un sous-champ de tableau d'objets (`contactPoint[*].email`, y compris via `match`) sont tous gatés. Une feuille refusée neutralise **toute la traversée** en `false` — y compris sous le quantificateur `all`/`none` (jamais transformée en oracle d'existence).

## Bonnes pratiques

### Performance et index

Les transformations `alt` **empêchent l'utilisation d'un index** sur le champ transformé. Le moteur ArangoDB ne peut pas pré-calculer `LOWER(doc.email)` sans appliquer la fonction à chaque document.

```jsonc
// N'utilisera PAS l'index sur 'email'
{"key":"email","val":"john@example.com","alt":"lower"}

// Utilisera l'index sur 'email' (si la valeur est déjà normalisée en base)
{"key":"email","val":"john@example.com"}
```

Règle d'or : **normaliser à l'insertion** plutôt qu'au filtrage dès que la charge le justifie. Sinon, accepter le coût (raisonnable pour un volume modéré) et utiliser `alt` librement.

### Validation côté serveur

Le framework valide :

- que la clé `key` est dans `AQL::FILTERS` ;
- que la valeur `val` est compatible avec le `FilterType` déclaré (string pour `STRING`, nombre pour `NUMBER`, ISO 8601 ou ms pour `DATE`, etc.) ;
- que l'opérateur `op` est connu ;
- que la fonction `alt` (et ses paramètres) sont valides.

Les conditions invalides sont **silencieusement ignorées** plutôt que rejetées avec 400. Ce choix protège le service contre des erreurs de client peu graves, mais demande de l'attention côté observabilité : un filtre qui « ne fait rien » mérite une vérification.

### Ordre des fonctions dans `alt`

L'ordre change le résultat. Toujours **réduire la donnée avant de la transformer** :

```jsonc
// Bon ordre : substring avant lower
{"alt":[["substring",0,3],"lower"]}
// LOWER(SUBSTRING(doc.x, 0, 3))

// Mauvais ordre : transforme toute la chaîne puis extrait
{"alt":["lower",["substring",0,3]]}
// SUBSTRING(LOWER(doc.x), 0, 3)
```

### Limiter la longueur de la chaîne `alt`

Plus la chaîne est longue, plus la fonction AQL générée est coûteuse. En pratique, 2-3 transformations suffisent pour 95 % des besoins.

## Voir aussi

- [Filtrage interne `AQL::CONDITIONS` + `AQL::BINDS`](filter-internal.md) — conditions serveur-only, `FilterType::VIRTUAL`.
- [Modèles `Documents` et `Edges`](../models.md) — déclaration `AQL::FILTERS` dans la définition du modèle.
- [Fonctions AQL](../aql/aql-functions-strings.md) — équivalents PHP des transformations exposées ici.
- [Glossaire — Alteration](../getting-started/glossary.md#alteration-alt) — définition de `alt`.
