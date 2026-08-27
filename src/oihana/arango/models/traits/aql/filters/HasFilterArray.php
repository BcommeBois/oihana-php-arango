<?php

namespace oihana\arango\models\traits\aql\filters;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\functions\CheckFunction;
use oihana\arango\db\enums\Operator;
use oihana\enums\Char;
use oihana\arango\models\enums\filters\FilterArrayComparator;
use oihana\arango\models\enums\filters\FilterComparator;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use function oihana\arango\db\functions\arrays\arrayContains;
use function oihana\arango\db\functions\arrays\arrayFilter;
use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\helpers\buildCombinedInlineFilter;
use function oihana\arango\db\helpers\buildInlineFilterCondition;
use function oihana\arango\db\helpers\requestAlt;
use function oihana\arango\db\helpers\resolveAltSides;
use function oihana\arango\db\helpers\resolveQuantifier;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\betweenBrackets;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\func;
use function oihana\core\strings\key;
use function oihana\core\strings\predicate;

/**
 * This trait defines the array filter helpers.
 *
 * ### Config
 * Defines the 'filters' property in the model (Documents) definition.
 * ```
 * Models::PLACES => fn( ContainerInterface $container ) => new Documents
 * (
 *     $container ,
 *     Collections::PLACES ,
 *     [
 *         ...
 *         AQL::FILTERS =>
 *         [
 *              Prop::EVENTS => FilterType::ARRAY ,
 *              ...
 *         ]
 *         ...
 * ```
 * @example
 * ```
 * ?filter={ "key":"events" , "at"=>"0" , "op":"eq" , "val":100 }
 * ?filter={ "key":"events" , "op":"ge" , "alt"=>"count" , "val":100  }
 * ```
 *
 * **Comparators**
 *
 * *ALL operator*
 * ```
 * ?filter={ "key":"values" , "op":"all.eq" , "val":4 } -> FILTER doc.values ALL == 4
 * ?filter={ "key":"values" , "op":"all.ne" , "val":4 } -> FILTER doc.values ALL != 4
 * ?filter={ "key":"values" , "op":"all.gt" , "val":4 } -> FILTER doc.values ALL >  4
 * ?filter={ "key":"values" , "op":"all.ge" , "val":4 } -> FILTER doc.values ALL >= 4
 * ?filter={ "key":"values" , "op":"all.lt" , "val":4 } -> FILTER doc.values ALL <  4
 * ?filter={ "key":"values" , "op":"all.le" , "val":4 } -> FILTER doc.values ALL <= 4
 * ?filter={ "key":"values" , "op":"all.in" , "val":[2,3,4] } -> FILTER doc.values ALL IN [2,3,4]
 * ?filter={ "key":"values" , "op":"all.nin" , "val":[2,3,4] } -> FILTER doc.values ALL NOT IN [2,3,4]
 * ```
 *
 * *ANY operator*
 * ```
 * ?filter={ "key":"values" , "op":"any.eq" , "val":4 } -> FILTER doc.values ANY == 4
 * ?filter={ "key":"values" , "op":"any.ne" , "val":4 } -> FILTER doc.values ANY != 4
 * ?filter={ "key":"values" , "op":"any.gt" , "val":4 } -> FILTER doc.values ANY >  4
 * ?filter={ "key":"values" , "op":"any.ge" , "val":4 } -> FILTER doc.values ANY >= 4
 * ?filter={ "key":"values" , "op":"any.lt" , "val":4 } -> FILTER doc.values ANY <  4
 * ?filter={ "key":"values" , "op":"any.le" , "val":4 } -> FILTER doc.values ANY <= 4
 * ?filter={ "key":"values" , "op":"any.in" , "val":[2,3,4] } -> FILTER doc.values ANY IN [2,3,4]
 * ?filter={ "key":"values" , "op":"any.nin" , "val":[2,3,4] } -> FILTER doc.values ANY NOT IN [2,3,4]
 * ```
 *
 * *NONE operator*
 * ```
 * ?filter={ "key":"values" , "op":"none.eq" , "val":4 } -> FILTER doc.values NONE == 4
 * ?filter={ "key":"values" , "op":"none.ne" , "val":4 } -> FILTER doc.values NONE != 4
 * ?filter={ "key":"values" , "op":"none.gt" , "val":4 } -> FILTER doc.values NONE >  4
 * ?filter={ "key":"values" , "op":"none.ge" , "val":4 } -> FILTER doc.values NONE >= 4
 * ?filter={ "key":"values" , "op":"none.lt" , "val":4 } -> FILTER doc.values NONE <  4
 * ?filter={ "key":"values" , "op":"none.le" , "val":4 } -> FILTER doc.values NONE <= 4
 * ?filter={ "key":"values" , "op":"none.in" , "val":[2,3,4] } -> FILTER doc.values NONE IN [2,3,4]
 * ?filter={ "key":"values" , "op":"none.nin" , "val":[2,3,4] } -> FILTER doc.values NONE NOT IN [2,3,4]
 * ```
 *
 * **Functions**
 * ```
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"avg"    } -> FILTER AVERAGE(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"count"  } -> FILTER LENGTH(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"first"  } -> FILTER FIRST(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"last"   } -> FILTER LAST(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"max"    } -> FILTER MAX(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"median" } -> FILTER MEDIAN(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"min"    } -> FILTER MIN(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"sum"    } -> FILTER SUM(doc.values) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"nth" , "pos":2 } -> FILTER NTH(doc.values,2) >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"percentile" , "pos":20 , "method":"interpolation" } -> FILTER PERCENTILE(doc.values,20,"interpolation") >= 10
 * ?filter={ "key":"values" , "op":"ge" , "val":10 , "alt":"product" } -> FILTER PRODUCT(doc.values) >= 10
 * ```
 */
