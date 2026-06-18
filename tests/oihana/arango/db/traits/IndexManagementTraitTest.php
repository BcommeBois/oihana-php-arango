<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\collection\indexes\InvertedIndex;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;
use oihana\arango\db\traits\IndexManagementTrait;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;

use ReflectionException;
use tests\oihana\arango\db\ArangoDBTestCase;

/**
 * Characterization coverage for {@see IndexManagementTrait} — the index
 * management surface delegated to the `clients/Database` +
 * `clients/Collection` layer, plus the `doctor` conformity primitives
 * (`indexesDiff()` / `indexesSync()`).
 *
 * @package tests\oihana\arango\db\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( IndexManagementTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class IndexManagementTraitTest extends ArangoDBTestCase
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
        // the handle's getName() must be used as the collection name
        $database->expects( $this->once() )->method( 'collection' )->with( 'people' )->willReturn( $collection ) ;

        $db = $this->newArangoDB( $database ) ;

        $handle = $this->createMock( Collection::class ) ;
        $handle->method( 'getName' )->willReturn( 'people' ) ;

        $this->assertSame( [ 'id' => 'x' ] , $db->createIndex( $handle , [ 'type' => 'persistent' ] ) ) ;
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

    // ---- indexesDiff --------------------------------------------------------

    /**
     * The declared fixture index : a named unique persistent index on `id`.
     */
    private function declaredIndexes() :array
    {
        return [ new PersistentIndexOptions(
        [
            IndexOptions::NAME   => 'id' ,
            IndexOptions::FIELDS => [ 'id' ] ,
            IndexOptions::UNIQUE => true ,
        ]) ] ;
    }

    /**
     * A server-side index payload, normalised the way the server answers
     * (defaults filled in, `id` and `selectivityEstimate` added).
     */
    private function serverIndex( array $overrides = [] ) :array
    {
        return
        [
            'cacheEnabled'        => false ,
            'deduplicate'         => true ,
            'estimates'           => true ,
            'fields'              => [ 'id' ] ,
            'id'                  => 'places/1' ,
            'name'                => 'id' ,
            'selectivityEstimate' => 1 ,
            'sparse'              => false ,
            'type'                => 'persistent' ,
            'unique'              => true ,
            ...$overrides ,
        ] ;
    }

    /**
     * The automatic primary index, always present server-side and always
     * out of the comparison scope.
     */
    private function primaryIndex() :array
    {
        return [ 'fields' => [ '_key' ] , 'id' => 'places/0' , 'name' => 'primary' , 'sparse' => false , 'type' => 'primary' , 'unique' => true ] ;
    }

    /**
     * A Collection double answering `exists()` true and `indexes()` with
     * the given list (the primary index is always prepended).
     */
    private function collectionWithIndexes( array $indexes ) :Collection
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists'  )->willReturn( true ) ;
        $collection->method( 'indexes' )->willReturn( [ $this->primaryIndex() , ...$indexes ] ) ;
        return $collection ;
    }

    public function testIndexesDiffIsInvalidWhenTheCollectionIsMissing() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( false ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertSame( DiffKind::INDEXES , $report->kind ) ;
        $this->assertSame( [ "collection 'places' not found on the server" ] , $report->changes ) ;
    }

    public function testIndexesDiffIsInSyncWhenTheServerMatches() :void
    {
        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $this->serverIndex() ] ) ) ) ;

        $report = $db->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertTrue( $report->inSync() ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testIndexesDiffReportsAMissingIndex() :void
    {
        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [] ) ) ) ;

        $report = $db->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertSame( [ 'id : missing on the server' ] , $report->changes ) ;
    }

    public function testIndexesDiffReportsADefinitionDrift() :void
    {
        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $this->serverIndex( [ 'unique' => false ] ) ] ) ) ) ;

        $report = $db->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'id.unique : server false ≠ declared true (drop + recreate required)' ] , $report->changes ) ;
    }

    public function testIndexesDiffComparesFieldsOrderSensitively() :void
    {
        $declared = [ new PersistentIndexOptions( [ IndexOptions::NAME => 'pair' , IndexOptions::FIELDS => [ 'a' , 'b' ] ] ) ] ;
        $server   = [ $this->serverIndex( [ 'name' => 'pair' , 'fields' => [ 'b' , 'a' ] , 'unique' => false ] ) ] ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( $server ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertStringContainsString( 'pair.fields : server ["b","a"] ≠ declared ["a","b"]' , $report->changes[0] ) ;
    }

    public function testIndexesDiffReportsAnUndeclaredServerIndex() :void
    {
        $extra = $this->serverIndex( [ 'id' => 'places/9' , 'name' => 'legacy' , 'fields' => [ 'old' ] ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $this->serverIndex() , $extra ] ) ) ) ;

        $report = $db->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'legacy : on the server but not declared' ] , $report->changes ) ;
    }

    public function testIndexesDiffMatchesUnnamedIndexesByTypeAndFields() :void
    {
        $declared = [ [ 'type' => 'persistent' , 'fields' => [ 'id' ] , 'unique' => true ] ] ; // no name : raw array shape

        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $this->serverIndex( [ 'name' => 'idx_832910498' ] ) ] ) ) ) ;

        $this->assertTrue( $db->indexesDiff( 'places' , $declared )->inSync() ) ;
    }

    public function testIndexesDiffIgnoresTheInBackgroundCreationOption() :void
    {
        $declared = [ [ 'type' => 'persistent' , 'fields' => [ 'id' ] , 'name' => 'id' , 'unique' => true , 'inBackground' => true ] ] ;

        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $this->serverIndex() ] ) ) ) ;

        $this->assertTrue( $db->indexesDiff( 'places' , $declared )->inSync() ) ;
    }

    public function testIndexesDiffReportsUnreachableOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $report = $this->newArangoDB( $database )->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
    }

    // ---- indexesSync --------------------------------------------------------

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testIndexesSyncCreatesAMissingIndex() :void
    {
        $collection = $this->collectionWithIndexes( [] ) ;
        $collection->expects( $this->once() )->method( 'createIndex' )->willReturn( [ 'id' => 'places/2' ] ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesSync( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testIndexesSyncLeavesADriftedIndexWithoutForce() :void
    {
        $collection = $this->collectionWithIndexes( [ $this->serverIndex( [ 'unique' => false ] ) ] ) ;
        $collection->expects( $this->never() )->method( 'dropIndex' ) ;
        $collection->expects( $this->never() )->method( 'createIndex' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesSync( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testIndexesSyncRebuildsADriftedIndexWithForce() :void
    {
        $collection = $this->collectionWithIndexes( [ $this->serverIndex( [ 'unique' => false ] ) ] ) ;
        $collection->expects( $this->once() )->method( 'dropIndex' )->with( 'places/1' ) ;
        $collection->expects( $this->once() )->method( 'createIndex' )->willReturn( [ 'id' => 'places/2' ] ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesSync( 'places' , $this->declaredIndexes() , force : true ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
    }

    public function testIndexesSyncLeavesAnInSyncCollectionUntouched() :void
    {
        $collection = $this->collectionWithIndexes( [ $this->serverIndex() ] ) ;
        $collection->expects( $this->never() )->method( 'createIndex' ) ;
        $collection->expects( $this->never() )->method( 'dropIndex' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesSync( 'places' , $this->declaredIndexes() ) ;

        $this->assertTrue( $report->inSync() ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testIndexesDiffReportsAMissingNamedIndexAmongOthers() :void
    {
        $other = $this->serverIndex( [ 'id' => 'places/7' , 'name' => 'other' , 'fields' => [ 'slug' ] ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $other ] ) ) ) ;

        $report = $db->indexesDiff( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'id : missing on the server' , $report->changes ) ;
        $this->assertContains( 'other : on the server but not declared' , $report->changes ) ;
    }

    public function testIndexesSyncReportsAFailedApply() :void
    {
        $calls = 0 ;

        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->method( 'indexes' )->willReturnCallback( function() use ( &$calls )
        {
            if ( ++$calls > 1 )
            {
                throw new ArangoException( 'boom' ) ;
            }
            return [ $this->primaryIndex() ] ;
        } ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesSync( 'places' , $this->declaredIndexes() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
        $this->assertContains( 'sync failed : boom' , $report->changes ) ;
    }

    public function testIndexesSyncNeverTouchesUndeclaredServerIndexes() :void
    {
        $extra = $this->serverIndex( [ 'id' => 'places/9' , 'name' => 'legacy' , 'fields' => [ 'old' ] ] ) ;

        $collection = $this->collectionWithIndexes( [ $this->serverIndex() , $extra ] ) ;
        $collection->expects( $this->never() )->method( 'dropIndex' ) ;
        $collection->expects( $this->never() )->method( 'createIndex' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $collection ) )->indexesSync( 'places' , $this->declaredIndexes() , force : true ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    // ---- inverted indexes ---------------------------------------------------

    /**
     * A server-side inverted index payload, normalised the way the server
     * answers (string fields expanded to `{ name }` objects, `primarySort`
     * direction stored as `{ asc }`, defaults filled in, extras added) — the
     * real shape captured live on ArangoDB 3.12.
     */
    private function serverInvertedIndex( array $overrides = [] ) :array
    {
        return
        [
            'analyzer'                  => 'identity' ,
            'cleanupIntervalStep'       => 2 ,
            'commitIntervalMsec'        => 1000 ,
            'consolidationIntervalMsec' => 1000 ,
            'consolidationPolicy'       => [ 'type' => 'tier' , 'segmentsMax' => 10 , 'segmentsMin' => 1 ] ,
            'features'                  => [ 'frequency' , 'norm' ] ,
            'fields'                    => [ [ 'name' => 'tag' ] ] ,
            'id'                        => 'places/100' ,
            'includeAllFields'          => false ,
            'name'                      => 'inv_search' ,
            'primarySort'               => [ 'fields' => [] , 'compression' => 'lz4' ] ,
            'searchField'               => false ,
            'sparse'                    => true ,
            'storedValues'              => [] ,
            'trackListPositions'        => false ,
            'type'                      => 'inverted' ,
            'unique'                    => false ,
            'version'                   => 1 ,
            'writebufferActive'         => 0 ,
            'writebufferIdle'           => 64 ,
            'writebufferSizeMax'        => 33554432 ,
            ...$overrides ,
        ] ;
    }

    public function testIndexesDiffIsInSyncOnASimpleInvertedIndex() :void
    {
        // declared string field + top-level analyzer vs the server's expanded
        // `{ name }` field and filled-in defaults : no false drift.
        $declared = [ new InvertedIndex( fields: [ 'tag' ] , name: 'inv_search' , analyzer: 'identity' ) ] ;

        $db = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $this->serverInvertedIndex() ] ) ) ) ;

        $report = $db->indexesDiff( 'places' , $declared ) ;

        $this->assertTrue( $report->inSync() , implode( ' | ' , $report->changes ) ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testIndexesDiffIsInSyncOnInvertedPrimarySortAndStoredValues() :void
    {
        // the special inverted options : declared `{ direction:"asc" }` vs the
        // server's `{ asc:true }`, declared `storedValues` without the server's
        // default `compression` — all reconciled, no false drift.
        $declared =
        [
            new InvertedIndex
            (
                fields       : [ 'title' ] ,
                name         : 'inv_nested' ,
                analyzer     : 'identity' ,
                primarySort  : [ 'fields' => [ [ 'field' => 'title' , 'direction' => 'asc' ] ] ] ,
                storedValues : [ [ 'fields' => [ 'title' ] ] ] ,
            )
        ] ;

        $server = $this->serverInvertedIndex(
        [
            'name'         => 'inv_nested' ,
            'fields'       => [ [ 'name' => 'title' ] ] ,
            'primarySort'  => [ 'fields' => [ [ 'field' => 'title' , 'asc' => true ] ] , 'compression' => 'lz4' ] ,
            'storedValues' => [ [ 'fields' => [ 'title' ] , 'compression' => 'lz4' ] ] ,
        ]) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertTrue( $report->inSync() , implode( ' | ' , $report->changes ) ) ;
    }

    public function testIndexesDiffDetectsARealPrimarySortDrift() :void
    {
        // declared ascending, server descending : a genuine drift must surface.
        $declared =
        [
            new InvertedIndex
            (
                fields      : [ 'title' ] ,
                name        : 'inv_nested' ,
                primarySort : [ 'fields' => [ [ 'field' => 'title' , 'direction' => 'asc' ] ] ] ,
            )
        ] ;

        $server = $this->serverInvertedIndex(
        [
            'name'        => 'inv_nested' ,
            'fields'      => [ [ 'name' => 'title' ] ] ,
            'primarySort' => [ 'fields' => [ [ 'field' => 'title' , 'asc' => false ] ] , 'compression' => 'lz4' ] ,
        ]) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertStringContainsString( 'inv_nested.primarySort' , implode( ' | ' , $report->changes ) ) ;
    }

    public function testIndexesDiffDetectsARealStoredValuesDrift() :void
    {
        // declared a stored value the server does not keep : genuine drift.
        $declared =
        [
            new InvertedIndex
            (
                fields       : [ 'title' ] ,
                name         : 'inv_nested' ,
                storedValues : [ [ 'fields' => [ 'title' ] ] ] ,
            )
        ] ;

        $server = $this->serverInvertedIndex(
        [
            'name'         => 'inv_nested' ,
            'fields'       => [ [ 'name' => 'title' ] ] ,
            'storedValues' => [] ,
        ]) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertStringContainsString( 'inv_nested.storedValues' , implode( ' | ' , $report->changes ) ) ;
    }

    public function testIndexesDiffComparesInvertedFeaturesOrderInsensitively() :void
    {
        $declared = [ new InvertedIndex( fields: [ 'tag' ] , name: 'inv_search' , features: [ 'position' , 'frequency' ] ) ] ;

        $server = $this->serverInvertedIndex( [ 'features' => [ 'frequency' , 'position' ] ] ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertTrue( $report->inSync() , implode( ' | ' , $report->changes ) ) ;
    }

    public function testIndexesDiffMatchesUnnamedInvertedIndexByExpandedFields() :void
    {
        // a raw, unnamed inverted declaration (string field) must still match
        // the server index whose fields are `{ name }` objects.
        $declared = [ [ 'type' => 'inverted' , 'fields' => [ 'tag' ] ] ] ;

        $server = $this->serverInvertedIndex( [ 'name' => 'idx_auto_inverted' ] ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertTrue( $report->inSync() , implode( ' | ' , $report->changes ) ) ;
    }

    public function testIndexesDiffToleratesANonArrayPrimarySort() :void
    {
        // defensive : a malformed (non-array) primarySort passes through the
        // canonicaliser untouched on both sides.
        $declared = [ [ 'type' => 'inverted' , 'fields' => [ 'tag' ] , 'name' => 'inv_search' , 'primarySort' => 'unexpected' ] ] ;

        $server = $this->serverInvertedIndex( [ 'primarySort' => 'unexpected' ] ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertTrue( $report->inSync() , implode( ' | ' , $report->changes ) ) ;
    }

    public function testIndexesDiffToleratesScalarPrimarySortFields() :void
    {
        // defensive : a primarySort whose field entries are bare scalars (not
        // `{ field, asc }` maps) is left as-is by the direction folding.
        $declared = [ [ 'type' => 'inverted' , 'fields' => [ 'title' ] , 'name' => 'inv_nested' , 'primarySort' => [ 'fields' => [ 'title' ] ] ] ] ;

        $server = $this->serverInvertedIndex(
        [
            'name'        => 'inv_nested' ,
            'fields'      => [ [ 'name' => 'title' ] ] ,
            'primarySort' => [ 'fields' => [ 'title' ] ] ,
        ]) ;

        $report = $this->newArangoDB( $this->databaseReturning( $this->collectionWithIndexes( [ $server ] ) ) )->indexesDiff( 'places' , $declared ) ;

        $this->assertTrue( $report->inSync() , implode( ' | ' , $report->changes ) ) ;
    }

    public function testCreateIndexAcceptsAnInvertedIndexDefinition() :void
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->expects( $this->once() )
                   ->method( 'createIndex' )
                   ->with( $this->isInstanceOf( InvertedIndex::class ) )
                   ->willReturn( [ 'id' => 'products/9' ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $collection ) ) ;

        $this->assertSame( [ 'id' => 'products/9' ] , $db->createIndex( 'products' , new InvertedIndex( fields: [ 'name' ] , analyzer: 'text_en' ) ) ) ;
    }
}
