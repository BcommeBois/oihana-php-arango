<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\alterExpression;

/**
 * Direct unit coverage for the free helper
 * {@see \oihana\arango\db\helpers\alterExpression()}: the side-agnostic engine
 * that wraps an arbitrary AQL expression (a field reference, a bind placeholder,
 * or the `CURRENT` loop variable) with an `alt` function chain.
 */
final class AlterExpressionTest extends TestCase
{
    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testNullChainIsANoOp(): void
    {
        $this->assertSame( 'doc.name' , alterExpression( 'doc.name' , null ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testSingleFunction(): void
    {
        $this->assertSame( 'LOWER(doc.name)' , alterExpression( 'doc.name' , 'lower' ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testFunctionChainAppliesLeftToRight(): void
    {
        // ['trim','lower'] => LOWER(TRIM(expr)) — the last function wraps.
        $this->assertSame( 'LOWER(TRIM(doc.name))' , alterExpression( 'doc.name' , [ 'trim' , 'lower' ] ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testSingleFunctionWithParams(): void
    {
        $this->assertSame( 'SUBSTRING(doc.code,0,3)' , alterExpression( 'doc.code' , [ 'substring' , 0 , 3 ] ) ) ;
    }

    /**
     * @throws ValidationException
     * @throws UnsupportedOperationException
     */
    public function testMixedChainOfPlainAndParameterizedFunctions(): void
    {
        $this->assertSame
        (
            'LOWER(SUBSTRING(TRIM(doc.x),0,3))' ,
            alterExpression( 'doc.x' , [ 'trim' , [ 'substring' , 0 , 3 ] , 'lower' ] )
        ) ;
    }

    /**
     * @throws ValidationException
     */
    public function testWrapsABindPlaceholder(): void
    {
        // Side-agnostic: the same engine wraps a value placeholder.
        $this->assertSame( 'LOWER(@value)' , alterExpression( '@value' , 'lower' ) ) ;
    }

    /**
     * @throws ValidationException
     */
    public function testPluckProjectsAnArrayOfObjects(): void
    {
        // The `pluck` alter is parameterized and emits the inline projection.
        $this->assertSame
        (
            'doc.items[* RETURN CURRENT.price]' ,
            alterExpression( 'doc.items' , [ 'pluck' , 'price' ] )
        ) ;
    }

    public function testPluckRejectsAnUnsafeSubField(): void
    {
        $this->expectException( ValidationException::class ) ;
        alterExpression( 'doc.items' , [ 'pluck' , 'price] || true || [' ] ) ;
    }
}
