<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\options\indexes\IndexOptions;
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

    // ---- createIndex ----------------------------------------------------

    public function testCreateIndexReturnsServerResponse() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'createIndex' )->willReturn( [ 'id' => 'users/42' ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertSame( [ 'id' => 'users/42' ] , $db->createIndex( 'users' , [ 'type' => 'persistent' , 'fields' => [ 'name' ] ] ) ) ;
    }

    public function testCreateIndexAcceptsAnIndexOptionsObject() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'createIndex' )->willReturn( [ 'id' => 'users/7' ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        // an IndexOptions value object is serialized via jsonSerialize()
        $options = new IndexOptions( [ 'type' => 'persistent' , 'fields' => [ 'name' ] ] ) ;

        $this->assertSame( [ 'id' => 'users/7' ] , $db->createIndex( 'users' , $options ) ) ;
    }

    public function testCreateIndexResolvesCollectionObjectName() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'createIndex' )->willReturn( [ 'id' => 'x' ] ) ;

        $database = $this->createMock( Database::class ) ;
        // the object's getName() must be used as the collection name
        $database->expects( $this->once() )->method( 'collection' )->with( 'people' )->willReturn( $collection ) ;

        $db = $this->newArangoDB( $database ) ;

        $namedCollection = new class { public function getName() :string { return 'people' ; } } ;

        $this->assertSame( [ 'id' => 'x' ] , $db->createIndex( $namedCollection , [ 'type' => 'persistent' ] ) ) ;
    }

    public function testCreateIndexLogsAndReturnsNullOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException( 'bad index' ) ) ;

        $this->assertNull( $this->newArangoDB( $database )->createIndex( 'users' , [ 'type' => 'persistent' ] ) ) ;
    }

    // ---- dropIndex ------------------------------------------------------

    public function testDropIndexWithFullHandle() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->expects( $this->once() )->method( 'dropIndex' )->with( '42' ) ;

        $database = $this->createMock( Database::class ) ;
        $database->expects( $this->once() )->method( 'collection' )->with( 'users' )->willReturn( $collection ) ;

        $this->assertTrue( $this->newArangoDB( $database )->dropIndex( 'users/42' ) ) ;
    }

    public function testDropIndexWithCollectionAndHandle() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->expects( $this->once() )->method( 'dropIndex' )->with( 'idx' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertTrue( $db->dropIndex( 'users' , 'idx' ) ) ;
    }

    public function testDropIndexReturnsFalseOnMalformedHandle() :void
    {
        // no '/' and no explicit handle → cannot resolve → false
        $this->assertFalse( $this->newArangoDB()->dropIndex( 'noslash' ) ) ;
    }

    public function testDropIndexLogsAndReturnsFalseOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->dropIndex( 'users' , 'idx' ) ) ;
    }

    // ---- getIndex / getIndexes ------------------------------------------

    public function testGetIndexReturnsDefinition() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'index' )->willReturn( [ 'id' => 'users/1' ] ) ;

        $this->assertSame( [ 'id' => 'users/1' ] , $this->newArangoDB( $this->databaseReturning( $collection ) )->getIndex( 'users' , '1' ) ) ;
    }

    public function testGetIndexPropagatesClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException() ) ;

        $this->expectException( ArangoException::class ) ;
        $this->newArangoDB( $database )->getIndex( 'users' , '1' ) ;
    }

    public function testGetIndexesReturnsList() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'indexes' )->willReturn( [ [ 'id' => 'a' ] , [ 'id' => 'b' ] ] ) ;

        $this->assertCount( 2 , $this->newArangoDB( $this->databaseReturning( $collection ) )->getIndexes( 'users' ) ) ;
    }

    public function testGetIndexesPropagatesClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException() ) ;

        $this->expectException( ArangoException::class ) ;
        $this->newArangoDB( $database )->getIndexes( 'users' ) ;
    }
}
