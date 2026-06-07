<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;
use oihana\models\notices\AfterInsert;
use oihana\models\notices\BeforeInsert;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsInsertTrait::insert()}.
 *
 * Verifies the orchestration around the INSERT clause (created/modified stamping
 * via prepareDocumentClause), the getObject() return, and the before/after signal
 * emission.
 */
final class DocumentsInsertTraitTest extends TestCase
{
    public function testInsertBuildsMergeClauseForStringDocument() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' , 'name' => 'Marc' ] ;

        $result = $model->insert( [ Arango::DOC => 'doc' ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertSame
        (
            'INSERT MERGE(doc,created:DATE_ISO8601(DATE_NOW()),modified:DATE_ISO8601(DATE_NOW())) INTO @@collection RETURN NEW' ,
            $model->lastQuery ,
        ) ;
    }

    public function testInsertBindsArrayDocumentWithCreatedAndModifiedKeys() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $model->insert( [ Arango::DOC => [ 'name' => 'Marc' ] ] ) ;

        $this->assertSame( 'INSERT @insert INTO @@collection RETURN NEW' , $model->lastQuery ) ;
        $this->assertSame( [ 'name' , 'created' , 'modified' ] , array_keys( $model->lastBinds[ 'insert' ] ) ) ;
    }

    public function testInsertSeedsDeclaredArrayFieldsToEmpty() :void
    {
        $model = new MockDocuments( 'Playlist' ) ;
        $model->objectResult = (object) [ '_key' => 'p1' ] ;
        $model->arrays =
        [
            'tags'   => [ Arango::MODE => ArrayMode::SET  , Arango::COUNTER => null ] ,
            'tracks' => [ Arango::MODE => ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ,
        ] ;

        $model->insert( [ Arango::DOC => [ 'name' => 'Marc' , 'tags' => [ 'jazz' ] ] ] ) ;

        $insert = $model->lastBinds[ 'insert' ] ;
        $this->assertSame( [ 'jazz' ] , $insert[ 'tags' ] ) ;   // provided field kept, not overwritten
        $this->assertSame( [] , $insert[ 'tracks' ] ) ;         // missing array field seeded to []
        $this->assertSame( 0 , $insert[ 'numberOfTracks' ] ) ;  // its counter seeded to 0
        $this->assertSame( 'Marc' , $insert[ 'name' ] ) ;
    }

    public function testDebugFlagLogsButDoesNotAlterTheInsert() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $result = $model->insert( [ Arango::DOC => 'doc' , Arango::DEBUG => true ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertSame
        (
            'INSERT MERGE(doc,created:DATE_ISO8601(DATE_NOW()),modified:DATE_ISO8601(DATE_NOW())) INTO @@collection RETURN NEW' ,
            $model->lastQuery ,
        ) ;
    }

    public function testInsertEmitsBeforeAndAfterSignals() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;
        $model->initializeInsertSignals() ;

        $before = [] ;
        $after  = [] ;
        $model->beforeInsert->connect( function( $event ) use ( &$before ) { $before[] = $event ; } ) ;
        $model->afterInsert->connect ( function( $event ) use ( &$after  ) { $after[]  = $event ; } ) ;

        $result = $model->insert( [ Arango::DOC => 'doc' ] ) ;

        $this->assertCount( 1 , $before ) ;
        $this->assertCount( 1 , $after ) ;
        $this->assertInstanceOf( BeforeInsert::class , $before[ 0 ] ) ;
        $this->assertInstanceOf( AfterInsert::class , $after[ 0 ] ) ;
        $this->assertSame( $model , $before[ 0 ]->target ) ;
        $this->assertSame( $model , $after[ 0 ]->target ) ;
        $this->assertSame( $result , $after[ 0 ]->data , 'afterInsert must carry the inserted document' ) ;
    }
}
