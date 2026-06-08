<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Return the distance between two GeoJSON geometries, in meters.
 *
 * This helper wraps the ArangoDB AQL function `GEO_DISTANCE(geoJson1, geoJson2,
 * ellipsoid)` which computes the shortest distance between two GeoJSON objects.
 * Both arguments are GeoJSON geometries (built with {@see geoPoint()},
 * {@see geoPolygon()}, … or a document attribute), using the **longitude-first**
 * coordinate convention. The optional ellipsoid (`"sphere"` or `"wgs84"`)
 * selects the reference model.
 *
 * Example AQL usage:
 * ```aql
 * GEO_DISTANCE(doc.geo, GEO_POINT(2.3522, 48.8566))
 * GEO_DISTANCE(doc.geo, @target, "wgs84")
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoDistance;
 * use function oihana\arango\db\functions\geo\geoPoint;
 *
 * $expr = geoDistance( 'doc.geo' , geoPoint( 48.8566 , 2.3522 ) );
 * // Produces: 'GEO_DISTANCE(doc.geo,GEO_POINT(2.3522,48.8566))'
 *
 * $expr = geoDistance( 'doc.geo' , '@target' , 'wgs84' );
 * // Produces: 'GEO_DISTANCE(doc.geo,@target,"wgs84")'
 * ```
 *
 * @param array|string $geo1      First GeoJSON geometry (AQL expression or `[longitude, latitude]`).
 * @param array|string $geo2      Second GeoJSON geometry (AQL expression or `[longitude, latitude]`).
 * @param string|null  $ellipsoid Optional reference ellipsoid: `"sphere"` (default) or `"wgs84"`.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_distance
 * @see distance() For the latitude-first scalar equivalent.
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoDistance( array|string $geo1 , array|string $geo2 , ?string $ellipsoid = null ) : string
{
    $arguments = [ aqlArray( $geo1 ) , aqlArray( $geo2 ) ] ;

    if ( $ellipsoid !== null )
    {
        $arguments[] = betweenDoubleQuotes( $ellipsoid ) ;
    }

    return func( GeoFunction::GEO_DISTANCE , $arguments ) ;
}
