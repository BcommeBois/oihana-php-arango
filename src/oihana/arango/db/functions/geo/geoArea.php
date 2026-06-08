<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Return the area of a GeoJSON Polygon or MultiPolygon, in square meters.
 *
 * This helper wraps the ArangoDB AQL function `GEO_AREA(geoJson, ellipsoid)`
 * which computes the area of a polygonal GeoJSON geometry. The optional
 * ellipsoid (`"sphere"` or `"wgs84"`) selects the reference model.
 *
 * Example AQL usage:
 * ```aql
 * GEO_AREA(doc.area)
 * GEO_AREA(doc.area, "wgs84")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoArea;
 *
 * $expr = geoArea( 'doc.area' , 'wgs84' );
 * // Produces: 'GEO_AREA(doc.area,"wgs84")'
 * ```
 *
 * @param array|string $geo       The polygonal GeoJSON geometry (AQL expression or coordinates).
 * @param string|null  $ellipsoid Optional reference ellipsoid: `"sphere"` (default) or `"wgs84"`.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_area
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoArea( array|string $geo , ?string $ellipsoid = null ) : string
{
    $arguments = [ aqlArray( $geo ) ] ;

    if ( $ellipsoid !== null )
    {
        $arguments[] = betweenDoubleQuotes( $ellipsoid ) ;
    }

    return func( GeoFunction::GEO_AREA , $arguments ) ;
}
