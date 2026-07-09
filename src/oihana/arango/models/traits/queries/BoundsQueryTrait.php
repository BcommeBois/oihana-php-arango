<?php

namespace oihana\arango\models\traits\queries;

use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Operator;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;

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
use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\aqlDocument;
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
 * Builds the AQL query that computes the numeric **bounds** — the `{ min, max }`
 * extent — of several fields at once, alongside (not replacing) the document
 * list.
 *
 * Each requested field is a key of the model's `$this->bounds` whitelist. The
 * extent is aggregated over the **same conjunctive filter** as the list, so the
 * bounds frame the currently filtered set (a `?bounds=` request scoped by
 * `?filter=` / `?search` / a facet narrows to that subset).
 *
 * Flat scalar fields share a single `COLLECT AGGREGATE` — one pass over the
 * filtered set frames every one of them at once:
 *
 * ```aql
 * FOR doc IN @@coll FILTER <same filters>
 * COLLECT AGGREGATE width_min = MIN(doc.width), width_max = MAX(doc.width),
 *                   height_min = MIN(doc.height), height_max = MAX(doc.height)
 * RETURN { width: { min: width_min, max: width_max }, height: { min: height_min, max: height_max } }
 * ```
 *
 * A nested measure declared with the `[*]` array-expansion marker
 * (`offers[*].price`) cannot share that root `FOR` — it must unwind the array —
 * so it gets its own `LET` sub-query, merged into the flat block:
 *
 * ```aql
 * LET __bounds = FIRST( ( FOR doc IN @@coll FILTER <f> COLLECT AGGREGATE … RETURN { … } ) )
 * LET price    = FIRST( ( FOR doc IN @@coll FILTER <f> FOR item IN doc.offers COLLECT AGGREGATE lo = MIN(item.price), hi = MAX(item.price) RETURN { min: lo, max: hi } ) )
 * RETURN MERGE( __bounds, { price: price } )
 * ```
 *
 * `MIN` / `MAX` ignore `null`, so a field absent from a document does not skew
 * its extent; a field with no non-null value in the set yields `{ min: null, max: null }`.
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
     * The upper-bound attribute name in the returned `{ min, max }` object.
     */
    private const string BOUND_MAX = 'max' ;

    /**
     * The lower-bound attribute name in the returned `{ min, max }` object.
     */
    private const string BOUND_MIN = 'min' ;

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

        // The FOR + conjunctive FILTER are the list's scope, so the bounds frame
        // exactly the displayed set. Shared by every list-family query.
        [ $for , $filter ] = $this->buildFilteredScope( $init , $bindVars ) ;

        $aggregate  = [] ; // flat COLLECT AGGREGATE assignments, shared in one pass
        $flatReturn = [] ; // flat RETURN entries: "field: { min: …, max: … }"
        $lets       = [] ; // one LET per nested ([*]) field
        $mergeParts = [] ; // nested RETURN entries: "field: field"

        foreach ( $fields as $field )
        {
            // Whitelist: only declared bound keys are boundable.
            if ( !is_string( $field ) )
            {
                continue ;
            }

            $bound = $this->bounds[ $field ] ?? null ;
            if ( $bound === null )
            {
                continue ;
            }

            $definition = is_array( $bound ) ? $bound : [] ;
            $property   = $definition[ Facet::PROPERTY ] ?? $field ;

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
                $subquery     = $this->buildBoundsSubquery( $property , $for , $filter , $docRef ) ;
                $lets[]       = aqlLet( $field , first( betweenParentheses( $subquery ) ) ) ;
                $mergeParts[] = $field . Char::COLON . Char::SPACE . $field ;
                continue ;
            }

            assertAttributeName( $property ) ; // defensive: property is config-trusted, but cheap to guard.

            $reference = key( $property , $docRef ) ;
            $minAlias  = $field . Char::UNDERLINE . self::BOUND_MIN ;
            $maxAlias  = $field . Char::UNDERLINE . self::BOUND_MAX ;

            $aggregate[ $minAlias ] = min( $reference ) ;
            $aggregate[ $maxAlias ] = max( $reference ) ;
            $flatReturn[]           = $field . Char::COLON . Char::SPACE . $this->boundObject( $minAlias , $maxAlias ) ;
        }

        return $this->assembleBoundsQuery( $for , $filter , $aggregate , $flatReturn , $lets , $mergeParts ) ;
    }

    /**
     * Assembles the final query from the flat block and the nested `LET`s.
     *
     * Three shapes, cheapest first: a single flat block becomes the top-level
     * query directly; nested-only fields return an object of their `LET`s; a mix
     * binds the flat block to a `LET` and `MERGE`s the nested fields into it.
     *
     * @param string $for The shared `FOR` segment.
     * @param string|null $filter The shared `FILTER` clause.
     * @param array $aggregate The flat `COLLECT AGGREGATE` assignments.
     * @param array $flatReturn The flat `RETURN` entries.
     * @param array $lets The nested-field `LET` clauses.
     * @param array $mergeParts The nested-field `RETURN` entries.
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
     * with a `FOR` and aggregating `MIN` / `MAX` over the projected leaf.
     *
     * @param string $property The `[*]`-bearing property path.
     * @param string $for The pre-built root `FOR` segment.
     * @param string|null $filter The shared `FILTER` clause.
     * @param string $docRef The document reference.
     *
     * @return string The `FOR … COLLECT AGGREGATE … RETURN { min, max }` sub-query.
     *
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function buildBoundsSubquery( string $property , string $for , ?string $filter , string $docRef ) :string
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
            self::BOUND_LO => min( $value ) ,
            self::BOUND_HI => max( $value ) ,
        ] ;

        return compile(
        [
            $for ,
            $filter ,
            ...$fors ,
            aqlCollect( [ AQL::AGGREGATE => $aggregate ] ) ,
            aqlReturn( $this->boundObject( self::BOUND_LO , self::BOUND_HI ) ) ,
        ]) ;
    }

    /**
     * Serializes a `{ min: <lo>, max: <hi> }` object from two AQL variables.
     *
     * @param string $lo The lower-bound variable / expression.
     * @param string $hi The upper-bound variable / expression.
     *
     * @return string The `{ min: <lo>, max: <hi> }` object literal.
     *
     * @throws UnsupportedOperationException
     */
    private function boundObject( string $lo , string $hi ) :string
    {
        return aqlDocument( compile(
        [
            self::BOUND_MIN . Char::COLON . Char::SPACE . $lo ,
            self::BOUND_MAX . Char::COLON . Char::SPACE . $hi ,
        ] , Char::COMMA . Char::SPACE ) ) ;
    }
}
