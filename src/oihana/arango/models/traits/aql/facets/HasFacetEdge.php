<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\exceptions\BindException;
use org\schema\constants\Prop;
use ReflectionException;

use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Builds the AQL filter fragment for an {@see Facet::EDGE} facet: it keeps
 * documents linked (or, when negated, not linked) to a target vertex through an
 * inbound edge traversal. The match is driven by {@see HasFacetSimpleConditions}
 * — a configurable operator (`Facet::OP`, default `eq`) over one or more vertex
 * fields (`AQL::FIELDS`, default `_key`). Composed via {@see FacetTrait}.
 *
 * Matching several fields with `op = like` makes this facet a full-text-ish
 * lookup over a linked vocabulary (the former THESAURUS facet, now just an
 * EDGE configuration).
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 * @see HasFacetSimpleConditions   The shared value-matching logic.
 */
trait HasFacetEdge
{
    use HasFacetSimpleConditions ;

    /**
     * Prepares a facet condition with an edge definition.
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
     * @throws ReflectionException
     *
     * @example
     * Set the facetable definition in the model :
     * ```php
     * AQL::FACETABLE =>
     * [
     *     Prop::LOCATION =>
     *     [
     *        Facet::TYPE => Facet::EDGE ,
     *        Facet::EDGE => 'organizations_places'
     *     ]
     * ]
     * ```
     * Exact match on `_key` (default operator `eq`, single field) :
     * ```
     * ?facets={"location":1234}          // linked to vertex 1234
     * ?facets={"location":"1234,5678"}   // linked to 1234 OR 5678
     * ?facets={"location":"-1234"}       // NOT linked to 1234        (LENGTH(...) == 0)
     * ?facets={"location":"1234,-5678"}  // linked to 1234 AND not linked to 5678
     * ```
     * Search several vertex fields with `like` (the former THESAURUS) :
     * ```php
     * Prop::SUBJECTS =>
     * [
     *     Facet::TYPE => Facet::EDGE ,
     *     Facet::EDGE => 'has_subject' ,
     *     AQL::FIELDS => '_key,name,alternateName' , // OR across these fields
     *     Facet::OP   => 'like'                      // CONTAINS-like with % wildcards
     * ]
     * // ?facets={"subjects":"art"} => a linked subject whose _key, name OR alternateName LIKE @art
     * ```
     *
     * Values are comma-separated and OR-ed; a leading `-` negates a value,
     * excluding the document. See {@see HasFacetSimpleConditions} for the full
     * operator catalogue and multi-field behaviour.
     */
    protected function prepareFacetEdge( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        $docRef = AQL::DOC_PREFIX . $key ;
        $edge   = $facet[ AQL::EDGE ] ?? null ;

        $for    = aqlFor( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] ) ;
        $return = aqlReturn( key( Prop::_KEY , $docRef ) ) ;

        return $this->prepareSimpleConditions( $value , $facet , $for , null , $docRef , $key , $binds , $return ) ;
    }
}
