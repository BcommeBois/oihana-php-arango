<?php

namespace tests\oihana\arango\clients\aql\helpers ;

use oihana\arango\clients\aql\AqlLiteral ;

use PHPUnit\Framework\TestCase ;

use function oihana\arango\clients\aql\helpers\aqlLiteral ;

/**
 * Tests for the {@see aqlLiteral()} function helper — convenience
 * constructor for {@see AqlLiteral}.
 */
class AqlLiteralFunctionTest extends TestCase
{
    public function testReturnsAqlLiteralWithGivenValue() :void
    {
        $literal = aqlLiteral( 'DESC' ) ;

        $this->assertInstanceOf( AqlLiteral::class , $literal ) ;
        $this->assertSame( 'DESC' , $literal->value ) ;
    }

    public function testEveryCallReturnsAFreshInstance() :void
    {
        $a = aqlLiteral( 'ASC' ) ;
        $b = aqlLiteral( 'ASC' ) ;

        $this->assertEquals  ( $a , $b ) ;
        $this->assertNotSame( $a , $b ) ;
    }
}
