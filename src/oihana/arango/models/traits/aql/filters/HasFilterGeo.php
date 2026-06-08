<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\GeoFilterParam;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Schema;

use oihana\logging\LoggerTrait;
use function oihana\arango\db\functions\geo\distance;
use function oihana\arango\db\helpers\buildBetweenClauses;
use function oihana\core\strings\key;

/**
 * This trait defines the geospatial filter helpers.
 *
 * ### Configure
 * Declare a {@see FilterType::GEO} key in the model (`Documents`) definition.
 * The value stored under that key is expected to be a Schema.org
 * `GeoCoordinates`-shaped object, i.e. `<key>.latitude` and `<key>.longitude`.
 * ```
 * AQL::FILTERS =>
 * [
 *     'geo' => FilterType::GEO ,
 * ]
 * ```
 *
 * ### Use
 * The `distance` operator filters documents by their distance (in meters) to a reference point.
 * The radius bounds reuse the `min` / `max` keys, exactly like `between`:
 * ```
 * // within 5 km
 * ?filter={ "key":"geo", "op":"distance", "val":{ "latitude":48.85, "longitude":2.35 }, "max":5000 }
 * // → DISTANCE(doc.geo.latitude, doc.geo.longitude, @lat, @lng) <= @max
 *
 * // ring between 1 km and 5 km
 * ?filter={ "key":"geo", "op":"distance", "val":{ "latitude":48.85, "longitude":2.35 }, "min":1000, "max":5000 }
 * ```
 *
 * `DISTANCE` reads two scalar attributes, so the predicate is index-accelerated
 * when a two-field `GeoIndex` is declared over `<key>.latitude` /
 * `<key>.longitude` (`geoJson: false`).
 */
trait HasFilterGeo
{
    use BindTrait,
        LoggerTrait ;

    /**
     * Prepares the filter clause for a geospatial attribute.
     *
     * @param array $init
     * @param array|null $binds
     * @param string $doc
     *
     * @return string
     *
     * @throws BindException
     */
    protected function prepareFilterGeo( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ): string
    {
        $key = $init[ FilterParam::KEY ] ?? null ;

        if ( !is_string( $key ) || $key === Char::EMPTY )
        {
            return Char::EMPTY ;
        }

        $operator = $init[ FilterParam::OP ] ?? FilterComparator::DISTANCE ;

        if ( $operator !== FilterComparator::DISTANCE )
        {
            $this->logger?->warning( __METHOD__ . ' failed, unsupported geo operator: "' . $operator . '"' ) ;
            return Char::EMPTY ;
        }

        [ $latitude , $longitude ] = $this->resolveGeoPoint( $init[ FilterParam::VAL ] ?? null ) ;

        if ( $latitude === null || $longitude === null )
        {
            return Char::EMPTY ;
        }

        $expression = distance
        (
            key( $key . Char::DOT . Schema::LATITUDE  , $doc ) ,
            key( $key . Char::DOT . Schema::LONGITUDE , $doc ) ,
            $this->bind( $latitude  , $binds ) ,
            $this->bind( $longitude , $binds )
        ) ;

        $min = array_key_exists( FilterParam::MIN , $init ) ? $this->bind( $init[ FilterParam::MIN ] , $binds ) : null ;
        $max = array_key_exists( FilterParam::MAX , $init ) ? $this->bind( $init[ FilterParam::MAX ] , $binds ) : null ;

        return buildBetweenClauses( $expression , $min , $max ) ;
    }

    /**
     * Resolve a `{ latitude, longitude }` point from a filter `val` object.
     *
     * Accepts the canonical Schema.org keys (`latitude` / `longitude`) and the
     * short aliases (`lat` / `lng` / `lon`). Returns `[ null, null ]` when the
     * value is not an object or a coordinate is missing.
     *
     * @param mixed $value
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function resolveGeoPoint( mixed $value ): array
    {
        if ( !is_array( $value ) )
        {
            return [ null , null ] ;
        }

        $latitude  = $value[ Schema::LATITUDE  ] ?? $value[ GeoFilterParam::LAT ] ?? null ;
        $longitude = $value[ Schema::LONGITUDE ] ?? $value[ GeoFilterParam::LNG ] ?? $value[ GeoFilterParam::LON ] ?? null ;

        return [ $latitude , $longitude ] ;
    }
}
