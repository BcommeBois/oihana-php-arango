# Fonctions numériques `db/functions/numerics/`

Le sous-dossier [`src/oihana/arango/db/functions/numerics/`](../../../src/oihana/arango/db/functions/numerics/) regroupe **35 fonctions** qui correspondent aux *numeric functions* natives d'AQL — calculs scalaires, trigonométrie, logarithmes, agrégations sur tableau, génération de plages, et distances vectorielles.

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Arithmétique de base | `abs`, `ceil`, `floor`, `round`, `sqrt` |
| Puissances et exponentielles | `pow`, `exp`, `exp2` |
| Logarithmes | `log`, `log10`, `log2` |
| Trigonométrie | `sin`, `cos`, `tan`, `asin`, `acos`, `atan`, `atan2` |
| Conversion d'angles | `degrees`, `radians` |
| Constantes et aléa | `pi`, `rand` |
| Agrégation sur tableau | `average`, `max`, `min`, `median`, `percentile`, `product`, `sum` |
| Séquence | `range` |
| Vecteurs | `cosSimilarity`, `l1Distance`, `l2Distance`, `approxNearCosine`, `approxNearL2` |

## Arithmétique de base

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `abs` | `(string\|int\|float $value)` | `ABS(<value>)` |
| `ceil` | `(string\|int\|float $value)` | `CEIL(<value>)` |
| `floor` | `(string\|int\|float $value)` | `FLOOR(<value>)` |
| `round` | `(string\|int\|float $value)` | `ROUND(<value>)` |
| `sqrt` | `(string\|int\|float $value)` | `SQRT(<value>)` |

```php
use function oihana\arango\db\functions\numerics\abs   ;
use function oihana\arango\db\functions\numerics\round ;

abs  ( 'doc.balance' ) ;     // "ABS(doc.balance)"
round( 'doc.price'   ) ;     // "ROUND(doc.price)"
```

## Puissances et exponentielles

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `pow` | `(mixed $base, int $exp)` | `POW(<base>, <exp>)` |
| `exp` | `(string\|int\|float $value)` | `EXP(<value>)` (e^x) |
| `exp2` | `(string\|int\|float $value)` | `EXP2(<value>)` (2^x) |

## Logarithmes

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `log` | `(string\|int\|float $value)` | `LOG(<value>)` (logarithme naturel) |
| `log10` | `(string\|int\|float $value)` | `LOG10(<value>)` |
| `log2` | `(string\|int\|float $value)` | `LOG2(<value>)` |

## Trigonométrie

Toutes en radians. Pour travailler en degrés, encadrer avec `radians()` en entrée et `degrees()` en sortie.

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `sin` | `(string\|int\|float $value)` | `SIN(<value>)` |
| `cos` | `(string\|int\|float $value)` | `COS(<value>)` |
| `tan` | `(string\|int\|float $value)` | `TAN(<value>)` |
| `asin` | `(string\|int\|float $value)` | `ASIN(<value>)` |
| `acos` | `(string\|int\|float $value)` | `ACOS(<value>)` |
| `atan` | `(string\|int\|float $value)` | `ATAN(<value>)` |
| `atan2` | `(string\|int $y, string\|int $x)` | `ATAN2(<y>, <x>)` |

## Conversion d'angles

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `degrees` | `(string\|int\|float $rad)` | `DEGREES(<rad>)` |
| `radians` | `(string\|int\|float $deg)` | `RADIANS(<deg>)` |

## Constantes et aléa

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `pi` | `()` | `PI()` |
| `rand` | `()` | `RAND()` (réel pseudo-aléatoire entre 0 et 1) |

## Agrégation sur tableau

Toutes ces fonctions prennent un argument `mixed $anyArray` (référence à un champ de type tableau ou expression qui en produit un) et calculent une statistique.

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `average` | `(mixed $anyArray)` | `AVERAGE(<array>)` |
| `max` | `(mixed $anyArray)` | `MAX(<array>)` |
| `min` | `(mixed $anyArray)` | `MIN(<array>)` |
| `median` | `(mixed $anyArray)` | `MEDIAN(<array>)` |
| `percentile` | `(mixed $numArray, int $position, ?string $method)` | `PERCENTILE(<array>, <position>[, <method>])` |
| `product` | `(mixed $numArray)` | `PRODUCT(<array>)` |
| `sum` | `(mixed $numArray)` | `SUM(<array>)` |

