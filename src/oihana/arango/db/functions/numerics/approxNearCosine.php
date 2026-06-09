<?php

namespace oihana\arango\db\functions\numerics;

use oihana\arango\db\enums\functions\NumericFunction;
use oihana\arango\db\enums\VectorSearchOption;
use function oihana\core\strings\func;

/**
 * Return the approximate cosine similarity between two vectors, accelerated by a vector index.
 *
 * This helper wraps the ArangoDB AQL function `APPROX_NEAR_COSINE(x, y)`.
 * One of the two operands must reference a document attribute covered by a
 * {@see \oihana\arango\clients\collection\indexes\VectorIndex} created with the
 * `"cosine"` metric; the other is the query vector. The closer the returned
 * value is to `1`, the more similar the vectors are.
 *
 * Because higher is more similar, you **sort in descending order** to get the
 * nearest neighbours first — which is exactly what {@see aqlVectorSearch()}
 * does for the `cosine` metric.
 *
 * > Requires ArangoDB started with the experimental vector index feature.
 *
 * Example AQL usage:
 * ```aql
 * FOR doc IN items
 *   SORT APPROX_NEAR_COSINE(doc.embedding, @query) DESC
 *   LIMIT 10
 *   RETURN doc
 * ```
 *
 * @example
 * ```php
 * use function oihana\arango\db\functions\numerics\approxNearCosine;
 *
 * $expr = approxNearCosine('doc.embedding', '@query');
 * // Produces: 'APPROX_NEAR_COSINE(doc.embedding,@query)'
 *
 * $expr = approxNearCosine('doc.embedding', '@query', 20);
 * // Produces: 'APPROX_NEAR_COSINE(doc.embedding,@query,{"nProbe":20})'
 * ```
 *
 * @param string|int $x      First operand — the stored attribute or the query vector.
 * @param string|int $y      Second operand — the query vector or the stored attribute.
 * @param int|null   $nProbe Optional number of neighbouring centroids to probe (higher = more accurate, slower).
 * @return string The formatted AQL expression.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/vector/#approx_near_cosine
 * @see approxNearL2() For the L2-metric counterpart.
 * @see aqlVectorSearch() For the full `FOR … SORT … LIMIT` query builder.
 *
 * @package oihana\arango\db\functions\numerics
 * @since 1.1.0
 * @author Marc Alcaraz
 */
function approxNearCosine( string|int $x , string|int $y , ?int $nProbe = null ) : string
{
    $args = [ $x , $y ] ;
    if ( $nProbe !== null )
    {
        $args[] = json_encode( [ VectorSearchOption::N_PROBE => $nProbe ] ) ;
    }
    return func( NumericFunction::APPROX_NEAR_COSINE , $args ) ;
}