trait HasFilterArray
{
    /**
     * Prepares the filter clause with a string attribute.
     *
     * @param array $init
     * @param array|null $binds
     * @param string $docRef
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterArray
    (
         array $init   = [] ,
        ?array &$binds = null ,
        string $docRef = AQL::DOC
    )
    :string
    {
        $operator = $init[ FilterParam::OP    ] ?? FilterComparator::EQ ;
        $value    = $init[ FilterParam::VAL   ] ?? null ;
        $match    = $init[ FilterParam::MATCH ] ?? null ;
        $alt      = requestAlt( $init[ FilterParam::ALT   ] ?? null ) ; // request slot: presumed from the wire
        $quant    = $init[ FilterParam::QUANT ] ?? null ;

        // Legacy AT LEAST (n) — notation ["atLeast.ge", 2] → doc.x AT LEAST (2) >= @v.
        // Kept as a BC alias of the unified `quant` key on scalar arrays.
        if ( is_array( $operator ) && is_string( $operator[ 0 ] ?? null ) && str_starts_with( $operator[ 0 ] , FilterArrayComparator::AT_LEAST . '.' ) )
        {
            return $this->prepareFilterAtLeast( $init , $binds , $docRef ) ;
        }

        // Detect array expansion on the RAW key: an `alt` here is applied inside
        // the inline condition (CURRENT.<field>), not around the whole expansion —
        // wrapping the key first would break the `[*]` parsing below.
        $key = key( $init[ FilterParam::KEY ] ?? null , $docRef ) ;

        // Check if this is an array expansion filter (contains [*])
        if ( str_contains( $key , Operator::ARRAY_EXPANSION ) )
        {
            // Extract base array from key
            if ( preg_match( '/^(.+)\[\*]$/' , $key , $matches ) )
            {
                $baseKey = $matches[1] ;

                // Handle combined match conditions
                if ( $match !== null )
                {
                    // Extract clean base key (remove docRef prefix)
                    // "doc.additionalProperty" → "additionalProperty"
                    // "v1.contactPoint" → "contactPoint"
                    $cleanBaseKey = preg_replace('/^[a-z0-9_]+\./', '', $baseKey);

                    $filterConfig  = $this->filters[ $cleanBaseKey ] ?? null;
                    $allowedFields = [];

                    if ( is_array( $filterConfig ) && isset( $filterConfig[ AQL::FILTERS ] ) )
                    {
                        $allowedFields = $filterConfig[ AQL::FILTERS ] ;
                    }

                    $inlineCondition = buildCombinedInlineFilter( $match , $binds , $allowedFields , $alt ) ;

                    // `quant` present → question-mark operator (ANY/ALL/NONE/AT LEAST n).
                    if ( $quant !== null )
                    {
                        return arrayContains( $baseKey , $inlineCondition , resolveQuantifier( $quant ) ) ;
                    }

                    return predicate
                    (
                        leftOperand  : length( arrayFilter( $baseKey , $inlineCondition ) ) ,
                        operator     : Comparator::GREATER_THAN ,
                        rightOperand : '0' ,
                    ) ;
                }
            }

            // Extract the field from the key. The sub-field part now accepts a DOTTED
            // path so a nested object leaf (`offers[*].seller.id`, where `seller` is
            // an object) builds the correct inline `CURRENT.seller.id` — instead of
            // falling through to `doc.offers[*].seller.id == @v`, an array projection
            // compared to a scalar that never matches (a silent `0`). The base stays
            // greedy, so a multi-level array (`employee[*].contactPoint[*].verified`)
            // keeps its existing existential `LENGTH(…[* FILTER CURRENT.<leaf>])` form
            // (the greedy base absorbs the inner `[*]`, the field is the final leaf).
            // `assertAttributeName()` (in the inline builder) still guards the dotted
            // name against injection.
            if ( preg_match( '/^(.+)\[\*]\.([\w.]+)$/' , $key , $matches ) )
            {
                $baseKey = $matches[1] ; // "doc.contactPoint" or "doc.offers"
                $field   = $matches[2] ; // "email" or "seller.id"

                // Determine the inline filter condition
                $inlineCondition = buildInlineFilterCondition
                (
                    field    : $field    ,
                    operator : $operator ,
                    value    : $value    ,
                    binds  : $binds ,
                    alt    : $alt   ,
                ) ;

                // `quant` present → question-mark operator (ANY/ALL/NONE/AT LEAST n).
                if ( $quant !== null )
                {
                    return arrayContains( $baseKey , $inlineCondition , resolveQuantifier( $quant ) ) ;
                }

                // Generate: LENGTH(array[* FILTER CURRENT.field <op> value]) > 0
                return predicate
                (
                    leftOperand  : length( arrayFilter( $baseKey , $inlineCondition ) ) ,
                    operator     : Comparator::GREATER_THAN ,
                    rightOperand : '0' ,
                ) ;
            }
        }

        // Scalar array with the unified `quant` key → array comparison operator
        // (`doc.scores ALL >= @v`, BC alias of op:"all.ge" / op:["atLeast.ge", n]).
        if ( $quant !== null )
        {
            return $this->prepareFilterQuantified( $init , $binds , $docRef ) ;
        }

        return predicate
        (
            $this->prepareFilterArrayKey( $init , $docRef , $binds ) ,
            $this->prepareFilterArrayComparator( $init ) ,
            $this->prepareFilterValue( $init , $binds ) ,
        ) ;
    }

    /**
     * Prepares the filter clause with a specific operator.
     * @param array $init
     * @return string
     */
    protected function prepareFilterArrayComparator( array $init = [] ):string
    {
        $op = $init[ FilterParam::OP ] ?? null ;

        // A non-string operator reached `__ALIAS__[ $op ]` and raised a TypeError — a PHP
        // fatal nobody declares or catches, for what is only a malformed request. The
        // shapes that mean something (`[ 'atLeast.ge' , 3 ]`) are routed upstream; anything
        // else falls through to the plain comparator, which refuses it by name.
        $alias = is_string( $op ) ? FilterArrayComparator::getAlias( $op ) : null ;

        return $alias ?? $this->prepareFilterComparator( $init ) ;
    }

