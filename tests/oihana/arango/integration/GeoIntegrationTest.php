<?php

namespace tests\oihana\arango\integration ;

use DI\Container ;
use Psr\Log\LoggerInterface ;
use Psr\Log\NullLogger ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\collection\indexes\GeoIndex ;
use oihana\arango\db\enums\AQL ;
use oihana\arango\enums\Arango ;
use oihana\arango\enums\Filter ;
use oihana\arango\models\Documents ;
use oihana\arango\models\enums\filters\FilterParam ;
use oihana\arango\models\enums\filters\FilterType ;

use PHPUnit\Framework\Attributes\Group ;

/**
 * Live validation of the geospatial surface against a seeded, disposable
 * ArangoDB database: the `geo` `?filter=` distance operator
 * ({@see \oihana\arango\models\traits\aql\filters\HasFilterGeo}), the `?near=`
 * distance sort ({@see \oihana\arango\models\traits\aql\SortTrait}), and the
 * `GeoIndex` value object.
 *
 * The seed places five real French cities (with their actual WGS84 coordinates)
 * so the assertions exercise true geography: a latitude/longitude swap would
 * reorder the results and fail {@see testNearOrdersNearestFirst()} — something a
 * pure AQL-string unit test cannot catch.
 */
#[Group( 'integration' )]
class GeoIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_geo_it' ;

    private const string COLLECTION = 'places' ;

    protected static function seed( Database $db ) :void
    {
        $places = $db->collection( self::COLLECTION ) ;
        $places->create() ;

        // Real WGS84 coordinates. Great-circle distances from Paris (km, approx):
        // paris 0 < lille 203 < lyon 391 < bordeaux 498 < marseille 659.
        //
        // Every place carries the SAME point twice: once at the root, once inside an
        // `address` sub-record. That is what lets a case ask the identical question at
        // both depths and compare the answers — a nested `geo` used to be dropped
        // entirely, so "within 50 km" answered every city in the collection.
        $points =
        [
            'paris'     => [ 'Paris'     , 'capital' , 48.8566 ,  2.3522 ] ,
            'lille'     => [ 'Lille'     , 'city'    , 50.6292 ,  3.0573 ] ,
            'lyon'      => [ 'Lyon'      , 'city'    , 45.7640 ,  4.8357 ] ,
            'bordeaux'  => [ 'Bordeaux'  , 'city'    , 44.8378 , -0.5792 ] , // negative longitude
            'marseille' => [ 'Marseille' , 'city'    , 43.2965 ,  5.3698 ] ,
        ];

        foreach ( $points as $key => [ $name , $type , $latitude , $longitude ] )
        {
            $point = [ 'latitude' => $latitude , 'longitude' => $longitude ] ;

            $places->insert
            ([
                '_key'    => $key   ,
                'name'    => $name  ,
                'type'    => $type  ,
                'geo'     => $point ,
                'address' => [ 'geo' => $point ] ,
            ]) ;
        }
    }

    // ---- Helpers

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
                'geo'     => FilterType::GEO ,
                'type'    => FilterType::STRING ,
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS => [ 'geo' => FilterType::GEO ] ,
                ] ,
            ] ,
            // 'geo' is whitelisted so the ?near= distance dimension is allowed (fail-closed gate).
            AQL::SORTABLE   => [ 'name' , 'geo' ] ,
        ]);
    }

    private static function point( float $latitude , float $longitude , string $key = 'geo' ) :array
    {
        return [ FilterParam::KEY => $key , 'latitude' => $latitude , 'longitude' => $longitude ] ;
    }

    /**
     * Run a FILTER-only query and return the matching keys, sorted (order-independent).
     */
    private function filteredKeys( array $filterInit ) :array
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( $filterInit , $binds ) ;
        $aql    = 'FOR doc IN ' . self::COLLECTION . ' FILTER ' . $filter . ' RETURN doc._key' ;
        $keys   = array_map( 'strval' , iterator_to_array( self::$db->query( $aql , $binds ) , false ) ) ;
        sort( $keys ) ;
        return $keys ;
    }

    /**
     * Run a SORT-only query and return the keys in result order (order-preserving).
     */
    private function orderedKeys( array $sortInit ) :array
    {
        $binds = [] ;
        $sort  = $this->model()->prepareSort( $sortInit , binds: $binds ) ;
        $aql   = 'FOR doc IN ' . self::COLLECTION . ' SORT ' . $sort . ' RETURN doc._key' ;
        return array_map( 'strval' , iterator_to_array( self::$db->query( $aql , $binds ) , false ) ) ;
    }

    // ---- Distance filter

    public function testDistanceWithinSmallRadiusReturnsOnlyParis() :void
    {
        // within 50 km of Paris → only Paris (Lille is ~203 km away).
        $keys = $this->filteredKeys( [ 'key' => 'geo' , 'op' => 'distance' , 'val' => self::point( 48.8566 , 2.3522 ) , 'max' => 50_000 ] ) ;
        $this->assertSame( [ 'paris' ] , $keys ) ;
    }

    public function testDistanceWithinRadiusReturnsNearbyCities() :void
    {
        // within 300 km of Paris → Paris (0) + Lille (203). Lyon (391) excluded.
        $keys = $this->filteredKeys( [ 'key' => 'geo' , 'op' => 'distance' , 'val' => self::point( 48.8566 , 2.3522 ) , 'max' => 300_000 ] ) ;
        $this->assertSame( [ 'lille' , 'paris' ] , $keys ) ;
    }

    public function testDistanceAnnulusReturnsMiddleRing() :void
    {
        // ring 250 km..450 km from Paris → only Lyon (391). Lille (203) and Bordeaux (498) excluded.
        $keys = $this->filteredKeys( [ 'key' => 'geo' , 'op' => 'distance' , 'val' => self::point( 48.8566 , 2.3522 ) , 'min' => 250_000 , 'max' => 450_000 ] ) ;
        $this->assertSame( [ 'lyon' ] , $keys ) ;
    }

    /**
     * The same three questions, asked of a `geo` kept inside an `address` sub-record.
     *
     * Every place carries the identical point at both depths, so any difference in the
     * answers is the grammar's and not the data's. Before the hierarchical walk learned
     * `geo`, the nested spelling was dropped: the first case below answered all five
     * cities instead of Paris alone, in `200`.
     *
     * @throws ArangoException
     */
    public function testANestedGeoAnswersExactlyLikeTheRootOne() :void
    {
        $paris = fn( string $key ) => self::point( 48.8566 , 2.3522 , $key ) ;

        // within 50 km
        $this->assertSame
        (
            $this->filteredKeys( [ 'key' => 'geo'         , 'op' => 'distance' , 'val' => $paris( 'geo' )         , 'max' => 50_000 ] ) ,
            $this->filteredKeys( [ 'key' => 'address.geo' , 'op' => 'distance' , 'val' => $paris( 'address.geo' ) , 'max' => 50_000 ] )
        ) ;

        // within 300 km
        $this->assertSame
        (
            [ 'lille' , 'paris' ] ,
            $this->filteredKeys( [ 'key' => 'address.geo' , 'op' => 'distance' , 'val' => $paris( 'address.geo' ) , 'max' => 300_000 ] )
        ) ;

        // the annulus, 250 km .. 450 km
        $this->assertSame
        (
            [ 'lyon' ] ,
            $this->filteredKeys( [ 'key' => 'address.geo' , 'op' => 'distance' , 'val' => $paris( 'address.geo' ) , 'min' => 250_000 , 'max' => 450_000 ] )
        ) ;
    }

    public function testDistanceHandlesNegativeLongitude() :void
    {
        // within 50 km of Bordeaux (negative longitude) → only Bordeaux.
        $keys = $this->filteredKeys( [ 'key' => 'geo' , 'op' => 'distance' , 'val' => self::point( 44.8378 , -0.5792 ) , 'max' => 50_000 ] ) ;
        $this->assertSame( [ 'bordeaux' ] , $keys ) ;
    }

    // ---- Distance sort (?near=)

    public function testNearOrdersNearestFirst() :void
    {
        // Nearest-first from Paris. A latitude/longitude swap would break this order.
        $keys = $this->orderedKeys( [ Arango::NEAR => self::point( 48.8566 , 2.3522 ) ] ) ;
        $this->assertSame( [ 'paris' , 'lille' , 'lyon' , 'bordeaux' , 'marseille' ] , $keys ) ;
    }

    public function testNearDescOrdersFarthestFirst() :void
    {
        $keys = $this->orderedKeys( [ Arango::SORT => '-distance' , Arango::NEAR => self::point( 48.8566 , 2.3522 ) ] ) ;
        $this->assertSame( [ 'marseille' , 'bordeaux' , 'lyon' , 'lille' , 'paris' ] , $keys ) ;
    }

    // ---- Composition: ?near= + ?filter= + LIMIT (end-to-end)

    public function testNearWithFilterAndLimit() :void
    {
        // The 2 nearest *cities* to Paris (capital Paris is filtered out): Lille, Lyon.
        $binds  = [] ;
        $model  = $this->model() ;
        $filter = $model->prepareFilter( [ 'key' => 'type' , 'val' => 'city' ] , $binds ) ;
        $sort   = $model->prepareSort( [ Arango::NEAR => self::point( 48.8566 , 2.3522 ) ] , binds: $binds ) ;

        $aql  = 'FOR doc IN ' . self::COLLECTION . ' FILTER ' . $filter . ' SORT ' . $sort . ' LIMIT 2 RETURN doc._key' ;
        $keys = array_map( 'strval' , iterator_to_array( self::$db->query( $aql , $binds ) , false ) ) ;

        $this->assertSame( [ 'lille' , 'lyon' ] , $keys ) ;
    }

    // ---- Plain (non-geo) sort

    public function testPlainSortByNameAscending() :void
    {
        $keys = $this->orderedKeys( [ Arango::SORT => 'name' ] ) ;
        $this->assertSame( [ 'bordeaux' , 'lille' , 'lyon' , 'marseille' , 'paris' ] , $keys ) ;
    }

    public function testPlainSortByNameDescending() :void
    {
        $keys = $this->orderedKeys( [ Arango::SORT => '-name' ] ) ;
        $this->assertSame( [ 'paris' , 'marseille' , 'lyon' , 'lille' , 'bordeaux' ] , $keys ) ;
    }

    // ---- GeoIndex value object

    public function testGeoIndexCanBeCreated() :void
    {
        // Validates the two-field GeoIndex (Schema.org geo.latitude / geo.longitude) against the live server.
        $response = self::$db->collection( self::COLLECTION )->createIndex
        (
            new GeoIndex( fields: [ 'geo.latitude' , 'geo.longitude' ] , geoJson: false )
        ) ;

        $this->assertSame( 'geo' , $response[ 'type' ] ?? null ) ;
        $this->assertSame( [ 'geo.latitude' , 'geo.longitude' ] , $response[ 'fields' ] ?? null ) ;
    }
}
