<?php

namespace tests\oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\NormAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see NormAnalyzer} — the locale-aware normaliser
 * value object.
 */
#[CoversClass( NormAnalyzer::class )]
class NormAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf( AnalyzerOptions::class , new NormAnalyzer( locale : 'en' ) ) ;
    }

    public function testMinimalPayloadCarriesOnlyTypeAndLocale() :void
    {
        $payload = new NormAnalyzer( locale : 'en' )->toArray() ;

        $this->assertSame
        (
            [
                'type'       => 'norm' ,
                'properties' => [ 'locale' => 'en' ] ,
            ] ,
            $payload ,
        ) ;
    }

    public function testOptionalFlagsAreEmittedWhenSet() :void
    {
        $payload = new NormAnalyzer
        (
            locale : 'fr.utf-8' ,
            case   : 'lower' ,
            accent : false ,
        )->toArray() ;

        $this->assertSame
        (
            [
                'locale' => 'fr.utf-8' ,
                'case'   => 'lower' ,
                'accent' => false ,
            ] ,
            $payload[ 'properties' ] ,
        ) ;
    }

    public function testNullOptionalFlagsAreOmitted() :void
    {
        $payload = new NormAnalyzer( locale : 'en' )->toArray() ;

        $this->assertArrayNotHasKey( 'case'   , $payload[ 'properties' ] ) ;
        $this->assertArrayNotHasKey( 'accent' , $payload[ 'properties' ] ) ;
    }
}
