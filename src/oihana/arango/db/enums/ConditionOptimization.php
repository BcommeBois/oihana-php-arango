<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The values of the `conditionOptimization` option of the AQL `SEARCH` operation,
 * controlling how the search criteria are optimized — used as the
 * {@see \oihana\arango\db\options\SearchOptions::$conditionOptimization} value.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#conditionoptimization
 */
class ConditionOptimization
{
    use ConstantsTrait ;

    /**
     * Convert the conditions to disjunctive normal form (DNF) and apply optimizations,
     * removing redundant or overlapping conditions (server default). Can take quite
     * some time even for a low number of nested conditions.
     */
    public const string AUTO = 'auto' ;

    /**
     * Search the index without optimizing the conditions.
     */
    public const string NONE = 'none' ;
}
