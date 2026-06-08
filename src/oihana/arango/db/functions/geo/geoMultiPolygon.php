<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Build a GeoJSON MultiPolygon geometry.
 *
 * This helper wraps the ArangoDB AQL function `GEO_MULTIPOLYGON(polygons)` which
 * constructs a valid GeoJSON MultiPolygon from an array of polygons. Each polygon
 * is itself an array of linear rings of `[longitude, latitude]` pairs, following
 * the GeoJSON **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_MULTIPOLYGON([ [ [ [0,0],[1,0],[1,1],[0,0] ] ] ])
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoMultiPolygon;
 *
 * $expr = geoMultiPolygon( 'doc.areas' );
 * // Produces: 'GEO_MULTIPOLYGON(doc.areas)'
 * ```
 *
 * @param array|string $polygons An array of polygons, or an AQL expression.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_multipolygon
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoMultiPolygon( array|string $polygons ) : string
{
    return func( GeoFunction::GEO_MULTIPOLYGON , aqlArray( $polygons ) ) ;
}
