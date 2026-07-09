<?php

namespace tests\oihana\arango\controllers;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\enums\Output;

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

    public function testListComputesFacetCountsWhenRequested() :void
    {
        $model = new MockDocuments( 'articles' ) ;
        $model->facets          = [ 'category' => [ Facet::TYPE => Facet::FIELD ] ] ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] ] ;
        $model->firstResult     = [ 'category' => [ [ 'value' => 'A' , 'count' => 3 ] ] ] ; // canned facet buckets

        $controller = $this->makeDocumentsController( $model ) ;

        // ?facetCounts=category → the controller runs facetCounts() over the same filter.
        $request = $this->makeRequest( [ Arango::FACET_COUNTS => 'category' ] ) ;
        $result  = $controller->list( $request , null , [] ) ;

        $this->assertSame( $model->documentsResult , $result ) ;
        $this->assertStringContainsString( 'WITH COUNT INTO count' , $model->lastQuery ) ;
        $this->assertStringContainsString( 'LET category' , $model->lastQuery ) ;
    }

    public function testListComputesBoundsWhenRequested() :void
    {
        $model = new MockDocuments( 'products' ) ;
        $model->bounds          = [ 'width' => true ] ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] ] ;
        $model->firstResult     = [ 'width' => [ 'min' => 5 , 'max' => 240 ] ] ; // canned extent

        $controller = $this->makeDocumentsController( $model ) ;

        // ?bounds=width → the controller runs bounds() over the same filter and
        // attaches the { min, max } extent to the response options.
        $request = $this->makeRequest( [ Arango::BOUNDS => 'width' ] ) ;
        $result  = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [ 'min' => 5 , 'max' => 240 ] , $payload[ Arango::BOUNDS ][ 'width' ] ) ;
        $this->assertStringContainsString( 'COLLECT AGGREGATE width_min = MIN(doc.width)' , $model->lastQuery ) ;
    }

    public function testListReturnsNullOnModelFailure() :void
    {
        $controller = $this->makeDocumentsController( new ThrowingDocuments( 'users' ) ) ;

        $this->assertNull( $controller->list( null , null , [] ) ) ;
    }

    // ---- facetsOnly (counts-only mode) ----------------------------------

    public function testListFacetsOnlyReturnsCountsWithoutDocuments() :void
    {
        // count() is overridden so the counts-only total (42) is distinct from the
        // canned facet buckets returned by facetCounts() through getFirstResult().
        $model = new class( 'articles' ) extends MockDocuments
        {
            public array $countInit = [] ;
            public function count( array $init = [] ) :int { $this->countInit = $init ; return 42 ; }
        } ;
        $model->facets          = [ 'category' => [ Facet::TYPE => Facet::FIELD ] ] ;
        $model->documentsResult = [ (object) [ '_key' => 'x' ] ] ; // would leak if list() ran
        $model->firstResult     = [ 'category' => [ [ 'value' => 'A' , 'count' => 3 ] ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        // ?facetsOnly=true&facetCounts=category → no documents, exact total + facets.
        $request  = $this->makeRequest( [ Arango::FACETS_ONLY => 'true' , Arango::FACET_COUNTS => 'category' ] ) ;
        $result   = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload  = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [] , $payload[ Output::RESULT ] ) ;                    // documents skipped
        $this->assertSame( 42 , $payload[ Output::TOTAL ] ) ;                     // exact count(), not count(documents)
        $this->assertArrayHasKey( 'category' , $payload[ Arango::FACETS ] ) ;     // facet counts still computed
        $this->assertArrayHasKey( Arango::FACETS , $model->countInit ) ;          // count() ran over the same filters
        $this->assertStringContainsString( 'LET category' , $model->lastQuery ) ; // facetCounts query executed
    }

    public function testListFacetsOnlyWithoutFacetCountsSkipsFacets() :void
    {
        $model = new class( 'users' ) extends MockDocuments
        {
            public function count( array $init = [] ) :int { return 7 ; }
        } ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $request  = $this->makeRequest( [ Arango::FACETS_ONLY => '1' ] ) ;
        $result   = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload  = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [] , $payload[ Output::RESULT ] ) ;
        $this->assertSame( 7 , $payload[ Output::TOTAL ] ) ;
        $this->assertArrayNotHasKey( Arango::FACETS , $payload ) ;
    }

    public function testListFacetsOnlyWithMockModelReturnsZeroTotal() :void
    {
        // Mock model → the isDocuments guard is false: no count()/facetCounts() call,
        // total falls back to 0 and no facets are attached.
        $model = new MockDocuments( 'users' ) ;
        $model->mock            = true ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $request  = $this->makeRequest( [ Arango::FACETS_ONLY => 'true' , Arango::FACET_COUNTS => 'category' ] ) ;
        $result   = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload  = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [] , $payload[ Output::RESULT ] ) ;
        $this->assertSame( 0 , $payload[ Output::TOTAL ] ) ;
        $this->assertArrayNotHasKey( Arango::FACETS , $payload ) ;
    }
}
