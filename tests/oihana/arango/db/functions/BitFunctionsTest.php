<?php

namespace tests\oihana\arango\db\functions;

use ReflectionClass;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\BitFunction;

use function oihana\arango\db\functions\bit\bitAnd;
use function oihana\arango\db\functions\bit\bitConstruct;
use function oihana\arango\db\functions\bit\bitDeconstruct;
use function oihana\arango\db\functions\bit\bitFromString;
use function oihana\arango\db\functions\bit\bitNegate;
use function oihana\arango\db\functions\bit\bitOr;
use function oihana\arango\db\functions\bit\bitPopcount;
use function oihana\arango\db\functions\bit\bitShiftLeft;
use function oihana\arango\db\functions\bit\bitShiftRight;
use function oihana\arango\db\functions\bit\bitTest;
use function oihana\arango\db\functions\bit\bitToString;
use function oihana\arango\db\functions\bit\bitXor;

class BitFunctionsTest extends TestCase
{
    public function testBitAndArrayForm(): void
    {
        $this->assertSame( 'BIT_AND([1,4,8,16])' , bitAnd( [ 1 , 4 , 8 , 16 ] ) );
    }

    public function testBitAndTwoOperandForm(): void
    {
        $this->assertSame( 'BIT_AND(127,255)' , bitAnd( 127 , 255 ) );
    }

    public function testBitAndExpression(): void
    {
        $this->assertSame( 'BIT_AND(doc.flags)' , bitAnd( 'doc.flags' ) );
    }

    public function testBitOr(): void
    {
        $this->assertSame( 'BIT_OR([1,4,8,16])' , bitOr( [ 1 , 4 , 8 , 16 ] ) );
        $this->assertSame( 'BIT_OR(1,2)' , bitOr( 1 , 2 ) );
    }

    public function testBitXor(): void
    {
        $this->assertSame( 'BIT_XOR([1,2,3])' , bitXor( [ 1 , 2 , 3 ] ) );
        $this->assertSame( 'BIT_XOR(1,5)' , bitXor( 1 , 5 ) );
    }

    public function testBitNegate(): void
    {
        $this->assertSame( 'BIT_NEGATE(0,8)' , bitNegate( 0 , 8 ) );
    }

    public function testBitTest(): void
    {
        $this->assertSame( 'BIT_TEST(255,0)' , bitTest( 255 , 0 ) );
    }

    public function testBitPopcount(): void
    {
        $this->assertSame( 'BIT_POPCOUNT(255)' , bitPopcount( 255 ) );
    }

    public function testBitShiftLeft(): void
    {
        $this->assertSame( 'BIT_SHIFT_LEFT(1,4,8)' , bitShiftLeft( 1 , 4 , 8 ) );
    }

    public function testBitShiftRight(): void
    {
        $this->assertSame( 'BIT_SHIFT_RIGHT(16,4,8)' , bitShiftRight( 16 , 4 , 8 ) );
    }

    public function testBitConstruct(): void
    {
        $this->assertSame( 'BIT_CONSTRUCT([1,2,3])' , bitConstruct( [ 1 , 2 , 3 ] ) );
        $this->assertSame( 'BIT_CONSTRUCT(doc.positions)' , bitConstruct( 'doc.positions' ) );
    }

    public function testBitDeconstruct(): void
    {
        $this->assertSame( 'BIT_DECONSTRUCT(14)' , bitDeconstruct( 14 ) );
    }

    public function testBitToString(): void
    {
        $this->assertSame( 'BIT_TO_STRING(7,8)' , bitToString( 7 , 8 ) );
    }

    public function testBitFromString(): void
    {
        $this->assertSame( 'BIT_FROM_STRING("0101")' , bitFromString( '0101' ) );
    }

    public function testAllBitFunctionConstants(): void
    {
        $constants = ( new ReflectionClass( BitFunction::class ) )->getConstants();

        $expected =
        [
            'BIT_AND', 'BIT_CONSTRUCT', 'BIT_DECONSTRUCT', 'BIT_FROM_STRING',
            'BIT_NEGATE', 'BIT_OR', 'BIT_POPCOUNT', 'BIT_SHIFT_LEFT',
            'BIT_SHIFT_RIGHT', 'BIT_TEST', 'BIT_TO_STRING', 'BIT_XOR',
        ];

        $this->assertSame( $expected , array_keys( $constants ) );

        foreach ( $constants as $name => $value )
        {
            $this->assertSame( $name , $value , "Wrong constant value for $name" );
        }
    }
}
