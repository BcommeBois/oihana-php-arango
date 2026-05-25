# Fonctions documents et vérifications

Cette page regroupe **28 fonctions** réparties entre la racine de [`db/functions/`](../../../src/oihana/arango/db/functions/) (24 fonctions transverses) et le sous-dossier [`db/functions/documents/`](../../../src/oihana/arango/db/functions/documents/) (4 fonctions documents). On y trouve les *type checks*, les *casts*, les opérations sur documents et les fonctions d'information de la base.

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Vérifications de type | `isArray`, `isBool`, `isDateString`, `isKey`, `isNull`, `isNumber`, `isObject`, `isString` |
| Conversions de type | `toArray`, `toBool`, `toNumber`, `toString` |
| Inspection de type | `typeName` |
| Choix conditionnel | `firstDocument`, `firstList`, `notNull` |
| Documents (root) | `document`, `checkDocument`, `decodeRev` |
| Documents (subfolder) | `has`, `merge`, `translate`, `value` |
| Information de la base | `length`, `collectionCount`, `currentDatabase`, `currentUser` |

## Vérifications de type

Toutes ces fonctions partagent la signature `(mixed $value) : string` et produisent le prédicat AQL correspondant. Sont des **expressions booléennes** qu'on insère typiquement dans un `aqlFilter()` ou un `aqlReturn()`.

| Fonction | Sortie AQL | Vrai si |
|---|---|---|
| `isArray` | `IS_ARRAY(<value>)` | tableau ou objet-tableau |
| `isBool` | `IS_BOOL(<value>)` | booléen |
| `isDateString` | `IS_DATESTRING(<value>)` | chaîne ISO 8601 valide |
| `isKey` | `IS_KEY(<value>)` | chaîne respectant la grammaire des `_key` ArangoDB |
| `isNull` | `IS_NULL(<value>)` | `null` strict |
| `isNumber` | `IS_NUMBER(<value>)` | entier ou réel |
| `isObject` | `IS_OBJECT(<value>)` | objet (document) |
| `isString` | `IS_STRING(<value>)` | chaîne |

```php
use function oihana\arango\db\functions\isString ;
use function oihana\arango\db\functions\isNull   ;

isString( 'doc.email' ) ;     // "IS_STRING(doc.email)"
isNull  ( 'doc.parent' ) ;    // "IS_NULL(doc.parent)"
```

## Conversions de type

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `toArray` | `(mixed $value)` | `TO_ARRAY(<value>)` |
| `toBool` | `(mixed $value)` | `TO_BOOL(<value>)` |
| `toNumber` | `(mixed $value)` | `TO_NUMBER(<value>)` |
| `toString` | `(mixed $value)` | `TO_STRING(<value>)` |

Conversions explicites de type — équivalents AQL des fonctions `(bool)`, `(int)`, `(string)`, `(array)` de PHP. Pratiques pour comparer des champs de types différents ou normaliser une donnée hétérogène.

```php
use function oihana\arango\db\functions\toNumber ;

toNumber( 'doc.amount' ) ;     // "TO_NUMBER(doc.amount)"
```

## Inspection de type

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `typeName` | `(mixed $value)` | `TYPENAME(<value>)` |

Retourne le nom du type AQL d'une valeur (`'null'`, `'bool'`, `'number'`, `'string'`, `'array'`, `'object'`). Utile pour le *debug* ou pour filtrer dynamiquement sur le type.

## Choix conditionnel

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `firstDocument` | `(mixed ...$alternatives)` | `FIRST_DOCUMENT(<a>, <b>, ...)` |
| `firstList` | `(mixed ...$alternatives)` | `FIRST_LIST(<a>, <b>, ...)` |
| `notNull` | `(mixed ...$alternatives)` | `NOT_NULL(<a>, <b>, ...)` |

