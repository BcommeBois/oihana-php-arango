<?php

namespace oihana\arango\db\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The vector similarity metrics, used both as the `metric` value of a
 * {@see \oihana\arango\clients\collection\indexes\VectorIndex} and to select the
 * approximate-nearest-neighbour function in {@see \oihana\arango\db\operations\aqlVectorSearch()}.
 *
 * The metric of a query must match the metric of the index covering the attribute.
 *
 * @see FaithParam::METRIC
 * @see https://docs.arangodb.com/stable/index-and-search/indexing/working-with-indexes/vector-indexes/
 */
class VectorMetric
{
    use ConstantsTrait ;

    /**
     * Angular similarity (`APPROX_NEAR_COSINE`). Vectors are normalized; sort `DESC`, closer to 1 is nearer.
     */
    public const string COSINE = 'cosine' ;

    /**
     * Similarity in terms of angle and magnitude (introduced in ArangoDB v3.12.6). Vectors are not normalized.
     *
     * Note: there is no `APPROX_NEAR_*` function for this metric, so it is not selectable in {@see aqlVectorSearch()}.
     */
    public const string INNER_PRODUCT = 'innerProduct' ;

    /**
     * Euclidean distance (`APPROX_NEAR_L2`). Sort `ASC`, closer to 0 is nearer.
     */
    public const string L2 = 'l2' ;
}
