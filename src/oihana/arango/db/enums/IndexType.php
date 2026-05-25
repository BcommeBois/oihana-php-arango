<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of the ArangoDB index types.
 *
 * @see https://docs.arango.ai/arangodb/stable/develop/http-api/indexes/
 */
class IndexType
{
    use ConstantsTrait ;

    /**
     * The 'geo-spatial' index type.
     */
    public const string GEO = 'geo' ;

    /**
     * The 'inverted' index type.
     */
    public const string INVERTED = 'inverted' ;

    /**
     * The 'multi-dimensional' index type.
     */
    public const string MDI = 'mdi' ;

    /**
     * The 'multi-dimensional prefixed' index type.
     */
    public const string MDI_PREFIXED = 'mdi-prefixed' ;

    /**
     * The 'persistent' index type.
     */
    public const string PERSISTENT = 'persistent' ;

    /**
     * The 'ttl' index type.
     */
    public const string TTL = 'ttl' ;

    /**
     * The 'vector' index type.
     */
    public const string VECTOR = 'vector' ;
}
