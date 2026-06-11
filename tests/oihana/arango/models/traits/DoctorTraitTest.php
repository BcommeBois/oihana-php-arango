<?php

namespace tests\oihana\arango\models\traits;

use DI\Container;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\collection\enums\CollectionType;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;
use oihana\arango\db\results\DiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\arango\models\enums\Search;
use oihana\arango\models\traits\DoctorTrait;

/**
 * Unit coverage for {@see DoctorTrait} — the model-level `diagnose()` /
 * `repair()` pair aggregating the collection / indexes / View reports.
 *
 * @package tests\oihana\arango\models\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( DoctorTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class DoctorTraitTest extends TestCase
{
    /**
     * A Documents model bound to the given façade, declaring the fixture
     * collection, one index and one View (overridable).
     */
    private function model( ?ArangoDB $facade = null , array $init = [] ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => false ,
            AQL::INDEXES     => [ new PersistentIndexOptions( [ IndexOptions::NAME => 'id' , IndexOptions::FIELDS => [ 'id' ] , IndexOptions::UNIQUE => true ] ) ] ,
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => 1 ] ] ,
            ...$init ,
        ]) ;
    }

    /**
     * A façade double answering a fully in-sync server.
     */
    private function healthyFacade() :ArangoDB
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'collectionDiff'   )->willReturn( new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ) ;
        $facade->method( 'indexesDiff'      )->willReturn( new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::INDEXES ) ) ;
        $facade->method( 'analyzerExists'   )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ;
        return $facade ;
    }

    // ---------------------------------------------------------------- diagnose

    public function testDiagnoseIsInvalidWithoutCollection() :void
    {
        $reports = $this->model( init : [ AQL::COLLECTION => null , AQL::INDEXES => null , AQL::VIEW => null ] )->diagnose() ;

        $this->assertCount( 1 , $reports ) ;
        $this->assertSame( DiffStatus::INVALID , $reports[0]->status ) ;
        $this->assertContains( 'declaration : no collection' , $reports[0]->changes ) ;
    }

    public function testDiagnoseIsUnreachableWithoutDatabase() :void
    {
        $reports = $this->model()->diagnose() ;

        $this->assertCount( 1 , $reports ) ;
        $this->assertSame( DiffStatus::UNREACHABLE , $reports[0]->status ) ;
        $this->assertSame( 'places' , $reports[0]->name ) ;
    }

    public function testDiagnoseAggregatesCollectionIndexesAndViewInOrder() :void
    {
        $reports = $this->model( $this->healthyFacade() )->diagnose() ;

        $this->assertCount( 3 , $reports ) ;
        $this->assertSame( [ DiffKind::COLLECTION , DiffKind::INDEXES , DiffKind::VIEW ] , array_map( fn( $r ) => $r->kind , $reports ) ) ;
        $this->assertTrue( $reports[0]->inSync() && $reports[1]->inSync() && $reports[2]->inSync() ) ;
    }

    public function testDiagnoseSkipsUndeclaredIndexesAndView() :void
    {
        $reports = $this->model( $this->healthyFacade() , [ AQL::INDEXES => null , AQL::VIEW => null ] )->diagnose() ;

        $this->assertCount( 1 , $reports ) ;
        $this->assertSame( DiffKind::COLLECTION , $reports[0]->kind ) ;
    }

    public function testDiagnosePassesTheDeclaredTypeToTheCollectionDiff() :void
    {
        $facade = $this->healthyFacade() ;
        $facade->expects( $this->once() )
               ->method( 'collectionDiff' )
               ->with( 'places' , CollectionType::DOCUMENT ) ;

        $this->model( $facade , [ AQL::INDEXES => null , AQL::VIEW => null ] )->diagnose() ;
    }

    public function testDiagnosePassesTheEdgeTypeForAnEdgesModel() :void
    {
        $facade = $this->healthyFacade() ;
        $facade->expects( $this->once() )
               ->method( 'collectionDiff' )
               ->with( 'placeHasType' , CollectionType::EDGE ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        new Edges( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'placeHasType' ,
            AQL::LAZY        => false ,
        ] )->diagnose() ;
    }

    // ---------------------------------------------------------------- repair

    public function testRepairFallsBackToDiagnoseWithoutDatabase() :void
    {
        $reports = $this->model()->repair() ;

        $this->assertCount( 1 , $reports ) ;
        $this->assertSame( DiffStatus::UNREACHABLE , $reports[0]->status ) ;
    }

    public function testRepairCreatesAMissingCollectionWithItsType() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'collectionDiff' )->willReturn( new DiffReport( 'places' , DiffStatus::MISSING , kind : DiffKind::COLLECTION ) ) ;
        $facade->expects( $this->once() )
               ->method( 'collectionCreate' )
               ->with( 'places' , [ 'type' => CollectionType::DOCUMENT ] )
               ->willReturn( true ) ;

        $reports = $this->model( $facade , [ AQL::INDEXES => null , AQL::VIEW => null ] )->repair() ;

        $this->assertSame( DiffStatus::MISSING , $reports[0]->status ) ;
        $this->assertTrue( $reports[0]->applied ) ;
    }

    public function testRepairNeverCreatesAnExistingCollection() :void
    {
        $facade = $this->healthyFacade() ;
        $facade->expects( $this->never() )->method( 'collectionCreate' ) ;

        $reports = $this->model( $facade , [ AQL::INDEXES => null , AQL::VIEW => null ] )->repair() ;

        $this->assertTrue( $reports[0]->inSync() ) ;
    }

    public function testRepairDelegatesIndexesSyncWithTheForceFlag() :void
    {
        $synced = new DiffReport( 'places' , DiffStatus::DRIFTED , [] , true , DiffKind::INDEXES ) ;

        $facade = $this->healthyFacade() ;
        $facade->expects( $this->once() )
               ->method( 'indexesSync' )
               ->with( 'places' , $this->callback( is_array( ... ) ) , true )
               ->willReturn( $synced ) ;

        $reports = $this->model( $facade , [ AQL::VIEW => null ] )->repair( force : true ) ;

        $this->assertSame( $synced , $reports[1] ) ;
    }

    public function testRepairDelegatesTheViewSync() :void
    {
        $applied = new DiffReport( 'placesView' , DiffStatus::MISSING , [] , true ) ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'collectionDiff'   )->willReturn( new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ) ;
        $facade->method( 'analyzerExists'   )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff' )->willReturn( new DiffReport( 'placesView' , DiffStatus::MISSING ) ) ;
        $facade->expects( $this->once() )->method( 'viewSync' )->willReturn( $applied ) ;

        $reports = $this->model( $facade , [ AQL::INDEXES => null ] )->repair() ;

        $this->assertSame( $applied , $reports[1] ) ;
    }
}
