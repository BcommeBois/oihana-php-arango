<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use org\schema\constants\Prop;

use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\key;

/**
 * Builds the AQL filter fragment for a {@see \oihana\arango\models\enums\Facet::JOIN_AGGREGATE}
 * facet: the key-join counterpart of {@see HasFacetEdgeAggregate} and the
 * aggregate counterpart of {@see HasFacetJoin}. Instead of testing for the mere
 * existence of a joined document, it aggregates a numeric field over ALL joined
 * documents and compares the result to a threshold —
 * `AGG(FOR doc_<key> IN <collection> FILTER doc_<key>.<KEY> == doc.<PROPERTY> RETURN doc_<key>.<field>) <op> @<key>_0`.
 *
 * The join itself is `doc_join.<KEY> == doc.<PROPERTY>` (or `IN` when
 * `AQL::ARRAY` is set), with `AQL::KEY` the joined side (default `_key`) and
 * `Facet::PROPERTY` the main side (default the facet key). The aggregation logic
 * is shared with {@see HasFacetEdgeAggregate} through {@see HasFacetAggregateConditions}.
 *
 * @see FacetTrait::prepareFacets()      The dispatcher that invokes this builder.
 * @see HasFacetAggregateConditions      The shared aggregation logic.
 */
trait HasFacetJoinAggregate
{
    use HasFacetAggregateConditions ;

    /**
     * Prepares a join aggregate facet.
     *
     * @param string $key The facet key (also the default main-side join property).
     * @param mixed $value A scalar threshold, or an `{agg, field, op, val}` object.
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`AQL::COLLECTION`, `AQL::KEY`, `Facet::PROPERTY`, `AQL::ARRAY`, `Facet::AGG`, `AQL::FIELDS`, `Facet::OP`).
     * @param string $doc The main document reference.
     *
     * @return string
     *
     * @throws BindException
     * @throws ValidationException
     *
     * @example
     * Average comment score per article — keep articles rated 4 or more on average :
     * ```php
     * Arango::FACETS =>
     * [
     *     'comments' =>
     *     [
     *         Facet::TYPE     => Facet::JOIN_AGGREGATE ,
     *         AQL::COLLECTION => 'comments' ,  // joined collection
     *         AQL::KEY        => 'articleId' , // joined side  (default _key)
     *         Facet::PROPERTY => '_key' ,      // main side    (default the facet key)
     *         Facet::AGG      => 'avg' ,       // default aggregator
     *         AQL::FIELDS     => 'score' ,     // default aggregated field
     *         Facet::OP       => 'ge'          // default threshold comparator
     *     ]
     * ]
     * ```
     * ```
     * ?facets={"comments":{"agg":"avg","field":"score","op":"ge","val":4}}
     * // AVERAGE(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN doc_comments.score) >= @comments_0
     *
     * ?facets={"comments":{"agg":"count","val":3}}      // at least 3 comments
     * ?facets={"comments":{"agg":"min","field":"score","val":3}}  // worst score still >= 3
     * ?facets={"comments":{"agg":"count","op":"lt","val":2}}      // lightly commented (fewer than 2)
     * ```
     */
    protected function prepareFacetJoinAggregate( string $key , mixed $value , array &$binds , array $facet , string $doc , array $init = [] ) :string
    {
        $docRef     = AQL::DOC_PREFIX . $key ;
        $collection = $facet[ AQL::COLLECTION ] ?? null ;
        $joinKey    = $facet[ AQL::KEY        ] ?? Prop::_KEY ;
        $property   = $facet[ Facet::PROPERTY ] ?? $key ;
        $isArray    = $facet[ AQL::ARRAY      ] ?? false ;

        // Join match: doc_<key>.<KEY> == doc.<PROPERTY>  (or IN for an array of keys)
        $joinLeft  = key( $joinKey  , $docRef ) ;
        $joinRight = key( $property , $doc ) ;
        $match     = $isArray ? in( $joinLeft , $joinRight ) : equal( $joinLeft , $joinRight ) ;

        $for = aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => $collection ] ) ;

        return $this->prepareAggregateConditions( $value , $facet , $for , $match , $docRef , $key , $binds , $init ) ;
    }
}
