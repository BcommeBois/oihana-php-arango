<?php

namespace tests\oihana\arango\clients\analyzer ;

use InvalidArgumentException ;

use oihana\arango\clients\analyzer\AnalyzerOptions ;
use oihana\arango\clients\analyzer\NgramAnalyzer ;
use oihana\arango\clients\analyzer\NormAnalyzer ;
use oihana\arango\clients\analyzer\PipelineAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see PipelineAnalyzer} — the ordered chain (`norm` → `ngram`,
 * …) analyzer value object.
 */
#[CoversClass( PipelineAnalyzer::class )]
class PipelineAnalyzerTest extends TestCase
{
    public function testImplementsAnalyzerOptions() :void
    {
        $this->assertInstanceOf
        (
            AnalyzerOptions::class ,
            new PipelineAnalyzer( [ new NormAnalyzer( locale : 'fr' ) ] ) ,
        ) ;
    }

    public function testToArrayWrapsTheChainUnderPropertiesPipeline() :void
    {
        $payload = new PipelineAnalyzer
        ([
            new NormAnalyzer ( locale : 'fr' , case : 'lower' , accent : false ) ,
            new NgramAnalyzer( min : 3 , max : 5 , preserveOriginal : true ) ,
        ])->toArray() ;

        $this->assertSame
        (
            [
                'type'       => 'pipeline' ,
                'properties' =>
                [
                    'pipeline' =>
                    [
                        [
                            'type'       => 'norm' ,
                            'properties' => [ 'locale' => 'fr' , 'case' => 'lower' , 'accent' => false ] ,
                        ] ,
                        [
                            'type'       => 'ngram' ,
                            'properties' => [ 'min' => 3 , 'max' => 5 , 'preserveOriginal' => true ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
            $payload ,
        ) ;
    }

    public function testOrderIsPreservedAndEachMemberSerialised() :void
    {
        $payload = new PipelineAnalyzer
        ([
            new NormAnalyzer ( locale : 'fr' ) ,
            new NgramAnalyzer( min : 2 , max : 4 ) ,
        ])->toArray() ;

        $chain = $payload[ 'properties' ][ 'pipeline' ] ;

        $this->assertCount( 2 , $chain ) ;
        $this->assertSame( 'norm'  , $chain[ 0 ][ 'type' ] ) ; // norm first
        $this->assertSame( 'ngram' , $chain[ 1 ][ 'type' ] ) ; // ngram second
    }

    public function testEmptyPipelineIsRejected() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;

        new PipelineAnalyzer( [] ) ;
    }

    public function testNonAnalyzerOptionsMemberIsRejected() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'index 1' ) ;

        new PipelineAnalyzer
        ([
            new NormAnalyzer( locale : 'fr' ) ,
            'not-an-analyzer' ,
        ]) ;
    }
}