    /**
     * Prepares the filter clause of a string attribute with a specific key and document.
     *
     * @param string|array|null $init
     * @param string $docRef
     * @return string
     *
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterArrayKey
    (
        string|array|null $init   = [] ,
        string            $docRef = AQL::DOC ,
        ?array            &$binds = null
    )
    :string
    {
        $keyStr = key( $init[ FilterParam::KEY ] ?? null , $docRef ) ;

        $at = $init[ FilterParam::AT ] ?? null ;

        if ( is_int( $at ) )
        {
            $keyStr .= betweenBrackets( (string) $at ) ;
        }
        else
        {
            // A key declared as a list is read as a list.
            //
            // `LENGTH()` and `COUNT()` are the only two catalogue functions defined on
            // strings as well as arrays, so they are the only two that *answer* when a
            // document stores something that is not a list under a list-declared key —
            // every other one already says `null`. They answer with the character
            // count: `"backend"` reports 7 tags, and `alt:"count"` with `== 0` — "which
            // records have no tags" — leaves out exactly the malformed records the
            // question is looking for.
            //
            // Handing the chain the list-or-nothing form settles it without a list of
            // function names to keep in step: a non-array becomes the empty list, so the
            // count is 0. And it changes nothing anywhere else, because a real array
            // passes through untouched — measured, function by function.
            //
            // ⚠ The obvious spelling, `doc.tags[*]`, does NOT work, and the reason is
            // worth keeping: a bracket expression after `[*]` binds to every ELEMENT
            // rather than to the list. `pluck` compiles to an inline projection, so
            // `AVERAGE(doc.items[*][* RETURN CURRENT.price])` reads "the price of each
            // field of each item" and answers `null` where the plain form answers 100.
            // The ternary carries no bracket, so anything may be wrapped around it.
            //
            // ⚠ Two frontiers, both measured rather than assumed:
            //
            // - **the `at` index stays out** (the branch above). It carries a bracket of
            //   its own and would meet the same fate, and it needs no help anyway —
            //   `doc.tags[0]` already answers `null` on a string.
            // - **the comparison stays out**. Only the key the chain wraps is guarded.
            //   Widening it would turn `doc.tags == []` from "the stored list is empty"
            //   into "there is no list here either", and carry `ALL` and `AT LEAST` with
            //   it — another change entirely, and not this one.
            [ $keyChain ] = resolveAltSides( requestAlt( $init[ FilterParam::ALT ] ?? null ) ) ;

            if ( $keyChain !== null )
            {
                $keyStr = betweenParentheses( ternary
                (
                    func( CheckFunction::IS_ARRAY , [ $keyStr ] ) ,
                    $keyStr ,
                    Char::LEFT_BRACKET . Char::RIGHT_BRACKET
                ) ) ;
            }
        }

        return $this->alterFilterKey( $keyStr , $init , $binds ) ;
    }

    /**
     * Builds an `AT LEAST (n)` array quantifier filter: at least `n` elements of
     * the array satisfy the comparison.
     *
     * The operator is the array form `["atLeast.<cmp>", n]` (element 0 is the
     * `atLeast.<cmp>` code, element 1 the threshold, defaulting to 1). The `<cmp>`
     * suffix reuses {@see FilterComparator} (`eq`, `ne`, `gt`, `ge`, `lt`, `le`,
     * `in`, `nin`). The threshold is cast to an int and inlined (injection-safe);
     * the value is bound. The compared key stays alt-aware.
     *
     * ```aql
     * doc.scores AT LEAST (2) >= @value
     * ```
     *
     * @param array $init The filter init (`op` = `["atLeast.<cmp>", n]`).
     * @param array|null $binds The bind variables, populated by reference.
     * @param string $docRef The document reference.
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterAtLeast( array $init , ?array &$binds = null , string $docRef = AQL::DOC ) :string
    {
        $op    = $init[ FilterParam::OP ] ;
        $count = (int) ( $op[ 1 ] ?? 1 ) ;
        $code  = substr( (string) $op[ 0 ] , strlen( FilterArrayComparator::AT_LEAST ) + 1 ) ; // "ge"

        // Same guard as the plain comparator: `at_least.zzz` used to become `at_least (n) ==`.
        $comparator = Operator::AT_LEAST . ' (' . $count . ') ' . $this->resolveFilterComparator( $code ) ;

        return predicate
        (
            $this->prepareFilterArrayKey( $init , $docRef , $binds ) ,
            $comparator ,
            $this->prepareFilterValue( $init , $binds )
        ) ;
    }

    /**
     * Builds a quantified comparison on a scalar array via the `quant` key: how
     * many elements satisfy the comparison.
     *
     * The comparator stays in `op` (a plain {@see FilterComparator} code such as
     * `ge`); the element-axis quantifier comes from `quant` and is resolved by
     * {@see resolveQuantifier} into `ANY` / `ALL` /
     * `NONE` / `AT LEAST (n)`. This is the unified, recommended form; the legacy
     * `op:"all.ge"` and `op:["atLeast.ge", n]` notations remain valid aliases.
     *
     * ```aql
     * doc.scores ALL >= @value
     * doc.scores AT LEAST (2) >= @value
     * ```
     *
     * @param array $init The filter init (`op` = comparator code, `quant` = quantifier).
     * @param array|null $binds The bind variables, populated by reference.
     * @param string $docRef The document reference.
     *
     * @return string
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    protected function prepareFilterQuantified( array $init , ?array &$binds = null , string $docRef = AQL::DOC ) :string
    {
        $quantifier = resolveQuantifier( $init[ FilterParam::QUANT ] ) ;
        $comparator = $this->prepareFilterComparator( $init ) ;

        return predicate
        (
            $this->prepareFilterArrayKey( $init , $docRef , $binds ) ,
            $quantifier . ' ' . $comparator ,
            $this->prepareFilterValue( $init , $binds ) ,
        ) ;
    }
}