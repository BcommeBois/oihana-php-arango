# Geospatial functions `db/functions/geo/`

The [`src/oihana/arango/db/functions/geo/`](../../../src/oihana/arango/db/functions/geo/) sub-folder groups **17 functions** matching AQL's native *geo functions*. Each PHP function returns the `AQL_FUNCTION(args)` string, ready to drop into a predicate (`FILTER`), a sort (`SORT`) or a projection.

> ⚠️ **Coordinate order — ArangoDB's number-one trap.** AQL mixes two conventions:
> - **GeoJSON constructors** (`GEO_POINT`, `GEO_POLYGON`, …) expect `[longitude, latitude]` (**longitude first**);
> - **legacy** functions (`DISTANCE`, `IS_IN_POLYGON`, `NEAR`, `WITHIN`, …) expect `latitude, longitude` (**latitude first**).
>
> To avoid mistakes, the helpers in this folder expose their arguments in an **explicit, documented** order:
> - `geoPoint( $latitude , $longitude )` takes latitude first and **reorders internally** to longitude-first.
> - `distance( $lat1 , $lng1 , $lat2 , $lng2 )` stays latitude-first (legacy form, no reordering).

## Summary

| Category | Functions |
|---|---|
| Geometry constructors | `geoPoint`, `geoMultiPoint`, `geoLineString`, `geoMultiLineString`, `geoPolygon`, `geoMultiPolygon` |
| Distances and areas | `distance`, `geoDistance`, `geoArea` |
| Topological predicates | `geoContains`, `geoEquals`, `geoIntersects`, `geoInRange`, `isInPolygon` |
| Collection functions (legacy) | `near`, `within`, `withinRectangle` |

## Geometry constructors

| Function | Signature | AQL output |
|---|---|---|
| `geoPoint` | `(float\|int\|string $latitude, float\|int\|string $longitude)` | `GEO_POINT(<lng>, <lat>)` |
| `geoMultiPoint` | `(array\|string $points)` | `GEO_MULTIPOINT(<points>)` |
| `geoLineString` | `(array\|string $points)` | `GEO_LINESTRING(<points>)` |
| `geoMultiLineString` | `(array\|string $lines)` | `GEO_MULTILINESTRING(<lines>)` |
| `geoPolygon` | `(array\|string $points)` | `GEO_POLYGON(<rings>)` |
| `geoMultiPolygon` | `(array\|string $polygons)` | `GEO_MULTIPOLYGON(<polygons>)` |

`geoPoint` is the only constructor that reorders coordinates: it takes `(latitude, longitude)` and emits `GEO_POINT(longitude, latitude)`. The other constructors receive coordinate arrays that must **already** follow the GeoJSON `[longitude, latitude]` convention.

```php
use function oihana\arango\db\functions\geo\geoPoint ;
use function oihana\arango\db\functions\geo\geoPolygon ;

geoPoint( 48.8566 , 2.3522 ) ;                       // "GEO_POINT(2.3522,48.8566)"
geoPoint( 'doc.geo.latitude' , 'doc.geo.longitude' ) ;
// "GEO_POINT(doc.geo.longitude,doc.geo.latitude)"

geoPolygon( [ [ [ 0 , 0 ] , [ 1 , 0 ] , [ 1 , 1 ] , [ 0 , 0 ] ] ] ) ;
// "GEO_POLYGON([[[0,0],[1,0],[1,1],[0,0]]])"
```

## Distances and areas

| Function | Signature | AQL output |
|---|---|---|
| `distance` | `($lat1, $lng1, $lat2, $lng2)` | `DISTANCE(<lat1>, <lng1>, <lat2>, <lng2>)` |
| `geoDistance` | `(array\|string $geo1, array\|string $geo2, ?string $ellipsoid = null)` | `GEO_DISTANCE(<geo1>, <geo2>[, "<ellipsoid>"])` |
| `geoArea` | `(array\|string $geo, ?string $ellipsoid = null)` | `GEO_AREA(<geo>[, "<ellipsoid>"])` |

