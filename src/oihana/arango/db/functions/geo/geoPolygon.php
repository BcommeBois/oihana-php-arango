<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Build a GeoJSON Polygon geometry.
 *
 * This helper wraps the ArangoDB AQL function `GEO_POLYGON(points)` which
 * constructs a valid GeoJSON Polygon. The outer ring (and any holes) is an
 * array of linear rings, each a closed list of `[longitude, latitude]` pairs
 * (first and last coordinate must be equal). Coordinates must already follow
 * the GeoJSON **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_POLYGON([ [ [0,0], [1,0], [1,1], [0,1], [0,0] ] ])
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoPolygon;
 *
 * $expr = geoPolygon( [ [ [ 0 , 0 ] , [ 1 , 0 ] , [ 1 , 1 ] , [ 0 , 0 ] ] ] );
 * // Produces: 'GEO_POLYGON([[[0,0],[1,0],[1,1],[0,0]]])'
 *
 * $expr = geoPolygon( 'doc.area' );
 * // Produces: 'GEO_POLYGON(doc.area)'
 * ```
 *
 * @param array|string $points An array of linear rings, or an AQL expression.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_polygon
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoPolygon( array|string $points ) : string
{
    return func( GeoFunction::GEO_POLYGON , aqlArray( $points ) ) ;
}
