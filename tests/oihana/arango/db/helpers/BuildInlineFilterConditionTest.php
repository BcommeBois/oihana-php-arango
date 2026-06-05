<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
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
