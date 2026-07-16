<?php

namespace oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Comparator;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Operator;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Bound;

use oihana\enums\Char;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use oihana\reflect\exceptions\ConstantException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;

use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\documents\merge;
use function oihana\arango\db\functions\numerics\max;
use function oihana\arango\db\functions\numerics\min;
use function oihana\arango\db\functions\numerics\sum;
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\aqlArray;
use function oihana\arango\db\helpers\aqlDocument;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\operations\aqlCollect;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlLet;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\models\helpers\isAuthorized;
use function oihana\arango\models\helpers\isPathAuthorized;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

/**
 * Builds the AQL query that computes the numeric **bounds** — the
 * `{ min, max, count }` extent — of several fields at once, alongside (not
 * replacing) the document list.
 *
 * Each requested field is a key of the model's `$this->bounds` whitelist. The
 * extent is aggregated over the **same conjunctive filter** as the list, so the
 * bounds frame the currently filtered set (a `?bounds=` request scoped by
 * `?filter=` / `?search` / a facet narrows to that subset). Alongside the two
 * scalars, `count` reports how many values framed the extent (post-exclusion,
 * non-null) — so a client knows whether a range control is worth showing.
 *
 * Flat scalar fields share a single `COLLECT AGGREGATE` — one pass over the
 * filtered set frames every one of them at once:
 *
 * ```aql
 * FOR doc IN @@coll FILTER <same filters>
 * COLLECT AGGREGATE width_min = MIN(doc.width), width_max = MAX(doc.width), width_count = SUM(doc.width != null ? 1 : 0)
 * RETURN { width: { min: width_min, max: width_max, count: width_count } }
 * ```
 *
 * A nested measure declared with the `[*]` array-expansion marker
 * (`offers[*].price`) cannot share that root `FOR` — it must unwind the array —
 * so it gets its own `LET` sub-query, merged into the flat block.
 *
 * **Exclusions.** A bound definition can drop values from the aggregate through
 * {@see Bound} options (`POSITIVE` → `> 0`, `MIN` / `MAX` → accepted domain,
 * `IGNORE` → sentinel value(s)). An excluded value is mapped to `null` — which
 * `MIN` / `MAX` already ignore — so the guard is **per field**: a document
 * dropped from one field's extent still frames the others.
 *
 * ```aql
 * COLLECT AGGREGATE width_min = MIN(doc.width > 0 ? doc.width : null), … , width_count = SUM(doc.width > 0 ? 1 : 0)
 * ```
 *
 * @see BoundsQueryTrait::buildBoundsQuery() The entry point.
 *
 * @package oihana\arango\models\traits\queries
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait BoundsQueryTrait
{
    use FilteredScopeTrait ;

    /**
     * The count aggregate variable of a nested sub-query.
     */
    private const string BOUND_CNT = 'cnt' ;

    /**
     * The count attribute name in the returned `{ min, max, count }` object.
     */
    private const string BOUND_COUNT = 'count' ;

    /**
     * The upper-bound aggregate variable of a nested sub-query.
     */
    private const string BOUND_HI = 'hi' ;

    /**
     * The unwind loop variable for nested ([*]) bound fields.
     */
    private const string BOUND_ITEM = 'item' ;

    /**
     * The lower-bound aggregate variable of a nested sub-query.
     */
    private const string BOUND_LO = 'lo' ;

    /**
     * The upper-bound attribute name in the returned `{ min, max, count }` object.
     */
    private const string BOUND_MAX = 'max' ;

    /**
     * The lower-bound attribute name in the returned `{ min, max, count }` object.
     */
    private const string BOUND_MIN = 'min' ;

    /**
     * The AQL `null` literal — the value an excluded measure maps to.
     */
    private const string BOUND_NULL = 'null' ;

    /**
     * The `LET` variable holding the flat-fields extent object, merged with the
     * nested-field sub-queries.
     */
    private const string BOUNDS_FLAT = '__bounds' ;

    /**
     * Builds the bounds query, or an empty string when nothing is boundable.
     *
     * @param array  $init     The list query options (`Arango::BOUNDS` holds the fields).
     * @param array  $bindVars The bind variables, populated by reference.
     * @param string $docRef   The document reference.
     *
     * @return string The compiled AQL query, or an empty string.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function buildBoundsQuery( array $init = [] , array &$bindVars = [] , string $docRef = AQL::DOC ) :string
    {
        $fields = $init[ Arango::BOUNDS ] ?? [] ;
        if ( is_string( $fields ) )
        {
            $fields = array_map( 'trim' , explode( Char::COMMA , $fields ) ) ;
        }

        if ( !is_array( $fields ) || empty( $fields ) || !is_array( $this->bounds ) )
        {
            return Char::EMPTY ;
        }

        // The whitelist accepts both a bare field name (list entry) and a keyed
        // definition — normalise once to a `field => definition` map.
        $whitelist = $this->normalizeBounds() ;
        if ( empty( $whitelist ) )
        {
            return Char::EMPTY ;
        }

        // The FOR + conjunctive FILTER are the list's scope, so the bounds frame
        // exactly the displayed set. Shared by every list-family query.
        [ $for , $filter ] = $this->buildFilteredScope( $init , $bindVars ) ;

        $aggregate  = [] ; // flat COLLECT AGGREGATE assignments, shared in one pass
        $flatReturn = [] ; // flat RETURN entries: "field: { min: …, max: …, count: … }"
        $lets       = [] ; // one LET per nested ([*]) field
        $mergeParts = [] ; // nested RETURN entries: "field: field"

        foreach ( $fields as $field )
        {
            // Whitelist: only declared bound fields are boundable.
            if ( !is_string( $field ) || !array_key_exists( $field , $whitelist ) )
            {
                continue ;
            }

            $definition = $whitelist[ $field ] ;
            $property   = $definition[ Bound::PROPERTY ] ?? $field ;

            // Permission gate: a bound on a field hidden from the projection
            // (Field::REQUIRES, inherited from $fields at the exact sub-field, or
            // declared on the bound definition) is dropped — its min/max would
            // otherwise leak a real value of the hidden field (a bound oracle).
            if ( !isPathAuthorized( $property , $this->fields ?? null , $init ) || !isAuthorized( $definition , $init ) )
            {
                continue ;
            }

            // A nested measure (`offers[*].price`) must unwind the array, so it
            // cannot share the root FOR — it becomes its own FIRST(( … )) LET.
            if ( str_contains( $property , Operator::ARRAY_EXPANSION ) )
            {
                $subquery     = $this->buildBoundsSubquery( $property , $definition , $for , $filter , $docRef ) ;
                $lets[]       = aqlLet( $field , first( betweenParentheses( $subquery ) ) ) ;
                $mergeParts[] = $field . Char::COLON . Char::SPACE . $field ;
                continue ;
            }

            assertAttributeName( $property ) ; // defensive: property is config-trusted, but cheap to guard.

            $reference  = key( $property , $docRef ) ;
            $minAlias   = $field . Char::UNDERLINE . self::BOUND_MIN ;
            $maxAlias   = $field . Char::UNDERLINE . self::BOUND_MAX ;
            $countAlias = $field . Char::UNDERLINE . self::BOUND_COUNT ;

            $aggregate[ $minAlias ]   = min( $this->boundValue( $reference , $definition ) ) ;
            $aggregate[ $maxAlias ]   = max( $this->boundValue( $reference , $definition ) ) ;
            $aggregate[ $countAlias ] = sum( $this->boundCount( $reference , $definition ) ) ;
            $flatReturn[]             = $field . Char::COLON . Char::SPACE . $this->boundObject( $minAlias , $maxAlias , $countAlias ) ;
        }

        return $this->assembleBoundsQuery( $for , $filter , $aggregate , $flatReturn , $lets , $mergeParts ) ;
    }

    /**
     * Normalizes the `$bounds` whitelist to a `field => definition` map.
     *
     * Accepts both notations, mixed in the same declaration:
     * - a **bare field name** (list entry, e.g. `'width'`) → an empty definition;
     * - a **keyed definition** (`'price' => [ Bound::PROPERTY => '…' ]`) → itself
     *   (a non-array value, e.g. `'width' => true`, becomes an empty definition).
     *
     * The sole caller ({@see buildBoundsQuery()}) already guarantees `$this->bounds`
     * is an array, so no defensive `is_array` guard is needed here.
     *
     * @return array<string,array> The normalized whitelist (empty when nothing boundable).
     */
    private function normalizeBounds() :array
    {
        $whitelist = [] ;
        foreach ( $this->bounds as $key => $value )
        {
            if ( is_string( $key ) )
            {
                $whitelist[ $key ] = is_array( $value ) ? $value : [] ;
            }
            elseif ( is_string( $value ) )
            {
                $whitelist[ $value ] = [] ;
            }
        }

        return $whitelist ;
    }

    /**
     * Assembles the final query from the flat block and the nested `LET`s.
     *
     * Three shapes, cheapest first: a single flat block becomes the top-level
     * query directly; nested-only fields return an object of their `LET`s; a mix
     * binds the flat block to a `LET` and `MERGE`s the nested fields into it.
     *
     * @param string      $for        The shared `FOR` segment.
     * @param string|null $filter     The shared `FILTER` clause.
     * @param array       $aggregate  The flat `COLLECT AGGREGATE` assignments.
     * @param array       $flatReturn The flat `RETURN` entries.
     * @param array       $lets       The nested-field `LET` clauses.
     * @param array       $mergeParts The nested-field `RETURN` entries.
     *
     * @return string The compiled AQL query, or an empty string when nothing was boundable.
     *
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    private function assembleBoundsQuery( string $for , ?string $filter , array $aggregate , array $flatReturn , array $lets , array $mergeParts ) :string
    {
        $hasFlat   = !empty( $aggregate ) ;
        $hasNested = !empty( $lets ) ;

        if ( !$hasFlat && !$hasNested )
        {
            return Char::EMPTY ;
        }

        $flatCollect = $hasFlat
                     ? compile(
                       [
                           $for ,
                           $filter ,
                           aqlCollect( [ AQL::AGGREGATE => $aggregate ] ) ,
                           aqlReturn( aqlDocument( compile( $flatReturn , Char::COMMA . Char::SPACE ) ) ) ,
                       ])
                     : null ;

        // Flat only: the aggregation is the top-level query — one pass, one row.
        if ( $hasFlat && !$hasNested )
        {
            return $flatCollect ;
        }

        // Nested only: an object of the per-field LET variables.
        if ( !$hasFlat )
        {
            return compile( [ ...$lets , aqlReturn( aqlDocument( compile( $mergeParts , Char::COMMA . Char::SPACE ) ) ) ] ) ;
        }

        // Mix: bind the flat block, MERGE the nested fields into it.
        $flatLet = aqlLet( self::BOUNDS_FLAT , first( betweenParentheses( $flatCollect ) ) ) ;
        $nested  = aqlDocument( compile( $mergeParts , Char::COMMA . Char::SPACE ) ) ;

        return compile( [ $flatLet , ...$lets , aqlReturn( merge( [ self::BOUNDS_FLAT , $nested ] ) ) ] ) ;
    }

    /**
     * Builds one nested ([*]) field's extent sub-query, unwinding each `[*]` hop
     * with a `FOR` and aggregating `MIN` / `MAX` / count over the projected leaf.
     *
     * @param string      $property   The `[*]`-bearing property path.
     * @param array       $definition The bound definition (exclusion options).
     * @param string      $for        The pre-built root `FOR` segment.
     * @param string|null $filter     The shared `FILTER` clause.
     * @param string      $docRef     The document reference.
     *
     * @return string The `FOR … COLLECT AGGREGATE … RETURN { min, max, count }` sub-query.
     *
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildBoundsSubquery( string $property , array $definition , string $for , ?string $filter , string $docRef ) :string
    {
        // Split on the marker: 'a[*].b.c[*].d' → ['a', '.b.c', '.d']. Every
        // segment but the last opens a FOR hop (relative to the previous item
        // reference); the last segment is the projected leaf.
        $segments = explode( Operator::ARRAY_EXPANSION , $property ) ;
        $last     = count( $segments ) - 1 ;

        $reference = $docRef ;
        $fors      = [] ;
        for ( $i = 0 ; $i < $last ; $i++ )
        {
            $container = ltrim( $segments[ $i ] , Char::DOT ) ;
            assertAttributeName( $container ) ; // defensive: config-trusted, but cheap to guard.

            $itemRef   = self::BOUND_ITEM . ( $i === 0 ? Char::EMPTY : ( $i + 1 ) ) ;
            $fors[]    = aqlFor( [ AQL::DOC_REF => $itemRef , AQL::IN => key( $container , $reference ) ] ) ;
            $reference = $itemRef ;
        }

        $leaf  = ltrim( $segments[ $last ] , Char::DOT ) ;
        $value = $reference ;
        if ( $leaf !== Char::EMPTY )
        {
            assertAttributeName( $leaf ) ;
            $value = key( $leaf , $reference ) ;
        }

        $aggregate =
        [
            self::BOUND_LO  => min( $this->boundValue( $value , $definition ) ) ,
            self::BOUND_HI  => max( $this->boundValue( $value , $definition ) ) ,
            self::BOUND_CNT => sum( $this->boundCount( $value , $definition ) ) ,
        ] ;

        return compile(
        [
            $for ,
            $filter ,
            ...$fors ,
            aqlCollect( [ AQL::AGGREGATE => $aggregate ] ) ,
            aqlReturn( $this->boundObject( self::BOUND_LO , self::BOUND_HI , self::BOUND_CNT ) ) ,
        ]) ;
    }

    /**
     * Builds the AQL boolean condition a value must satisfy to enter the extent,
     * from the declaration's exclusion options, or null when none is declared.
     *
     * Conditions combine with a logical AND: `POSITIVE` → `> 0`, `MIN` / `MAX` →
     * the accepted `[ min, max ]` domain, `IGNORE` → `NOT IN [ … ]` sentinels.
     *
     * @param string $reference  The value reference (e.g. `doc.width`).
     * @param array  $definition The bound definition.
     *
     * @return string|null The AQL condition, or null when the value is unfiltered.
     */
    private function boundCondition( string $reference , array $definition ) :?string
    {
        $conditions = [] ;

        if ( !empty( $definition[ Bound::POSITIVE ] ) )
        {
            $conditions[] = $reference . Char::SPACE . Comparator::GREATER_THAN . Char::SPACE . '0' ;
        }

        if ( is_numeric( $definition[ Bound::MIN ] ?? null ) )
        {
            $conditions[] = $reference . Char::SPACE . Comparator::GREATER_THAN_OR_EQUAL . Char::SPACE . aqlValue( 0 + $definition[ Bound::MIN ] ) ;
        }

        if ( is_numeric( $definition[ Bound::MAX ] ?? null ) )
        {
            $conditions[] = $reference . Char::SPACE . Comparator::LESS_THAN_OR_EQUAL . Char::SPACE . aqlValue( 0 + $definition[ Bound::MAX ] ) ;
        }

        $ignore = $definition[ Bound::IGNORE ] ?? null ;
        if ( $ignore !== null )
        {
            $sentinels = array_values( array_filter( is_array( $ignore ) ? $ignore : [ $ignore ] , 'is_numeric' ) ) ;
            if ( !empty( $sentinels ) )
            {
                $conditions[] = $reference . Char::SPACE . Comparator::NOT_IN . Char::SPACE . aqlArray( array_map( fn( $v ) => 0 + $v , $sentinels ) ) ;
            }
        }

        return empty( $conditions ) ? null : implode( Char::SPACE . Logic::AND . Char::SPACE , $conditions ) ;
    }

    /**
     * The value expression fed to `MIN` / `MAX`: the raw reference when no
     * exclusion is declared (`MIN` / `MAX` ignore `null` natively), or a
     * `<condition> ? <reference> : null` guard that maps an excluded value to
     * `null` so it does not skew the extent.
     *
     * @param string $reference  The value reference.
     * @param array  $definition The bound definition.
     *
     * @return string The value expression.
     */
    private function boundValue( string $reference , array $definition ) :string
    {
        $condition = $this->boundCondition( $reference , $definition ) ;

        return $condition === null
             ? $reference
             : $condition . Char::SPACE . Char::QUESTION_MARK . Char::SPACE . $reference . Char::SPACE . Char::COLON . Char::SPACE . self::BOUND_NULL ;
    }

    /**
     * The count expression fed to `SUM`: `1` for each value that frames the
     * extent (non-null, and passing the exclusion condition when declared), `0`
     * otherwise — so `count` reports how many values the `{ min, max }` spans.
     *
     * @param string $reference  The value reference.
     * @param array  $definition The bound definition.
     *
     * @return string The count expression.
     */
    private function boundCount( string $reference , array $definition ) :string
    {
        $condition = $this->boundCondition( $reference , $definition )
                  ?? ( $reference . Char::SPACE . Comparator::NOT_EQUAL . Char::SPACE . self::BOUND_NULL ) ;

        return $condition . Char::SPACE . Char::QUESTION_MARK . Char::SPACE . '1' . Char::SPACE . Char::COLON . Char::SPACE . '0' ;
    }

    /**
     * Serializes a `{ min: <lo>, max: <hi>, count: <count> }` object from three
     * AQL variables.
     *
     * @param string $lo    The lower-bound variable / expression.
     * @param string $hi    The upper-bound variable / expression.
     * @param string $count The count variable / expression.
     *
     * @return string The `{ min: <lo>, max: <hi>, count: <count> }` object literal.
     */
    private function boundObject( string $lo , string $hi , string $count ) :string
    {
        return aqlDocument( compile(
        [
            self::BOUND_MIN   . Char::COLON . Char::SPACE . $lo ,
            self::BOUND_MAX   . Char::COLON . Char::SPACE . $hi ,
            self::BOUND_COUNT . Char::COLON . Char::SPACE . $count ,
        ] , Char::COMMA . Char::SPACE ) ) ;
    }
}
