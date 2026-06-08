<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\core\strings\betweenDoubleQuotes;
use function oihana\core\strings\func;

/**
 * Return the nearest documents of a collection to a coordinate (legacy).
 *
 * This helper wraps the ArangoDB AQL function `NEAR(collection, latitude,
 * longitude, limit, distanceName)` which returns up to `limit` documents
 * ordered by ascending distance from the given point. The collection must have
 * a geo index. The coordinate is given in the **latitude-first** order.
 *
 * This is a legacy function; prefer a geo index combined with
 * `SORT DISTANCE(doc.lat, doc.lng, @lat, @lng) ASC LIMIT n` for new code.
 *
 * Example AQL usage:
 * ```aql
 * FOR place IN NEAR(places, 48.8566, 2.3522, 10, "distance") RETURN place
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\near;
 *
 * $expr = near( 'places' , 48.8566 , 2.3522 , 10 , 'distance' );
 * // Produces: 'NEAR(places,48.8566,2.3522,10,"distance")'
 * ```
 *
 * @param string           $collection   The target collection name (AQL identifier).
 * @param float|int|string $latitude     Latitude of the reference point (or AQL expression).
 * @param float|int|string $longitude    Longitude of the reference point (or AQL expression).
 * @param int|string|null  $limit        Optional maximum number of documents to return.
 * @param string|null      $distanceName Optional attribute name to store the computed distance (requires `$limit`).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#near
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function near
(
    string           $collection ,
    float|int|string $latitude ,
    float|int|string $longitude ,
    int|string|null  $limit        = null ,
    ?string          $distanceName = null
)
: string
{
    $arguments = [ $collection , $latitude , $longitude ] ;

    if ( $limit !== null )
    {
        $arguments[] = $limit ;
    }

    if ( $distanceName !== null )
    {
        $arguments[] = betweenDoubleQuotes( $distanceName ) ;
    }

    return func( GeoFunction::NEAR , $arguments ) ;
}
