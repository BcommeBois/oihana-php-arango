<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use oihana\arango\db\enums\VectorSearchOption;
use function oihana\core\strings\func;

/**
 * Return the approximate L2 (Euclidean) distance between two vectors, accelerated by a vector index.
 *
 * This helper wraps the ArangoDB AQL function `APPROX_NEAR_L2(x, y)`.
 * One of the two operands must reference a document attribute covered by a
 * {@see \oihana\arango\clients\collection\indexes\VectorIndex} created with the
 * `"l2"` metric; the other is the query vector. The closer the returned value
 * is to `0`, the more similar the vectors are.
 *
 * Because smaller is more similar, you **sort in ascending order** to get the
 * nearest neighbours first — which is exactly what {@see aqlVectorSearch()}
 * does for the `l2` metric.
 *
 * > Requires ArangoDB started with the experimental vector index feature.
 *
 * Example AQL usage:
 * ```aql
 * FOR doc IN items
 *   SORT APPROX_NEAR_L2(doc.embedding, @query) ASC
 *   LIMIT 10
 *   RETURN doc
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\approxNearL2;
 *
 * $expr = approxNearL2('doc.embedding', '@query');
 * // Produces: 'APPROX_NEAR_L2(doc.embedding,@query)'
 *
 * $expr = approxNearL2('doc.embedding', '@query', 20);
 * // Produces: 'APPROX_NEAR_L2(doc.embedding,@query,{"nProbe":20})'
 * ```
 *
 * @param string|int $x      First operand — the stored attribute or the query vector.
 * @param string|int $y      Second operand — the query vector or the stored attribute.
 * @param int|null   $nProbe Optional number of neighbouring centroids to probe (higher = more accurate, slower).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/vector/#approx_near_l2
 * @see approxNearCosine() For the cosine-metric counterpart.
 * @see aqlVectorSearch() For the full `FOR … SORT … LIMIT` query builder.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function approxNearL2( string|int $x , string|int $y , ?int $nProbe = null ) : string
{
    $args = [ $x , $y ] ;
    if ( $nProbe !== null )
    {
        $args[] = json_encode( [ VectorSearchOption::N_PROBE => $nProbe ] ) ;
    }
    return func( NumericFunction::APPROX_NEAR_L2 , $args ) ;
}
