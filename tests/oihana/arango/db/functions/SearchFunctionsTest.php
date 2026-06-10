<?php

namespace tests\oihana\arango\db\functions;

use ReflectionClass;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\SearchFunction;

use function oihana\arango\db\functions\search\analyzer;
use function oihana\arango\db\functions\search\bm25;
use function oihana\arango\db\functions\search\boost;
use function oihana\arango\db\functions\search\exists;
use function oihana\arango\db\functions\search\inRange;
use function oihana\arango\db\functions\search\levenshteinMatch;
use function oihana\arango\db\functions\search\minhashMatch;
use function oihana\arango\db\functions\search\minMatch;
use function oihana\arango\db\functions\search\ngramMatch;
use function oihana\arango\db\functions\search\phrase;
use function oihana\arango\db\functions\search\tfidf;

class SearchFunctionsTest extends TestCase
{
    // ----- analyzer

    public function testAnalyzer(): void
    {
        $this->assertSame
        (
            'ANALYZER(doc.text == "bar","delimiter")' ,
            analyzer( 'doc.text == "bar"' , 'delimiter' )
        );
    }

    public function testAnalyzerWrapsNestedFunctions(): void
    {
        $this->assertSame
        (
            'ANALYZER(PHRASE(doc.text,"quick fox"),"text_en")' ,
            analyzer( phrase( 'doc.text' , 'quick fox' ) , 'text_en' )
        );
    }

    // ----- bm25

    public function testBm25DocOnly(): void
    {
        $this->assertSame( 'BM25(doc)' , bm25( 'doc' ) );
    }

    public function testBm25WithK(): void
    {
        $this->assertSame( 'BM25(doc,2.4)' , bm25( 'doc' , 2.4 ) );
    }

    public function testBm25WithKAndB(): void
    {
        $this->assertSame( 'BM25(doc,2.4,1)' , bm25( 'doc' , 2.4 , 1.0 ) );
    }

    public function testBm25FillsDefaultKWhenOnlyBIsGiven(): void
    {
        $this->assertSame( 'BM25(doc,1.2,0.5)' , bm25( 'doc' , b: 0.5 ) );
    }

    // ----- boost

    public function testBoostWithFloat(): void
    {
        $this->assertSame( 'BOOST(doc.text == "foo",2.5)' , boost( 'doc.text == "foo"' , 2.5 ) );
    }

    public function testBoostWithInt(): void
    {
        $this->assertSame( 'BOOST(doc.text == "foo",3)' , boost( 'doc.text == "foo"' , 3 ) );
    }

    // ----- exists

    public function testExistsPathOnly(): void
    {
        $this->assertSame( 'EXISTS(doc.text)' , exists( 'doc.text' ) );
    }

    public function testExistsWithType(): void
    {
        $this->assertSame( 'EXISTS(doc.text,"string")' , exists( 'doc.text' , 'string' ) );
    }

    public function testExistsWithNestedType(): void
    {
        $this->assertSame( 'EXISTS(doc.attr,"nested")' , exists( 'doc.attr' , 'nested' ) );
    }

    public function testExistsWithAnalyzerFillsTypeLiteral(): void
    {
        $this->assertSame
        (
            'EXISTS(doc.text,"analyzer","text_en")' ,
            exists( 'doc.text' , analyzer: 'text_en' )
        );
    }

    public function testExistsWithExplicitTypeAndAnalyzer(): void
    {
        $this->assertSame
        (
            'EXISTS(doc.text,"analyzer","text_fr")' ,
            exists( 'doc.text' , 'analyzer' , 'text_fr' )
        );
    }

    // ----- inRange

    public function testInRangeNumericBounds(): void
    {
        $this->assertSame
        (
            'IN_RANGE(doc.value,3,5,true,true)' ,
            inRange( 'doc.value' , 3 , 5 , true , true )
        );
    }

    public function testInRangeStringBounds(): void
    {
        $this->assertSame
        (
            'IN_RANGE(doc.value,"a","f",true,false)' ,
            inRange( 'doc.value' , 'a' , 'f' , true , false )
        );
    }

    public function testInRangeExclusiveBounds(): void
    {
        $this->assertSame
        (
            'IN_RANGE(doc.value,2.5,7.5,false,false)' ,
            inRange( 'doc.value' , 2.5 , 7.5 , false , false )
        );
    }

    // ----- levenshteinMatch

    public function testLevenshteinMatchMinimalForm(): void
    {
        $this->assertSame
        (
            'LEVENSHTEIN_MATCH(doc.text,"quikc",1)' ,
            levenshteinMatch( 'doc.text' , 'quikc' , 1 )
        );
    }

    public function testLevenshteinMatchWithTranspositions(): void
    {
        $this->assertSame
        (
            'LEVENSHTEIN_MATCH(doc.text,"quikc",2,false)' ,
            levenshteinMatch( 'doc.text' , 'quikc' , 2 , false )
        );
    }

    public function testLevenshteinMatchWithMaxTermsFillsTranspositions(): void
    {
        $this->assertSame
        (
            'LEVENSHTEIN_MATCH(doc.text,"quikc",2,true,32)' ,
            levenshteinMatch( 'doc.text' , 'quikc' , 2 , maxTerms: 32 )
        );
    }

    public function testLevenshteinMatchWithExplicitTranspositionsAndMaxTerms(): void
    {
        $this->assertSame
        (
            'LEVENSHTEIN_MATCH(doc.text,"quikc",2,false,16)' ,
            levenshteinMatch( 'doc.text' , 'quikc' , 2 , false , 16 )
        );
    }

