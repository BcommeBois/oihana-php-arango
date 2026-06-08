<?php

namespace oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\GeoFilterParam;
use org\schema\constants\Schema;

/**
 * Resolve a `[ latitude, longitude ]` pair from a request-supplied object.
 *
 * Reads the canonical Schema.org `GeoCoordinates` keys (`latitude` /
 * `longitude`) first, then falls back to the short aliases (`lat` / `lng` /
 * `lon`). Returns `[ null, null ]` when the value is not an array, or when a
 * coordinate is missing — callers treat that as "no geo point".
 *
 * @param mixed $value The candidate object (typically a filter `val` or a `?near=` payload).
 *
 * @return array{0: mixed, 1: mixed} The `[ latitude, longitude ]` pair.
 *
 * @example
 * ```php
 * use function oihana\arango\db\helpers\resolveGeoPoint;
 *
 * resolveGeoPoint( [ 'latitude' => 48.85 , 'longitude' => 2.35 ] ) ; // [ 48.85, 2.35 ]
 * resolveGeoPoint( [ 'lat' => 48.85 , 'lng' => 2.35 ] ) ;            // [ 48.85, 2.35 ]
 * resolveGeoPoint( 'nope' ) ;                                        // [ null, null ]
 * ```
 *
 * @package oihana\arango\db\helpers
 * @since 1.0.0
 * @author Marc Alcaraz
 */
function resolveGeoPoint( mixed $value ): array
{
    if ( !is_array( $value ) )
    {
        return [ null , null ] ;
    }

    $latitude  = $value[ Schema::LATITUDE  ] ?? $value[ GeoFilterParam::LAT ] ?? null ;
    $longitude = $value[ Schema::LONGITUDE ] ?? $value[ GeoFilterParam::LNG ] ?? $value[ GeoFilterParam::LON ] ?? null ;

    return [ $latitude , $longitude ] ;
}
