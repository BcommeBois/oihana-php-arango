<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\exceptions\UnsupportedOperationException;
use ReflectionException;

use org\schema\constants\Prop;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\arango\models\enums\Facet;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Builds the AQL filter fragment for an {@see Facet::EDGE_COMPLEX} facet: like
 * {@see HasFacetEdge} it keeps documents linked through an inbound edge
 * traversal, but matches SEVERAL fields on the same target vertex (AND), each
 * field accepting multiple values (OR) and per-value negation. Composed into
 * the model via {@see FacetTrait}.
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 */
trait HasFacetEdgeComplex
{
    use HasFacetComplexConditions ;

    /**
     * Prepares an edge complex facet.
     *
     * The value is an object of `field: condition` pairs, ALL matched on the
     * SAME inbound vertex (AND). Each field condition may be a single value, a
     * list of values (OR), and any value may be negated with a leading `-`.
     * Because every condition applies to the same traversed vertex, negation
     * stays inline (`!=`) inside the existential traversal — `{value:"-459"}`
     * keeps documents linked to a vertex whose `value != 459`, which differs
     * from the plain {@see HasFacetEdge} facet's "not linked to" semantics.
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
     * @throws UnsupportedOperationException
     * @throws ValidationException
     *
     * @example
     * Set the facetable definition in the model :
     * ```php
     * Arango::FACETS =>
     * [
     *     Prop::NUMBERS =>
     *     [
     *         Facet::TYPE => Facet::EDGE_COMPLEX,
     *         Facet::EDGE => 'livestocks_has_numbers'
     *     ]
     * ]
     * ```
     *
     * Use the facet :
     * ```
     * ?facets={"numbers":{"value":"459875642"}}            // value == 459875642
     * ?facets={"numbers":{"value":"459","kind":"ear"}}     // value == 459 AND kind == ear (same vertex)
     * ?facets={"numbers":{"value":["459","460"]}}          // value == 459 OR value == 460
     * ?facets={"numbers":{"value":"-459","kind":"ear"}}    // value != 459 AND kind == ear (same vertex)
     * ?facets={"numbers":{"value":["459","-460"]}}         // value == 459 AND value != 460
     * ```
     */
    protected function prepareFacetEdgeComplex
    (
        string    $key ,
        mixed   $value ,
        array  &$binds ,
        array   $facet ,
        string    $doc
    )
    :string
    {
        $docRef  = AQL::DOC_PREFIX . $key ;
        $edge    = $facet[ AQL::EDGE ] ?? null ;

        // Each field condition applies to the SAME traversed vertex, so all
        // fields (and any per-value negation) stay inside one existential
        // traversal — see HasFacetComplexConditions, shared with JOIN_COMPLEX.
        // A facet-wide Facet::ALT wraps every sub-field comparison symmetrically.
        $filters = $this->prepareComplexConditions( $value , $docRef , $key , $binds , $facet[ Facet::ALT ] ?? null ) ;

        // LENGTH( FOR doc_$key IN INBOUND $doc $edge FILTER ...$filters RETURN doc_$key._key ) > 0
        return greaterThan( length
        ([
            aqlFor    ( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] )    ,
            aqlFilter ( predicates( $filters ,  Logic::AND ) ) ,
            aqlReturn ( key( Prop::_KEY , $docRef ) )
        ]) , 0 ) ;
    }
}