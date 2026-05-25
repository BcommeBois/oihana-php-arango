<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * Represents the attributes of a runtime statistics per query execution node.
 *
 * @package oihana\arango\db\enums
 *
 * @see https://docs.arango.ai/arangodb/stable/aql/execution-and-performance/query-statistics
 */
class Node
{
    use ConstantsTrait ;

    /**
     * The execution node ID to correlate the statistics with the plan returned in the extra attribute.
     */
    public const string ID = 'id' ;

    /**
     * The number of calls to this node.
     */
    public const string CALLS = 'calls' ;

    /**
     * The number of items returned by this node. Items are the temporary results returned at this stage.
     */
    public const string ITEMS = 'items' ;

    /**
     * The execution time of this node in seconds.
     */
    public const string RUNTIME = 'runtime' ;
}