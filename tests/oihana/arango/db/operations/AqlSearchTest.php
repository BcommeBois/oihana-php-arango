<?php

namespace tests\oihana\arango\db\operations;

use ReflectionClass;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ConditionOptimization;
use oihana\arango\db\enums\CountApproximate;
use oihana\arango\db\options\SearchOptions;
use oihana\enums\Char;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operations\aqlSearch;

final class AqlSearchTest extends TestCase
{
    public function testReturnsEmptyWhenNoSearchKey(): void
    {
        $this->assertSame(Char::EMPTY, aqlSearch([]));
        $this->assertSame(Char::EMPTY, aqlSearch([AQL::FILTER => 'foo']));
    }

    public function testWithValidSearchString(): void
    {
        $search = "ANALYZER(PHRASE(doc.text, 'search phrase'), 'text_en')" ;
        $result = aqlSearch( [ AQL::SEARCH => $search ] );
        $this->assertSame
        (
            "SEARCH $search" ,
            $result
        ) ;
    }

    public function testWithEmptySearchString(): void
    {
        $result = aqlSearch( [ AQL::SEARCH => Char::EMPTY ] );
        $this->assertSame('' , $result ) ;
    }

    public function testAnalyzerWrapsTheExpression(): void
    {
        $this->assertSame
        (
            'SEARCH ANALYZER(doc.text == "foo","text_fr")' ,
            aqlSearch( [ AQL::SEARCH => 'doc.text == "foo"' , AQL::ANALYZER => 'text_fr' ] )
        ) ;
    }

    public function testEmptyAnalyzerIsIgnored(): void
    {
        $this->assertSame
        (
            'SEARCH doc.text == "foo"' ,
            aqlSearch( [ AQL::SEARCH => 'doc.text == "foo"' , AQL::ANALYZER => Char::EMPTY ] )
        ) ;
    }

    public function testSearchOptionsFromArray(): void
    {
        $this->assertSame
        (
            'SEARCH doc.x == 1 OPTIONS {"conditionOptimization":"none","countApproximate":"cost"}' ,
            aqlSearch
            ([
                AQL::SEARCH         => 'doc.x == 1' ,
                AQL::SEARCH_OPTIONS =>
                [
                    'conditionOptimization' => ConditionOptimization::NONE ,
                    'countApproximate'      => CountApproximate::COST ,
                ] ,
            ])
        ) ;
    }

    public function testSearchOptionsCollections(): void
    {
        $this->assertSame
        (
            'SEARCH doc.x == 1 OPTIONS {"collections":["coll1","coll2"]}' ,
            aqlSearch
            ([
                AQL::SEARCH         => 'doc.x == 1' ,
                AQL::SEARCH_OPTIONS => [ 'collections' => [ 'coll1' , 'coll2' ] ] ,
            ])
        ) ;
    }

    public function testSearchOptionsFromSearchOptionsInstance(): void
    {
        $this->assertSame
        (
            'SEARCH doc.x == 1 OPTIONS {"parallelism":4}' ,
            aqlSearch
            ([
                AQL::SEARCH         => 'doc.x == 1' ,
                AQL::SEARCH_OPTIONS => new SearchOptions( [ 'parallelism' => 4 ] ) ,
            ])
        ) ;
    }

    public function testSearchOptionsFromJsonString(): void
    {
        $this->assertSame
        (
            'SEARCH doc.x == 1 OPTIONS {"parallelism":2}' ,
            aqlSearch
            ([
                AQL::SEARCH         => 'doc.x == 1' ,
                AQL::SEARCH_OPTIONS => '{"parallelism":2}' ,
            ])
        ) ;
    }

    public function testUnknownSearchOptionKeysAreDropped(): void
    {
        $this->assertSame
        (
            'SEARCH doc.x == 1' ,
            aqlSearch( [ AQL::SEARCH => 'doc.x == 1' , AQL::SEARCH_OPTIONS => [ 'bogus' => 1 ] ] )
        ) ;
    }

    public function testAnalyzerAndOptionsCombined(): void
    {
        $this->assertSame
        (
            'SEARCH ANALYZER(doc.x == 1,"text_en") OPTIONS {"countApproximate":"cost"}' ,
            aqlSearch
            ([
                AQL::SEARCH         => 'doc.x == 1' ,
                AQL::ANALYZER       => 'text_en' ,
                AQL::SEARCH_OPTIONS => [ 'countApproximate' => CountApproximate::COST ] ,
            ])
        ) ;
    }

    public function testOptionsWithoutSearchAreIgnored(): void
    {
        $this->assertSame
        (
            Char::EMPTY ,
            aqlSearch( [ AQL::ANALYZER => 'text_en' , AQL::SEARCH_OPTIONS => [ 'parallelism' => 4 ] ] )
        ) ;
    }

    public function testConditionOptimizationConstants(): void
    {
        $constants = ( new ReflectionClass( ConditionOptimization::class ) )->getConstants() ;
        $this->assertSame( [ 'AUTO' => 'auto' , 'NONE' => 'none' ] , $constants ) ;
    }

    public function testCountApproximateConstants(): void
    {
        $constants = ( new ReflectionClass( CountApproximate::class ) )->getConstants() ;
        $this->assertSame( [ 'COST' => 'cost' , 'EXACT' => 'exact' ] , $constants ) ;
    }
}
