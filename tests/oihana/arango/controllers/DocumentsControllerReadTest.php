<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\controllers\mocks\ThrowingDocuments;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for the read handlers of {@see DocumentsController}: get / count /
 * list / last — happy paths (data returned through the null-response branch of
 * `success()`) and the shared `catch` → `fail()` branch (null on a null
 * response).
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( DocumentsController::class )]
class DocumentsControllerReadTest extends ControllerTestCase
{
    // ---- get ------------------------------------------------------------

    public function testGetReturnsDocumentAndForwardsIdToTheModel() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' , 'name' => 'Alice' ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $result = $controller->get( null , null , [ Arango::ID => 'k1' ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertContains( 'k1' , $model->lastBinds ) ; // the id reached the lookup
    }

    public function testGetReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;

        $this->assertNull( $controller->get( null , null , [ Arango::ID => 'k1' ] ) ) ;
    }

    // ---- count ----------------------------------------------------------

    public function testCountReturnsTheModelCount() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 7 ;

        $controller = $this->makeDocumentsController( $model ) ;

        $this->assertSame( 7 , $controller->count( null , null , [] ) ) ;
    }

    public function testCountReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;

        $this->assertNull( $controller->count( null , null , [] ) ) ;
    }

    // ---- last -----------------------------------------------------------

    public function testLastReturnsTheLastDocument() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = (object) [ '_key' => 'last' ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $this->assertSame( $model->firstResult , $controller->last( null , null , [] ) ) ;
    }

    public function testLastReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;

        $this->assertNull( $controller->last( null , null , [] ) ) ;
    }

    // ---- list -----------------------------------------------------------

    public function testListReturnsDocuments() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] , (object) [ '_key' => '2' ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $result = $controller->list( null , null , [] ) ;

        $this->assertSame( $model->documentsResult , $result ) ;
        $this->assertStringContainsString( 'FOR doc IN' , $model->lastQuery ) ;
    }

    public function testListWithLimitAppliesLimitAndUsesFoundRows() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] ] ;
        $model->foundRowsResult = 99 ;

        $controller = $this->makeDocumentsController( $model ) ;

        // ?limit=10 → the query carries LIMIT and the limit>0 branch calls foundRows()
        $request = $this->makeRequest( [ 'limit' => '10' ] ) ;

        $result = $controller->list( $request , null , [] ) ;

        $this->assertSame( $model->documentsResult , $result ) ;
        $this->assertStringContainsString( 'LIMIT 10' , $model->lastQuery ) ;
    }

    public function testListReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;

        $this->assertNull( $controller->list( null , null , [] ) ) ;
    }
}
