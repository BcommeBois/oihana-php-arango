<?php

namespace tests\oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\RawAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see RawAnalyzer} — the type-agnostic analyzer value object
 * (verbatim `type` + `properties`).
 */
#[CoversClass( RawAnalyzer::class )]
class RawAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf( AnalyzerOptions::class , new RawAnalyzer( 'text' ) ) ;
    }

    public function testToArrayEmitsTypeAndProperties() :void
    {
        $payload = new RawAnalyzer( 'text' , [ 'locale' => 'fr.utf-8' , 'case' => 'lower' , 'accent' => false ] )->toArray() ;

        $this->assertSame( 'text' , $payload[ 'type' ] ) ;
        $this->assertSame( [ 'locale' => 'fr.utf-8' , 'case' => 'lower' , 'accent' => false ] , $payload[ 'properties' ] ) ;
    }

    public function testEmptyPropertiesRoundTripAsAnEmptyObject() :void
    {
        $payload = new RawAnalyzer( 'identity' )->toArray() ;

        // Empty object on the wire ({}), not an empty array ([]) — the shape
        // the server expects, like IdentityAnalyzer.
        $encoded = json_encode( $payload , JSON_UNESCAPED_SLASHES ) ;
        $this->assertSame( '{"type":"identity","properties":{}}' , $encoded ) ;
    }
}
