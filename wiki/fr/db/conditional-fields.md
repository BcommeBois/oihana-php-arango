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

## Sécurité

La condition est compilée **inline** car la couche de projection ne transporte pas de
variables de *bind*. C'est sûr par construction :

- Les **noms d'attributs** (opérandes de condition et `else` valué par attribut) sont
  validés par `assertAttributeName()` — tout caractère capable de s'échapper d'un accesseur
  `doc.<attr>` est rejeté par une `ValidationException`.
- Les **valeurs** sont des littéraux déclarés par le développeur dans la définition du champ
  (jamais une entrée de requête — celles-ci passent par des binds dans `?filter=`), inlinées
  et échappées par `aqlValue()`.

## AQL généré — référence

```
price : (TO_BOOL(doc.active) && LOWER(doc.status) == 'public') ? LOWER(TRIM(doc.price)) : doc.basePrice
        └─────────────── condition ───────────────┘            └──── then (+ALTERS) ───┘   └── else ──┘
```

Voir aussi : [Helpers AQL](helpers.md) · [La projection des champs](../projection.md).