`firstDocument` retourne la première alternative qui est un objet/document (ou `null`). `firstList` retourne la première alternative qui est un tableau. `notNull` retourne la première alternative non `null` — alternative AQL à l'opérateur `??` qu'on trouve dans [`nullish`](aql-operators.md#opérateurs-logiques-et-conditionnels).

```php
use function oihana\arango\db\functions\notNull ;

notNull( 'doc.nickname' , 'doc.firstName' , "'Anonymous'" ) ;
// "NOT_NULL(doc.nickname, doc.firstName, 'Anonymous')"
```

## Documents (root)

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `document` | `(mixed ...$values)` | `DOCUMENT(<args>)` |
| `checkDocument` | `(mixed $document)` | `CHECK_DOCUMENT(<doc>)` |
| `decodeRev` | `(?string $value)` | `DECODE_REV(<rev>)` |

`document` est l'une des fonctions les plus utiles d'AQL : elle récupère dynamiquement un document à partir de son `_id` ou de son `_key`. `checkDocument` valide qu'un objet est un document bien formé (sans clés en double). `decodeRev` décompose un `_rev` en `{ date, count }`.

```php
use function oihana\arango\db\functions\document ;

document( "'users/42'"        ) ;     // "DOCUMENT('users/42')"
document( "'users'" , 'doc.userId' ) ; // "DOCUMENT('users', doc.userId)"
```

## Documents (sous-dossier `documents/`)

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `has` | `(string $document, string $attributeName)` | `HAS(<doc>, <attr>)` |
| `merge` | `(string\|array\|null $documents)` | `MERGE(<docs>)` |
| `translate` | `(mixed $value, mixed $lookupDocument, mixed $defaultValue = null)` | `TRANSLATE(<value>, <lookup>[, <default>])` |
| `value` | `(string $document, array $path)` | `VALUE(<doc>, [<path>])` |

`has` teste l'existence d'une clé. `merge` fusionne des documents (équivalent du `Object.assign` JS). `translate` est un *lookup* dans une table de traduction. `value` accède à un champ profond via un chemin (`['a', 'b', 'c']` pour `doc.a.b.c`).

```php
use function oihana\arango\db\functions\documents\has        ;
use function oihana\arango\db\functions\documents\translate  ;

has( 'doc' , "'email'" ) ;
// "HAS(doc, 'email')"

translate( 'doc.lang' , '{ "fr": "Français", "en": "English" }' , "'?'" ) ;
// "TRANSLATE(doc.lang, { \"fr\": \"Français\", \"en\": \"English\" }, '?')"
```

## Information de la base

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `length` | `(mixed $collection)` | `LENGTH(<collection>)` |
| `collectionCount` | `(mixed $collection)` | `COLLECTION_COUNT(<collection>)` |
| `currentDatabase` | `()` | `CURRENT_DATABASE()` |
| `currentUser` | `()` | `CURRENT_USER()` |

`length` et `collectionCount` retournent le nombre de documents dans une collection. `LENGTH()` est généralement préféré pour la cohérence avec le `LENGTH()` côté tableau (cf. [arrays](aql-functions-arrays.md)).

`currentDatabase` et `currentUser` sont surtout utiles pour les requêtes système ou les audits.

> Le `length` exposé ici (sur collection) cohabite avec le `length` du sous-dossier `arrays/` (sur tableau). Les deux produisent `LENGTH(...)` mais sont importés depuis des *namespaces* différents : `oihana\arango\db\functions\length` (root) vs `oihana\arango\db\functions\arrays\length` (sous-dossier).

## Composition typique

Récupérer un document lié et exposer uniquement certains champs si la cible existe et est bien formée :

```php
use function oihana\arango\db\operations\aqlReturn ;
use function oihana\arango\db\helpers\aqlDocument  ;
use function oihana\arango\db\functions\document        ;
use function oihana\arango\db\functions\isObject        ;
use function oihana\arango\db\functions\notNull         ;
use function oihana\arango\db\functions\documents\value ;

aqlReturn( aqlDocument
([
    'self'    => 'doc' ,
    'owner'   => document( "'users'" , 'doc.ownerId' ) ,
    'parent'  => notNull( document( "'users'" , 'doc.parentId' ) , 'null' ) ,
    'nestedX' => value( 'doc' , [ "'meta'" , "'x'" ] ) ,
])) ;
```

## Voir aussi

- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Opérateurs `db/operators/`](aql-operators.md) — combiner les prédicats `IS_*` avec `AND` / `OR`.
- [Helpers AQL `db/helpers/`](../db-helpers.md) — `aqlDocument` pour composer un document de retour.
- [Documentation officielle AQL — Type check & cast](https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/).
- [Documentation officielle AQL — Document functions](https://docs.arangodb.com/stable/aql/functions/document/).
- [Documentation officielle AQL — Miscellaneous](https://docs.arangodb.com/stable/aql/functions/miscellaneous/).
