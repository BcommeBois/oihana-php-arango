<?php

namespace oihana\arango\models\traits\aql\facets;

use ReflectionException;

use org\schema\constants\Prop;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\arango\db\operators\notEqual;
use function oihana\core\strings\betweenParentheses;
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
     *
     * @example
     * Set the facetable definition in the model :
     * ```php
     * AQL::FACETABLE =>
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
        $filters = [] ;

        // Each field condition applies to the SAME traversed vertex, so all
        // fields (and any per-value negation) stay inside one existential
        // traversal: a leading '-' negates that field's value with `!=`.
        foreach( $value as $subKey => $terms )
        {
            $field = key( $subKey , $docRef ) ;

            if( is_array( $terms ) )
            {
                $conditions = [] ;
                $logic      = Logic::OR ;
                foreach( $terms as $index => $term )
                {
                    $negative     = is_string( $term ) && $term !== Char::EMPTY && $term[ 0 ] === Char::HYPHEN ;
                    $term         = $negative ? ltrim( $term , Char::HYPHEN ) : $term ;
                    $bind         = $this->bind( $term , $binds , $key . Char::UNDERLINE . $subKey . $index ) ;
                    $conditions[] = $negative ? notEqual( $field , $bind ) : equal( $field , $bind ) ;
                    if( $negative ) { $logic = Logic::AND ; }
                }
                $filters[] = betweenParentheses( predicates( $conditions , $logic ) ) ;
            }
            else
            {
                $negative  = is_string( $terms ) && $terms !== Char::EMPTY && $terms[ 0 ] === Char::HYPHEN ;
                $terms     = $negative ? ltrim( $terms , Char::HYPHEN ) : $terms ;
                $bind      = $this->bind( $terms , $binds , $key . Char::UNDERLINE . $subKey ) ;
                $filters[] = $negative ? notEqual( $field , $bind ) : equal( $field , $bind ) ;
            }
        }

        // LENGTH( FOR doc_$key IN INBOUND $doc $edge FILTER ...$filters RETURN doc_$key._key ) > 0
        return greaterThan( length
        ([
            aqlFor    ( [ AQL::DOC_REF => $docRef , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] )    ,
            aqlFilter ( predicates( $filters ,  Logic::AND ) ) ,
            aqlReturn ( key( Prop::_KEY , $docRef ) )
        ]) , 0 ) ;
    }
}