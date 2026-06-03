<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\signals\Signal;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for {@see \oihana\arango\models\traits\documents\DocumentsMethodsTrait::initializeDocumentsMethods()}
 * — it wires the delete/insert/replace/update signals and registers the
 * onUpdateRelations callback.
 */
final class DocumentsMethodsTraitTest extends TestCase
{
    public function testInitializeDocumentsMethodsWiresAllSignalsAndReturnsSelf() :void
    {
        $model = new MockDocuments( 'users' ) ;

        $result = $model->initializeDocumentsMethods() ;

        $this->assertSame( $model , $result ) ;
        $this->assertInstanceOf( Signal::class , $model->beforeDelete ) ;
        $this->assertInstanceOf( Signal::class , $model->afterDelete ) ;
        $this->assertInstanceOf( Signal::class , $model->beforeInsert ) ;
        $this->assertInstanceOf( Signal::class , $model->afterInsert ) ;
        $this->assertInstanceOf( Signal::class , $model->beforeReplace ) ;
        $this->assertInstanceOf( Signal::class , $model->afterReplace ) ;
        $this->assertInstanceOf( Signal::class , $model->beforeUpdate ) ;
        $this->assertInstanceOf( Signal::class , $model->afterUpdate ) ;
    }
}