    public function testLevenshteinMatchWithPrefixFillsDefaults(): void
    {
        $this->assertSame
        (
            'LEVENSHTEIN_MATCH(doc.text,"kc",1,true,64,"qui")' ,
            levenshteinMatch( 'doc.text' , 'kc' , 1 , prefix: 'qui' )
        );
    }

    public function testLevenshteinMatchFullForm(): void
    {
        $this->assertSame
        (
            'LEVENSHTEIN_MATCH(doc.text,"kc",1,false,0,"qui")' ,
            levenshteinMatch( 'doc.text' , 'kc' , 1 , false , 0 , 'qui' )
        );
    }

    // ----- minhashMatch

    public function testMinhashMatchWithoutThreshold(): void
    {
        $this->assertSame
        (
            'MINHASH_MATCH(doc.text,"the quick brown fox","myMinHash")' ,
            minhashMatch( 'doc.text' , 'the quick brown fox' , 'myMinHash' )
        );
    }

    public function testMinhashMatchWithThresholdIsReorderedBeforeAnalyzer(): void
    {
        $this->assertSame
        (
            'MINHASH_MATCH(doc.text,"the quick brown fox",0.5,"myMinHash")' ,
            minhashMatch( 'doc.text' , 'the quick brown fox' , 'myMinHash' , 0.5 )
        );
    }

    // ----- minMatch

    public function testMinMatch(): void
    {
        $this->assertSame
        (
            'MIN_MATCH(doc.text == "quick",doc.text == "brown",doc.text == "fox",2)' ,
            minMatch( [ 'doc.text == "quick"' , 'doc.text == "brown"' , 'doc.text == "fox"' ] , 2 )
        );
    }

    public function testMinMatchSingleExpression(): void
    {
        $this->assertSame
        (
            'MIN_MATCH(doc.value > 1,1)' ,
            minMatch( [ 'doc.value > 1' ] , 1 )
        );
    }

    // ----- ngramMatch

    public function testNgramMatchWithoutThreshold(): void
    {
        $this->assertSame
        (
            'NGRAM_MATCH(doc.text,"quick fox","bigram")' ,
            ngramMatch( 'doc.text' , 'quick fox' , 'bigram' )
        );
    }

    public function testNgramMatchWithThresholdIsReorderedBeforeAnalyzer(): void
    {
        $this->assertSame
        (
            'NGRAM_MATCH(doc.text,"quick blue fox",0.4,"bigram")' ,
            ngramMatch( 'doc.text' , 'quick blue fox' , 'bigram' , 0.4 )
        );
    }

    // ----- phrase

    public function testPhraseSimpleString(): void
    {
        $this->assertSame
        (
            'PHRASE(doc.text,"quick fox","text_en")' ,
            phrase( 'doc.text' , 'quick fox' , 'text_en' )
        );
    }

    public function testPhraseWithoutAnalyzer(): void
    {
        $this->assertSame
        (
            'PHRASE(doc.text,"quick fox")' ,
            phrase( 'doc.text' , 'quick fox' )
        );
    }

    public function testPhraseQuotesAndEscapesTheString(): void
    {
        // json_encode escapes double quotes and non-ASCII characters (é → \\u00e9),
        // both valid JSON-style escapes in AQL string literals.
        $this->assertSame
        (
            'PHRASE(doc.name,"scierie de l\'\\u00e9vre \"sud\"","text_fr")' ,
            phrase( 'doc.name' , 'scierie de l\'évre "sud"' , 'text_fr' )
        );
    }

    public function testPhraseArrayFormWithSkipTokens(): void
    {
        $this->assertSame
        (
            'PHRASE(doc.text,["ipsum",2,"amet"],"text_en")' ,
            phrase( 'doc.text' , [ 'ipsum' , 2 , 'amet' ] , 'text_en' )
        );
    }

    public function testPhraseArrayFormWithObjectTokens(): void
    {
        $this->assertSame
        (
            'PHRASE(doc.text,["lorem",{"STARTS_WITH":["ips"]}],"text_en")' ,
            phrase( 'doc.text' , [ 'lorem' , [ 'STARTS_WITH' => [ 'ips' ] ] ] , 'text_en' )
        );
    }

    public function testPhraseArrayFormWithLevenshteinObjectToken(): void
    {
        $this->assertSame
        (
            'PHRASE(doc.text,[{"LEVENSHTEIN_MATCH":["quikc",2,false]}],"text_en")' ,
            phrase( 'doc.text' , [ [ 'LEVENSHTEIN_MATCH' => [ 'quikc' , 2 , false ] ] ] , 'text_en' )
        );
    }

    // ----- tfidf

    public function testTfidfDocOnly(): void
    {
        $this->assertSame( 'TFIDF(doc)' , tfidf( 'doc' ) );
    }

    public function testTfidfWithNormalize(): void
    {
        $this->assertSame( 'TFIDF(doc,true)'  , tfidf( 'doc' , true  ) );
        $this->assertSame( 'TFIDF(doc,false)' , tfidf( 'doc' , false ) );
    }

    // ----- enum

    public function testAllSearchFunctionConstants(): void
    {
        $constants = ( new ReflectionClass( SearchFunction::class ) )->getConstants();

        $expected =
        [
            'ANALYZER', 'BM25', 'BOOST', 'EXISTS', 'LEVENSHTEIN_MATCH',
            'MINHASH_MATCH', 'NGRAM_MATCH', 'OFFSET_INFO', 'PHRASE', 'TFIDF',
        ];

        $this->assertSame( $expected , array_keys( $constants ) );

        foreach ( $constants as $name => $value )
        {
            $this->assertSame( $name , $value , "Wrong constant value for $name" );
        }
    }
}
