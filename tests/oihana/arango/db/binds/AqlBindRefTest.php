<?php

namespace tests\oihana\arango\db\binds;

use oihana\arango\db\binds\AqlBindReference;
use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\aqlBindRef;

final class AqlBindRefTest extends TestCase
{
    /**
     * @throws BindException
     */
    public function testAqlBindRefReturnsReference(): void
    {
        $ref = aqlBindRef( 'allowedRegions' ) ;
        $this->assertInstanceOf( AqlBindReference::class , $ref ) ;
        $this->assertSame( 'allowedRegions' , $ref->name ) ;
    }

    /**
     * @throws BindException
     */
    public function testToAqlRendersTheBindToken(): void
    {
        $this->assertSame( '@allowedRegions' , aqlBindRef( 'allowedRegions' )->toAql() ) ;
        $this->assertSame( '@_private'       , aqlBindRef( '_private' )->toAql() ) ;
    }

    /**
     * @throws BindException
     */
    public function testAqlBindRefRegistersNoValue(): void
    {
        // A reference only names a slot — building one must not throw and must
        // carry no value (unlike aqlBind, which mutates a bind map).
        $ref = aqlBindRef( 'x' ) ;
        $this->assertSame( 'x' , $ref->name ) ;
    }

    public function testAqlBindRefRejectsLeadingDigit(): void
    {
        $this->expectException( BindException::class ) ;
        aqlBindRef( '1bad' ) ;
    }

    public function testAqlBindRefRejectsHyphen(): void
    {
        $this->expectException( BindException::class ) ;
        aqlBindRef( 'a-b' ) ;
    }

    public function testAqlBindRefRejectsInvalidCharacter(): void
    {
        $this->expectException( BindException::class ) ;
        aqlBindRef( '!invalid' ) ;
    }
}
