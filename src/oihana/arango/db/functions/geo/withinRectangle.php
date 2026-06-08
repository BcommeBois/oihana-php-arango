<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\core\strings\func;

/**
 * Return documents of a collection within a bounding rectangle (legacy).
 *
 * This helper wraps the ArangoDB AQL function `WITHIN_RECTANGLE(collection,
 * latitude1, longitude1, latitude2, longitude2)` which returns all documents
 * located inside the rectangle defined by two opposite corners. The collection
 * must have a geo index. Coordinates are given in the **latitude-first** order.
 *
 * This is a legacy function; prefer a geo index combined with GeoJSON
 * {@see geoContains()} over a polygon for new code.
 *
 * Example AQL usage:
 * ```aql
 * FOR place IN WITHIN_RECTANGLE(places, 48.80, 2.25, 48.90, 2.40) RETURN place
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\withinRectangle;
 *
 * $expr = withinRectangle( 'places' , 48.80 , 2.25 , 48.90 , 2.40 );
 * // Produces: 'WITHIN_RECTANGLE(places,48.8,2.25,48.9,2.4)'
 * ```
 *
 * @param string           $collection The target collection name (AQL identifier).
 * @param float|int|string $latitude1  Latitude of the first corner (or AQL expression).
 * @param float|int|string $longitude1 Longitude of the first corner (or AQL expression).
 * @param float|int|string $latitude2  Latitude of the opposite corner (or AQL expression).
 * @param float|int|string $longitude2 Longitude of the opposite corner (or AQL expression).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#within_rectangle
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function withinRectangle
(
    string           $collection ,
    float|int|string $latitude1 ,
    float|int|string $longitude1 ,
    float|int|string $latitude2 ,
    float|int|string $longitude2
)
: string
{
    return func( GeoFunction::WITHIN_RECTANGLE , [ $collection , $latitude1 , $longitude1 , $latitude2 , $longitude2 ] ) ;
}
