<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\traits\CollectionManagementTrait;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;

use tests\oihana\arango\db\ArangoDBTestCase;

/**
 * Characterization coverage for {@see CollectionManagementTrait} — the
 * collection / index management surface delegated to the `clients/Database`
 * + `clients/Collection` layer.
 *
 * @package tests\oihana\arango\db\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( CollectionManagementTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class CollectionManagementTraitTest extends ArangoDBTestCase
{
    /**
     * A Database double whose `collection()` always returns the given Collection.
     *
     * @param Collection $collection
     *
     * @return Database
     */
    private function databaseReturning( Collection $collection ) :Database
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;
        return $database ;
    }

    // ---- collectionCreate -----------------------------------------------

    public function testCollectionCreateCreatesWhenAbsent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;
        $collection->expects( $this->once() )->method( 'create' )->with( [ 'waitForSync' => true ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertTrue( $db->collectionCreate( 'users' , [ 'waitForSync' => true ] ) ) ;
    }

    public function testCollectionCreateReturnsFalseWhenAlreadyPresent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->never() )->method( 'create' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertFalse( $db->collectionCreate( 'users' ) ) ;
    }

    public function testCollectionCreateSwallowsClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $db = $this->newArangoDB( $database ) ;

        $this->assertFalse( $db->collectionCreate( 'users' ) ) ;
    }

    // ---- collectionDrop -------------------------------------------------

    public function testCollectionDropDropsWhenPresent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->once() )->method( 'drop' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertTrue( $db->collectionDrop( 'users' ) ) ;
    }

    public function testCollectionDropReturnsFalseWhenAbsent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertFalse( $db->collectionDrop( 'users' ) ) ;
    }

    public function testCollectionDropSwallowsClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->collectionDrop( 'users' ) ) ;
    }

    // ---- collectionExists -----------------------------------------------

    public function testCollectionExistsForwardsTheBoolean() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $collection ) )->collectionExists( 'users' ) ) ;
    }

    public function testCollectionExistsReturnsFalseOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->collectionExists( 'users' ) ) ;
    }

    // ---- collectionRename -----------------------------------------------

    public function testCollectionRenameRenamesWhenPresent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->once() )->method( 'rename' )->with( 'people' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertTrue( $db->collectionRename( 'users' , 'people' ) ) ;
    }

    public function testCollectionRenameReturnsFalseWhenAbsent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;
        $collection->expects( $this->never() )->method( 'rename' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertFalse( $db->collectionRename( 'users' , 'people' ) ) ;
    }

    public function testCollectionRenamePropagatesClientException() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->method( 'rename' )->willThrowException( new ArangoException( 'denied' ) ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->expectException( ArangoException::class ) ;
        $db->collectionRename( 'users' , 'people' ) ;
    }

    // ---- collectionTruncate ---------------------------------------------

    public function testCollectionTruncateTruncatesWhenPresent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->once() )->method( 'truncate' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertTrue( $db->collectionTruncate( 'users' ) ) ;
    }

    public function testCollectionTruncateReturnsFalseWhenAbsent() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;

        $this->assertFalse( $this->newArangoDB( $this->databaseReturning( $collection ) )->collectionTruncate( 'users' ) ) ;
    }

    public function testCollectionTruncateSwallowsClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->collectionTruncate( 'users' ) ) ;
    }

    // ---- collectionDiff ---------------------------------------------------

    public function testCollectionDiffReportsMissing() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->collectionDiff( 'places' ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertSame( DiffKind::COLLECTION , $report->kind ) ;
        $this->assertSame( 'places' , $report->name ) ;
    }

    public function testCollectionDiffIsInSyncWithoutTypeCheck() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->never() )->method( 'properties' ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $collection ) )->collectionDiff( 'places' )->inSync() ) ;
    }

    public function testCollectionDiffIsInSyncWhenTheTypeMatches() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->method( 'properties' )->willReturn( [ 'type' => 2 ] ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $collection ) )->collectionDiff( 'places' , 2 )->inSync() ) ;
    }

    public function testCollectionDiffReportsATypeDrift() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->method( 'properties' )->willReturn( [ 'type' => 3 ] ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->collectionDiff( 'places' , 2 ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'type : server 3 ≠ declared 2 (2 = document, 3 = edge)' ] , $report->changes ) ;
    }

    public function testCollectionDiffReportsUnreachableOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $report = $this->newArangoDB( $database )->collectionDiff( 'places' ) ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
        $this->assertSame( [ 'connection refused' ] , $report->changes ) ;
    }
}
