<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;
use oihana\models\notices\AfterDelete;
use oihana\models\notices\BeforeDelete;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsDeleteTrait::delete()}.
 *
 * Verifies the REMOVE-clause orchestration: the count==1 (getObject) vs count>1
 * (getDocuments) branch, the empty short-circuit, extra conditions, the debug
 * flag, and the before/after signal emission (afterDelete carries the OLD result).
 */
final class DocumentsDeleteTraitTest extends TestCase
{
    public function testDeleteSingleValueUsesGetObject() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $result = $model->delete( [ Arango::VALUE => 'k1' ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertMatchesRegularExpression
        (
            '/^FOR doc IN @@collection FILTER doc\._key IN \[@q_\d+\] REMOVE \{_key:doc\._key\} IN users RETURN OLD$/' ,
            $model->lastQuery ,
        ) ;
    }

    public function testDeleteMultipleValuesUsesGetDocuments() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ '_key' => 'k1' ] , (object) [ '_key' => 'k2' ] ] ;

        $result = $model->delete( [ Arango::VALUE => [ 'k1' , 'k2' ] ] ) ;

        $this->assertSame( $model->documentsResult , $result ) ;
        $this->assertMatchesRegularExpression
        (
            '/FILTER doc\._key IN \[@q_\d+,@q_\d+\] REMOVE/' ,
            $model->lastQuery ,
        ) ;
    }

    public function testDeleteWithNoValuesReturnsNullWithoutQuery() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $this->assertNull( $model->delete( [ Arango::VALUE => [] ] ) ) ;
        $this->assertSame( '' , $model->lastQuery ) ;
    }

    public function testDeleteAppendsExtraConditions() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $model->delete( [ Arango::VALUE => 'k1' , Arango::CONDITIONS => [ 'doc.locked == false' ] ] ) ;

        $this->assertStringContainsString( '&& doc.locked == false REMOVE' , $model->lastQuery ) ;
    }

    public function testDebugFlagDoesNotAlterTheDelete() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $result = $model->delete( [ Arango::VALUE => 'k1' , Arango::DEBUG => true ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
    }

    public function testDeleteEmitsBeforeAndAfterSignals() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;
        $model->initializeDeleteSignals() ;

        $before = [] ;
        $after  = [] ;
        $model->beforeDelete->connect( function( $event ) use ( &$before ) { $before[] = $event ; } ) ;
        $model->afterDelete->connect ( function( $event ) use ( &$after  ) { $after[]  = $event ; } ) ;

        $result = $model->delete( [ Arango::VALUE => 'k1' ] ) ;

        $this->assertCount( 1 , $before ) ;
        $this->assertCount( 1 , $after ) ;
        $this->assertInstanceOf( BeforeDelete::class , $before[ 0 ] ) ;
        $this->assertInstanceOf( AfterDelete::class , $after[ 0 ] ) ;
        $this->assertSame( $result , $after[ 0 ]->data , 'afterDelete must carry the OLD document(s)' ) ;
    }

    public function testDeleteStillEmitsAfterDeleteWhenNothingMatched() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->initializeDeleteSignals() ;

        $after = [] ;
        $model->afterDelete->connect( function( $event ) use ( &$after ) { $after[] = $event ; } ) ;

        $model->delete( [ Arango::VALUE => [] ] ) ;

        $this->assertCount( 1 , $after , 'afterDelete must still fire even when no values were given' ) ;
        $this->assertNull( $after[ 0 ]->data ) ;
    }
}
