<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\models\enums\filters\FilterMatch;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function oihana\arango\db\helpers\buildCombinedInlineFilter;

/**
 * Direct unit coverage for the free helper
 * {@see buildCombinedInlineFilter}: it combines
 * several `CURRENT.<field>` conditions for the array-expansion `match` filter,
 * with `all` (AND), `any` (OR) and `none` (NOT) logic, plus the simple
 * `{field: value}` shorthand and optional field whitelisting.
 */
final class BuildCombinedInlineFilterTest extends TestCase
{
    /**
     * @throws BindException
     */
    public function testSimpleObjectDefaultsToAndedEquality(): void
    {
        $binds  = [] ;
        $result = buildCombinedInlineFilter( [ 'propertyID' => 'X' , 'value' => true ] , $binds ) ;

        $this->assertMatchesRegularExpression
        (
            '/^CURRENT\.propertyID == @\S+ && CURRENT\.value == @\S+$/' ,
            $result
        ) ;
    }

    /**
     * @throws BindException
     */
    public function testExplicitAnyUsesOrLogic(): void
    {
        $binds  = [] ;
        $match  = [ 'any' => [ [ 'key' => 'email' , 'op' => 'ne' , 'val' => null ] , [ 'key' => 'telephone' , 'op' => 'ne' , 'val' => null ] ] ] ;
        $result = buildCombinedInlineFilter( $match , $binds ) ;

        $this->assertSame( 'CURRENT.email != null || CURRENT.telephone != null' , $result ) ;
    }

    /**
     * @throws BindException
     */
    public function testExplicitNoneNegatesTheOredConditions(): void
    {
        $binds  = [] ;
        $match  = [ 'none' => [ [ 'key' => 'archived' , 'op' => 'eq' , 'val' => true ] ] ] ;
        $result = buildCombinedInlineFilter( $match , $binds ) ;

        $this->assertMatchesRegularExpression( '/^!\(CURRENT\.archived == @\S+\)$/' , $result ) ;
    }

    /**
     * @throws BindException
     */
    public function testKeylessConditionsAreSkippedAndFallBackToTrue(): void
    {
        $binds  = [] ;
        $result = buildCombinedInlineFilter( [ 'all' => [ [ 'op' => 'eq' ] ] ] , $binds ) ;

        $this->assertSame( 'true' , $result ) ;
    }

    /**
     * @throws BindException
     */
    public function testAltAppliesToEverySubField(): void
    {
        $binds  = [] ;
        $result = buildCombinedInlineFilter
        (
            [ 'email' => 'X@Y.COM' ] ,
            $binds ,
            [] ,
            [ 'key' => 'lower' , 'val' => true ]
        ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(CURRENT\.email\) == LOWER\(@\S+\)$/' , $result ) ;
    }

    /**
     * @throws BindException
     */
    public function testDisallowedFieldThrows(): void
    {
        $binds = [] ;

        $this->expectException( RuntimeException::class ) ;
        buildCombinedInlineFilter( [ 'secret' => 'x' ] , $binds , [ 'email' => true ] ) ;
    }

    /**
     * Fail-loud: an operator spec (`{op,val}`) written as a simple-form value is a
     * silent-0 trap — `CURRENT.price == @{op,val}` never matches. It must throw and
     * point to the explicit `all` form.
     *
     * @throws BindException
     */
    public function testSimpleFormOperatorObjectValueThrows(): void
    {
        $binds = [] ;

        $this->expectException( ValidationException::class ) ;
        buildCombinedInlineFilter( [ 'price' => [ 'op' => 'gt' , 'val' => 0 ] ] , $binds ) ;
    }

    /**
     * A list value in the simple form is rejected the same way (array equality is an
     * object comparison — it belongs in the explicit `all` form).
     *
     * @throws BindException
     */
    public function testSimpleFormListValueThrows(): void
    {
        $binds = [] ;

        $this->expectException( ValidationException::class ) ;
        buildCombinedInlineFilter( [ 'tags' => [ 'a' , 'b' ] ] , $binds ) ;
    }

    /**
     * The object-comparison intent stays expressible through the explicit `all` form,
     * where a non-scalar `val` is legitimate.
     *
     * @throws BindException
     */
    public function testExplicitAllFormAcceptsAnObjectValue(): void
    {
        $binds  = [] ;
        $match  = [ FilterMatch::ALL => [ [ FilterParam::KEY => 'geo' , FilterParam::OP => 'eq' , FilterParam::VAL => [ 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ] ] ;
        $result = buildCombinedInlineFilter( $match , $binds ) ;

        $this->assertMatchesRegularExpression( '/^CURRENT\.geo == @\S+$/' , $result ) ;
    }

    /**
     * A `null` value keeps its dedicated null-literal form (not a scalar, but valid).
     *
     * @throws BindException
     */
    public function testSimpleFormNullValueIsStillAccepted(): void
    {
        $binds  = [] ;
        $result = buildCombinedInlineFilter( [ 'deletedAt' => null ] , $binds ) ;

        $this->assertSame( 'CURRENT.deletedAt == null' , $result ) ;
    }

    /**
     * The whitelist is also enforced inside the explicit-logic
     * (`all` / `any` / `none`) conditions loop, not only the simple shorthand.
     *
     * @throws BindException
     */
    public function testDisallowedFieldInExplicitLogicThrows(): void
    {
        $binds = [] ;

        $this->expectException( RuntimeException::class ) ;
        buildCombinedInlineFilter
        (
            [ FilterMatch::ALL => [ [ FilterParam::KEY => 'secret' , FilterParam::VAL => 'x' ] ] ] ,
            $binds ,
            [ 'email' => true ] ,
        ) ;
    }
}
