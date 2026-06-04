# Filtres HTTP `?filter=`

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
| `in` | Dans la liste fournie | `doc.x IN @val` |
| `nin` | Pas dans la liste | `doc.x NOT IN @val` |
| `between` | Plage inclusive (clés `min`/`max` au lieu de `val`) | `(doc.x >= @min && doc.x <= @max)` |

Exemples :

```
?filter={"key":"status","val":"closed","op":"ne"}
?filter={"key":"price","val":100,"op":"gt"}
?filter={"key":"name","val":"%john%","op":"like"}
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

## Types de filtres

Chaque champ filtrable est typé via `FilterType::*` dans la déclaration `AQL::FILTERS` du modèle. Le type **détermine** comment la valeur est validée et quel sous-ensemble des `alt` est compatible.

| Type | Validation côté serveur | Opérateurs utiles | Exemples d'`alt` compatibles |
|---|---|---|---|
| `FilterType::STRING` | Chaîne non vide | `eq`, `ne`, `like`, `in`, `nin` | `lower`, `upper`, `trim`, `substring`, `length`, `md5` |
| `FilterType::NUMBER` | Entier ou réel | `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `in`, `nin` | `abs`, `round`, `ceil`, `floor` |
| `FilterType::DATE` | ISO 8601 ou timestamp Unix ms | `eq`, `ne`, `gt`, `ge`, `lt`, `le` | `dateYear`, `dateMonth`, `dateDayOfWeek`, `dateFormat` |
| `FilterType::BOOL` | `true` / `false` | `eq`, `ne` | Aucun (booléen non transformable) |
| `FilterType::ARRAY` | Tableau JSON | `eq`, `in`, `nin` ; opérateurs quantifiés | `count`, `length`, `first`, `last`, `sum`, `avg` |
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

**Quantificateur `AT LEAST (n)`** — « au moins n éléments satisfont la comparaison ». L'opérateur prend la **forme tableau `["atLeast.<cmp>", n]`** (élément 0 = code, élément 1 = seuil, défaut 1). Le suffixe `<cmp>` réutilise le vocabulaire des opérateurs (`eq`, `ne`, `gt`, `ge`, `lt`, `le`, `in`, `nin`) :

```jsonc
// au moins 2 scores >= 80
{"key":"scores","op":["atLeast.ge",2],"val":80}
// FILTER doc.scores AT LEAST (2) >= @value

// au moins 3 éléments dans la liste fournie
{"key":"scores","op":["atLeast.in",3],"val":[1,2,3]}
// FILTER doc.scores AT LEAST (3) IN @value
```

Le seuil est converti en entier (anti-injection) ; le champ reste compatible `alt`.

### Combinaisons hash

```jsonc
{"key":"password","val":"5f4dcc3b5aa765d61d8327deb882cf99","alt":"md5"}
// FILTER MD5(doc.password) == @password
```

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
