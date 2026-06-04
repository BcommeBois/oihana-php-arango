<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\Logic;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\notEqual;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * Shared builder for the per-field conditions of the "complex" existential
 * facets ({@see HasFacetEdgeComplex}, {@see HasFacetJoinComplex}): both match
 * SEVERAL fields on the same related document, each field accepting a single
 * value, a list (OR) and per-value negation (`-` => `!=`).
 *
 * Only the iteration source differs between those facets (an edge traversal vs
 * a key-join), so the FILTER conditions are produced once, here.
 *
 * @see FacetTrait The aggregate that composes the facet builders.
 */
trait HasFacetComplexConditions
{
    /**
     * Builds the list of AQL conditions for an object of `field: condition`
     * pairs, each tested on the related document `$docRef`.
     *
     * Every condition applies to the same related document, so per-value
     * negation stays inline (`!=`) and a field given an array OR-es its values
     * (flipping to AND when a negative term is present, mirroring HasFacetField).
     * Each sub-field name is validated with {@see assertAttributeName()} before
     * being interpolated, guarding against AQL injection.
     *
     * A facet-wide `alt` (from the definition) wraps EVERY sub-field comparison
     * symmetrically — its key-side chain wraps each related field, its value-side
     * chain wraps each bound value (e.g. `LOWER(v.value) == LOWER(@0)`). Per
     * sub-field `alt` is not (yet) supported — see {@see static::prepareComplexConditions()}.
     *
     * @param mixed $value The object of `field: condition` pairs.
     * @param string $docRef The related-document variable (e.g. `doc_numbers`).
     * @param string $key The facet key, used to namespace the bind names.
     * @param array $binds The bind variables, populated by reference.
     * @param mixed $alt The facet-wide `alt` chain (from `Facet::ALT`), applied to every sub-field; null for none.
     *
     * @return array<int,string> The AQL conditions (to AND together).
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareComplexConditions( mixed $value , string $docRef , string $key , array &$binds , mixed $alt = null ) :array
    {
        // A facet-wide alt wraps every sub-field (left) and bound value (right);
        // legacy string/list alt only wraps the field. No per-sub-field alt yet.
        [ $keyChain , $valChain ] = $this->resolveAltSides( $alt ) ;

        $filters = [] ;

        foreach( $value as $subKey => $terms )
        {
            assertAttributeName( $subKey ) ; // guard the URL-provided sub-field against AQL injection
            $field = $this->alterExpression( key( $subKey , $docRef ) , $keyChain ) ;

            if( is_array( $terms ) )
            {
                $conditions = [] ;
                $logic      = Logic::OR ;
                foreach( $terms as $index => $term )
                {
                    $negative     = is_string( $term ) && $term !== Char::EMPTY && $term[ 0 ] === Char::HYPHEN ;
                    $term         = $negative ? ltrim( $term , Char::HYPHEN ) : $term ;
                    $bind         = $this->alterExpression( $this->bind( $term , $binds , $key . Char::UNDERLINE . $subKey . $index ) , $valChain ) ;
                    $conditions[] = $negative ? notEqual( $field , $bind ) : equal( $field , $bind ) ;
                    if( $negative ) { $logic = Logic::AND ; }
                }
                $filters[] = betweenParentheses( predicates( $conditions , $logic ) ) ;
            }
            else
            {
                $negative  = is_string( $terms ) && $terms !== Char::EMPTY && $terms[ 0 ] === Char::HYPHEN ;
                $terms     = $negative ? ltrim( $terms , Char::HYPHEN ) : $terms ;
                $bind      = $this->alterExpression( $this->bind( $terms , $binds , $key . Char::UNDERLINE . $subKey ) , $valChain ) ;
                $filters[] = $negative ? notEqual( $field , $bind ) : equal( $field , $bind ) ;
            }
        }

        return $filters ;
    }
}
