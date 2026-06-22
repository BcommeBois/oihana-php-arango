<?php

namespace tests\oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\NgramAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see NgramAnalyzer} — the n-gram (substring / autocomplete)
 * analyzer value object.
 */
#[CoversClass( NgramAnalyzer::class )]
class NgramAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf( AnalyzerOptions::class , new NgramAnalyzer( min : 2 , max : 5 ) ) ;
    }

    public function testMinimalPayloadCarriesBoundsAndPreserveOriginal() :void
    {
        $payload = new NgramAnalyzer( min : 2 , max : 5 )->toArray() ;

        $this->assertSame
        (
            [
                'type'       => 'ngram' ,
                'properties' =>
                [
                    'min'              => 2 ,
                    'max'              => 5 ,
                    'preserveOriginal' => false ,
                ] ,
            ] ,
            $payload ,
        ) ;
    }

    public function testOptionalMarkersAndStreamTypeAreEmittedWhenSet() :void
    {
        $payload = new NgramAnalyzer
        (
            min              : 3 ,
            max              : 4 ,
            preserveOriginal : true ,
            startMarker      : '^' ,
            endMarker        : '$' ,
            streamType       : 'utf8' ,
        )->toArray() ;

        $this->assertSame
        (
            [
                'min'              => 3 ,
                'max'              => 4 ,
                'preserveOriginal' => true ,
                'startMarker'      => '^' ,
                'endMarker'        => '$' ,
                'streamType'       => 'utf8' ,
            ] ,
            $payload[ 'properties' ] ,
        ) ;
    }

    public function testNullOptionalsAreOmitted() :void
    {
        $payload = new NgramAnalyzer( min : 2 , max : 5 )->toArray() ;

        $this->assertArrayNotHasKey( 'startMarker' , $payload[ 'properties' ] ) ;
        $this->assertArrayNotHasKey( 'endMarker'   , $payload[ 'properties' ] ) ;
        $this->assertArrayNotHasKey( 'streamType'  , $payload[ 'properties' ] ) ;
    }
}
