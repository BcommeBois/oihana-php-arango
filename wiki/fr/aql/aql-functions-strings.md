# Fonctions de chaînes `db/functions/strings/`

Le sous-dossier [`src/oihana/arango/db/functions/strings/`](../../../src/oihana/arango/db/functions/strings/) regroupe **37 fonctions** qui correspondent aux *string functions* natives d'AQL. Chaque fonction PHP retourne la chaîne `FONCTION_AQL(args)` prête à être insérée dans un prédicat ou une projection.

> Distinction importante : les fonctions de cette page produisent l'AQL `LOWER(doc.name)` à partir du code PHP `lower('doc.name')`. Elles n'ont **aucun rapport** avec les transformations `alt` exposées côté URL HTTP (`?filter={"alt":"lower"}`) documentées dans [`filter.md`](../db/filter.md) — ce sont deux mondes parallèles, malgré des noms qui se ressemblent.

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Casse | `lower`, `upper` |
| Nettoyage | `trim`, `ltrim`, `rtrim` |
| Extraction et concaténation | `subString`, `left`, `right`, `concat`, `concatSeparator`, `split`, `tokens` |
| Recherche | `contains`, `startsWith`, `like`, `findFirst`, `findLast` |
| Longueur | `charLength` |
| Empreintes et hashs | `md5`, `sha1`, `sha256`, `sha512`, `crc32`, `fnv64`, `soundex`, `levenshtein` |
| Encodage et conversion | `toBase64`, `toHex`, `encodeURIComponent`, `toChar`, `jsonParse`, `jsonStringify` |
| Génération | `uuid`, `randomToken` |
| IPv4 | `ipv4FromNumber`, `ipv4ToNumber`, `isIPV4` |

## Casse

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `lower` | `(string $value)` | `LOWER(<value>)` |
| `upper` | `(string $value)` | `UPPER(<value>)` |

```php
use function oihana\arango\db\functions\strings\lower ;
use function oihana\arango\db\functions\strings\upper ;

lower( 'doc.email' ) ;   // "LOWER(doc.email)"
upper( 'doc.code'  ) ;   // "UPPER(doc.code)"
```

## Nettoyage

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `trim` | `(string $value, string\|int\|null $charsOrType = null)` | `TRIM(<value>[, <type>])` |
| `ltrim` | `(string $value, ?string $chars = null)` | `LTRIM(<value>[, <chars>])` |
| `rtrim` | `(string $value, ?string $chars = null)` | `RTRIM(<value>[, <chars>])` |

Pour `trim`, le second paramètre est soit un entier (`0` = les deux côtés, `1` = gauche, `2` = droite), soit une chaîne de caractères à supprimer.

```php
trim ( 'doc.name'        ) ;        // "TRIM(doc.name)"
trim ( 'doc.path' , '/'  ) ;        // "TRIM(doc.path, '/')"
ltrim( 'doc.code' , 'X'  ) ;        // "LTRIM(doc.code, 'X')"
```

## Extraction et concaténation

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `subString` | `(string $value, int $offset, ?int $length = null)` | `SUBSTRING(<value>, <offset>[, <length>])` |
| `left` | `(string $value, int $length)` | `LEFT(<value>, <length>)` |
| `right` | `(string $value, int $length)` | `RIGHT(<value>, <length>)` |
| `concat` | `(array\|string\|null $arguments)` | `CONCAT(<args>)` |
| `concatSeparator` | `(string $separator, array\|string\|null $arguments = null)` | `CONCAT_SEPARATOR(<separator>, <args>)` |
| `split` | `(string $value, string $separator, ?int $limit = null)` | `SPLIT(<value>, <separator>[, <limit>])` |
| `tokens` | `(string $init, string $analyzer)` | `TOKENS(<text>, <analyzer>)` |

```php
subString( 'doc.code' , 0 , 3 ) ;          // "SUBSTRING(doc.code, 0, 3)"
concat( [ 'doc.firstName' , "' '" , 'doc.lastName' ] ) ;
// "CONCAT(doc.firstName, ' ', doc.lastName)"
concatSeparator( "'-'" , [ 'doc.year' , 'doc.month' , 'doc.day' ] ) ;
// "CONCAT_SEPARATOR('-', doc.year, doc.month, doc.day)"
```

`tokens` est utilisé en contexte ArangoSearch : il tokenize une chaîne via un analyseur déclaré côté serveur (`text_en`, `text_fr`, ...).

## Recherche

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `contains` | `(string $text, string $search, bool $returnIndex = false)` | `CONTAINS(<text>, <search>[, <returnIndex>])` |
| `startsWith` | `(string $value, string $prefix)` | `STARTS_WITH(<value>, <prefix>)` |
| `like` | `(string $text, string $search, bool $caseSensitive = false)` | `LIKE(<text>, <search>[, <caseSensitive>])` |
| `findFirst` | `(string $value, string $search, ?int $start, ?int $end)` | `FIND_FIRST(<value>, <search>[, <start>[, <end>]])` |
| `findLast` | `(string $value, string $search, ?int $start, ?int $end)` | `FIND_LAST(<value>, <search>[, <start>[, <end>]])` |

