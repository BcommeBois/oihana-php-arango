<?php

namespace oihana\arango\models\traits\aql\facets;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\Facet;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use org\schema\constants\Prop;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Builds the AQL filter fragment for a {@see Facet::JOIN_COMPLEX} facet: the
 * key-join counterpart of {@see HasFacetEdgeComplex}. Instead of traversing an
 * edge, it joins a collection by attribute equality and keeps documents that
 * have at least one joined document matching SEVERAL fields (AND), each field
 * accepting multiple values (OR) and per-value negation.
 *
 * A join is a nested `FOR` matched on a key, not a graph traversal:
 * `LENGTH(FOR doc_<key> IN <collection> FILTER doc_<key>.<KEY> == doc.<PROPERTY>
 * && …conditions… RETURN 1) > 0`. The join is `doc_join.<KEY> == doc.<PROPERTY>`
 * (or `IN` when the main document holds an array of keys), with `KEY` the joined
 * side (default `_key`) and `PROPERTY` the main side (default the facet key) —
 * which expresses both "the document holds the foreign key" and the reverse
 * one-to-many "the joined documents reference the document".
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 * @see HasFacetComplexConditions  The shared per-field condition builder.
 */
trait HasFacetJoinComplex
{
    use HasFacetComplexConditions ;

    /**
     * Prepares a join complex facet.
     *
     * @param string $key The facet key (also the default main-side join property).
     * @param mixed $value The object of `field: condition` pairs tested on the joined document.
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`AQL::COLLECTION`, `AQL::KEY`, `Facet::PROPERTY`, `AQL::ARRAY`).
     * @param string $doc The main document reference.
     *
     * @return string
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws ValidationException
     *
     * @example
     * Reverse one-to-many — the joined documents reference the main one :
     * ```php
     * Arango::FACETS =>
     * [
     *     Prop::COMMENTS =>
     *     [
     *         Facet::TYPE     => Facet::JOIN_COMPLEX ,
     *         AQL::COLLECTION => 'comments' ,
     *         AQL::KEY        => 'articleId' ,  // joined side (default _key)
     *         Facet::PROPERTY => '_key'         // main side  (default the facet key)
     *     ]
     * ]
     * ```
     * One-to-one — the main document holds the foreign key (defaults: KEY=_key, PROPERTY=facet key) :
     * ```php
     * Prop::PLACE => [ Facet::TYPE => Facet::JOIN_COMPLEX , AQL::COLLECTION => 'places' ]
     * ```
     * One-to-many by array — the main document holds an array of keys :
     * ```php
     * Prop::TAGS => [ Facet::TYPE => Facet::JOIN_COMPLEX , AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' ]
     * ```
     * Use the facet (sub-fields behave exactly like EDGE_COMPLEX) :
     * ```
     * ?facets={"comments":{"status":"approved"}}                 // a comment with status == approved
     * ?facets={"comments":{"status":"approved","score":"5"}}     // status == approved AND score == 5
     * ?facets={"comments":{"status":["approved","featured"]}}    // status == approved OR featured
     * ?facets={"comments":{"status":"-spam"}}                    // a comment whose status != spam
     * ```
     */
    protected function prepareFacetJoinComplex( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
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

        // Sub-field conditions, shared with EDGE_COMPLEX.
        $filters = $this->prepareComplexConditions( $value , $docRef , $key , $binds ) ;

        // LENGTH( FOR doc_$key IN <collection> FILTER <match> && ...filters RETURN 1 ) > 0
        return greaterThan( length
        ([
            aqlFor    ( [ AQL::DOC_REF => $docRef , AQL::IN => $collection ] ) ,
            aqlFilter ( predicates( [ $match , ...$filters ] , Logic::AND ) ) ,
            aqlReturn ( 1 )
        ]) , 0 ) ;
    }
}
