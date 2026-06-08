<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Build a GeoJSON MultiLineString geometry.
 *
 * This helper wraps the ArangoDB AQL function `GEO_MULTILINESTRING(lines)` which
 * constructs a valid GeoJSON MultiLineString from an array of line strings. Each
 * line is an array of `[longitude, latitude]` pairs, following the GeoJSON
 * **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_MULTILINESTRING([ [ [2.35,48.85], [4.83,45.76] ], [ [1.44,43.60], [5.37,43.29] ] ])
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoMultiLineString;
 *
 * $expr = geoMultiLineString( 'doc.routes' );
 * // Produces: 'GEO_MULTILINESTRING(doc.routes)'
 * ```
 *
 * @param array|string $lines An array of line strings, or an AQL expression.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_multilinestring
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoMultiLineString( array|string $lines ) : string
{
    return func( GeoFunction::GEO_MULTILINESTRING , aqlArray( $lines ) ) ;
}
