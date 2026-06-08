<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\core\strings\func;

/**
 * Build a GeoJSON Point geometry.
 *
 * This helper wraps the ArangoDB AQL function `GEO_POINT(longitude, latitude)`
 * which constructs a valid GeoJSON Point. Coordinates are accepted here in the
 * human-friendly `(latitude, longitude)` order and reordered internally to the
 * GeoJSON **longitude-first** convention expected by ArangoDB.
 *
 * Example AQL usage:
 * ```aql
 * GEO_POINT(2.3522, 48.8566)            // Paris, as [lng, lat]
 * GEO_POINT(doc.geo.longitude, doc.geo.latitude)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoPoint;
 *
 * $expr = geoPoint( 48.8566 , 2.3522 );
 * // Produces: 'GEO_POINT(2.3522,48.8566)'
 *
 * $expr = geoPoint( 'doc.geo.latitude' , 'doc.geo.longitude' );
 * // Produces: 'GEO_POINT(doc.geo.longitude,doc.geo.latitude)'
 * ```
 *
 * @param float|int|string $latitude  The latitude, or an AQL expression resolving to it.
 * @param float|int|string $longitude The longitude, or an AQL expression resolving to it.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_point
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoPoint( float|int|string $latitude , float|int|string $longitude ) : string
{
    return func( GeoFunction::GEO_POINT , [ $longitude , $latitude ] ) ;
}