`distance` operates on two scalar attributes (latitude/longitude) and returns **meters**. It is the **index-accelerated** form when a geo index is declared over the two attributes (see [Geo index](#geo-index)). `geoDistance` operates on two GeoJSON geometries. The optional ellipsoid is `"sphere"` (default) or `"wgs84"`.

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

## Topological predicates

| Function | Signature | AQL output |
|---|---|---|
| `geoContains` | `(array\|string $container, array\|string $contained)` | `GEO_CONTAINS(<a>, <b>)` |
| `geoEquals` | `(array\|string $geo1, array\|string $geo2)` | `GEO_EQUALS(<a>, <b>)` |
| `geoIntersects` | `(array\|string $geo1, array\|string $geo2)` | `GEO_INTERSECTS(<a>, <b>)` |
| `geoInRange` | `($geo1, $geo2, $low, $high, ?bool $includeLow = null, ?bool $includeHigh = null)` | `GEO_IN_RANGE(<a>, <b>, <low>, <high>[, <incLow>, <incHigh>])` |
| `isInPolygon` | `(array\|string $polygon, $latitude, $longitude)` | `IS_IN_POLYGON(<polygon>, <lat>, <lng>)` |

These functions return a boolean and drop straight into a `FILTER`. `geoInRange` only emits the inclusion flags when explicitly provided (ArangoDB default: `true` on both sides). `isInPolygon` is a legacy function: prefer `geoContains` with real GeoJSON geometries for new code.

```php
use function oihana\arango\db\functions\geo\geoContains ;
use function oihana\arango\db\functions\geo\geoInRange ;
use function oihana\arango\db\functions\geo\geoPoint ;

geoContains( 'doc.area' , geoPoint( 48.8566 , 2.3522 ) ) ;
// "GEO_CONTAINS(doc.area,GEO_POINT(2.3522,48.8566))"

geoInRange( 'doc.geo' , '@center' , 1000 , 5000 ) ;
// "GEO_IN_RANGE(doc.geo,@center,1000,5000)"
```

## Collection functions (legacy)

| Function | Signature | AQL output |
|---|---|---|
| `near` | `(string $collection, $latitude, $longitude, $limit = null, ?string $distanceName = null)` | `NEAR(<coll>, <lat>, <lng>[, <limit>[, "<dist>"]])` |
| `within` | `(string $collection, $latitude, $longitude, $radius, ?string $distanceName = null)` | `WITHIN(<coll>, <lat>, <lng>, <radius>[, "<dist>"])` |
| `withinRectangle` | `(string $collection, $lat1, $lng1, $lat2, $lng2)` | `WITHIN_RECTANGLE(<coll>, <lat1>, <lng1>, <lat2>, <lng2>)` |

These three functions operate on a **collection** (not a geometry) and require a geo index. They are kept for completeness but are **deprecated**: prefer a geo index combined with `FILTER DISTANCE(...) <= @radius` or `SORT DISTANCE(...) ASC LIMIT n`.

```php
use function oihana\arango\db\functions\geo\within ;

within( 'places' , 48.8566 , 2.3522 , 5000 , 'distance' ) ;
// "WITHIN(places,48.8566,2.3522,5000,\"distance\")"
```

## Geo index

For distance queries to be fast (and not a *full scan*), the target collection must carry a **geo index**. With a Schema.org document whose `geo` attribute holds `latitude` / `longitude`, declare a two-field index (`geoJson: false`):

```php
use oihana\arango\clients\collection\indexes\GeoIndex ;

$places->createIndex( new GeoIndex( fields : [ 'geo.latitude' , 'geo.longitude' ] ) ) ;
```

The ArangoDB optimizer then uses that index in both accelerated shapes:

```aql
FOR doc IN places
    FILTER DISTANCE( doc.geo.latitude , doc.geo.longitude , @lat , @lng ) <= @radius
    SORT   DISTANCE( doc.geo.latitude , doc.geo.longitude , @lat , @lng ) ASC
    LIMIT  10
    RETURN doc
```

See also the [`GeoIndex`](../clients/indexes.md) class and [model indexes on `Documents`](../models.md) (the `Arango::INDEXES` option).

## Typical composition

Find the 10 nearest places to a point, within a 5 km radius:

```php
use function oihana\arango\db\operations\aqlFilter ;
use function oihana\arango\db\operators\lessThanOrEqual ;
use function oihana\arango\db\functions\geo\distance ;
use function oihana\arango\db\binds\aqlBind ;

$d = distance( 'doc.geo.latitude' , 'doc.geo.longitude' , aqlBind( $lat , $binds , 'lat' ) , aqlBind( $lng , $binds , 'lng' ) ) ;

aqlFilter( lessThanOrEqual( $d , aqlBind( 5000 , $binds , 'radius' ) ) ) ;
// "FILTER DISTANCE(doc.geo.latitude,doc.geo.longitude,@lat,@lng) <= @radius"
// then: SORT <$d> ASC LIMIT 10
```

## See also

- [Building an AQL query step by step](aql-building-queries.md).
- [Operators `db/operators/`](aql-operators.md) — the comparators these functions slot into.
- [Bind variables `db/binds/`](../db/binds.md) — for comparison values.
- [Official AQL documentation — Geo functions](https://docs.arangodb.com/stable/aql/functions/geo/).
