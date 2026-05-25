<?php

namespace tests\oihana\arango\clients\analyzer ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\TextAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see TextAnalyzer} — the full-text tokenising analyzer
 * value object.
 */
#[CoversClass( TextAnalyzer::class )]
class TextAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf( AnalyzerOptions::class , new TextAnalyzer( locale : 'en' ) ) ;
    }

    public function testMinimalPayloadCarriesOnlyTypeAndLocale() :void
    {
        $payload = new TextAnalyzer( locale : 'en' )->toArray() ;

        $this->assertSame
        (
            [
                'type'       => 'text' ,
                'properties' => [ 'locale' => 'en' ] ,
            ] ,
            $payload ,
        ) ;
    }

    public function testAllOptionalFieldsAreEmittedWhenSet() :void
    {
        $payload = new TextAnalyzer
        (
            locale        : 'fr' ,
            case          : 'lower' ,
            accent        : false ,
            stemming      : true ,
            stopwords     : [ 'le' , 'la' , 'les' ] ,
            stopwordsPath : '/etc/stopwords/fr.txt' ,
            edgeNgram     : [ 'min' => 2 , 'max' => 5 , 'preserveOriginal' => true ] ,
        )->toArray() ;

        $this->assertSame
        (
            [
                'locale'        => 'fr' ,
                'case'          => 'lower' ,
                'accent'        => false ,
                'stemming'      => true ,
                'stopwords'     => [ 'le' , 'la' , 'les' ] ,
                'stopwordsPath' => '/etc/stopwords/fr.txt' ,
                'edgeNgram'     => [ 'min' => 2 , 'max' => 5 , 'preserveOriginal' => true ] ,
            ] ,
            $payload[ 'properties' ] ,
        ) ;
    }

    public function testNullOptionalFieldsAreOmitted() :void
    {
        $payload = new TextAnalyzer( locale : 'en' )->toArray() ;

        $this->assertSame( [ 'locale' => 'en' ] , $payload[ 'properties' ] ) ;
    }

    public function testStopwordsAreReindexed() :void
    {
        $payload = new TextAnalyzer
        (
            locale    : 'en' ,
            stopwords : [ 5 => 'the' , 10 => 'and' ] ,
        )->toArray() ;

        // Stopwords end up as a JSON array on the wire, so the value
        // object reindexes them on the way out.
        $this->assertSame( [ 'the' , 'and' ] , $payload[ 'properties' ][ 'stopwords' ] ) ;
    }
}
