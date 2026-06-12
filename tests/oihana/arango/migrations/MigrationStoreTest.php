<?php

namespace tests\oihana\arango\migrations;

use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\Database;
use oihana\arango\migrations\enums\MigrationKind;
use oihana\arango\migrations\MigrationAction;
use oihana\arango\migrations\MigrationStore;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see MigrationStore} — the tracking persistence, with a
 * mocked low-level {@see Database} client.
 *
 * @package tests\oihana\arango\migrations
 * @author  Marc Alcaraz
 */
#[CoversClass( MigrationStore::class )]
#[AllowMockObjectsWithoutExpectations]
class MigrationStoreTest extends TestCase
{
    /**
     * A Cursor double iterating the given documents.
     */
    private function cursor( array $documents ) :Cursor
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getIterator' )->willReturnCallback( fn() => ( function() use ( $documents ) { yield from $documents ; } )() ) ;
        return $cursor ;
    }

    public function testAppliedReturnsEmptyWhenTheCollectionIsMissing() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;
        $database->expects( $this->never() )->method( 'query' ) ;

        $this->assertSame( [] , new MigrationStore( $database )->applied() ) ;
    }

    public function testAppliedKeysTheMigrationsByVersion() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;

        $rows =
        [
            [ '_key' => '20260101_a' , 'additionalType' => MigrationKind::MIGRATE , 'actionStatus' => 'completed' ] ,
            [ '_key' => '20260102_b' , 'additionalType' => MigrationKind::MIGRATE , 'actionStatus' => 'completed' ] ,
        ] ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;
        $database->method( 'query' )->willReturn( $this->cursor( $rows ) ) ;

        $applied = new MigrationStore( $database )->applied() ;

        $this->assertSame( [ '20260101_a' , '20260102_b' ] , array_keys( $applied ) ) ;
        $this->assertInstanceOf( MigrationAction::class , $applied[ '20260101_a' ] ) ;
    }

    public function testEnsureCollectionCreatesWhenAbsent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;
        $collection->expects( $this->once() )->method( 'create' ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;

        new MigrationStore( $database )->ensureCollection() ;
    }

    public function testEnsureCollectionSkipsWhenPresent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->never() )->method( 'create' ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;

        new MigrationStore( $database )->ensureCollection() ;
    }

    public function testSaveUpsertsByKey() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;
        $database->expects( $this->once() )
                 ->method( 'query' )
                 ->with(
                     $this->stringContains( 'UPSERT { _key: @key }' ) ,
                     $this->callback( fn( $binds ) => $binds[ 'key' ] === '20260101_a' && $binds[ '@c' ] === 'migrations' )
                 ) ;

        $action = new MigrationAction() ;
        $action->_key = '20260101_a' ;

        new MigrationStore( $database )->save( $action ) ;
    }

    public function testAppendInsertsAnAuditRow() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->once() )->method( 'insert' ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;

        $action = new MigrationAction() ;
        $action->additionalType = MigrationKind::DOCTOR ;

        new MigrationStore( $database )->append( $action ) ;
    }

    public function testRemoveDeletesByKey() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->expects( $this->once() )
                 ->method( 'query' )
                 ->with(
                     $this->stringContains( 'REMOVE @key IN @@c' ) ,
                     $this->callback( fn( $binds ) => $binds[ 'key' ] === '20260101_a' )
                 ) ;

        new MigrationStore( $database )->remove( '20260101_a' ) ;
    }

    public function testHonoursACustomCollectionName() :void
    {
        $database = $this->createMock( Database::class ) ;
        $this->assertSame( 'audit' , new MigrationStore( $database , 'audit' )->collection ) ;
    }
}
