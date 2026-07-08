<?php

namespace oihana\arango\models\traits\aql\facets;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\operations\aqlFor;
use function oihana\core\strings\compile;

/**
 * Builds the AQL filter fragment for an {@see \oihana\arango\models\enums\Facet::EDGE_AGGREGATE}
 * facet: the aggregate counterpart of {@see HasFacetEdge}. Instead of testing for
 * the mere existence of a linked vertex, it aggregates a numeric field over ALL
 * vertices reached through an inbound edge traversal and compares the result to a
 * threshold — `AGG(FOR doc_<key> IN INBOUND doc <edge> RETURN doc_<key>.<field>) <op> @<key>_0`.
 *
 * The aggregation logic (aggregator / field / comparator / threshold resolution)
 * is shared with {@see HasFacetJoinAggregate} through {@see HasFacetAggregateConditions};
 * only the iteration source differs (an `INBOUND` traversal — no FILTER needed,
 * the traversal already targets the right vertices).
 *
 * @see FacetTrait::prepareFacets()      The dispatcher that invokes this builder.
 * @see HasFacetAggregateConditions      The shared aggregation logic.
 */
trait HasFacetEdgeAggregate
{
    use HasFacetAggregateConditions ;

    /**
     * Prepares an edge aggregate facet.
     *
     * @param string $key The facet key (also the related-document variable suffix).
     * @param mixed $value A scalar threshold, or an `{agg, field, op, val}` object.
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`AQL::EDGE`, `Facet::AGG`, `AQL::FIELDS`, `Facet::OP`).
     * @param string $doc The main document reference.
     *
     * @return string
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws ValidationException
     *
     * @example
     * Average revenue of all linked balance sheets — keep organisations above a threshold :
     * ```php
     * Arango::FACETS =>
     * [
     *     'balanceSheets' =>
     *     [
     *         Facet::TYPE => Facet::EDGE_AGGREGATE ,
     *         AQL::EDGE   => 'balance_edges' , // edge collection (INBOUND doc)
     *         Facet::AGG  => 'avg' ,           // default aggregator
     *         AQL::FIELDS => 'revenue' ,       // default aggregated field
     *         Facet::OP   => 'ge'              // default threshold comparator
     *     ]
     * ]
     * ```
     * ```
     * ?facets={"balanceSheets":{"agg":"avg","field":"revenue","op":"ge","val":1000000}}
     * // AVERAGE(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue) >= @balanceSheets_0
     *
     * ?facets={"balanceSheets":{"agg":"count","op":"ge","val":3}}  // at least 3 linked balance sheets
     * ?facets={"balanceSheets":{"agg":"sum","field":"revenue","val":5000000}}  // cumulative revenue >= 5M
     * ?facets={"balanceSheets":5}  // defaults (count, ge): at least 5 linked balance sheets
     * ```
     */
    protected function prepareFacetEdgeAggregate( string $key , mixed $value , array &$binds , array $facet , string $doc , array $init = [] ) :string
    {
        $docRef = AQL::DOC_PREFIX . $key ;
        $edge   = $facet[ AQL::EDGE ] ?? null ;

        $for = aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] ) ;

        return $this->prepareAggregateConditions( $value , $facet , $for , null , $docRef , $key , $binds , $init ) ;
    }
}
