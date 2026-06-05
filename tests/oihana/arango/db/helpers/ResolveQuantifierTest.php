<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\resolveQuantifier;

/**
 * Direct unit coverage for the free helper {@see resolveQuantifier}: it maps the
 * raw `quant` parameter (`any`/`all`/`none` or a bare integer) to the AQL
 * quantifier keyword (`ANY`/`ALL`/`NONE`/`AT LEAST (n)`), rejecting anything else.
 */
final class ResolveQuantifierTest extends TestCase
{
    public function testAnyYieldsAny(): void
    {
        $this->assertSame( 'ANY' , resolveQuantifier( 'any' ) ) ;
    }

    public function testAllYieldsAll(): void
    {
        $this->assertSame( 'ALL' , resolveQuantifier( 'all' ) ) ;
    }

    public function testNoneYieldsNone(): void
    {
        $this->assertSame( 'NONE' , resolveQuantifier( 'none' ) ) ;
    }

    public function testIntegerYieldsAtLeast(): void
    {
        $this->assertSame( 'AT LEAST (3)' , resolveQuantifier( 3 ) ) ;
    }

    public function testNumericStringYieldsAtLeast(): void
    {
        $this->assertSame( 'AT LEAST (3)' , resolveQuantifier( '3' ) ) ;
    }

    public function testZeroIsAcceptedAsAtLeast(): void
    {
        $this->assertSame( 'AT LEAST (0)' , resolveQuantifier( 0 ) ) ;
    }

    public function testUnknownNameIsRejected(): void
    {
        $this->expectException( ValidationException::class ) ;
        resolveQuantifier( 'bogus' ) ;
    }

    public function testFloatStringIsRejected(): void
    {
        // "3.5" is not a digit string → not a valid « at least n ».
        $this->expectException( ValidationException::class ) ;
        resolveQuantifier( '3.5' ) ;
    }

    public function testNullIsRejected(): void
    {
        $this->expectException( ValidationException::class ) ;
        resolveQuantifier( null ) ;
    }
}
