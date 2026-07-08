<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\buildInlineFilterCondition;

/**
 * Direct unit coverage for the free helper
 * {@see buildInlineFilterCondition}: it builds a
 * single `CURRENT.<field> <op> <value>` condition for the array-expansion inline
 * filter (`doc.items[* FILTER … ]`), binding non-null values and optionally
 * wrapping both sides with an `alt` chain.
 */
final class BuildInlineFilterConditionTest extends TestCase
{
    /**
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function testNullValueComparesAgainstTheNullLiteralWithoutBinding(): void
    {
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'email' , 'ne' , null , $binds ) ;

        $this->assertSame( 'CURRENT.email != null' , $result ) ;
        $this->assertSame( [] , $binds ) ; // null is never bound
    }

    /**
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function testValueIsBoundAndCompared(): void
    {
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'email' , 'eq' , 'john@doe.com' , $binds ) ;

        $this->assertMatchesRegularExpression( '/^CURRENT\.email == @\S+$/' , $result ) ;
        $this->assertContains( 'john@doe.com' , $binds ) ;
    }

    /**
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function testOperatorIsResolvedFromTheFilterVocabulary(): void
    {
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'score' , 'ge' , 10 , $binds ) ;

        $this->assertMatchesRegularExpression( '/^CURRENT\.score >= @\S+$/' , $result ) ;
    }

    public function testDottedSubFieldPathIsAccepted(): void
    {
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'address.city' , 'eq' , 'Paris' , $binds ) ;

        $this->assertMatchesRegularExpression( '/^CURRENT\.address\.city == @\S+$/' , $result ) ;
    }

    public function testUnsafeFieldNameThrows(): void
    {
        // Defense in depth: an injection-looking sub-field name (e.g. from a `match`
        // filter with no AQL::FILTERS whitelist) never reaches CURRENT.<field>.
        $this->expectException( ValidationException::class ) ;

        $binds = [] ;
        buildInlineFilterCondition( 'x) || 1==1' , 'eq' , 'v' , $binds ) ;
    }

    public function testBetweenOperatorThrowsInsteadOfDegradingToEquality(): void
    {
        // Fail-loud: `between` is not wired inline — it used to degrade to `==`
        // against the raw [min,max] array, a valid AQL that never matched (silent 0).
        $this->expectException( ValidationException::class ) ;

        $binds = [] ;
        buildInlineFilterCondition( 'price' , 'between' , [ 10 , 100 ] , $binds ) ;
    }

    public function testUnknownOperatorThrowsInsteadOfDegradingToEquality(): void
    {
        // The guard is generic: any operator outside FilterComparator::__ALIAS__
        // (a flat-only form like `contains`, or a typo like `gte`) is rejected.
        $this->expectException( ValidationException::class ) ;

        $binds = [] ;
        buildInlineFilterCondition( 'name' , 'contains' , 'foo' , $binds ) ;
    }

    /**
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function testInOperatorRemainsSupportedWithAnArrayValue(): void
    {
        // `in` / `nin` ARE recognised aliases: the array value is legitimate.
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'status' , 'in' , [ 'a' , 'b' ] , $binds ) ;

        $this->assertMatchesRegularExpression( '/^CURRENT\.status IN @\S+$/' , $result ) ;
    }

    /**
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function testAltMirrorWrapsBothSides(): void
    {
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'email' , 'eq' , 'JOHN@DOE.COM' , $binds , [ 'key' => 'lower' , 'val' => true ] ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(CURRENT\.email\) == LOWER\(@\S+\)$/' , $result ) ;
    }

    /**
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    public function testAltStringWrapsTheFieldSideOnly(): void
    {
        $binds  = [] ;
        $result = buildInlineFilterCondition( 'email' , 'eq' , 'x' , $binds , 'lower' ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(CURRENT\.email\) == @\S+$/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER(@' , $result ) ;
    }
}
