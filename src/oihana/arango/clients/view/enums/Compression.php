<?php

namespace oihana\arango\clients\view\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Compression strategy for the `primarySortCompression` of an ArangoSearch
 * view and for the compression of its `storedValues` columns.
 *
 * @see https://docs.arangodb.com/stable/index-and-search/arangosearch/arangosearch-views-reference/#view-properties
 *
 * @package oihana\arango\clients\view\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class Compression
{
    use ConstantsTrait ;

    /** LZ4 compression — fast, lightweight. Server default. */
    public const string LZ4 = 'lz4' ;

    /** No compression — values are stored verbatim. */
    public const string NONE = 'none' ;
}
