<?php

namespace tests\oihana\arango\clients\aql ;

use oihana\arango\clients\aql\AqlLiteral ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see AqlLiteral} — marker value for AQL fragments inlined
 * verbatim into a query (bypasses the safe binding layer).
 */
#[CoversClass( AqlLiteral::class )]
class AqlLiteralTest extends TestCase
{
    public function testConstructStoresValueAsIs() :void
    {
        $literal = new AqlLiteral( 'DESC' ) ;

        $this->assertSame( 'DESC' , $literal->value ) ;
    }

    public function testToStringReturnsValue() :void
    {
        $literal = new AqlLiteral( 'ASC' ) ;

        $this->assertSame( 'ASC'           , (string) $literal ) ;
        $this->assertSame( "FOR u SORT ASC" , 'FOR u SORT ' . $literal ) ;
    }

    public function testAcceptsArbitraryFragments() :void
    {
        $literal = new AqlLiteral( 'NULL' ) ;
        $this->assertSame( 'NULL' , $literal->value ) ;

        $literal = new AqlLiteral( 'LENGTH(@col)' ) ;
        $this->assertSame( 'LENGTH(@col)' , $literal->value ) ;
    }
}
