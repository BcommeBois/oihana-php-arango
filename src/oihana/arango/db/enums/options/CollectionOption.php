<?php

namespace oihana\arango\db\enums\options;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of all COLLECT optional OPTIONS properties to modify the clause behavior.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/collect/#collect-options
 */
class CollectionOption
{
    use ConstantsTrait ;
    
    public const string HASH   = 'hash'   ;
    public const string SORTED = 'sorted' ;
}