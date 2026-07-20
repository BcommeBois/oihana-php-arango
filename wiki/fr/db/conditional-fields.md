# Champs conditionnels — `Field::WHEN`

Une projection scalaire peut être **gardée par une condition** : la valeur du champ n'est
calculée que si la condition tient, sinon elle retombe sur une branche `else`. C'est
l'équivalent AQL du `CASE WHEN … THEN … ELSE …` SQL, rendu par un ternaire :

```aql
price: doc.visibility == 'public' ? doc.price : null
```

- La **clé est toujours présente** — seule la *valeur* bascule. `Field::WHEN` ne retire
  jamais la clé (cela demanderait un `MERGE`, volontairement hors périmètre ; une valeur
  absente s'exprime par `null`).
- La condition est **résolue à l'exécution** à partir des attributs du document (décision
  par ligne), contrairement à `Field::SKINS` / `Field::REQUIRES` qui décident de
  l'*inclusion* en amont (par requête / par permission). Les trois sont orthogonaux et se
  cumulent.
- Les valeurs de condition sont **inlinées** (non bindées) : un `WHEN` est de la
  configuration déclarée par le développeur, jamais une entrée utilisateur — voir
  [Sécurité](#sécurité).

> S'applique **uniquement à la projection scalaire par défaut**. Un `Field::WHEN` sur un
> filtre typé/structurel (`EDGES`, `JOINS`, `DOCUMENT`, `MAP`, `URL`, …) lève une
> `UnsupportedOperationException`.

## Démarrage rapide

```php
use oihana\arango\enums\Field ;

$fields =
[
    // montrer le vrai prix au public, le prix de base sinon
    'price' =>
    [
        Field::WHEN => [ 'visibility' , 'public' ] ,
        Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ,
    ],
];
// price: doc.visibility == 'public' ? doc.price : doc.basePrice
```

L'attribut de condition est **indépendant** du champ projeté — ici la valeur est `price`
mais le test lit `visibility`.

## La condition

Une condition est une **feuille** (une comparaison) ou un **groupe** (des feuilles
combinées par de la logique).

### Formes de feuille

| Déclaré | Sens | AQL |
|---|---|---|
| `'active'` (chaîne) | véracité | `TO_BOOL(doc.active)` |
| `[ 'visibility', 'public' ]` | égalité | `doc.visibility == 'public'` |
| `[ 'stock', 'gt', 0 ]` | comparateur explicite | `doc.stock > 0` |
| `[ FilterParam::KEY => 'status', FilterParam::OP => 'eq', FilterParam::VAL => 'public' ]` | forme associative | `doc.status == 'public'` |

La forme associative reprend le vocabulaire de feuille du DSL `?filter=` (`FilterParam`
`key` / `op` / `val` / `alt`) — une condition écrite pour un filtre se lit pareil ici.

**Comparateurs supportés** (infixes uniquement) : `eq`, `ne`, `ge`, `gt`, `le`, `lt`, `in`,
`nin`, `like`, `nlike`, `match`, `nmatch`. Les opérateurs en forme de fonction (`contains`,
`sw`, `ew`, `regex`, …) sont **rejetés** — utilisez le `?filter=` plat pour ceux-ci.

**Comparer deux attributs** — une valeur qui ressemble à une référence de document est
conservée brute :

```php
Field::WHEN => [ 'price', 'gt', 'doc.minPrice' ]
// doc.price > doc.minPrice
```

### `alt` sur les opérandes

Une feuille peut porter une chaîne `alt` qui enrobe l'attribut comparé (gauche) et/ou la
valeur (droite) — même vocabulaire miroir `"lower"` / `{ key, val }` / `{ key, val:true }`
que les filtres plats :

```php
Field::WHEN =>
[
    FilterParam::KEY => 'status' ,
    FilterParam::VAL => 'PUBLIC' ,
    FilterParam::ALT => [ 'key' => 'lower' , 'val' => true ] , // miroir des deux côtés
]
// LOWER(doc.status) == LOWER('PUBLIC')
```

> Ne pas confondre les deux portées d'`alt` : un `alt` **dans une feuille** enrobe les
> *opérandes de condition* ; `Field::ALTERS` sur le champ enrobe la *valeur projetée*
> (voir plus bas).

### Groupes — AND / OR / NOT

Les groupes reprennent la grammaire récursive de `?filter=` :

| Déclaré | AQL |
|---|---|
| `[ [ 'visibility', 'public' ], [ 'stock', 'gt', 0 ] ]` (AND implicite) | `(doc.visibility == 'public' && doc.stock > 0)` |
| `[ 'and', c1, c2 ]` | `(c1 && c2)` |
| `[ 'or', [ 'role', 'admin' ], [ 'owner', 'eq', true ] ]` | `(doc.role == 'admin' \|\| doc.owner == true)` |
| `[ 'not', [ 'anonymized', true ] ]` | `!(doc.anonymized == true)` |
| `[ 'and', [ 'or', c1, c2 ], [ 'active', true ] ]` (imbriqué) | `((c1 \|\| c2) && doc.active == true)` |

Désambiguïsation : une liste commençant par `and` / `or` / `not` est un **groupe** ; une
liste dont tous les éléments sont des tableaux est un **AND implicite** ; une liste de
scalaires est une **feuille unique**.

## La branche `else`

Sans `Field::ELSE`, le repli est `null`. Deux formes sinon :

| Déclaré | else AQL | Sens |
|---|---|---|
| `Field::ELSE => 0` | `0` | littéral (inliné ; `null` / `0` / `'N/A'` …) |
| `Field::ELSE => [ Field::PROPERTY => 'basePrice' ]` | `doc.basePrice` | un autre attribut du document |

## Combinaison avec les autres options

`Field::WHEN` se compose avec les autres options par champ :

```php
'slug' =>
[
    Field::NAME   => 'title' ,                  // source de la valeur ≠ clé de sortie
    Field::WHEN   => [ 'published', 'eq', true ] ,
    Field::ALTERS => [ 'trim', 'lower' ] ,      // enrobe la valeur du THEN
]
// slug: doc.published == true ? LOWER(TRIM(doc.title)) : null
```

- `Field::ALTERS` décore la branche **then** (`cond ? ALTERS(valeur) : else`).
- `Field::NAME` aliase la source projetée, indépendamment de l'attribut de condition.
- `Field::REQUIRES` (gating de permission) et `Field::SKINS` (variantes nommées)
  s'appliquent toujours — ils décident si le champ est présent du tout, avant que la
  condition soit évaluée.

## Filtrer les éléments d'un tableau projeté — `Field::WHERE`

`Field::WHEN` décide **la valeur** d'un champ scalaire. `Field::WHERE` décide **quels
éléments** d'un tableau projeté (`Filter::MAP`) sont retournés — un `FILTER` posé dans la
boucle imbriquée, **entre** le `FOR` et le `RETURN` :

```aql
addresses: ( FOR item IN doc.addresses
             FILTER item.region IN @allowedRegions
             RETURN { street: item.street, city: item.city } )
```

Ne pas les confondre :

| Marqueur | Décide | Posé sur |
|---|---|---|
| `Field::WHEN` | la *valeur* d'un champ (ternaire) | projection scalaire par défaut |
| `Field::WHERE` | *quels éléments* d'un tableau sont projetés (`FILTER`) | un `Filter::MAP` |

`Field::WHERE` réutilise **exactement** la grammaire de condition de `Field::WHEN` (feuilles,
groupes `AND` / `OR` / `NOT`, `alt`) — compilée contre **l'élément du tableau** (`item`), pas
contre `doc`.

### Comparer à une valeur connue seulement à l'exécution — `aqlBindRef()`

**La situation.** Chaque `user` porte un tableau `addresses[]`, chaque adresse a une
`region`. On veut qu'un appelant ne voie que les adresses de **ses** régions autorisées — et
cette liste n'est connue **qu'à l'exécution**, pas à l'écriture du modèle.

Une condition `WHEN` **inline** ses valeurs : de la configuration figée. Ici la valeur — la
liste des régions — n'existe qu'à la requête. `aqlBindRef('nom')` déclare « cette valeur est
une **variable liée** `@nom`, fournie ailleurs » : le nom est **validé** (règles de bind
ArangoDB), **aucune valeur n'est inlinée**, seul le jeton `@nom` est émis.

**1. Le modèle** (statique) :

```php
use function oihana\arango\db\binds\aqlBindRef ;

'addresses' =>
[
    Field::FILTER => Filter::MAP ,
    Field::WHERE  => [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] ,
    Field::FIELDS => [ 'street' => Filter::DEFAULT , 'city' => Filter::DEFAULT ] ,
]
```

**2. L'appelant fournit les valeurs** (par requête, via le mécanisme existant `AQL::BINDS`) :

```php
$init[ AQL::BINDS ] = [ 'allowedRegions' => [ 'eu-west' , 'eu-north' ] ] ;
```

**3. L'AQL produit** — le jeton `@allowedRegions`, jamais la liste inlinée ; sa valeur voyage
dans la carte `bindVars` **unique** de la requête (fusionnée par `AQL::BINDS`). La projection
n'a qu'à **nommer** le créneau ; l'hôte le **remplit**.

### Le bind peut aussi être à gauche

Un bind **booléen** peut occuper la position d'attribut — un interrupteur fourni à la
requête. `[ aqlBindRef('unrestricted') ]` compile en `@unrestricted` (jeton nu, ni `doc.`, ni
`TO_BOOL`). Utile pour « voit tout, **sauf si** restreint » :

```php
Field::WHERE =>
[ 'or' ,
    [ aqlBindRef( 'unrestricted' ) ] ,                    // → @unrestricted
    [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] , // → item.region IN @allowedRegions
]
// FILTER (@unrestricted || item.region IN @allowedRegions)
```

### Fermé par défaut (*fail-closed*)

Contrairement à `Field::REQUIRES` (ouvert en l'absence d'authorizer), `Field::WHERE`
**ferme** :

- bind lié à un tableau **vide** → `IN []` → **aucun élément** (comportement voulu) ;
- bind **absent** de la carte finale → la requête AQL **échoue** (erreur ArangoDB) → aucune
  donnée. Un bind manquant n'est **jamais** réinterprété en « pas de filtre » (ce serait
  *fail-open*).

