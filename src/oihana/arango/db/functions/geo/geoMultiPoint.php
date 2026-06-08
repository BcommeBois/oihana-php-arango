<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Build a GeoJSON MultiPoint geometry.
 *
 * This helper wraps the ArangoDB AQL function `GEO_MULTIPOINT(points)` which
 * constructs a valid GeoJSON MultiPoint from an array of coordinate pairs.
 * Each coordinate pair must already follow the GeoJSON **longitude-first**
 * convention (`[longitude, latitude]`).
 *
 * Example AQL usage:
 * ```aql
 * GEO_MULTIPOINT([ [2.35, 48.85], [4.83, 45.76] ])
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoMultiPoint;
 *
 * $expr = geoMultiPoint( [ [ 2.35 , 48.85 ] , [ 4.83 , 45.76 ] ] );
 * // Produces: 'GEO_MULTIPOINT([[2.35,48.85],[4.83,45.76]])'
 *
 * $expr = geoMultiPoint( 'doc.points' );
 * // Produces: 'GEO_MULTIPOINT(doc.points)'
 * ```
 *
 * @param array|string $points An array of `[longitude, latitude]` pairs, or an AQL expression.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_multipoint
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoMultiPoint( array|string $points ) : string
{
    return func( GeoFunction::GEO_MULTIPOINT , aqlArray( $points ) ) ;
}
