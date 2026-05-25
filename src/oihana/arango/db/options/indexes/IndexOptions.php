<?php

namespace oihana\arango\db\options\indexes;

use JsonSerializable;
use oihana\arango\db\enums\traits\IndexOptionsTrait;
use oihana\reflect\traits\ReflectionTrait;
use ReflectionException;

/**
 * A simple index options representation.
 */
class IndexOptions implements JsonSerializable
{
    /**
     * Creates a new IndexOptions instance.
     * @param array|object|null $init A generic object containing properties with which to populate the newly instance.
     * If this argument is null, it is ignored.
     */
    public function __construct( array|object|null $init = null )
    {
        if( isset( $init ) )
        {
            foreach ( $init as $key => $value )
            {
                if( property_exists( $this , $key ) )
                {
                    $this->{ $key } = $value ;
                }
            }
        }
    }

    use IndexOptionsTrait ,
        ReflectionTrait ;

    /**
     * An array of attribute paths, containing the document attributes (or sub-attributes) to be indexed.
     *
     * Some indexes allow using only a single path, and others allow multiple.
     * If multiple attributes are used, their order matters.
     *
     * The '.' character denotes sub-attributes in attribute paths.
     *
     * Attributes with literal '.' in their name cannot be indexed.
     * Attributes with the name _id cannot be indexed either, neither as a top-level attribute nor
     * as a sub-attribute (except the inverted index type).
     *
     * If an attribute path contains an [*] extension (e.g. friends[*].id),
     * it means that the index attribute value is treated as an array and all array members are indexed separately.
     *
     * This is possible with persistent and inverted indexes.
     *
     * @var array
     * @see https://docs.arango.ai/arangodb/stable/develop/http-api/indexes/
     */
    public array $fields = [] ;

    /**
     * An easy-to-remember name for the index to look it up or refer to it in index hints.
     *
     * Index names are subject to the same character restrictions as collection names.
     *
     * If omitted, a name is auto-generated so that it is unique with respect to the collection, e.g. idx_832910498.
     *
     * @var string
     */
    public string $name ;

    /**
     * Can be one of the following values:
     * - "persistent": persistent (array) index, including vertex-centric index
     * - "inverted": inverted index (not implemented yet)
     * - "ttl": time-to-live index
     * - "geo": geo-spatial index, with one or two attributes
     * - "mdi": multi-dimensional index
     * - "mdi-prefixed": multi-dimensional index with search prefix, including vertex-centric index
     * - "vector": vector inde
     *
     * @var string
     */
    public string $type ;

    /**
     * Invoked to serialize the object with the json serializer.
     * @throws ReflectionException
     */
    public function jsonSerialize() : array
    {
        return $this->toArray() ;
    }
}