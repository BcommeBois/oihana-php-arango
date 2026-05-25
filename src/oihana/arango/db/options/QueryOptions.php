<?php

namespace oihana\arango\db\options;

use JsonSerializable;
use oihana\arango\db\enums\traits\QueryOptionsTrait;
use ReflectionException;

use oihana\reflect\traits\ReflectionTrait;

class QueryOptions implements JsonSerializable
{
    /**
     * Creates a new QueryOptions instance.
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

    use QueryOptionsTrait ,
        ReflectionTrait   ;

    /**
     * Invoked to serialize the object with the json serializer.
     * @throws ReflectionException
     */
    public function jsonSerialize() : array
    {
        return $this->toArray() ;
    }
}