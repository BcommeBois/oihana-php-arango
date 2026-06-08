# Fonctions géospatiales `db/functions/geo/`

Le sous-dossier [`src/oihana/arango/db/functions/geo/`](../../../src/oihana/arango/db/functions/geo/) regroupe **17 fonctions** qui correspondent aux *geo functions* natives d'AQL. Chaque fonction PHP retourne la chaîne `FONCTION_AQL(args)` prête à être insérée dans un prédicat (`FILTER`), un tri (`SORT`) ou une projection.

> ⚠️ **Ordre des coordonnées — le piège n°1 d'ArangoDB.** AQL mêle deux conventions :
> - les **constructeurs GeoJSON** (`GEO_POINT`, `GEO_POLYGON`, …) attendent `[longitude, latitude]` (**longitude d'abord**) ;
> - les fonctions **legacy** (`DISTANCE`, `IS_IN_POLYGON`, `NEAR`, `WITHIN`, …) attendent `latitude, longitude` (**latitude d'abord**).
>
> Pour éviter l'erreur, les helpers de ce dossier exposent leurs arguments dans un ordre **explicite et documenté** :
> - `geoPoint( $latitude , $longitude )` accepte latitude d'abord et **réordonne lui-même** en longitude-first.
> - `distance( $lat1 , $lng1 , $lat2 , $lng2 )` reste latitude-first (forme legacy, pas de réordonnancement).

## Sommaire

| Catégorie | Fonctions |
|---|---|
| Constructeurs de géométries | `geoPoint`, `geoMultiPoint`, `geoLineString`, `geoMultiLineString`, `geoPolygon`, `geoMultiPolygon` |
| Distances et aires | `distance`, `geoDistance`, `geoArea` |
| Prédicats topologiques | `geoContains`, `geoEquals`, `geoIntersects`, `geoInRange`, `isInPolygon` |
| Fonctions de collection (legacy) | `near`, `within`, `withinRectangle` |

## Constructeurs de géométries

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `geoPoint` | `(float\|int\|string $latitude, float\|int\|string $longitude)` | `GEO_POINT(<lng>, <lat>)` |
| `geoMultiPoint` | `(array\|string $points)` | `GEO_MULTIPOINT(<points>)` |
| `geoLineString` | `(array\|string $points)` | `GEO_LINESTRING(<points>)` |
| `geoMultiLineString` | `(array\|string $lines)` | `GEO_MULTILINESTRING(<lines>)` |
| `geoPolygon` | `(array\|string $points)` | `GEO_POLYGON(<rings>)` |
| `geoMultiPolygon` | `(array\|string $polygons)` | `GEO_MULTIPOLYGON(<polygons>)` |

`geoPoint` est le seul constructeur à réordonner les coordonnées : il prend `(latitude, longitude)` et émet `GEO_POINT(longitude, latitude)`. Les autres constructeurs reçoivent des tableaux de coordonnées qui doivent **déjà** suivre la convention GeoJSON `[longitude, latitude]`.

```php
use function oihana\arango\db\functions\geo\geoPoint ;
use function oihana\arango\db\functions\geo\geoPolygon ;

geoPoint( 48.8566 , 2.3522 ) ;                       // "GEO_POINT(2.3522,48.8566)"
geoPoint( 'doc.geo.latitude' , 'doc.geo.longitude' ) ;
// "GEO_POINT(doc.geo.longitude,doc.geo.latitude)"

geoPolygon( [ [ [ 0 , 0 ] , [ 1 , 0 ] , [ 1 , 1 ] , [ 0 , 0 ] ] ] ) ;
// "GEO_POLYGON([[[0,0],[1,0],[1,1],[0,0]]])"
```

## Distances et aires

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `distance` | `($lat1, $lng1, $lat2, $lng2)` | `DISTANCE(<lat1>, <lng1>, <lat2>, <lng2>)` |
| `geoDistance` | `(array\|string $geo1, array\|string $geo2, ?string $ellipsoid = null)` | `GEO_DISTANCE(<geo1>, <geo2>[, "<ellipsoid>"])` |
| `geoArea` | `(array\|string $geo, ?string $ellipsoid = null)` | `GEO_AREA(<geo>[, "<ellipsoid>"])` |

`distance` opère sur deux attributs scalaires (latitude/longitude) et retourne des **mètres**. C'est la forme **index-accélérée** lorsqu'un index géo est déclaré sur les deux attributs (voir [Index géo](#index-géo)). `geoDistance` opère sur deux géométries GeoJSON. L'ellipsoïde optionnel vaut `"sphere"` (défaut) ou `"wgs84"`.

```php
use function oihana\arango\db\functions\geo\distance ;
use function oihana\arango\db\functions\geo\geoDistance ;
use function oihana\arango\db\functions\geo\geoPoint ;

distance( 'doc.geo.latitude' , 'doc.geo.longitude' , 48.8566 , 2.3522 ) ;
// "DISTANCE(doc.geo.latitude,doc.geo.longitude,48.8566,2.3522)"

geoDistance( 'doc.geo' , geoPoint( 48.8566 , 2.3522 ) ) ;
// "GEO_DISTANCE(doc.geo,GEO_POINT(2.3522,48.8566))"

geoDistance( 'doc.geo' , '@target' , 'wgs84' ) ;
// "GEO_DISTANCE(doc.geo,@target,\"wgs84\")"
```

## Prédicats topologiques

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `geoContains` | `(array\|string $container, array\|string $contained)` | `GEO_CONTAINS(<a>, <b>)` |
| `geoEquals` | `(array\|string $geo1, array\|string $geo2)` | `GEO_EQUALS(<a>, <b>)` |
| `geoIntersects` | `(array\|string $geo1, array\|string $geo2)` | `GEO_INTERSECTS(<a>, <b>)` |
| `geoInRange` | `($geo1, $geo2, $low, $high, ?bool $includeLow = null, ?bool $includeHigh = null)` | `GEO_IN_RANGE(<a>, <b>, <low>, <high>[, <incLow>, <incHigh>])` |
| `isInPolygon` | `(array\|string $polygon, $latitude, $longitude)` | `IS_IN_POLYGON(<polygon>, <lat>, <lng>)` |

Ces fonctions retournent un booléen et s'insèrent directement dans un `FILTER`. `geoInRange` n'émet les drapeaux d'inclusion que s'ils sont fournis explicitement (défaut ArangoDB : `true` des deux côtés). `isInPolygon` est une fonction legacy : préférez `geoContains` avec de vraies géométries GeoJSON pour le code neuf.

```php
use function oihana\arango\db\functions\geo\geoContains ;
use function oihana\arango\db\functions\geo\geoInRange ;
use function oihana\arango\db\functions\geo\geoPoint ;

geoContains( 'doc.area' , geoPoint( 48.8566 , 2.3522 ) ) ;
// "GEO_CONTAINS(doc.area,GEO_POINT(2.3522,48.8566))"

geoInRange( 'doc.geo' , '@center' , 1000 , 5000 ) ;
// "GEO_IN_RANGE(doc.geo,@center,1000,5000)"
```

## Fonctions de collection (legacy)

| Fonction | Signature | Sortie AQL |
|---|---|---|
| `near` | `(string $collection, $latitude, $longitude, $limit = null, ?string $distanceName = null)` | `NEAR(<coll>, <lat>, <lng>[, <limit>[, "<dist>"]])` |
| `within` | `(string $collection, $latitude, $longitude, $radius, ?string $distanceName = null)` | `WITHIN(<coll>, <lat>, <lng>, <radius>[, "<dist>"])` |
| `withinRectangle` | `(string $collection, $lat1, $lng1, $lat2, $lng2)` | `WITHIN_RECTANGLE(<coll>, <lat1>, <lng1>, <lat2>, <lng2>)` |

Ces trois fonctions opèrent sur une **collection** (et non sur une géométrie) et requièrent un index géo. Elles sont conservées pour la complétude mais sont **dépréciées** : préférez un index géo combiné à `FILTER DISTANCE(...) <= @radius` ou `SORT DISTANCE(...) ASC LIMIT n`.

```php
use function oihana\arango\db\functions\geo\within ;

within( 'places' , 48.8566 , 2.3522 , 5000 , 'distance' ) ;
// "WITHIN(places,48.8566,2.3522,5000,\"distance\")"
```

## Index géo

Pour que les requêtes de distance soient performantes (et non un *full scan*), la collection cible doit porter un **index géo**. Avec un document Schema.org dont l'attribut `geo` contient `latitude` / `longitude`, on déclare un index à deux champs (`geoJson: false`) :

```php
use oihana\arango\clients\collection\indexes\GeoIndex ;

$places->createIndex( new GeoIndex( fields : [ 'geo.latitude' , 'geo.longitude' ] ) ) ;
```

L'optimiseur ArangoDB exploite alors cet index dans les deux schémas accélérés :

```aql
FOR doc IN places
    FILTER DISTANCE( doc.geo.latitude , doc.geo.longitude , @lat , @lng ) <= @radius
    SORT   DISTANCE( doc.geo.latitude , doc.geo.longitude , @lat , @lng ) ASC
    LIMIT  10
    RETURN doc
```

Voir aussi la classe [`GeoIndex`](../clients/indexes.md) et les [index sur modèle `Documents`](../models.md) (option `Arango::INDEXES`).

## Composition typique

Trouver les 10 lieux les plus proches d'un point, dans un rayon de 5 km :

```php
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\operators\lessThanOrEqual ;
use function oihana\arango\db\functions\geo\distance ;
use function oihana\arango\db\binds\aqlBind ;

$d = distance( 'doc.geo.latitude' , 'doc.geo.longitude' , aqlBind( $lat , $binds , 'lat' ) , aqlBind( $lng , $binds , 'lng' ) ) ;

aqlFilter( lessThanOrEqual( $d , aqlBind( 5000 , $binds , 'radius' ) ) ) ;
// "FILTER DISTANCE(doc.geo.latitude,doc.geo.longitude,@lat,@lng) <= @radius"
// puis : SORT <$d> ASC LIMIT 10
```

## Voir aussi

- [Construire une requête AQL pas à pas](aql-building-queries.md).
- [Opérateurs `db/operators/`](aql-operators.md) — les comparateurs où l'on insère ces fonctions.
- [Bind variables `db/binds/`](../db/binds.md) — pour les valeurs de comparaison.
- [Documentation officielle AQL — Geo functions](https://docs.arangodb.com/stable/aql/functions/geo/).
