# Fonctions binaires `db/functions/bit/`

Le sous-dossier [`src/oihana/arango/db/functions/bit/`](../../../src/oihana/arango/db/functions/bit/) regroupe les **12 fonctions** qui correspondent aux *bit functions* natives d'AQL — logique bit à bit, décalages, comptage de bits et conversions de chaîne binaire. Chaque helper renvoie la chaîne `BIT_FUNCTION(args)`, prête à insérer dans un `FILTER` / `SORT` / une projection.

> Toutes les opérations bit d'AQL travaillent sur des **entiers non signés jusqu'à 32 bits** — les valeurs d'entrée doivent être dans l'intervalle `0 … 2³² - 1`, et tout argument `bits` dans `0 … 32`.

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Logique | `bitAnd`, `bitOr`, `bitXor`, `bitNegate` |
| Inspection | `bitTest`, `bitPopcount` |
| Décalages | `bitShiftLeft`, `bitShiftRight` |
| Construction | `bitConstruct`, `bitDeconstruct` |
| Conversion chaîne | `bitToString`, `bitFromString` |

## Référence

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `bitAnd` | `(string\|int\|array $values, string\|int\|null $value2 = null)` | `BIT_AND([…])` ou `BIT_AND(a, b)` |
| `bitOr` | `(string\|int\|array $values, string\|int\|null $value2 = null)` | `BIT_OR([…])` ou `BIT_OR(a, b)` |
| `bitXor` | `(string\|int\|array $values, string\|int\|null $value2 = null)` | `BIT_XOR([…])` ou `BIT_XOR(a, b)` |
| `bitNegate` | `(string\|int $value, string\|int $bits)` | `BIT_NEGATE(<v>, <bits>)` |
| `bitTest` | `(string\|int $value, string\|int $index)` | `BIT_TEST(<v>, <index>)` |
| `bitPopcount` | `(string\|int $value)` | `BIT_POPCOUNT(<v>)` |
| `bitShiftLeft` | `(string\|int $value, string\|int $shift, string\|int $bits)` | `BIT_SHIFT_LEFT(<v>, <shift>, <bits>)` |
| `bitShiftRight` | `(string\|int $value, string\|int $shift, string\|int $bits)` | `BIT_SHIFT_RIGHT(<v>, <shift>, <bits>)` |
| `bitConstruct` | `(string\|array $positions)` | `BIT_CONSTRUCT([…])` |
| `bitDeconstruct` | `(string\|int $value)` | `BIT_DECONSTRUCT(<v>)` |
| `bitToString` | `(string\|int $value, string\|int $bits)` | `BIT_TO_STRING(<v>, <bits>)` |
| `bitFromString` | `(string $bitstring)` | `BIT_FROM_STRING("<bitstring>")` |

`bitAnd` / `bitOr` / `bitXor` acceptent **soit** un unique tableau de nombres, **soit** deux opérandes (en passant le second argument). Les tableaux PHP (forme tableau, et `bitConstruct`) sont émis en littéraux JSON ; la chaîne binaire de `bitFromString` est émise en littéral string entre guillemets. Tout autre argument est passé tel quel comme expression AQL brute : une colonne comme `doc.flags` fonctionne directement.

`bitConstruct` ⇄ `bitDeconstruct` et `bitToString` ⇄ `bitFromString` sont des paires inverses.

## Exemples

```php
use function oihana\arango\db\functions\bit\bitAnd        ;
use function oihana\arango\db\functions\bit\bitShiftLeft   ;
use function oihana\arango\db\functions\bit\bitTest        ;
use function oihana\arango\db\functions\bit\bitFromString  ;

bitAnd([1, 4, 8, 16]);      // "BIT_AND([1,4,8,16])"      → 0
bitAnd(127, 255);           // "BIT_AND(127,255)"         → 127
bitShiftLeft(1, 4, 8);      // "BIT_SHIFT_LEFT(1,4,8)"    → 16
bitTest('doc.flags', 3);    // "BIT_TEST(doc.flags,3)"
bitFromString('0101');      // "BIT_FROM_STRING(\"0101\")" → 5
```

Filtrer les documents dont l'attribut `flags` a un bit donné positionné :

```php
use function oihana\arango\db\functions\bit\bitTest ;
use function oihana\arango\db\operations\aqlFilter  ;

aqlFilter( bitTest( 'doc.flags' , 2 ) );
// "FILTER BIT_TEST(doc.flags, 2)"
```

## Voir aussi

- [Fonctions numériques `db/functions/numerics/`](aql-functions-numerics.md) — arithmétique, trigonométrie, vecteurs.
- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Documentation AQL officielle — Bit functions](https://docs.arangodb.com/stable/aql/functions/bit/).
