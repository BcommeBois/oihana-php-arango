<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;

use oihana\enums\Char;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\helpers\buildBetweenClauses;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\key;
use function oihana\core\strings\predicate;
use function oihana\core\strings\predicates;

/**
 * Builds the AQL filter fragment for a {@see Facet::FIELD} facet: a comparison
 * on a scalar document property `doc.<property>`, driven by an operator (`op`)
 * that reuses the filter vocabulary ({@see FilterComparator}: `eq`, `ne`, `gt`,
 * `ge`, `lt`, `le`, `like`, `nlike`, `match` default, `nmatch`).
 *
 * The compact multi-select syntax is preserved: comma-separated values are
 * OR-ed, and a leading `-` negates a value by switching the operator to its
 * negative counterpart (`match`→`nmatch`, `eq`→`ne`, `like`→`nlike`) — which
 * also flips the group to AND. Composed into the model via {@see FacetTrait}.
 *
 * @see FacetTrait::prepareFacets() The dispatcher that invokes this builder.
 */
trait HasFacetField
{
    /**
     * Prepares a field facet (scalar property comparison, `=~` match by default).
     *
     * The operator defaults to `match` (regex `=~`, backward compatible). It can
     * be set in the facet definition ({@see Facet::OP}) or overridden per request
     * with an `{op, val}` object. Operator codes reuse {@see FilterComparator}.
     *
     * @param string $key The facet key (also the default document property).
     * @param mixed $value A CSV string, a list, or an `{op, val}` object selecting the operator per request.
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`Facet::PROPERTY`, `Facet::OP`).
     * @param string $doc The document reference the property is read from.
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     *
     * @example
     * Set the facetable definition in the model (operator optional, defaults to
     * `match`; it can also be overridden per request) :
     * ```php
     * Arango::FACETS =>
     * [
     *     Prop::WITH_STATUS => [ Facet::TYPE => Facet::FIELD ] ,                                  // =~ (default)
     *     Prop::ID          => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => '_key' , Facet::OP => FilterComparator::EQ ] , // ==
     *     Prop::PRICE       => [ Facet::TYPE => Facet::FIELD , Facet::OP => FilterComparator::GE ] // >=
     * ]
     * ```
     * Default operator — regex match, comma-separated values are OR-ed :
     * ```
     * ?facets={"withStatus":"under_review"}            // (doc.withStatus =~ @0)
     * ?facets={"withStatus":"draft,under_review"}      // (doc.withStatus =~ @0 || doc.withStatus =~ @1)
     * ```
     * Negation — a leading `-` switches to the negative operator and ANDs the group :
     * ```
     * ?facets={"withStatus":"-draft"}                  // (doc.withStatus !~ @0)
     * ?facets={"withStatus":"-under_review,-draft"}    // (doc.withStatus !~ @0 && doc.withStatus !~ @1)
     * ```
     * Pick the operator per request with `{op, val}` :
     * ```
     * ?facets={"withStatus":{"op":"eq","val":"draft"}} // (doc.withStatus == @0)   exact, not regex
     * ?facets={"price":{"op":"ge","val":100}}          // (doc.price >= @0)
     * ?facets={"name":{"op":"like","val":"jo%"}}       // (doc.name LIKE @0)
     * ?facets={"withStatus":{"op":"eq","val":"-draft"}}// (doc.withStatus != @0)   negation, generic
     * ```
     * Supported operators: `eq`, `ne`, `gt`, `ge`, `lt`, `le`, `like`, `nlike`,
     * `match` (default), `nmatch`. An unknown op falls back to `match`.
     */
    protected function prepareFacetField( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        $op  = $facet[ Facet::OP  ] ?? FilterComparator::MATCH ;
        $alt = $facet[ Facet::ALT ] ?? null ;

        // {op, val, alt} request object overrides the configured operator / alt.
        if( is_array( $value ) && !array_is_list( $value ) )
        {
            $op  = $value[ FilterParam::OP  ] ?? $op ;
            $alt = $value[ FilterParam::ALT ] ?? $alt ;

            // `between` carries min/max instead of val — handle it before the val guard.
            if( $op === FilterComparator::BETWEEN )
            {
                return $this->prepareFacetFieldBetween( $key , $value , $binds , $facet , $doc , $alt ) ;
            }

            if( !array_key_exists( FilterParam::VAL , $value ) )
            {
                return Char::EMPTY ;
            }
            $value = $value[ FilterParam::VAL ] ;
        }

        // A string is split on commas (multi-select); a scalar (int/float/bool)
        // is kept with its type so numeric comparisons bind a number, not a
        // string (`doc.price >= 100`, never `>= "100"` which AQL types wrong).
        $values = match( true )
        {
            is_array ( $value ) => array_values( $value ) ,
            is_string( $value ) => explode( Char::COMMA , $value ) ,
            default             => [ $value ] ,
        } ;

        if( empty( $values ) )
        {
            return Char::EMPTY ;
        }

        $property = $facet[ Facet::PROPERTY ] ?? $key ;
        $negated  = $this->negatedComparator( $op ) ;

        // `alt` wraps the compared field (left) and/or the bound value (right):
        // alt:{ key:.. , val:.. } or val:true mirror. Legacy string/list = key only.
        [ $keyChain , $valChain ] = $this->resolveAltSides( $alt ) ;
        $left = $this->alterExpression( key( $property , $doc ) , $keyChain ) ;

        $conditions = [] ;
        $logic      = Logic::OR ;

        foreach( $values as $index => $item )
        {
            $operator = $op ;

            // A leading `-` negates the value, but only when the operator has a
            // negative counterpart (otherwise `-` is kept, e.g. negative numbers).
            if( $negated !== null && is_string( $item ) && strlen( $item ) > 1 && $item[ 0 ] === Char::HYPHEN )
            {
                $item     = ltrim( $item , Char::HYPHEN ) ;
                $operator = $negated ;
                $logic    = Logic::AND ;
            }

            $comparator   = FilterComparator::getAlias( $operator , Comparator::MATCH ) ;
            $right        = $this->alterExpression( $this->bind( $item , $binds , $key . Char::UNDERLINE . $index ) , $valChain ) ;
            $conditions[] = predicate( $left , $comparator , $right ) ;
        }

        return betweenParentheses( predicates( $conditions , $logic ) ) ;
    }

