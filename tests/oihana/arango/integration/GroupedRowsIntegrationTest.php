<?php

namespace tests\oihana\arango\integration;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Group;

use org\schema\Thing;

use PHPUnit\Framework\Attributes\Group as TestGroup;

use function oihana\init\initConfig;

/**
 * Live proof that a grouped row reaches the caller with the values the server
 * computed.
 *
 * ⚠ This has to run against a real database, through the real `list()`, because
 * the damage happened **after** the query: the AQL was always right, and the
 * losses occurred while the rows were being hydrated into the model's schema.
 * `CollectIntegrationTest` runs the built query directly and never touches that
 * step, and the unit doubles return canned results — neither can see it.
 */
#[TestGroup( 'integration' )]
class GroupedRowsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_grouped_rows_it' ;

    private const string COLLECTION = 'measurements' ;

    protected static function seed( Database $db ) :void
    {
        $measurements = $db->collection( self::COLLECTION ) ;
        $measurements->create() ;

        // year 2023 → speed.value sums to 150.5, amount sums to 10
        // year 2024 → speed.value sums to  20.5, amount sums to  1
        $measurements->insert( [ '_key' => 'm1' , 'year' => 2023 , 'speed' => [ 'value' => 100.5 ] , 'amount' => 7 ] ) ;
        $measurements->insert( [ '_key' => 'm2' , 'year' => 2023 , 'speed' => [ 'value' =>  50.0 ] , 'amount' => 3 ] ) ;
        $measurements->insert( [ '_key' => 'm3' , 'year' => 2024 , 'speed' => [ 'value' =>  20.5 ] , 'amount' => 1 ] ) ;
    }

    /**
     * A model declaring a schema — the ordinary case, and the one where the loss
     * used to happen.
     */
    private function model() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $model = new Documents( $container ,
        [
            Arango::DATABASE  => $arangodb ,
            AQL::COLLECTION   => self::COLLECTION ,
            AQL::LAZY         => false ,
            Arango::GROUPABLE => [ 'year' => 'year' ] ,
        ]);

        $model->schema = Thing::class ;

        return $model ;
    }

    /**
     * ⚠ The measurement that matters. `Thing` declares neither `year` nor `total`,
     * and its constructor copies only the keys matching a declared public property
     * — so every row used to come back as a bare `{"@type":"Thing"}`. Not "the
     * aggregate went missing": **everything the query invented went missing**, the
     * grouping dimension included.
     */
    public function testAGroupedListKeepsTheDimensionAndTheAggregate() :void
    {
        $rows = $this->model()->list
        ([
            Arango::GROUP =>
            [
                Group::BY   => 'year' ,
                Group::AGG  => [ 'total' => 'sum:speed.value' ] ,
                Group::SORT => 'year' ,
            ]
        ]) ;

        $this->assertCount( 2 , $rows ) ;

        $this->assertSame( 2023  , $rows[ 0 ]->year  ) ;
        $this->assertSame( 150.5 , $rows[ 0 ]->total ) ;
        $this->assertSame( 2024  , $rows[ 1 ]->year  ) ;
        $this->assertSame( 20.5  , $rows[ 1 ]->total ) ;

        // The rows are no longer dressed as documents of the collection.
        $this->assertObjectNotHasProperty( '@type' , $rows[ 0 ] ) ;
    }

    /**
     * 🚨 The half nobody had seen. When an aggregate is named after a **typed**
     * property of the schema, it was not dropped — it was **coerced**: a sum of
     * `10` reaching `Thing::$active` (`?bool`) came back as `true`, and a sum of
     * `150.5` reaching `Thing::$name` (`string|int|null`) came back as `150`, with
     * a `Deprecated: Implicit conversion from float …` per row. A wrong number is
     * worse than a missing key, because nothing shows.
     */
    public function testAnAggregateNamedAfterASchemaPropertyIsNoLongerCoerced() :void
    {
        $rows = $this->model()->list
        ([
            Arango::GROUP =>
            [
                Group::BY   => 'year' ,
                Group::AGG  => [ 'name' => 'sum:speed.value' , 'active' => 'sum:amount' ] ,
                Group::SORT => 'year' ,
            ]
        ]) ;

        $this->assertSame( 150.5 , $rows[ 0 ]->name   ) ; // was 150 — truncated to int
        $this->assertSame( 10    , $rows[ 0 ]->active ) ; // was true — cast to bool
        $this->assertSame( 20.5  , $rows[ 1 ]->name   ) ;
        $this->assertSame( 1     , $rows[ 1 ]->active ) ;
    }

    /**
     * A count alone groups too, through `COLLECT WITH COUNT INTO`.
     */
    public function testAGroupedListKeepsAStandaloneCount() :void
    {
        $rows = $this->model()->list
        ([
            Arango::GROUP => [ Group::BY => 'year' , Group::COUNT => true , Group::SORT => 'year' ]
        ]) ;

        $this->assertSame( 2 , $rows[ 0 ]->count ) ;
        $this->assertSame( 1 , $rows[ 1 ]->count ) ;
    }

    /**
     * The other half of the contract: an **ungrouped** list is untouched. Its rows
     * are still hydrated into the schema, so they are `Thing` instances carrying
     * the `_key` the class declares.
     */
    public function testAnUngroupedListIsStillHydrated() :void
    {
        $rows = $this->model()->list( [ Arango::SORT => '_key' ] ) ;

        $this->assertCount( 3 , $rows ) ;
        $this->assertInstanceOf( Thing::class , $rows[ 0 ] ) ;
        $this->assertSame( 'm1' , $rows[ 0 ]->_key ) ;
    }

    /**
     * And the boundary: a group spec whose every dimension is dropped emits no
     * `COLLECT`, so the query still returns documents and they are still hydrated.
     */
    public function testAGroupSpecEmittingNoCollectStillHydrates() :void
    {
        $rows = $this->model()->list( [ Arango::GROUP => [ Group::BY => 'unknown' ] ] ) ;

        $this->assertCount( 3 , $rows ) ;
        $this->assertInstanceOf( Thing::class , $rows[ 0 ] ) ;
    }

    /**
     * stream() builds the same query, and now reads it the same way.
     */
    public function testAGroupedStreamKeepsItsAggregate() :void
    {
        $rows = iterator_to_array( $this->model()->stream
        ([
            Arango::GROUP =>
            [
                Group::BY   => 'year' ,
                Group::AGG  => [ 'total' => 'sum:speed.value' ] ,
                Group::SORT => 'year' ,
            ]
        ]) , false ) ;

        $this->assertCount( 2 , $rows ) ;
        $this->assertSame( 150.5 , $rows[ 0 ]->total ) ;
        $this->assertSame( 20.5  , $rows[ 1 ]->total ) ;
    }
}
