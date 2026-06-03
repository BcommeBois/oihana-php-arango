<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\exceptions\BindException;

/**
 * Builds the AQL filter fragment for an {@see Facet::LIST_FIELD} (and
 * {@see Facet::LIST_FIELD_SORTED}) facet. Kept as a thin alias over the
 * {@see HasFacetIn} primitive (operator defaults to `any.in`), preserving the
 * historical type names. Composed into the model via {@see FacetTrait}.
 *
 * @see HasFacetIn::prepareFacetIn() The membership primitive these delegate to.
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 */
trait HasFacetListField
{
    /**
     * Prepares a list field facet (array membership, `ANY IN` by default).
     *
     * Historical alias of {@see HasFacetIn::prepareFacetIn()} — see that method
     * for the full operator catalogue (`any.in`, `all.in`, `none.in`, …).
     *
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     * @param bool $sortable
     *
     * @return string
     *
     * @throws BindException
     *
     * @example
     * Set the facetable definition in the model :
     * ```php
     * Arango::FACETS =>
     * [
     *     Prop::KEYWORDS =>
     *     [
     *         Facet::TYPE     => Facet::LIST_FIELD ,
     *         Facet::PROPERTY => Prop::KEYWORDS
     *     ]
     * ]
     * ```
     * Use the facet (array membership tested against `doc.keywords`) :
     * ```
     * ?facets={"keywords":"cuisine,jardin"}                        // ANY IN  : has cuisine OR jardin
     * ?facets={"keywords":["cuisine","jardin"]}                    // ANY IN  : array form, same result
     * ?facets={"keywords":{"op":"all.in","val":"cuisine,jardin"}}  // ALL IN  : has BOTH
     * ?facets={"keywords":{"op":"none.in","val":"cuisine"}}        // NONE IN : has NEITHER
     * ```
     * Generated AQL (default `any.in`) :
     * ```aql
     * TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords
     * ```
     */
    protected function prepareFacetListField( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        return $this->prepareFacetIn( $key , $value , $binds , $facet , $doc , $sortable ) ;
    }

    /**
     * Prepares a sortable list field facet: same membership as
     * {@see prepareFacetListField()}, plus a `SORT POSITION(...)` clause that
     * ranks the matched documents by the order of the requested values (a value
     * appearing first in the request sorts first).
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
     * ```php
     * Arango::FACETS =>
     * [
     *     Prop::TAGS =>
     *     [
     *         Facet::TYPE     => Facet::LIST_FIELD_SORTED ,
     *         Facet::PROPERTY => Prop::TAGS
     *     ]
     * ]
     * ```
     * Use the facet — keep articles tagged `featured`, `new` or `sale`, ranked
     * in that priority order :
     * ```
     * ?facets={"tags":"featured,new,sale"}
     * ?facets={"tags":["featured","new","sale"]}
     * ```
     * Generated AQL :
     * ```aql
     * TO_ARRAY([@tags_0,@tags_1,@tags_2]) ANY IN doc.tags SORT POSITION([@tags_0,@tags_1,@tags_2],doc.tags,true)
     * ```
     */
    protected function prepareFacetListFieldSorted( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetIn( $key , $value , $binds , $facet , $doc , true ) ;
    }
}
