<?php

namespace oihana\arango\models\enums\filters;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Convenience aliases accepted inside the `val` object of a
 * {@see FilterType::GEO} filter, in addition to the canonical Schema.org
 * `GeoCoordinates` keys.
 *
 * The canonical names (`latitude` / `longitude`) come from
 * {@see \org\schema\constants\Schema}; this enum only carries the short forms
 * (`lat` / `lng` / `lon`) commonly used by geo APIs, accepted on the request
 * side as a courtesy.
 *
 * @package oihana\arango\models\enums\filters
 * @since 1.0.0
 * @author Marc Alcaraz
 */
class GeoFilterParam
{
    use ConstantsTrait ;

    /**
     * Short alias for `latitude` on the request side.
     */
    public const string LAT = 'lat' ;

    /**
     * Short alias for `longitude` on the request side.
     */
    public const string LNG = 'lng' ;

    /**
     * Short alias for `longitude` on the request side.
     */
    public const string LON = 'lon' ;
}
