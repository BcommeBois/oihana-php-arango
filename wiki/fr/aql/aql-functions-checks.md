# Fonctions documents et vérifications

Cette page regroupe **45 fonctions** réparties entre la racine de [`db/functions/`](../../../src/oihana/arango/db/functions/) (24 fonctions transverses) et le sous-dossier [`db/functions/documents/`](../../../src/oihana/arango/db/functions/documents/) (21 fonctions documents). On y trouve les *type checks*, les *casts*, les opérations sur documents et les fonctions d'information de la base.

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Vérifications de type | `isArray`, `isBool`, `isDateString`, `isKey`, `isNull`, `isNumber`, `isObject`, `isString` |
| Conversions de type | `toArray`, `toBool`, `toNumber`, `toString` |
| Inspection de type | `typeName` |
| Choix conditionnel | `firstDocument`, `firstList`, `notNull` |
| Documents (root) | `document`, `checkDocument`, `decodeRev` |
| Documents (sous-dossier) | `attributes`, `count`, `entries`, `has`, `isSameCollection`, `keep`, `keepRecursive`, `keys`, `length`, `matches`, `merge`, `mergeRecursive`, `parseCollection`, `parseIdentifier`, `parseKey`, `translate`, `unsetAttributes`, `unsetRecursive`, `value`, `values`, `zip` |
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

L'ensemble complet des *document/object functions* AQL (21 helpers, un par constante `DocumentFunction`) :

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `attributes` | `(string $document, ?bool $removeSystemAttrs = null, ?bool $sort = null)` | `ATTRIBUTES(<doc>[, <bool>[, <bool>]])` |
| `count` | `(string $document)` | `COUNT(<doc>)` |
| `entries` | `(string $document)` | `ENTRIES(<doc>)` |
| `has` | `(string $document, string $attributeName)` | `HAS(<doc>, <attr>)` |
| `isSameCollection` | `(string $collectionName, string $documentIdentifier)` | `IS_SAME_COLLECTION("<coll>", <id>)` |
| `keep` | `(string $document, string ...$attributes)` | `KEEP(<doc>, "<attr>", ...)` |
| `keepRecursive` | `(string $document, string ...$attributes)` | `KEEP_RECURSIVE(<doc>, "<attr>", ...)` |
| `keys` | `(string $document, ?bool $removeSystemAttrs = null, ?bool $sort = null)` | `KEYS(<doc>[, <bool>[, <bool>]])` |
| `length` | `(string $document)` | `LENGTH(<doc>)` |
| `matches` | `(string $document, string\|array $examples, ?bool $returnIndex = null)` | `MATCHES(<doc>, <examples>[, <bool>])` |
| `merge` | `(string\|array\|null $documents)` | `MERGE(<docs>)` |
| `mergeRecursive` | `(string\|array\|null $documents)` | `MERGE_RECURSIVE(<docs>)` |
| `parseCollection` | `(string $documentIdentifier)` | `PARSE_COLLECTION(<id>)` |
| `parseIdentifier` | `(string $documentIdentifier)` | `PARSE_IDENTIFIER(<id>)` |
| `parseKey` | `(string $documentIdentifier)` | `PARSE_KEY(<id>)` |
| `translate` | `(mixed $value, mixed $lookupDocument, mixed $defaultValue = null)` | `TRANSLATE(<value>, <lookup>[, <default>])` |
| `unsetAttributes` | `(string $document, string ...$attributes)` | `UNSET(<doc>, "<attr>", ...)` |
| `unsetRecursive` | `(string $document, string ...$attributes)` | `UNSET_RECURSIVE(<doc>, "<attr>", ...)` |
| `value` | `(string $document, array $path)` | `VALUE(<doc>, [<path>])` |
| `values` | `(string $document, ?bool $removeSystemAttrs = null)` | `VALUES(<doc>[, <bool>])` |
| `zip` | `(string\|array $keys, string\|array $values)` | `ZIP(<keys>, <values>)` |

Clés (`attributes`/`keys`, `values`, `entries`, `count`/`length`), projection (`keep`/`keepRecursive`, `unsetAttributes`/`unsetRecursive`), fusion (`merge`/`mergeRecursive`), construction (`zip`), lookup (`translate`, `value`), correspondance (`matches`, `has`) et analyse d'identifiant (`parseCollection`/`parseIdentifier`/`parseKey`, `isSameCollection`).

> **Quoting** — les helpers dont l'argument AQL doit être un *littéral string* le mettent entre guillemets pour vous : les noms d'attributs de `keep`/`unset*`, le nom de collection de `isSameCollection`, et les tableaux PHP passés à `matches`/`zip` (émis en JSON via `json_encode`). Les arguments `document` / identifiant restent des expressions AQL brutes (`doc`, `@bind`, `doc._id`). `unsetAttributes()` porte ce nom car `unset` est un mot-clé réservé de PHP.

```php
use function oihana\arango\db\functions\documents\keep            ;
use function oihana\arango\db\functions\documents\unsetAttributes  ;
use function oihana\arango\db\functions\documents\merge            ;
use function oihana\arango\db\functions\documents\zip             ;

keep( 'doc' , 'name' , 'email' ) ;        // "KEEP(doc,\"name\",\"email\")"
unsetAttributes( 'doc' , '_id' , '_rev' ); // "UNSET(doc,\"_id\",\"_rev\")"
merge( [ 'doc' , '{ active: true }' ] ) ;  // "MERGE(doc,{ active: true })"
zip( [ 'a' , 'b' ] , [ 1 , 2 ] ) ;         // "ZIP([\"a\",\"b\"],[1,2])"
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
- [Helpers AQL `db/helpers/`](../db/helpers.md) — `aqlDocument` pour composer un document de retour.
- [Documentation officielle AQL — Type check & cast](https://docs.arangodb.com/stable/aql/functions/type-check-and-cast/).
- [Documentation officielle AQL — Document functions](https://docs.arangodb.com/stable/aql/functions/document/).
- [Documentation officielle AQL — Miscellaneous](https://docs.arangodb.com/stable/aql/functions/miscellaneous/).
