<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;
use oihana\exceptions\BindException;

use org\schema\constants\Prop;

use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\in;
use function oihana\core\strings\key;

/**
 * Builds the AQL filter fragment for a {@see Facet::JOIN} facet: the key-join
 * counterpart of {@see HasFacetEdge}. Instead of an edge traversal, it joins a
 * collection by attribute equality and keeps documents that have at least one
 * joined document whose field matches the requested value.
 *
 * Like EDGE, the match is driven by {@see HasFacetSimpleConditions} — a
 * configurable operator (`Facet::OP`, default `eq`) over one or more joined-doc
 * fields (`AQL::FIELDS`, default `_key`), with multi-value OR and `-` negation.
 *
 * Difference with {@see HasFacetJoinComplex}: JOIN is "simple" — the value is a
 * scalar/CSV matched against one field (or several in OR). JOIN_COMPLEX takes an
 * object `{field: condition}` and matches SEVERAL fields AND-ed on the same
 * joined document.
 *
 * The join itself is `doc_join.<KEY> == doc.<PROPERTY>` (or `IN` when
 * `AQL::ARRAY` is set), with `AQL::KEY` the joined side (default `_key`) and
 * `Facet::PROPERTY` the main side (default the facet key).
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 * @see HasFacetSimpleConditions   The shared value-matching logic.
 */
trait HasFacetJoin
{
    use HasFacetSimpleConditions ;

    /**
     * Prepares a simple join facet.
     *
     * @param string $key The facet key (also the default main-side join property).
     * @param mixed $value A scalar, CSV string, list, or `{op, val}` object matched against the joined field(s).
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`AQL::COLLECTION`, `AQL::KEY`, `Facet::PROPERTY`, `AQL::ARRAY`, `AQL::FIELDS`, `Facet::OP`).
     * @param string $doc The main document reference.
     *
     * @return string
     *
     * @throws BindException
     *
     * @example
     * Filter posts by their author's name (join authors on post.authorId == author._key) :
     * ```php
     * AQL::FACETABLE =>
     * [
     *     Prop::AUTHOR =>
     *     [
     *         Facet::TYPE     => Facet::JOIN ,
     *         AQL::COLLECTION => 'authors' ,
     *         Facet::PROPERTY => 'authorId' , // main side  (default the facet key)
     *         AQL::KEY        => '_key' ,      // joined side (default _key)
     *         AQL::FIELDS     => 'name'        // searched field(s), default _key
     *     ]
     * ]
     * ```
     * ```
     * ?facets={"author":"alice"}        // a joined author whose name == alice
     * ?facets={"author":"alice,bob"}    // name == alice OR bob
     * ?facets={"author":"-spammer"}     // exclude posts joined to author "spammer"
     * ?facets={"author":{"op":"like","val":"al"}}  // name LIKE @al
     * ```
     */
    protected function prepareFacetJoin( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
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

        $for    = aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => $collection ] ) ;
        $return = aqlReturn( 1 ) ;

        return $this->prepareSimpleConditions( $value , $facet , $for , $match , $docRef , $key , $binds , $return ) ;
    }
}
