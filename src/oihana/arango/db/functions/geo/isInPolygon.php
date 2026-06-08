<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Check whether a coordinate lies inside a polygon (legacy).
 *
 * This helper wraps the ArangoDB AQL function `IS_IN_POLYGON(polygon, latitude,
 * longitude)` which returns `true` when the point is inside the polygon. The
 * polygon is a plain array of `[latitude, longitude]` pairs, and — unlike the
 * GeoJSON functions — the point is given in the **latitude-first** order.
 *
 * This is a legacy function kept for completeness; prefer {@see geoContains()}
 * with proper GeoJSON geometries and a geo index for new code.
 *
 * Example AQL usage:
 * ```aql
 * IS_IN_POLYGON([ [48.8, 2.2], [48.9, 2.2], [48.9, 2.4] ], doc.geo.latitude, doc.geo.longitude)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\isInPolygon;
 *
 * $expr = isInPolygon( '@area' , 'doc.geo.latitude' , 'doc.geo.longitude' );
 * // Produces: 'IS_IN_POLYGON(@area,doc.geo.latitude,doc.geo.longitude)'
 * ```
 *
 * @param array|string     $polygon   The polygon as an array of `[latitude, longitude]` pairs, or an AQL expression.
 * @param float|int|string $latitude  Latitude of the point to test (or AQL expression).
 * @param float|int|string $longitude Longitude of the point to test (or AQL expression).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#is_in_polygon
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function isInPolygon( array|string $polygon , float|int|string $latitude , float|int|string $longitude ) : string
{
    return func( GeoFunction::IS_IN_POLYGON , [ aqlArray( $polygon ) , $latitude , $longitude ] ) ;
}