```php
use function oihana\arango\db\functions\numerics\average ;
use function oihana\arango\db\functions\numerics\sum     ;

average( 'doc.scores' ) ;        // "AVERAGE(doc.scores)"
sum    ( 'doc.amounts' ) ;       // "SUM(doc.amounts)"
```

## Séquence

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `range` | `(int $start, int $stop, float $step = 1.0)` | `RANGE(<start>, <stop>[, <step>])` |

Produit un tableau de nombres dans l'intervalle. Utile dans un `FOR i IN RANGE(1, 10)` pour itérer sur des entiers.

## Vecteurs

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `cosSimilarity` | `(string\|int $x, string\|int $y)` | `COSINE_SIMILARITY(<x>, <y>)` |
| `l1Distance` | `(string\|int $x, string\|int $y)` | `L1_DISTANCE(<x>, <y>)` |
| `l2Distance` | `(string\|int $x, string\|int $y)` | `L2_DISTANCE(<x>, <y>)` |
| `approxNearCosine` | `(string\|int $x, string\|int $y, ?int $nProbe = null)` | `APPROX_NEAR_COSINE(<x>, <y>[, {"nProbe":N}])` |
| `approxNearL2` | `(string\|int $x, string\|int $y, ?int $nProbe = null)` | `APPROX_NEAR_L2(<x>, <y>[, {"nProbe":N}])` |

`cosSimilarity`, `l1Distance` et `l2Distance` calculent une mesure **exacte** entre deux vecteurs (chacun étant un tableau de nombres) et ne nécessitent aucun index.

`approxNearCosine` et `approxNearL2` calculent un score de plus-proche-voisin **approché**, *accéléré par un index `vector`* — un opérande doit référencer l'attribut indexé, l'autre est le vecteur de requête. Ce sont les briques de la recherche par similarité sur *embeddings* (RAG, recommandation). Le `nProbe` optionnel élargit la recherche (plus de centroïdes explorés → plus précis, plus lent).

> La métrique doit correspondre à celle du `VectorIndex` : `approxNearCosine` ⇄ index `cosine` trié **`DESC`** (proche de 1 = plus proche), `approxNearL2` ⇄ index `l2` trié **`ASC`** (proche de 0 = plus proche). Les index vectoriels sont une fonctionnalité **expérimentale** d'ArangoDB (serveur démarré avec `--experimental-vector-index`).

Pour la requête de plus-proches-voisins complète, préférez l'opération dédiée [`aqlVectorSearch()`](aql-operations.md#aqlvectorsearch--recherche-approximative-de-plus-proches-voisins), qui câble pour vous la fonction, le sens du tri et le `LIMIT` :

```php
use function oihana\arango\db\operations\aqlVectorSearch ;

aqlVectorSearch( collection: 'items', attribute: 'embedding', vector: '@query', limit: 10 ) ;
// "FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query) DESC LIMIT 10 RETURN doc"
```

## Composition typique

Calculer la moyenne, le min et le max d'un tableau de scores, le tout dans un même `RETURN` :

```php
use function oihana\arango\db\operations\aqlReturn      ;
use function oihana\arango\db\helpers\aqlDocument       ;
use function oihana\arango\db\functions\numerics\average ;
use function oihana\arango\db\functions\numerics\min     ;
use function oihana\arango\db\functions\numerics\max     ;

aqlReturn
(
    aqlDocument
    ([
        'avg' => average( 'doc.scores' ) ,
        'min' => min    ( 'doc.scores' ) ,
        'max' => max    ( 'doc.scores' ) ,
    ])
) ;
// "RETURN {avg:AVERAGE(doc.scores),min:MIN(doc.scores),max:MAX(doc.scores)}"
```

## Voir aussi

- [Fonctions de tableaux `db/functions/arrays/`](aql-functions-arrays.md) — autres opérations sur tableau (`count`, `length`, `first`, `last`, ...).
- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Documentation officielle AQL — Numeric functions](https://docs.arangodb.com/stable/aql/functions/numeric/).
