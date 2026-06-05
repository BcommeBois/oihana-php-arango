<?php

namespace oihana\arango\models\traits\aql\facets;

use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\Facet;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\resolveAltSides;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\key;
use function oihana\core\strings\predicate;
use function oihana\core\strings\predicates;

/**
 * Builds the AQL filter fragment for a {@see Facet::ARRAY_COMPLEX} facet: it
 * keeps documents whose embedded array property holds at least one element
 * matching the requested sub-field conditions. Composed into the model via
 * {@see FacetTrait}.
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 */
trait HasFacetArrayComplex
{
    /**
     * Prepares an array complex facet.
     *
     * Keeps documents whose embedded array property (`$docRef.$key`) holds at
     * least one element matching the requested sub-field conditions, expressed
     * as an existential traversal `LENGTH(FOR e IN $docRef.$key FILTER ... ) > 0`.
     * The value is an object of `subField: condition` pairs; each condition may
     * be a single value or a list, and any value may be negated with a leading
     * `-` (`!=`). Negation is inline in the existential, so it keeps documents
     * having an element that does NOT equal the value (not "exclude the value").
     *
     * @param string $key    The facet key, also the embedded array property name.
     * @param mixed  $value  The sub-field conditions (object of value or list).
     * @param array  $binds  The bind variables, populated by reference.
     * @param array  $facet  The facet definition (reads a facet-wide `Facet::ALT`, applied to every sub-field).
     * @param string $docRef The document reference the array is read from (default `doc`).
     *
     * @return string
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws ValidationException
     * @throws UnsupportedOperationException
     *
     * @example
     * Set the facetable definition in the model :
     * ```
     * Arango::FACETS =>
     * [
     *     Prop::WORKSHOPS =>
     *     [
     *         Facet::TYPE => Facet::ARRAY_COMPLEX
     *     ]
     * ]
     * ```
     * Use the facet :
     * ```
     * ?facets={"workshops":{"breeding.alternateName":"pig"}}            // an element with breeding.alternateName == pig
     * ?facets={"workshops":{"breeding.alternateName":["pig","cattle"]}} // an element == pig OR == cattle
     * ?facets={"workshops":{"breeding.alternateName":["-pig","cattle"]}}// an element != pig AND != cattle
     * ```
     */
    protected function prepareFacetArrayComplex( string $key , mixed $value , array &$binds , array $facet = [] , string $docRef = AQL::DOC ) :string
    {
        // A facet-wide Facet::ALT wraps every sub-field (left) and bound value
        // (right) symmetrically; legacy string/list alt wraps the field only.
        [ $keyChain , $valChain ] = resolveAltSides( $facet[ Facet::ALT ] ?? null ) ;

        $filter = [] ;
        foreach( $value as $subKey => $s )
        {
            assertAttributeName( $subKey ) ; // guard the URL-provided sub-field against AQL injection
            $search = preg_replace( '/\./' , Char::UNDERLINE , $key . Char::UNDERLINE . $subKey ) ;
            $field  = alterExpression( key( $subKey , AQL::DOC_PREFIX . $key ) , $keyChain ) ; // [alt] doc_$key.$subKey
            if( is_array( $s ) && !empty( $s ) ) // test negative and multiple
            {
                $i = 0 ;
                $subFilter = [] ;
                $negative  = false ;
                foreach( $s as $si )
                {
                    // test negative
                    if( is_string( $si ) && $si[0] == Char::HYPHEN )
                    {
                        $si = substr( $si , 1 ) ;
                        $negative = true ;
                    }
                    elseif( is_int( $si ) && $si < 0 )
                    {
                        $si = abs( $si ) ;
                        $negative = true ;
                    }
                    $subSearch = $search . $i ;
                    $binds[ $subSearch ] = $si ;
                    $subFilter[] = predicate
                    (
                        $field ,
                        $negative ? Comparator::NOT_EQUAL : Comparator::EQUAL ,
                        alterExpression( $this->bind( $si , $binds , $subSearch ) , $valChain )
                    ) ;
                    $i++ ;
                }
                $filter[] = predicates( $subFilter , $negative ? Logic::AND : Logic::OR ) ;
            }
            else
            {
                if( is_string( $s ) && $s[0] == Char::HYPHEN ) // test negative
                {
                    $s = substr( $s ,1 ) ;
                    $comparator = Comparator::NOT_EQUAL ;
                }
                elseif( is_int( $s ) && $s < 0 )
                {
                    $s = abs( $s ) ;
                    $comparator = Comparator::NOT_EQUAL ;
                }
                else
                {
                    $comparator = Comparator::EQUAL ;
                }

                $filter[] = predicate
                (
                    $field ,
                    $comparator ,
                    alterExpression( $this->bind( $s , $binds , $search ) , $valChain )
                ) ;
            }
        }
        // LENGTH( FOR doc_$key IN $docRef.$key FILTER cond1 && ... RETURN 1 ) > 0
        return greaterThan( length
        ([
            aqlFor    ( [ AQL::DOC_REF => AQL::DOC_PREFIX . $key , AQL::IN => key( $key , $docRef ) ] ) ,
            aqlFilter ( predicates( $filter ,  Logic::AND ) ) ,
            aqlReturn ( 1 )
        ]) , 0 ) ;
    }
}