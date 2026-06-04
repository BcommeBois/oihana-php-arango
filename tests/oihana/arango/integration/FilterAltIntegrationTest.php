<?php

namespace tests\oihana\arango\integration ;

use DI\Container ;
use Psr\Log\LoggerInterface ;
use Psr\Log\NullLogger ;

use oihana\arango\clients\Database ;
use oihana\arango\db\enums\AQL ;
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
        // p1/p2 are the SAME email in different cases ; p3 differs.
        $people->insert( [ '_key' => 'p1' , 'email' => 'Jean@X.COM' , 'category' => 'Tech'  , 'price' => -10 ] ) ;
        $people->insert( [ '_key' => 'p2' , 'email' => 'jean@x.com' , 'category' => 'NEWS'  , 'price' =>  10 ] ) ;
        $people->insert( [ '_key' => 'p3' , 'email' => 'bob@x.com'  , 'category' => 'sport' , 'price' =>  -5 ] ) ;
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
            ]
        ]);
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
}