    /**
     * Returns the negative counterpart of an operator code, or null when it has
     * none (ordering operators, already-negative operators, …).
     *
     * @param string $op
     *
     * @return ?string
     */
    private function negatedComparator( string $op ) :?string
    {
        return match( $op )
        {
            FilterComparator::MATCH => FilterComparator::NMATCH ,
            FilterComparator::EQ    => FilterComparator::NE ,
            FilterComparator::LIKE  => FilterComparator::NLIKE ,
            default                 => null ,
        } ;
    }

    /**
     * Builds an inclusive `between` (range) field facet: `(LEFT >= @min && LEFT <= @max)`.
     *
     * The compared property is alt-aware (the key-side chain wraps `doc.<property>`).
     * An omitted bound drops its side (one-sided range), mirroring the number/string
     * `?filter=` semantics; both omitted yields an empty fragment.
     *
     * @param string $key The facet key.
     * @param array $value The request object (`min`, `max`).
     * @param array $binds The bind variables, populated by reference.
     * @param array $facet The facet definition (`Facet::PROPERTY`).
     * @param string $doc The document reference.
     * @param mixed $alt The resolved `alt` parameter (request over definition).
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function prepareFacetFieldBetween( string $key , array $value , array &$binds , array $facet , string $doc , mixed $alt ) :string
    {
        [ $keyChain ] = $this->resolveAltSides( $alt ) ;
        $left = $this->alterExpression( key( $facet[ Facet::PROPERTY ] ?? $key , $doc ) , $keyChain ) ;

        $min = array_key_exists( FilterParam::MIN , $value ) ? $this->bind( $value[ FilterParam::MIN ] , $binds , $key . Char::UNDERLINE . FilterParam::MIN ) : null ;
        $max = array_key_exists( FilterParam::MAX , $value ) ? $this->bind( $value[ FilterParam::MAX ] , $binds , $key . Char::UNDERLINE . FilterParam::MAX ) : null ;

        return buildBetweenClauses( $left , $min , $max ) ;
    }
}
