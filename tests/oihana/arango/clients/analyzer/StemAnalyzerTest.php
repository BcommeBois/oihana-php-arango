<?php

namespace tests\oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\StemAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see StemAnalyzer} — the locale-aware stemmer value
 * object.
 */
#[CoversClass( StemAnalyzer::class )]
class StemAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf( AnalyzerOptions::class , new StemAnalyzer( locale : 'en' ) ) ;
    }

    public function testToArrayEmitsTypeAndLocaleOnly() :void
    {
        $payload = new StemAnalyzer( locale : 'en' )->toArray() ;

        $this->assertSame
        (
            [
                'type'       => 'stem' ,
                'properties' => [ 'locale' => 'en' ] ,
            ] ,
            $payload ,
        ) ;
    }
}
