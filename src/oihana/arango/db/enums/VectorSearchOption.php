<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The query-time options accepted by the AQL vector similarity functions
 * (`APPROX_NEAR_COSINE`, `APPROX_NEAR_L2`) as their trailing options object.
 *
 * Distinct from {@see FaithParam} (which configures the index at creation time);
 * these tune a single query.
 *
 * @see \oihana\arango\db\functions\numerics\approxNearCosine()
 * @see \oihana\arango\db\functions\numerics\approxNearL2()
 * @see https://docs.arangodb.com/stable/aql/functions/vector/
 */
class VectorSearchOption
{
    use ConstantsTrait ;

    /**
     * How many neighbouring centroids to consider for the search.
     *
     * The larger the number, the slower the search but the better the results.
     * Overrides the index's {@see FaithParam::DEFAULT_N_PROBE} for this query.
     */
    public const string N_PROBE = 'nProbe' ;
}
