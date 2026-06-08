<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Check whether one GeoJSON geometry fully contains another.
 *
 * This helper wraps the ArangoDB AQL function `GEO_CONTAINS(geoJsonA, geoJsonB)`
 * which returns `true` when geometry B is completely inside geometry A (a point
 * inside a polygon, a polygon inside a larger polygon, …). Both arguments use
 * the GeoJSON **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_CONTAINS(doc.area, GEO_POINT(2.3522, 48.8566))
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoContains;
 * use function oihana\arango\db\functions\geo\geoPoint;
 *
 * $expr = geoContains( 'doc.area' , geoPoint( 48.8566 , 2.3522 ) );
 * // Produces: 'GEO_CONTAINS(doc.area,GEO_POINT(2.3522,48.8566))'
 * ```
 *
 * @param array|string $container The containing GeoJSON geometry (AQL expression or coordinates).
 * @param array|string $contained The contained GeoJSON geometry (AQL expression or coordinates).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_contains
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoContains( array|string $container , array|string $contained ) : string
{
    return func( GeoFunction::GEO_CONTAINS , [ aqlArray( $container ) , aqlArray( $contained ) ] ) ;
}
