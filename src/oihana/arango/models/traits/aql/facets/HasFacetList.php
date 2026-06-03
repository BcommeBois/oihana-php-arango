<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\exceptions\BindException;

/**
 * Builds the AQL filter fragment for a {@see Facet::LIST} facet. Kept as a thin
 * alias over the {@see HasFacetIn} primitive (operator defaults to `any.in`),
 * preserving the historical type name. Composed into the model via
 * {@see FacetTrait}.
 *
 * @see HasFacetIn::prepareFacetIn() The membership primitive this delegates to.
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 */
trait HasFacetList
{
    /**
     * Prepares a list facet (array membership, `ANY IN` by default).
     *
     * Accepts a CSV string, a list, or an `{op, val}` object selecting the
     * operator per request ({@see FilterArrayComparator}).
     *
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     *
     * @return string
     *
     * @throws BindException
     *
     * @example
     * Set the facetable definition in the model :
     * ```
     * AQL::FACETABLE =>
     * [
     *     Prop::KEYWORDS =>
     *     [
     *         Facet::TYPE     => Facet::LIST ,
     *         Facet::PROPERTY => Prop::KEYWORDS
     *     ]
     * ]
     * ```
     * Use the facet (array membership tested against `doc.keywords`) :
     * ```
     * ?facets={"keywords":"key1,key2"}                        // ANY IN  : has key1 OR key2
     * ?facets={"keywords":["key1","key2"]}                    // ANY IN  : array form, same result
     * ?facets={"keywords":{"op":"all.in","val":"key1,key2"}}  // ALL IN  : has BOTH
     * ?facets={"keywords":{"op":"none.in","val":["key1"]}}    // NONE IN : has NEITHER
     * ```
     * Generated AQL (default `any.in`) :
     * ```aql
     * TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords
     * ```
     */
    protected function prepareFacetList( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetIn( $key , $value , $binds , $facet , $doc ) ;
    }
}
