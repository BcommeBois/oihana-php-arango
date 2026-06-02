<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\Operation;
use oihana\arango\enums\Arango;
use oihana\models\notices\AfterUpdate;
use oihana\models\notices\BeforeUpdate;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsUpdateTrait}:
 * update(), updateDate() and the shared executeWriteOperation() they delegate to.
 */
final class DocumentsUpdateTraitTest extends TestCase
{
    public function testUpdateBuildsFilteredUpdateQuery() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $result = $model->update( [ Arango::VALUE => 'k1' , Arango::DOC => [ 'name' => 'New' ] ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @key UPDATE doc WITH @update IN @@collection RETURN NEW' ,
            $model->lastQuery ,
        ) ;
        $this->assertSame( 'k1' , $model->lastBinds[ 'key' ] ) ;
        $this->assertSame( [ 'name' , 'modified' ] , array_keys( $model->lastBinds[ 'update' ] ) ) ;
    }

    public function testUpdateCanReturnOld() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $model->update( [ Arango::VALUE => 'k1' , Arango::DOC => [ 'name' => 'New' ] , Arango::RETURN => Clause::OLD ] ) ;

        $this->assertStringEndsWith( 'RETURN OLD' , $model->lastQuery ) ;
    }

    public function testDebugFlagDoesNotAlterTheUpdate() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $model->update( [ Arango::VALUE => 'k1' , Arango::DOC => [ 'name' => 'New' ] , Arango::DEBUG => true ] ) ;

        $this->assertStringStartsWith( 'FOR doc IN @@collection FILTER doc._key == @key UPDATE' , $model->lastQuery ) ;
    }

    public function testUpdateDateStampsTheGivenProperty() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $model->updateDate( [ Arango::VALUE => 'k1' ] ) ;

        $this->assertArrayHasKey( 'modified' , $model->lastBinds[ 'update' ] ) ;
    }

    public function testExecuteWriteOperationRejectsUnsupportedOperation() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        ( new MockDocuments( 'users' ) )->callExecuteWriteOperation( [ Arango::VALUE => 'k1' ] , Operation::INSERT ) ;
    }

    public function testUpdateEmitsBeforeAndAfterSignals() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;
        $model->initializeUpdateSignals() ;

        $before = [] ;
        $after  = [] ;
        $model->beforeUpdate->connect( function( $event ) use ( &$before ) { $before[] = $event ; } ) ;
        $model->afterUpdate->connect ( function( $event ) use ( &$after  ) { $after[]  = $event ; } ) ;

        $result = $model->update( [ Arango::VALUE => 'k1' , Arango::DOC => [ 'name' => 'New' ] ] ) ;

        $this->assertCount( 1 , $before ) ;
        $this->assertCount( 1 , $after ) ;
        $this->assertInstanceOf( BeforeUpdate::class , $before[ 0 ] ) ;
        $this->assertInstanceOf( AfterUpdate::class , $after[ 0 ] ) ;
        $this->assertSame( $result , $after[ 0 ]->data ) ;
    }
}
