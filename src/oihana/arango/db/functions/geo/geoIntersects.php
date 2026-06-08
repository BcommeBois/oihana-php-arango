<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Check whether two GeoJSON geometries intersect.
 *
 * This helper wraps the ArangoDB AQL function `GEO_INTERSECTS(geoJsonA, geoJsonB)`
 * which returns `true` when the two geometries share at least one point. Both
 * arguments use the GeoJSON **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_INTERSECTS(doc.area, @zone)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoIntersects;
 *
 * $expr = geoIntersects( 'doc.area' , '@zone' );
 * // Produces: 'GEO_INTERSECTS(doc.area,@zone)'
 * ```
 *
 * @param array|string $geo1 First GeoJSON geometry (AQL expression or coordinates).
 * @param array|string $geo2 Second GeoJSON geometry (AQL expression or coordinates).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_intersects
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoIntersects( array|string $geo1 , array|string $geo2 ) : string
{
    return func( GeoFunction::GEO_INTERSECTS , [ aqlArray( $geo1 ) , aqlArray( $geo2 ) ] ) ;
}
