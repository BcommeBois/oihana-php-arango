<?php

namespace oihana\arango\db\operations;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\VectorMetric;
use oihana\enums\Char;
use oihana\enums\Order;

use function oihana\arango\db\functions\numerics\approxNearCosine;
use function oihana\arango\db\functions\numerics\approxNearL2;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Builds a complete AQL approximate nearest-neighbour (ANN) query over a vector index.
 *
 * The generated query follows the canonical ANN form:
 * ```
 * FOR <docRef> IN <collection>
 *   SORT APPROX_NEAR_<METRIC>(<docRef>.<attribute>, <vector>) <ASC|DESC>
 *   LIMIT <limit>
 *   RETURN <return>
 * ```
 *
 * The `$metric` selects **both** the AQL function and the sort direction, which
 * is the part developers get wrong most often:
 * - `'cosine'` → `APPROX_NEAR_COSINE` sorted `DESC` (closer to 1 is nearer),
 * - `'l2'`     → `APPROX_NEAR_L2` sorted `ASC` (closer to 0 is nearer).
 *
 * The metric **must match the metric of the {@see \oihana\arango\clients\collection\indexes\VectorIndex}**
 * covering `$attribute`, otherwise the optimiser cannot accelerate the query.
 *
 * > Requires ArangoDB started with the experimental vector index feature.
 *
 * ### Example: cosine search with a bound query vector
 * ```php
 * use function oihana\arango\db\operations\aqlVectorSearch;
 *
 * $aql = aqlVectorSearch
 * (
 *     collection : 'items' ,
 *     attribute  : 'embedding' ,
 *     vector     : '@query' ,
 *     limit      : 10 ,
 * ) ;
 * // FOR doc IN items SORT APPROX_NEAR_COSINE(doc.embedding,@query) DESC LIMIT 10 RETURN doc
 * ```
 *
 * ### Example: L2 search, custom nProbe, projection and iteration variable
 * ```php
 * $aql = aqlVectorSearch
 * (
 *     collection : 'items' ,
 *     attribute  : 'embedding' ,
 *     vector     : '@query' ,
 *     limit      : 5 ,
 *     metric     : 'l2' ,
 *     nProbe     : 20 ,
 *     docRef     : 'd' ,
 *     return     : '{ key: d._key, score: APPROX_NEAR_L2(d.embedding, @query) }' ,
 * ) ;
 * // FOR d IN items SORT APPROX_NEAR_L2(d.embedding,@query,{"nProbe":20}) ASC LIMIT 5
 * //   RETURN { key: d._key, score: APPROX_NEAR_L2(d.embedding, @query) }
 * ```
 *
 * @param string      $collection The collection to scan (or any AQL iterable expression).
 * @param string      $attribute  The document attribute holding the indexed vector (e.g. `'embedding'`).
 * @param string      $vector      The query vector — typically a bind placeholder (`'@query'`) or an AQL array literal.
 * @param int         $limit      The number of nearest neighbours to return (the `LIMIT`).
 * @param string      $metric     The similarity metric: `'cosine'` (default) or `'l2'`. Must match the vector index.
 * @param int|null    $nProbe     Optional number of neighbouring centroids to probe (higher = more accurate, slower).
 * @param string      $docRef     The iteration variable name (default `'doc'`).
 * @param string|null $return     Optional `RETURN` expression. Defaults to the iteration variable (the whole document).
 *
 * @return string The complete AQL ANN query.
 *
 * @throws InvalidArgumentException If `$metric` is neither `'cosine'` nor `'l2'`.
 *
 * @see https://docs.arangodb.com/stable/aql/functions/vector/
 * @see approxNearCosine()
 * @see approxNearL2()
 *
 * @package oihana\arango\db\operations
 * @since   1.1.0
 * @author  Marc Alcaraz
 */
function aqlVectorSearch
(
    string  $collection ,
    string  $attribute ,
    string  $vector ,
    int     $limit ,
    string  $metric  = VectorMetric::COSINE ,
    ?int    $nProbe  = null ,
    string  $docRef  = 'doc' ,
    ?string $return  = null ,
)
: string
{
    $field = key( $attribute , $docRef ) ;

    [ $distance , $order ] = match ( $metric )
    {
        VectorMetric::COSINE => [ approxNearCosine( $field , $vector , $nProbe ) , Order::DESC ] ,
        VectorMetric::L2     => [ approxNearL2    ( $field , $vector , $nProbe ) , Order::ASC  ] ,
        default              => throw new InvalidArgumentException
        (
            "aqlVectorSearch(): unsupported metric '$metric', expected 'cosine' or 'l2'."
        ) ,
    } ;

    return compile
    ([
        aqlFor   ( [ AQL::DOC_REF => $docRef , AQL::IN => $collection ] ) ,
        aqlSort  ( $distance . Char::SPACE . $order ) ,
        aqlLimit ( $limit ) ,
        aqlReturn( $return ?? $docRef ) ,
    ]) ;
}
