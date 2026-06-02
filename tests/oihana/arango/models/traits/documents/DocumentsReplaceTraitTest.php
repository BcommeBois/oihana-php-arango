<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;
use oihana\models\notices\AfterReplace;
use oihana\models\notices\BeforeReplace;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsReplaceTrait::replace()}.
 *
 * replace() shares executeWriteOperation() with update() but selects the REPLACE
 * operation; this verifies the REPLACE clause and the before/after signals.
 */
final class DocumentsReplaceTraitTest extends TestCase
{
    public function testReplaceBuildsFilteredReplaceQuery() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $result = $model->replace( [ Arango::VALUE => 'k1' , Arango::DOC => [ 'name' => 'Rep' ] ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc._key == @key REPLACE doc WITH @replace IN @@collection RETURN NEW' ,
            $model->lastQuery ,
        ) ;
        $this->assertSame( [ 'name' , 'modified' ] , array_keys( $model->lastBinds[ 'replace' ] ) ) ;
    }

    public function testReplaceEmitsBeforeAndAfterSignals() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;
        $model->initializeReplaceSignals() ;

        $before = [] ;
        $after  = [] ;
        $model->beforeReplace->connect( function( $event ) use ( &$before ) { $before[] = $event ; } ) ;
        $model->afterReplace->connect ( function( $event ) use ( &$after  ) { $after[]  = $event ; } ) ;

        $result = $model->replace( [ Arango::VALUE => 'k1' , Arango::DOC => [ 'name' => 'Rep' ] ] ) ;

        $this->assertCount( 1 , $before ) ;
        $this->assertCount( 1 , $after ) ;
        $this->assertInstanceOf( BeforeReplace::class , $before[ 0 ] ) ;
        $this->assertInstanceOf( AfterReplace::class , $after[ 0 ] ) ;
        $this->assertSame( $result , $after[ 0 ]->data ) ;
    }
}
