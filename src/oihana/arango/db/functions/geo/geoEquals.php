<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Check whether two GeoJSON geometries are equal.
 *
 * This helper wraps the ArangoDB AQL function `GEO_EQUALS(geoJsonA, geoJsonB)`
 * which returns `true` when both geometries describe the same shape. Both
 * arguments use the GeoJSON **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_EQUALS(doc.geo, GEO_POINT(2.3522, 48.8566))
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoEquals;
 *
 * $expr = geoEquals( 'doc.geo' , '@target' );
 * // Produces: 'GEO_EQUALS(doc.geo,@target)'
 * ```
 *
 * @param array|string $geo1 First GeoJSON geometry (AQL expression or coordinates).
 * @param array|string $geo2 Second GeoJSON geometry (AQL expression or coordinates).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_equals
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoEquals( array|string $geo1 , array|string $geo2 ) : string
{
    return func( GeoFunction::GEO_EQUALS , [ aqlArray( $geo1 ) , aqlArray( $geo2 ) ] ) ;
}
