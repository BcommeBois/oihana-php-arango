<?php

namespace oihana\arango\db\options\indexes;

use oihana\arango\db\enums\IndexType;

/**
 * The options of a geo-spatial index options.
 */
class GeoIndexOptions extends IndexOptions
{
    /**
     * If you create a geo-spatial index over a single attribute and geoJson is true,
     * then the coordinate order within the attribute’s array is longitude followed by latitude.
     *
     * This corresponds to the format described in http://geojson.org/geojson-spec.html#positions
     *
     * @var bool
     */
    public bool $geoJson = false ;

    /**
     * Set this option to true to keep the collection/shards available for write operations
     * by not using an exclusive write lock for the duration of the index creation.
     *
     * @var bool
     */
    public bool $inBackground = false ;

    /**
     * How the polygons of a GeoJSON index are read : `true` keeps the reading of the
     * indexes created before ArangoDB 3.10 (a polygon's edges are straight lines in
     * longitude and latitude), `false` reads them as geodesics on the sphere — the
     * default of every index created since.
     *
     * Sent only when declared : the server then applies its own default.
     *
     * @var bool
     */
    public bool $legacyPolygons ;

    /**
     * Can be one of the following values:
     * - "persistent": persistent (array) index, including vertex-centric index
     * - "inverted": inverted index
     * - "ttl": time-to-live index
     * - "fulltext": full-text index (deprecated from ArangoDB 3.10 onwards)
     * - "geo": geo-spatial index, with one or two attributes
     * - "mdi": multi-dimensional index
     * - "mdi-prefixed": multi-dimensional index with search prefix, including vertex-centric index
     *
     * @var string
     */
    public string $type = IndexType::GEO ;
}