```php
contains  ( 'doc.bio'   , "'php'"   , true ) ;     // "CONTAINS(doc.bio, 'php', true)"
startsWith( 'doc.title' , "'Mr'"           ) ;     // "STARTS_WITH(doc.title, 'Mr')"
like      ( 'doc.name'  , "'%john%'"       ) ;     // "LIKE(doc.name, '%john%')"
```

## Longueur

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `charLength` | `(string $expression)` | `CHAR_LENGTH(<value>)` |

Pour la longueur d'un tableau, voir [`length`](aql-functions-arrays.md) dans les fonctions de tableaux.

## Empreintes et hashs

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `md5` | `(string $value)` | `MD5(<value>)` |
| `sha1` | `(string $value)` | `SHA1(<value>)` |
| `sha256` | `(string $value)` | `SHA256(<value>)` |
| `sha512` | `(string $value)` | `SHA512(<value>)` |
| `crc32` | `(string $value)` | `CRC32(<value>)` (hexadécimal) |
| `fnv64` | `(string $value)` | `FNV64(<value>)` (hexadécimal) |
| `soundex` | `(string $value)` | `SOUNDEX(<value>)` (empreinte phonétique anglais) |
| `levenshtein` | `(string $value1, string $value2)` | `LEVENSHTEIN_DISTANCE(<a>, <b>)` |

Les fonctions cryptographiques (`md5`, `sha*`) sont à n'utiliser que pour des empreintes de cache ou de déduplication — jamais pour stocker des mots de passe.

```php
md5      ( 'doc.email'                  ) ;        // "MD5(doc.email)"
soundex  ( 'doc.lastName'               ) ;        // "SOUNDEX(doc.lastName)"
levenshtein( 'doc.name' , "'francois'"  ) ;        // "LEVENSHTEIN_DISTANCE(doc.name, 'francois')"
```

## Encodage et conversion

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `toBase64` | `(string $value)` | `TO_BASE64(<value>)` |
| `toHex` | `(string $value)` | `TO_HEX(<value>)` |
| `encodeURIComponent` | `(string $value)` | `ENCODE_URI_COMPONENT(<value>)` |
| `toChar` | `(int $codepoint)` | `TO_CHAR(<codepoint>)` |
| `jsonParse` | `(string $text)` | `JSON_PARSE(<text>)` |
| `jsonStringify` | `(mixed $value)` | `JSON_STRINGIFY(<value>)` |

`jsonParse` et `jsonStringify` permettent de stocker du JSON sérialisé et de le reparser côté serveur (utile pour les champs *blob* qu'on ne veut pas indexer).

## Génération

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `uuid` | `()` | `UUID()` |
| `randomToken` | `(int $length)` | `RANDOM_TOKEN(<length>)` |

`uuid` produit un UUID v4. `randomToken` génère une chaîne aléatoire de la longueur demandée (utile pour les tokens d'API à signer).

## IPv4

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `ipv4FromNumber` | `(string $value)` | `IPV4_FROM_NUMBER(<value>)` |
| `ipv4ToNumber` | `(string $value)` | `IPV4_TO_NUMBER(<value>)` |
| `isIPV4` | `(string $value)` | `IS_IPV4(<value>)` |

Conversions entre représentation texte (`'192.168.1.1'`) et entier 32 bits, plus prédicat de validation. Utile pour stocker les IP de manière compacte tout en gardant la possibilité de les filtrer.

## Composition typique

Les fonctions s'emboîtent librement, car toutes retournent une chaîne AQL. Exemple de comparaison normalisée :

```php
use function oihana\arango\db\operators\equal           ;
use function oihana\arango\db\operations\aqlFilter      ;
use function oihana\arango\db\functions\strings\lower   ;
use function oihana\arango\db\functions\strings\trim    ;
use function oihana\arango\db\binds\aqlBind             ;

aqlFilter
(
    equal
    (
        lower( trim( 'doc.email' ) ) ,                                     // LOWER(TRIM(doc.email))
        aqlBind( strtolower( $userInput ) , $binds , 'email' )             // @email
    )
) ;
// "FILTER LOWER(TRIM(doc.email)) == @email"
```

## Voir aussi

- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Opérateurs `db/operators/`](aql-operators.md) — les comparateurs où l'on insère ces fonctions.
- [Bind variables `db/binds/`](../db/binds.md) — pour les valeurs de comparaison.
- [Documentation officielle AQL — String functions](https://docs.arangodb.com/stable/aql/functions/string/).
