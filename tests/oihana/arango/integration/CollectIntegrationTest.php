<?php

namespace tests\oihana\arango\integration ;

use oihana\arango\clients\Database ;
use oihana\arango\controllers\traits\PrepareGroupTrait ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\Facet ;
use oihana\arango\models\enums\Group as GroupSpec ;
use oihana\arango\models\traits\queries\FacetCountsQueryTrait ;
use oihana\arango\models\traits\queries\ListQueryTrait ;

use tests\oihana\arango\models\traits\queries\ListQueryTraitStub ;

use Slim\Psr7\Factory\ServerRequestFactory ;

use PHPUnit\Framework\Attributes\Group ;

/**
 * Live validation of the `COLLECT` integration in {@see \oihana\arango\models\traits\queries\ListQueryTrait::buildListQuery()}.
 *
 * Each grouped query is built by the real model trait, executed against a
 * seeded, disposable ArangoDB database, and its buckets are asserted. This
 * proves the generated `FOR ... COLLECT ... RETURN` actually parses AND groups
 * as intended — something the unit suite (which only freezes the AQL string)
 * cannot.
 */
#[Group( 'integration' )]
class CollectIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_collect_it' ;

    private const string COLLECTION = 'sales' ;

    protected static function seed( Database $db ) :void
    {
        $sales = $db->collection( self::COLLECTION ) ;
        $sales->create() ;
        // category A : 3 rows (100 + 200 + 30 = 330) ; category B : 2 rows (50 + 70 = 120)
        // tags counts : x → 3 (s1,s2,s5) ; y → 3 (s1,s3,s4) ; z → 1 (s4)
        // offers[*].priceCurrency counts (per element)         : EUR → 4 (s1,s2,s4,s5) ; USD → 2 (s1,s3) ; GBP → 1 (s4)
        // offers[*].prices[*].currency counts (per leaf, nested) : EUR → 3 ; USD → 3 ; GBP → 2 (s4's empty prices array adds nothing)
        $sales->insert( [ '_key' => 's1' , 'category' => 'A' , 'amount' => 100 , 'tags' => [ 'x' , 'y' ] , 'offers' => [ [ 'priceCurrency' => 'EUR' , 'prices' => [ [ 'currency' => 'USD' ] , [ 'currency' => 'USD' ] ] ] , [ 'priceCurrency' => 'USD' , 'prices' => [ [ 'currency' => 'EUR' ] ] ] ] ] ) ;
        $sales->insert( [ '_key' => 's2' , 'category' => 'A' , 'amount' => 200 , 'tags' => [ 'x' ]       , 'offers' => [ [ 'priceCurrency' => 'EUR' , 'prices' => [ [ 'currency' => 'EUR' ] ] ] ] ] ) ;
        $sales->insert( [ '_key' => 's3' , 'category' => 'B' , 'amount' => 50  , 'tags' => [ 'y' ]       , 'offers' => [ [ 'priceCurrency' => 'USD' , 'prices' => [ [ 'currency' => 'GBP' ] ] ] ] ] ) ;
        $sales->insert( [ '_key' => 's4' , 'category' => 'B' , 'amount' => 70  , 'tags' => [ 'y' , 'z' ] , 'offers' => [ [ 'priceCurrency' => 'EUR' , 'prices' => [ [ 'currency' => 'EUR' ] , [ 'currency' => 'GBP' ] ] ] , [ 'priceCurrency' => 'GBP' , 'prices' => [] ] ] ] ) ;
        $sales->insert( [ '_key' => 's5' , 'category' => 'A' , 'amount' => 30  , 'tags' => [ 'x' ]       , 'offers' => [ [ 'priceCurrency' => 'EUR' , 'prices' => [ [ 'currency' => 'USD' ] ] ] ] ] ) ;
    }

    private function stub() :ListQueryTraitStub
    {
        $stub = new ListQueryTraitStub() ;
        $stub->collection = self::COLLECTION ;
        return $stub ;
    }

    /**
     * Builds the listing query with the real trait and runs it live.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rows( array $init ) :array
    {
        $binds = [] ;
        $aql   = $this->stub()->buildListQuery( $init , $binds ) ;
        return iterator_to_array( self::$db->query( $aql , $binds ) , false ) ;
    }

    public function testDistinctValues() :void
    {
        $rows = $this->rows( [ Arango::COLLECT => [ AQL::ASSIGN => [ 'category' => 'doc.category' ] ] ] ) ;

        $categories = array_map( fn( $r ) => $r['category'] , $rows ) ;
        sort( $categories ) ;
        $this->assertSame( [ 'A' , 'B' ] , $categories ) ;
    }

    public function testCountPerGroup() :void
    {
        $rows = $this->rows(
        [
            Arango::COLLECT => [ AQL::ASSIGN => [ 'category' => 'doc.category' ] , AQL::WITH_COUNT => 'count' ] ,
        ]) ;

        $counts = [] ;
        foreach ( $rows as $r ) { $counts[ $r['category'] ] = $r['count'] ; }
        $this->assertSame( [ 'A' => 3 , 'B' => 2 ] , $counts ) ;
    }

    public function testSumPerGroup() :void
    {
        $rows = $this->rows(
        [
            Arango::COLLECT => [
                AQL::ASSIGN    => [ 'category' => 'doc.category' ] ,
                AQL::AGGREGATE => [ 'total' => 'SUM(doc.amount)' ] ,
            ] ,
        ]) ;

        $totals = [] ;
        foreach ( $rows as $r ) { $totals[ $r['category'] ] = $r['total'] ; }
        $this->assertSame( [ 'A' => 330 , 'B' => 120 ] , $totals ) ;
    }

    public function testTotalCountScalar() :void
    {
        $rows = $this->rows( [ Arango::COLLECT => [ AQL::WITH_COUNT => 'length' ] ] ) ;

        $this->assertSame( [ 5 ] , $rows ) ;
    }

    // ---------------------------------------------------------------- high-level Group spec

    public function testGroupHighLevelCountSorted() :void
    {
        // Arango::GROUP with WITH COUNT + descending sort on the count variable.
        $rows = $this->rows(
        [
            Arango::GROUP => [ GroupSpec::BY => 'category' , GroupSpec::COUNT => true , GroupSpec::SORT => '-count' ] ,
        ]) ;

        // A (3) before B (2) thanks to SORT count DESC.
        $this->assertSame(
            [ [ 'category' => 'A' , 'count' => 3 ] , [ 'category' => 'B' , 'count' => 2 ] ] ,
            array_map( fn( $r ) => [ 'category' => $r['category'] , 'count' => $r['count'] ] , $rows )
        ) ;
    }

    public function testGroupHighLevelSumAndCount() :void
    {
        // Sum per group + count emitted as LENGTH(1) (aggregates present).
        $rows = $this->rows(
        [
            Arango::GROUP =>
            [
                GroupSpec::BY    => 'category' ,
                GroupSpec::AGG   => [ 'total' => 'sum:amount' ] ,
                GroupSpec::COUNT => 'n' ,
            ] ,
        ]) ;

        $byCat = [] ;
        foreach ( $rows as $r ) { $byCat[ $r['category'] ] = [ $r['total'] , $r['n'] ] ; }
        $this->assertSame( [ 'A' => [ 330 , 3 ] , 'B' => [ 120 , 2 ] ] , $byCat ) ;
    }

    public function testHttpGroupByEndToEnd() :void
    {
        // Full HTTP chain: ?groupBy=category -> prepareGroup() -> Arango::GROUP -> live AQL.
        $host = new class { use PrepareGroupTrait { prepareGroup as public ; } } ;
        $request = new ServerRequestFactory()->createServerRequest( 'GET' , '/' )
            ->withQueryParams( [ 'groupBy' => 'category' ] ) ;

        $group = $host->prepareGroup( $request ) ;
        $rows  = $this->rows( [ Arango::GROUP => $group ] ) ;

        $counts = [] ;
        foreach ( $rows as $r ) { $counts[ $r['category'] ] = $r['count'] ; }
        $this->assertSame( [ 'A' => 3 , 'B' => 2 ] , $counts ) ;
    }

    public function testFacetCountsLive() :void
    {
        // Several independent bucket counts in one query: a FIELD facet (category)
        // and an array-membership IN facet (tags), both over the same set.
        $stub = new class
        {
            use ListQueryTrait , FacetCountsQueryTrait ;

            public function __construct()
            {
                $this->initializeQueryID( 'q' ) ;
                $this->collection = 'sales' ;
                $this->facets =
                [
                    'category' => [ Facet::TYPE => Facet::FIELD ] ,
                    'tags'     => [ Facet::TYPE => Facet::IN ] ,
                ] ;
            }
        } ;

        $binds = [] ;
        $aql   = $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category,tags' ] , $binds ) ;
        $row   = iterator_to_array( self::$db->query( $aql , $binds ) , false )[ 0 ] ;

        $category = [] ;
        foreach ( $row['category'] as $bucket ) { $category[ $bucket['value'] ] = $bucket['count'] ; }
        ksort( $category ) ;
        $this->assertSame( [ 'A' => 3 , 'B' => 2 ] , $category ) ;

        $tags = [] ;
        foreach ( $row['tags'] as $bucket ) { $tags[ $bucket['value'] ] = $bucket['count'] ; }
        ksort( $tags ) ;
        $this->assertSame( [ 'x' => 3 , 'y' => 3 , 'z' => 1 ] , $tags ) ;
    }

    public function testFacetCountsLiveCountsAnObjectArraySubField() :void
    {
        // Count by a sub-field of an embedded object array (`offers[*].priceCurrency`):
        // the `[*]` marker unwinds the array and projects the sub-field, so each
        // element is counted as its own bucket — proves the generated FOR/COLLECT
        // actually parses AND groups per element on a real server.
        $stub = new class
        {
            use ListQueryTrait , FacetCountsQueryTrait ;

            public function __construct()
            {
                $this->initializeQueryID( 'q' ) ;
                $this->collection = 'sales' ;
                $this->facets =
                [
                    'currency' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'offers[*].priceCurrency' ] ,
                ] ;
            }
        } ;

        $binds = [] ;
        $aql   = $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currency' ] , $binds ) ;
        $row   = iterator_to_array( self::$db->query( $aql , $binds ) , false )[ 0 ] ;

        $currency = [] ;
        foreach ( $row['currency'] as $bucket ) { $currency[ $bucket['value'] ] = $bucket['count'] ; }
        ksort( $currency ) ;
        $this->assertSame( [ 'EUR' => 4 , 'GBP' => 1 , 'USD' => 2 ] , $currency ) ;
    }

    public function testFacetCountsLiveCountsANestedObjectArraySubField() :void
    {
        // Count by a sub-field of a *nested* object array (`offers[*].prices[*].currency`):
        // each `[*]` opens a FOR hop, so each innermost price is counted as its own
        // bucket — proves the generated nested FOR/COLLECT parses AND groups per leaf
        // on a real server (and that an empty inner array contributes nothing).
        $stub = new class
        {
            use ListQueryTrait , FacetCountsQueryTrait ;

            public function __construct()
            {
                $this->initializeQueryID( 'q' ) ;
                $this->collection = 'sales' ;
                $this->facets =
                [
                    'currency' => [ Facet::TYPE => Facet::IN , Facet::PROPERTY => 'offers[*].prices[*].currency' ] ,
                ] ;
            }
        } ;

        $binds = [] ;
        $aql   = $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currency' ] , $binds ) ;
        $row   = iterator_to_array( self::$db->query( $aql , $binds ) , false )[ 0 ] ;

        $currency = [] ;
        foreach ( $row['currency'] as $bucket ) { $currency[ $bucket['value'] ] = $bucket['count'] ; }
        ksort( $currency ) ;
        $this->assertSame( [ 'EUR' => 3 , 'GBP' => 2 , 'USD' => 3 ] , $currency ) ;
    }
}
