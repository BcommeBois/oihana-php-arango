<?php

namespace oihana\arango\db\functions\geo;

use oihana\arango\db\enums\functions\GeoFunction;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\core\strings\func;

/**
 * Check whether the distance between two geometries falls within a range.
 *
 * This helper wraps the ArangoDB AQL function `GEO_IN_RANGE(geoJson1, geoJson2,
 * low, high, includeLow, includeHigh)` which returns `true` when the distance
 * (in meters) between the two geometries lies between `low` and `high`. The
 * boundary inclusion flags default to `true` on the ArangoDB side and are only
 * emitted when explicitly provided. Both geometries use the GeoJSON
 * **longitude-first** convention.
 *
 * Example AQL usage:
 * ```aql
 * GEO_IN_RANGE(doc.geo, @center, 1000, 5000)
 * GEO_IN_RANGE(doc.geo, @center, 1000, 5000, false, true)
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\geo\geoInRange;
 *
 * $expr = geoInRange( 'doc.geo' , '@center' , 1000 , 5000 );
 * // Produces: 'GEO_IN_RANGE(doc.geo,@center,1000,5000)'
 *
 * $expr = geoInRange( 'doc.geo' , '@center' , 1000 , 5000 , false , true );
 * // Produces: 'GEO_IN_RANGE(doc.geo,@center,1000,5000,false,true)'
 * ```
 *
 * @param array|string     $geo1        First GeoJSON geometry (AQL expression or coordinates).
 * @param array|string     $geo2        Second GeoJSON geometry (AQL expression or coordinates).
 * @param float|int|string $low         Lower distance bound, in meters (or AQL expression).
 * @param float|int|string $high        Upper distance bound, in meters (or AQL expression).
 * @param bool|null        $includeLow  Whether the lower bound is inclusive (ArangoDB default: `true`).
 * @param bool|null        $includeHigh Whether the upper bound is inclusive (ArangoDB default: `true`).
 *
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/geo/#geo_in_range
 *
 * @package oihana\arango\db\functions\geo
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function geoInRange
(
    array|string     $geo1 ,
    array|string     $geo2 ,
    float|int|string $low ,
    float|int|string $high ,
    ?bool            $includeLow  = null ,
    ?bool            $includeHigh = null
)
: string
{
    $arguments = [ aqlArray( $geo1 ) , aqlArray( $geo2 ) , $low , $high ] ;

    if ( $includeLow !== null || $includeHigh !== null )
    {
        $arguments[] = ( $includeLow  ?? true ) ? 'true' : 'false' ;
        $arguments[] = ( $includeHigh ?? true ) ? 'true' : 'false' ;
    }

    return func( GeoFunction::GEO_IN_RANGE , $arguments ) ;
}
