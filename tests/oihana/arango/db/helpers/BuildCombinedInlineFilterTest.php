<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\BindException;
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
}
