<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use oihana\exceptions\ValidationException;
use org\schema\constants\Prop;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\alterExpression;
use function oihana\arango\db\helpers\resolveAltSides;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;
use function oihana\core\strings\predicate;
use function oihana\core\strings\predicates;

/**
 * Shared builder for the "simple" existential facets ({@see HasFacetEdge},
 * {@see HasFacetJoin}): both keep documents that have at least one related
 * document (reached by an edge traversal or a key-join) whose field matches the
 * requested value.
 *
 * Only the iteration source differs (an `INBOUND` traversal vs a `FOR` over a
 * collection plus a join condition), so the value-matching logic lives here:
 *
 * - the comparison operator is configurable (`Facet::OP`, default `eq`,
 *   reusing {@see FilterComparator}: `eq`, `ne`, `gt`, `ge`, `lt`, `le`,
 *   `like`, `match`, …), and may be overridden per request with `{op, val}`;
 * - one or more fields can be searched (`AQL::FIELDS`, CSV or list, default
 *   `_key`); a value matches when ANY of the fields satisfies the operator (OR);
 * - comma-separated values are OR-ed; a leading `-` negates a value, excluding
 *   the document via a `LENGTH(...) == 0` clause.
 *
 * @see FacetTrait The aggregate that composes the facet builders.
 */
trait HasFacetSimpleConditions
{
    /**
     * Builds a simple existential facet expression.
     *
     * Positive values are matched inside a `LENGTH(FOR … FILTER [prefix &&]
     * (… OR …) …) > 0` clause; negated values are excluded with a twin
     * `LENGTH(…) == 0` clause; both are AND-ed (and parenthesized) when present.
     *
     * @param mixed $value The facet value: a scalar, a CSV string, a list, or an `{op, val}` object.
     * @param array $facet The facet definition (`Facet::OP`, `AQL::FIELDS`).
     * @param string $forSource The compiled `FOR …` source (traversal or collection).
     * @param string|null $prefix An extra condition AND-ed inside the FILTER (e.g. a join match), or null.
     * @param string $docRef The related-document variable (e.g. `doc_location`).
     * @param string $key The facet key, used to namespace the bind names.
     * @param array $binds The bind variables, populated by reference.
     * @param string $return The compiled `RETURN …` clause.
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareSimpleConditions
    (
        mixed   $value ,
        array   $facet ,
        string  $forSource ,
        ?string $prefix ,
        string  $docRef ,
        string  $key ,
        array   &$binds ,
        string  $return
    )
    :string
    {
        $op  = $facet[ Facet::OP  ] ?? FilterComparator::EQ ;
        $alt = $facet[ Facet::ALT ] ?? null ;

        // {op, val, alt} request object overrides the configured operator / alt.
        if( is_array( $value ) && !array_is_list( $value ) )
        {
            $op  = $value[ FilterParam::OP  ] ?? $op ;
            $alt = $value[ FilterParam::ALT ] ?? $alt ;
            if( !array_key_exists( FilterParam::VAL , $value ) )
            {
                return Char::EMPTY ;
            }
            $value = $value[ FilterParam::VAL ] ;
        }

        $comparator = FilterComparator::getAlias( $op , Comparator::EQUAL ) ;

        // `alt` wraps the compared field (left) and/or the bound value (right):
        // alt:{ key:.. , val:.. } or val:true mirror. Legacy string/list = key only.
        [ $keyChain , $valChain ] = resolveAltSides( $alt ) ;

        $fields = $facet[ AQL::FIELDS ] ?? Prop::_KEY ;
        $fields = is_array( $fields ) ? $fields : explode( Char::COMMA , (string) $fields ) ;

        // Values are matched as strings (keys / labels); typed comparisons live
        // in the complex facets. A string is split on commas, a list is kept.
        $values = is_array( $value ) ? array_values( $value ) : explode( Char::COMMA , (string) $value ) ;

        $positives = [] ;
        $negatives = [] ;

        foreach( $values as $index => $item )
        {
            $negative = is_string( $item ) && strlen( $item ) > 1 && $item[ 0 ] === Char::HYPHEN ;
            $item     = $negative ? ltrim( $item , Char::HYPHEN ) : $item ;
            $bind     = alterExpression( $this->bind( $item , $binds , $key . Char::UNDERLINE . $index ) , $valChain ) ;

            $group = [] ;
            foreach( $fields as $field )
            {
                $group[] = predicate( alterExpression( key( $field , $docRef ) , $keyChain ) , $comparator , $bind ) ;
            }
            $term = count( $group ) > 1 ? betweenParentheses( predicates( $group , Logic::OR ) ) : $group[ 0 ] ;

            if( $negative ) { $negatives[] = $term ; }
            else            { $positives[] = $term ; }
        }

        $clauses = [] ;

        if( !empty( $positives ) )
        {
            $clauses[] = greaterThan( $this->simpleLength( $forSource , $prefix , $positives , $return ) , 0 ) ;
        }

        if( !empty( $negatives ) )
        {
            $clauses[] = equal( $this->simpleLength( $forSource , $prefix , $negatives , $return ) , 0 ) ;
        }

        if( empty( $clauses ) )
        {
            return Char::EMPTY ;
        }

        $result = predicates( $clauses , Logic::AND ) ;

        return count( $clauses ) > 1 ? betweenParentheses( $result ) : $result ;
    }

    /**
     * Wraps a set of OR-ed term groups into `LENGTH(FOR … FILTER [prefix &&] (…) RETURN …)`.
     *
     * @param string $forSource
     * @param string|null $prefix
     * @param array<int,string> $groups
     * @param string $return
     *
     * @return string
     */
    private function simpleLength( string $forSource , ?string $prefix , array $groups , string $return ) :string
    {
        $disjunction = predicates( $groups , Logic::OR ) ;

        if( $prefix !== null )
        {
            $filter = aqlFilter( [ $prefix , count( $groups ) > 1 ? betweenParentheses( $disjunction ) : $disjunction ] ) ;
        }
        else
        {
            $filter = aqlFilter( $disjunction ) ;
        }

        return length( [ $forSource , $filter , $return ] ) ;
    }
}
