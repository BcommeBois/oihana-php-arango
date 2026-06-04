<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\facets\FacetAggregator;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\greaterThan;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\func;
use function oihana\core\strings\key;
use function oihana\core\strings\predicate;
use function oihana\core\strings\predicates;

/**
 * Shared builder for the "aggregate" facets ({@see HasFacetEdgeAggregate},
 * {@see HasFacetJoinAggregate}): both keep documents whose related documents
 * (reached by an edge traversal or a key-join) satisfy an aggregate condition
 * over a numeric field — `AGG(FOR … [FILTER join] RETURN related.field) <op> @threshold`.
 *
 * Only the iteration source differs (an `INBOUND` traversal vs a `FOR` over a
 * collection plus a join condition), so the aggregation logic lives here. It
 * generalizes the existential `LENGTH(FOR … RETURN …) > 0` clause used by the
 * simple/complex facets: `count` + `op:gt` + `val:0` reproduces it exactly.
 *
 * The facet is driven by `{agg, field, op, val}`, each piece overridable per
 * request (URL) and falling back to the definition:
 *
 * - `agg`   — the aggregator ({@see FacetAggregator}: `avg`, `sum`, `min`,
 *   `max`, `count`); definition default `Facet::AGG`, global default `count`;
 * - `field` — the related numeric attribute to aggregate; definition default
 *   `AQL::FIELDS`; ignored by `count` (which returns `1`); URL-provided names
 *   are validated with {@see assertAttributeName()} against AQL injection;
 * - `op`    — the threshold comparator ({@see FilterComparator}: `ge`, `gt`,
 *   `le`, `lt`, `eq`, `ne`); definition default `Facet::OP`, global default `ge`;
 * - `val`   — the threshold; REQUIRED (absent ⇒ the facet is skipped). A bare
 *   scalar facet value is read as the threshold directly.
 *
 * Neither `-` negation (the `op` already carries the direction) nor `alt`
 * (field and threshold are numeric) apply to aggregate facets.
 *
 * The aggregate is guarded by `LENGTH(FOR … RETURN 1) > 0`, so an aggregate
 * facet only ever matches documents that have AT LEAST ONE related document.
 * This avoids the AQL empty-set surprise: `AVERAGE([])`/`MIN([])`/`MAX([])`
 * yield `null` (and `SUM([])`/`COUNT([])` yield `0`), and since `null` sorts
 * below every number, a `lt`/`le` threshold would otherwise spuriously match
 * documents with no related document at all.
 *
 * @see FacetTrait The aggregate that composes the facet builders.
 */
trait HasFacetAggregateConditions
{
    /**
     * Builds an aggregate facet expression.
     *
     * Resolves the aggregator, field, comparator and threshold (URL overriding
     * the definition), then emits
     * `AGG(FOR … [FILTER prefix] RETURN related.field) <comparator> @<key>_0`.
     *
     * @param mixed $value The facet value: a scalar threshold, or an `{agg, field, op, val}` object.
     * @param array $facet The facet definition (`Facet::AGG`, `AQL::FIELDS`, `Facet::OP`).
     * @param string $forSource The compiled `FOR …` source (traversal or collection).
     * @param string|null $prefix An extra condition AND-ed inside the FILTER (e.g. a join match), or null.
     * @param string $docRef The related-document variable (e.g. `doc_comments`).
     * @param string $key The facet key, used to namespace the bind name.
     * @param array $binds The bind variables, populated by reference.
     *
     * @return string The AQL fragment, or an empty string when no threshold is supplied.
     *
     * @throws BindException
     * @throws ValidationException When the aggregator is unknown or a non-count aggregate has no valid field.
     */
    protected function prepareAggregateConditions
    (
        mixed   $value ,
        array   $facet ,
        string  $forSource ,
        ?string $prefix ,
        string  $docRef ,
        string  $key ,
        array   &$binds
    )
    :string
    {
        $agg   = $facet[ Facet::AGG  ] ?? FacetAggregator::COUNT ;
        $field = $facet[ AQL::FIELDS ] ?? null ;
        $op    = $facet[ Facet::OP   ] ?? FilterComparator::GE ;

        // {agg, field, op, val} request object overrides the configured defaults;
        // a bare scalar is read as the threshold directly.
        if( is_array( $value ) && !array_is_list( $value ) )
        {
            $agg   = $value[ FilterParam::AGG   ] ?? $agg ;
            $field = $value[ FilterParam::FIELD ] ?? $field ;
            $op    = $value[ FilterParam::OP    ] ?? $op ;
            if( !array_key_exists( FilterParam::VAL , $value ) )
            {
                return Char::EMPTY ;
            }
            $value = $value[ FilterParam::VAL ] ;
        }

        if( $value === null || $value === Char::EMPTY )
        {
            return Char::EMPTY ;
        }

        $function = FacetAggregator::getAlias( $agg ) ;
        if( $function === null )
        {
            throw new ValidationException( "Unsupported facet aggregator '" . $agg . "'." ) ;
        }

        // `count` is field-less (RETURN 1); every other aggregator needs a
        // validated related field (RETURN related.<field>).
        if( $agg === FacetAggregator::COUNT )
        {
            $return = aqlReturn( 1 ) ;
        }
        else
        {
            $field = is_array( $field ) ? ( $field[ 0 ] ?? null ) : $field ;
            assertAttributeName( $field ) ; // guard the (possibly URL-provided) field against AQL injection
            $return = aqlReturn( key( $field , $docRef ) ) ;
        }

        $filter     = $prefix !== null ? aqlFilter( $prefix ) : Char::EMPTY ;
        $aggregate  = func( $function , compile( [ $forSource , $filter , $return ] ) ) ;
        $threshold  = is_numeric( $value ) ? $value + 0 : $value ;
        $bind       = $this->bind( $threshold , $binds , $key . Char::UNDERLINE . '0' ) ;
        $comparator = FilterComparator::getAlias( $op , Comparator::GREATER_THAN_OR_EQUAL ) ;
        $comparison = predicate( $aggregate , $comparator , $bind ) ;

        // Only match documents having AT LEAST ONE related document, so a `lt`/
        // `le` threshold never lets the empty-set sentinel (`null` for avg/min/
        // max, `0` for sum/count) slip through.
        $guard = greaterThan( length( compile( [ $forSource , $filter , aqlReturn( 1 ) ] ) ) , 0 ) ;

        return betweenParentheses( predicates( [ $guard , $comparison ] , Logic::AND ) ) ;
    }
}