Les éléments hors périmètre ne sont **jamais lus** en base : filtre, tri et facette ne
peuvent donc rien en inférer. Le câblage applicatif (résoudre la liste, injecter les binds)
se fait **hors** de la librairie, dans le projet consommateur.

## Sécurité

La condition d'un `Field::WHEN` est compilée **inline** ; celle d'un `Field::WHERE` peut en
plus **référencer un bind**. Les deux sont sûres par construction :

- Les **noms d'attributs** (opérandes de condition et `else` valué par attribut) sont
  validés par `assertAttributeName()` — tout caractère capable de s'échapper d'un accesseur
  `doc.<attr>` est rejeté par une `ValidationException`.
- Les **valeurs littérales** sont déclarées par le développeur dans la définition du champ
  (jamais une entrée de requête — celles-ci passent par des binds dans `?filter=`), inlinées
  et échappées par `aqlValue()`.
- Une **référence de bind** (`aqlBindRef('nom')`) n'inline rien : le **nom** est validé par
  `assertBindVariable()`, et seul le jeton `@nom` est émis. La **valeur** est fournie à la
  requête via `AQL::BINDS` — donc jamais concaténée dans le texte AQL, quel que soit son
  contenu.

## AQL généré — référence

```
price : (TO_BOOL(doc.active) && LOWER(doc.status) == 'public') ? LOWER(TRIM(doc.price)) : doc.basePrice
        └─────────────── condition ───────────────┘            └──── then (+ALTERS) ───┘   └── else ──┘
```

Voir aussi : [Helpers AQL](helpers.md) · [La projection des champs](../projection.md).
