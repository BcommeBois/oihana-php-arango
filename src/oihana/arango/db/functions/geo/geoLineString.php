<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Build a GeoJSON LineString geometry.
 *
 * This helper wraps the ArangoDB AQL function `GEO_LINESTRING(points)` which
 * constructs a valid GeoJSON LineString from an ordered array of `[longitude,
 * latitude]` pairs, following the GeoJSON **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_LINESTRING([ [2.35, 48.85], [4.83, 45.76] ])
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoLineString;
 *
 * $expr = geoLineString( [ [ 2.35 , 48.85 ] , [ 4.83 , 45.76 ] ] );
 * // Produces: 'GEO_LINESTRING([[2.35,48.85],[4.83,45.76]])'
 * ```
 *
 * @param array|string $points An array of `[longitude, latitude]` pairs, or an AQL expression.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_linestring
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoLineString( array|string $points ) : string
{
    return func( GeoFunction::GEO_LINESTRING , aqlArray( $points ) ) ;
}
