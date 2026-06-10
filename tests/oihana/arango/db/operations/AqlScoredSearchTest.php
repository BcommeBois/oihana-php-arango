<?php

namespace tests\oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionClass;

use oihana\arango\db\enums\ConditionOptimization;
use oihana\arango\db\enums\SearchScorer;
use oihana\arango\db\options\SearchOptions;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\functions\search\phrase;
use function oihana\arango\db\operations\aqlScoredSearch;

final class AqlScoredSearchTest extends TestCase
{
    public function testMinimalForm(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 LET score = BM25(doc) SORT score DESC LIMIT 10 RETURN doc' ,
            aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 10 )
        ) ;
    }

    public function testAnalyzerWrapsTheExpression(): void
    {
        $this->assertSame
        (
            'FOR doc IN placesView SEARCH ANALYZER(PHRASE(doc.name,"scierie"),"text_fr") LET score = BM25(doc) SORT score DESC LIMIT 20 RETURN doc' ,
            aqlScoredSearch
            (
                view     : 'placesView' ,
                search   : phrase( 'doc.name' , 'scierie' ) ,
                limit    : 20 ,
                analyzer : 'text_fr' ,
            )
        ) ;
    }

    public function testSearchOptions(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 OPTIONS {"conditionOptimization":"none"} LET score = BM25(doc) SORT score DESC LIMIT 5 RETURN doc' ,
            aqlScoredSearch
            (
                view    : 'myView' ,
                search  : 'doc.x == 1' ,
                limit   : 5 ,
                options : [ 'conditionOptimization' => ConditionOptimization::NONE ] ,
            )
        ) ;
    }

    public function testSearchOptionsInstance(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 OPTIONS {"parallelism":4} LET score = BM25(doc) SORT score DESC LIMIT 5 RETURN doc' ,
            aqlScoredSearch
            (
                view    : 'myView' ,
                search  : 'doc.x == 1' ,
                limit   : 5 ,
                options : new SearchOptions( [ 'parallelism' => 4 ] ) ,
            )
        ) ;
    }

    public function testBm25Tuning(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 LET score = BM25(doc,2.4,1) SORT score DESC LIMIT 5 RETURN doc' ,
            aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 5 , k: 2.4 , b: 1.0 )
        ) ;
    }

    public function testTfidfScorer(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 LET score = TFIDF(doc) SORT score DESC LIMIT 5 RETURN doc' ,
            aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 5 , scorer: SearchScorer::TFIDF )
        ) ;
    }

    public function testTfidfNormalize(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 LET score = TFIDF(doc,true) SORT score DESC LIMIT 5 RETURN doc' ,
            aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 5 , scorer: SearchScorer::TFIDF , normalize: true )
        ) ;
    }

    public function testOffsetPagination(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 LET score = BM25(doc) SORT score DESC LIMIT 20, 10 RETURN doc' ,
            aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 10 , offset: 20 )
        ) ;
    }

    public function testCustomRefsAndReturn(): void
    {
        $this->assertSame
        (
            'FOR d IN myView SEARCH d.x == 1 LET s = BM25(d) SORT s DESC LIMIT 5 RETURN MERGE(d, { score: s })' ,
            aqlScoredSearch
            (
                view     : 'myView' ,
                search   : 'd.x == 1' ,
                limit    : 5 ,
                docRef   : 'd' ,
                scoreRef : 's' ,
                return   : 'MERGE(d, { score: s })' ,
            )
        ) ;
    }

    public function testSearchArrayIsCompiled(): void
    {
        $this->assertSame
        (
            'FOR doc IN myView SEARCH doc.x == 1 && doc.y == 2 LET score = BM25(doc) SORT score DESC LIMIT 5 RETURN doc' ,
            aqlScoredSearch( view: 'myView' , search: [ 'doc.x == 1' , '&&' , 'doc.y == 2' ] , limit: 5 )
        ) ;
    }

    public function testUnknownScorerThrows(): void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( "unsupported scorer 'bogus'" ) ;
        aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 5 , scorer: 'bogus' ) ;
    }

    public function testKOrBWithTfidfThrows(): void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( "'k' and 'b' arguments only apply to the 'bm25' scorer" ) ;
        aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 5 , scorer: SearchScorer::TFIDF , k: 1.2 ) ;
    }

    public function testNormalizeWithBm25Throws(): void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( "'normalize' argument only applies to the 'tfidf' scorer" ) ;
        aqlScoredSearch( view: 'myView' , search: 'doc.x == 1' , limit: 5 , normalize: false ) ;
    }

    public function testSearchScorerConstants(): void
    {
        $constants = ( new ReflectionClass( SearchScorer::class ) )->getConstants() ;
        $this->assertSame( [ 'BM25' => 'bm25' , 'TFIDF' => 'tfidf' ] , $constants ) ;
    }
}
