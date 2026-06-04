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
 * Live validation of the value-side (right) `alt` transformation: the
 * `?filter=` object form `alt:{ key:<chain>, val:<chain|true> }` is built by the
 * real Documents::prepareFilter(), embedded in a `FOR doc IN people FILTER ..`
 * query and executed against a seeded, disposable ArangoDB database. This proves
 * the symmetric comparisons (e.g. case-insensitive equality, Option A array
 * expansion) actually parse AND filter — not just that the AQL string matches.
 */
#[Group( 'integration' )]
class FilterAltIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_filter_alt_it' ;

    private const string COLLECTION = 'people' ;

    protected static function seed( Database $db ) :void
    {
        $people = $db->collection( self::COLLECTION ) ;
        $people->create() ;
        // p1/p2 are the SAME email in different cases ; p3 differs. contactPoint
        // is an embedded array of objects with a mixed-case email sub-field.
        // `items` is an embedded array of objects (price per line) for the `pluck` alt.
        // `discount` is present on p1, ABSENT on p2 (null), 0 on p3 — for the coalesce alt.
        // `scores` is an array of numbers for the AT LEAST (n) quantifier.
        $people->insert( [ '_key' => 'p1' , 'email' => 'Jean@X.COM' , 'category' => 'Tech'  , 'price' => -10 , 'discount' => 5 , 'created' => '2024-01-15' , 'scores' => [ 90 , 85 ] , 'contactPoint' => [ [ 'email' => 'Admin@ACME.com' ] ] , 'items' => [ [ 'price' => 50 ] , [ 'price' => 150 ] ] ] ) ; // avg 100
        $people->insert( [ '_key' => 'p2' , 'email' => 'jean@x.com' , 'category' => 'NEWS'  , 'price' =>  10 ,                   'created' => '2024-06-15' , 'scores' => [ 90 , 40 ] , 'contactPoint' => [ [ 'email' => 'admin@acme.com' ] ] , 'items' => [ [ 'price' => 10 ] ] ] ) ; // avg 10
        $people->insert( [ '_key' => 'p3' , 'email' => 'bob@x.com'  , 'category' => 'sport' , 'price' =>  -5 , 'discount' => 0 , 'created' => '2024-12-15' , 'scores' => [ 30 , 20 ] , 'contactPoint' => [ [ 'email' => 'other@x.com' ] ] , 'items' => [ [ 'price' => 300 ] ] ] ) ; // avg 300
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
                'email'    => FilterType::STRING ,
                'category' => FilterType::STRING ,
                'price'    => FilterType::NUMBER ,
                'discount' => FilterType::NUMBER ,
                'created'  => FilterType::DATE ,
                'items'    => FilterType::ARRAY ,
                'scores'   => FilterType::ARRAY ,
            ]
        ]);
    }

    public function testAtLeastQuantifierMatchesWhenEnoughElementsQualify() :void
    {
        // at least 2 scores >= 80 → only p1 (90,85). p2 (90,40) has 1, p3 (30,20) has 0.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.ge' , 2 ] , 'val' => 80 ] , $binds ) ;
        $this->assertSame( [ 'p1' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testNumberBetweenMatchesInclusiveRange() :void
    {
        // -8 <= price <= 8 → only p3 (-5). p1=-10, p2=10 excluded.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => -8 , 'max' => 8 ] , $binds ) ;
        $this->assertSame( [ 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testDateBetweenMatchesInclusiveRange() :void
    {
        // 2024-03-01 <= created <= 2024-09-01 → only p2 (2024-06-15).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'created' , 'op' => 'between' , 'min' => '2024-03-01' , 'max' => '2024-09-01' ] , $binds ) ;
        $this->assertSame( [ 'p2' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testDateBetweenOmittedMaxIsBoundedByNow() :void
    {
        // created >= 2024-01-01 && created <= now → all three (seed dates are in the past).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'created' , 'op' => 'between' , 'min' => '2024-01-01' ] , $binds ) ;
        $this->assertSame( [ 'p1' , 'p2' , 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testMirrorLowerMatchesCaseInsensitively() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ,
            $binds
        ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testLegacyKeyOnlyLeavesValueRawSoNothingMatches() :void
    {
        // LOWER(doc.email) == "JEAN@X.COM" — value never lowered → no match.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => 'lower' ] ,
            $binds
        ) ;
        $this->assertSame( [] , $this->keys( $filter , $binds ) ) ;
    }

    public function testArrayValueOptionAMatchesEachLoweredElement() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'category' , 'op' => 'in' , 'val' => [ 'TECH' , 'NEWS' ] , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ,
            $binds
        ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testNumberAbsBothSides() :void
    {
        // |price| >= 10 → p1(-10) and p2(10).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'price' , 'op' => 'ge' , 'val' => 10 , 'alt' => [ 'key' => 'abs' , 'val' => true ] ] ,
            $binds
        ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testCoalesceTreatsMissingFieldAsDefault() :void
    {
        // NOT_NULL(doc.discount, 0) == 0 → p2 (no discount → 0) and p3 (0). p1 has 5.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'discount' , 'op' => 'eq' , 'val' => 0 , 'alt' => [ [ 'coalesce' , 0 ] ] ] ,
            $binds
        ) ;
        $this->assertSame( [ 'p2' , 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testPluckThenAverageAggregatesEmbeddedObjects() :void
    {
        // AVERAGE(doc.items[* RETURN CURRENT.price]) >= 100 → p1 (avg 100) + p3 (avg 300).
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'items' , 'op' => 'ge' , 'val' => 100 , 'alt' => [ [ 'pluck' , 'price' ] , 'avg' ] ] ,
            $binds
        ) ;
        $this->assertSame( [ 'p1' , 'p3' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testHierarchicalArrayExpansionAltMatchesCaseInsensitively() :void
    {
        // LENGTH(doc.contactPoint[* FILTER LOWER(CURRENT.email) == LOWER(@v)]) > 0
        $binds  = [] ;
        $filter = $this->modelHier()->prepareFilter
        (
            [ 'key' => 'contactPoint[*].email' , 'val' => 'ADMIN@ACME.COM' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ,
            $binds
        ) ;
        $this->assertSame( [ 'p1' , 'p2' ] , $this->keys( $filter , $binds ) ) ;
    }

    private function modelHier() :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        return new Documents( $container ,
        [
            AQL::COLLECTION => self::COLLECTION ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'email' => FilterType::STRING ],
                ],
            ]
        ]);
    }
}
