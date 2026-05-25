<?php

namespace oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\enums\IndexField ;
use oihana\arango\clients\collection\indexes\enums\IndexType ;

/**
 * Geospatial index definition.
 *
 * Two input shapes are supported:
 * - one field path (`['location']`) holding either a `[lat, lng]` pair
 *   or a GeoJSON object (depending on `$geoJson`),
 * - two field paths (`['lat', 'lng']`) when latitude and longitude
 *   are stored as separate attributes.
 *
 * Example:
 * ```php
 * $places->createIndex( new GeoIndex( fields : [ 'location' ] , geoJson : true ) ) ;
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/geo-spatial-indexes/
 *
 * @package oihana\arango\clients\collection\indexes
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class GeoIndex implements IndexDefinition
{
    /**
     * @param array<int, string> $fields       One field (point or GeoJSON object) or two fields ([latField, lngField]).
     * @param string|null        $name         Optional human-readable index name.
     * @param bool|null          $geoJson      Interpret the single field as a GeoJSON object instead of a `[lat, lng]` pair.
     * @param bool|null          $inBackground Build the index in the background.
     */
    public function __construct
    (
        public array   $fields ,
        public ?string $name         = null ,
        public ?bool   $geoJson      = null ,
        public ?bool   $inBackground = null ,
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function toArray() : array
    {
        $data =
        [
            IndexField::TYPE   => IndexType::GEO ,
            IndexField::FIELDS => $this->fields ,
        ] ;

        if ( $this->name         !== null ) { $data[ IndexField::NAME ]          = $this->name         ; }
        if ( $this->geoJson      !== null ) { $data[ IndexField::GEO_JSON ]      = $this->geoJson      ; }
        if ( $this->inBackground !== null ) { $data[ IndexField::IN_BACKGROUND ] = $this->inBackground ; }

        return $data ;
    }
}
