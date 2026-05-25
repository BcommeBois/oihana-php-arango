<?php

namespace tests\oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\IdentityAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see IdentityAnalyzer} — the pass-through analyzer
 * value object.
 */
#[CoversClass( IdentityAnalyzer::class )]
class IdentityAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf( AnalyzerOptions::class , new IdentityAnalyzer() ) ;
    }

    public function testToArrayEmitsTypeAndEmptyProperties() :void
    {
        $payload = new IdentityAnalyzer()->toArray() ;

        $this->assertSame( 'identity' , $payload[ 'type' ] ) ;
        $this->assertArrayHasKey( 'properties' , $payload ) ;

        // Empty object on the wire, not an empty array — keeps the
        // server response shape stable when round-tripped through
        // json_encode().
        $encoded = json_encode( $payload , JSON_UNESCAPED_SLASHES ) ;
        $this->assertSame( '{"type":"identity","properties":{}}' , $encoded ) ;
    }
}
