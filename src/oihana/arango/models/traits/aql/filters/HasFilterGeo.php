<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Schema;

use oihana\logging\LoggerTrait;
use function oihana\arango\db\functions\geo\distance;
use function oihana\arango\db\helpers\buildBetweenClauses;
use function oihana\arango\db\helpers\resolveGeoPoint;
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
     * @return string|null The AQL condition, or `null` when the request names an operator this
     *                     filter cannot honour, or a value that is not a point. `null` is what
     *                     the composition layer knows how to drop; an empty string is not.
     *
     * @throws BindException
     */
    protected function prepareFilterGeo( array $init = [] , ?array &$binds = null , string $doc = AQL::DOC ): ?string
    {
        // The key is guaranteed by the dispatch (a declared FilterType::GEO attribute).
        $key = (string) ( $init[ FilterParam::KEY ] ?? Char::EMPTY ) ;

        $operator = $init[ FilterParam::OP ] ?? FilterComparator::DISTANCE ;

        if ( $operator !== FilterComparator::DISTANCE )
        {
            // 🚨 `null`, not an empty string.
            //
            // Both mean "no clause", and only one of them behaves like it: `null` is
            // dropped by the composition layer, while `''` travels on as if it were a
            // condition and reaches the server as `FILTER  RETURN` — a syntax error on
            // its own or inside an `OR`, and a silent disappearance inside an `AND`.
            // This filter was the only one in the library returning the empty string.
            $this->logger?->warning( __METHOD__ . ' failed, unsupported geo operator: "' . $operator . '"' ) ;
            return null ;
        }

        [ $latitude , $longitude ] = resolveGeoPoint( $init[ FilterParam::VAL ] ?? null ) ;

        if ( $latitude === null || $longitude === null )
        {
            return null ; // as above: a droppable "no clause", not an empty condition.
        }

        $expression = distance
        (
            key( $key . Char::DOT . Schema::LATITUDE  , $doc ) ,
            key( $key . Char::DOT . Schema::LONGITUDE , $doc ) ,
            $this->bind( $latitude  , $binds ) ,
            $this->bind( $longitude , $binds )
        ) ;

        // A radius is a VALUE, not a key — `{"max":null}` is an unfilled widget.
        $min = ( $init[ FilterParam::MIN ] ?? null ) !== null ? $this->bind( $init[ FilterParam::MIN ] , $binds ) : null ;
        $max = ( $init[ FilterParam::MAX ] ?? null ) !== null ? $this->bind( $init[ FilterParam::MAX ] , $binds ) : null ;

        return buildBetweenClauses( $expression , $min , $max ) ;
    }
}
