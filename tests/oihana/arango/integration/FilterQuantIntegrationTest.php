<?php

namespace tests\oihana\arango\integration ;

use DI\Container ;
use Psr\Log\LoggerInterface ;
use Psr\Log\NullLogger ;

use oihana\arango\clients\Database ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Filter ;
use oihana\arango\models\Documents ;
use oihana\arango\models\enums\filters\FilterType ;

use PHPUnit\Framework\Attributes\Group ;

/**
 * Live validation of the unified `quant` element-axis quantifier: the `?filter=`
 * object is built by the real Documents::prepareFilter(), embedded in a
 * `FOR doc IN people FILTER ..` query and executed against a seeded, disposable
 * ArangoDB database. This proves the question-mark operator (object arrays) and
 * the array comparison operator (scalar arrays) actually parse AND filter — not
 * just that the AQL string matches — including the BC quant-less legacy forms.
 */
#[Group( 'integration' )]
class FilterQuantIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_filter_quant_it' ;

    private const string COLLECTION = 'people' ;

    protected static function seed( Database $db ) :void
    {
        $people = $db->collection( self::COLLECTION ) ;
        $people->create() ;
        // reviews: array of objects (rating) ; scores: array of numbers ;
        // contactPoint: array of objects (verified) — chosen so each quantifier
        // selects a distinct subset:
        //   p1: 3 ratings>=4, both scores>=80, all verified
        //   p2: 1 rating >=4, 1 score >=80,   mixed verified
        //   p3: 0 ratings>=4, 0 scores>=80,   none verified
        $people->insert( [ '_key' => 'p1' , 'reviews' => [ [ 'rating' => 5 ] , [ 'rating' => 4 ] , [ 'rating' => 4 ] ] , 'scores' => [ 90 , 85 ] , 'contactPoint' => [ [ 'verified' => true  ] , [ 'verified' => true  ] ] ] ) ;
        $people->insert( [ '_key' => 'p2' , 'reviews' => [ [ 'rating' => 5 ] , [ 'rating' => 2 ] ]                     , 'scores' => [ 90 , 40 ] , 'contactPoint' => [ [ 'verified' => true  ] , [ 'verified' => false ] ] ] ) ;
        $people->insert( [ '_key' => 'p3' , 'reviews' => [ [ 'rating' => 1 ] , [ 'rating' => 2 ] ]                     , 'scores' => [ 30 , 20 ] , 'contactPoint' => [ [ 'verified' => false ] ] ] ) ;
    }

    private function keys( string $filter , array $binds ) :array
    {
        $aql    = 'FOR doc IN ' . self::COLLECTION . ' FILTER ' . $filter . ' RETURN doc._key' ;
        $cursor = self::$db->query( $aql , $binds ) ;
        $keys   = array_map( 'strval' , iterator_to_array( $cursor , false ) ) ;
        sort( $keys ) ;
        return $keys ;
    }

    private function model() :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        return new Documents( $container ,
        [
            AQL::COLLECTION => self::COLLECTION ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'scores'  => FilterType::ARRAY ,
                'reviews' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'rating' => FilterType::NUMBER ],
                ],
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'verified' => FilterType::BOOL ],
                ],
            ]
        ]);
    }

    // ----- object arrays : question-mark operator -----

    public function testObjectAtLeastMatchesWhenEnoughElementsQualify() :void
    {
        // at least 3 reviews with rating >= 4 → only p1.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 3 ] , $binds ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testObjectAllMatchesWhenEveryElementQualifies() :void
    {
        // every review rating >= 4 → only p1 (5,4,4). p2 has a 2, p3 has 1,2.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 'all' ] , $binds ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testObjectNoneMatchesWhenNoElementQualifies() :void
    {
        // no review rating >= 4 → only p3 (1,2).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testObjectWithoutQuantIsExistential() :void
    {
        // at least one review rating >= 4 (legacy) → p1 and p2.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 ] , $binds ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( $filter , $binds ) ) ;
    }

    // ----- object arrays via match : question-mark operator -----

    public function testObjectMatchAllVerified() :void
    {
        // all contactPoint verified → only p1.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'contactPoint[*]' , 'match' => [ 'verified' => true ] , 'quant' => 'all' ] , $binds ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testObjectMatchNoneVerified() :void
    {
        // no contactPoint verified → only p3.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'contactPoint[*]' , 'match' => [ 'verified' => true ] , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    // ----- scalar arrays : array comparison operator -----

    public function testScalarAllScoresQualify() :void
    {
        // every score >= 80 → only p1 (90,85).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 'all' ] , $binds ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testScalarNoneScoresQualify() :void
    {
        // no score >= 80 → only p3 (30,20).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 'none' ] , $binds ) ;
        $this->assertSame( [ 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testScalarAtLeastTwoScoresQualify() :void
    {
        // at least 2 scores >= 80 → only p1.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 2 ] , $binds ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( $filter , $binds ) ) ;
    }
}
