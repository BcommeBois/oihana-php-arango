<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The values of the `countApproximate` option of the AQL `SEARCH` operation,
 * controlling how the total row count is calculated when the `fullCount` query
 * option is enabled or a `COLLECT WITH COUNT` clause is executed — used as the
 * {@see \oihana\arango\db\options\SearchOptions::$countApproximate} value.
 *
 * @see https://docs.arangodb.com/stable/aql/high-level-operations/search/#countapproximate
 */
class CountApproximate
{
    use ConstantsTrait ;

    /**
     * Use a cost-based approximation: rows are not enumerated, the approximate
     * count is returned with O(1) complexity. Precise if the `SEARCH` condition
     * is empty or a single term query, the usual View eventual consistency aside.
     */
    public const string COST = 'cost' ;

    /**
     * Enumerate the rows for a precise count (server default).
     */
    public const string EXACT = 'exact' ;
}
