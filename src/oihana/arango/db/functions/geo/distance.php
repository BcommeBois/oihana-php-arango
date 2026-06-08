<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\core\strings\func;

/**
 * Return the distance between two coordinate pairs, in meters.
 *
 * This helper wraps the ArangoDB AQL function `DISTANCE(latitude1, longitude1,
 * latitude2, longitude2)` which computes the great-circle (haversine) distance
 * between two points given as separate scalar attributes. Unlike the GeoJSON
 * functions, `DISTANCE` takes its coordinates in the **latitude-first** order.
 *
 * This is the index-accelerated form when a geo index is declared over the two
 * latitude / longitude attributes (`geoJson: false`), e.g. used in a
 * `FILTER DISTANCE(...) <= @radius` or `SORT DISTANCE(...) ASC LIMIT n`.
 *
 * Example AQL usage:
 * ```aql
 * DISTANCE(doc.geo.latitude, doc.geo.longitude, 48.8566, 2.3522)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\distance;
 *
 * $expr = distance( 'doc.geo.latitude' , 'doc.geo.longitude' , 48.8566 , 2.3522 );
 * // Produces: 'DISTANCE(doc.geo.latitude,doc.geo.longitude,48.8566,2.3522)'
 * ```
 *
 * @param float|int|string $latitude1  Latitude of the first point (or AQL expression).
 * @param float|int|string $longitude1 Longitude of the first point (or AQL expression).
 * @param float|int|string $latitude2  Latitude of the second point (or AQL expression).
 * @param float|int|string $longitude2 Longitude of the second point (or AQL expression).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#distance
 * @see geoDistance() For the GeoJSON, longitude-first equivalent.
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function distance
(
    float|int|string $latitude1 ,
    float|int|string $longitude1 ,
    float|int|string $latitude2 ,
    float|int|string $longitude2
)
: string
{
    return func( GeoFunction::DISTANCE , [ $latitude1 , $longitude1 , $latitude2 , $longitude2 ] ) ;
}
