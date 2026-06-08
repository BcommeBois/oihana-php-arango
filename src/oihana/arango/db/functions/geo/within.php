<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Return documents of a collection within a radius of a coordinate (legacy).
 *
 * This helper wraps the ArangoDB AQL function `WITHIN(collection, latitude,
 * longitude, radius, distanceName)` which returns all documents located within
 * `radius` meters of the given point, ordered by ascending distance. The
 * collection must have a geo index. The coordinate is given in the
 * **latitude-first** order.
 *
 * This is a legacy function; prefer a geo index combined with
 * `FILTER DISTANCE(doc.lat, doc.lng, @lat, @lng) <= @radius` for new code.
 *
 * Example AQL usage:
 * ```aql
 * FOR place IN WITHIN(places, 48.8566, 2.3522, 5000, "distance") RETURN place
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\within;
 *
 * $expr = within( 'places' , 48.8566 , 2.3522 , 5000 , 'distance' );
 * // Produces: 'WITHIN(places,48.8566,2.3522,5000,"distance")'
 * ```
 *
 * @param string           $collection   The target collection name (AQL identifier).
 * @param float|int|string $latitude     Latitude of the reference point (or AQL expression).
 * @param float|int|string $longitude    Longitude of the reference point (or AQL expression).
 * @param float|int|string $radius       The search radius, in meters (or AQL expression).
 * @param string|null      $distanceName Optional attribute name to store the computed distance.
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#within
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function within
(
    string           $collection ,
    float|int|string $latitude ,
    float|int|string $longitude ,
    float|int|string $radius ,
    ?string          $distanceName = null
)
: string
{
    $arguments = [ $collection , $latitude , $longitude , $radius ] ;

    if ( $distanceName !== null )
    {
        $arguments[] = betweenDoubleQuotes( $distanceName ) ;
    }

    return func( GeoFunction::WITHIN , $arguments ) ;
}
