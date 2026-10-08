<?php

namespace tests\oihana\arango\controllers;

use Closure;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\enums\Output;

use org\schema\helpers\SchemaResolver;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Http\Message\ServerRequestInterface as Request;

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

        $result  = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload = json_decode( (string) $result->getBody() , true ) ;

        $this->assertCount( 1 , $payload[ Output::RESULT ] ) ;
        $this->assertSame( 99 , $payload[ Output::TOTAL ] ) ; // the full count, not the page size
        $this->assertStringContainsString( 'LIMIT 10' , $model->lastQuery ) ;
    }

    public function testListTotalSurvivesAHookThatQueriesTheModel() :void
    {
        // The full count belongs to the cursor of the LAST query the connection ran:
        // this double forgets it as soon as another fetch seam runs, like the driver does.
        $model = new class( 'users' ) extends MockDocuments
        {
            public function getObject( string $query , array $bindVars = [] , array $options = [] , bool $raw = false , null|SchemaResolver|Closure|string $schema = null , array $context = [] ) :?object
            {
                $this->foundRowsResult = 0 ;
                return parent::getObject( $query , $bindVars , $options , $raw , $schema , $context ) ;
            }
        } ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] , (object) [ '_key' => '2' ] ] ;
        $model->objectResult    = (object) [ '_key' => 'ref' ] ;
        $model->foundRowsResult = 99 ;

        // A consumer whose after hook reads a document back for every listed row.
        $controller = new class( ...$this->controllerArgsOf( $model ) ) extends DocumentsController
        {
            protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
            {
                parent::afterModelCall( $request , $init , $result ) ;

                if ( is_array( $result ) )
                {
                    $this->model->get( [ Arango::VALUE => 'ref' ] ) ;
                }
            }
        } ;

        $request = $this->makeRequest( [ 'limit' => '10' ] ) ;

        $result  = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload = json_decode( (string) $result->getBody() , true ) ;

        $this->assertCount( 2 , $payload[ Output::RESULT ] ) ;
        $this->assertSame( 99 , $payload[ Output::TOTAL ] ) ; // read before the hook, not after its `get`
        $this->assertSame( 0 , $model->foundRowsResult ) ;    // the hook did run its query
    }

    public function testListWithoutLimitCountsTheDocumentsTheHookLeaves() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] , (object) [ '_key' => '2' ] , (object) [ '_key' => '3' ] ] ;
        $model->foundRowsResult = 99 ; // must stay unread without a limit

        // A consumer whose after hook drops the last listed row.
        $controller = new class( ...$this->controllerArgsOf( $model ) ) extends DocumentsController
        {
            protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
            {
                parent::afterModelCall( $request , $init , $result ) ;

                if ( is_array( $result ) )
                {
                    array_pop( $result ) ;
                }
            }
        } ;

        $result  = $controller->list( $this->makeRequest() , $this->makeResponse() , [] ) ;
        $payload = json_decode( (string) $result->getBody() , true ) ;

        $this->assertCount( 2 , $payload[ Output::RESULT ] ) ;
        $this->assertSame( 2 , $payload[ Output::TOTAL ] ) ; // counted after the hook
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

    // ---- metaOnly (metadata-only mode) ----------------------------------

    public function testListMetaOnlyReturnsBoundsWithoutDocuments() :void
    {
        // count() is overridden so the meta-only total (42) is distinct from the
        // canned extent returned by bounds() through getFirstResult().
        $model = new class( 'products' ) extends MockDocuments
        {
            public function count( array $init = [] ) :int { return 42 ; }
        } ;
        $model->bounds          = [ 'width' => true ] ;
        $model->documentsResult = [ (object) [ '_key' => 'x' ] ] ; // would leak if list() ran
        $model->firstResult     = [ 'width' => [ 'min' => 5 , 'max' => 240 ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        // ?metaOnly=true&bounds=width → no documents, exact total + bounds.
        $request  = $this->makeRequest( [ Arango::META_ONLY => 'true' , Arango::BOUNDS => 'width' ] ) ;
        $result   = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload  = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [] , $payload[ Output::RESULT ] ) ;                                       // documents skipped
        $this->assertSame( 42 , $payload[ Output::TOTAL ] ) ;                                        // exact count(), not count(documents)
        $this->assertSame( [ 'min' => 5 , 'max' => 240 ] , $payload[ Arango::BOUNDS ][ 'width' ] ) ; // bounds still computed
    }

    public function testListFacetsOnlyStaysATruthyAliasOfMetaOnly() :void
    {
        // The deprecated ?facetsOnly= still skips the documents (OR-ed into metaOnly).
        $model = new class( 'users' ) extends MockDocuments
        {
            public function count( array $init = [] ) :int { return 9 ; }
        } ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] ] ;

        $controller = $this->makeDocumentsController( $model ) ;

        $request  = $this->makeRequest( [ Arango::FACETS_ONLY => 'true' ] ) ;
        $result   = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload  = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [] , $payload[ Output::RESULT ] ) ;
        $this->assertSame( 9 , $payload[ Output::TOTAL ] ) ;
    }

    public function testListMetaOnlyDefaultsToTrueWhenWiredInInit() :void
    {
        // A controller wired with META_ONLY => true (durable DI default) skips the
        // documents even when the request carries no ?metaOnly= param.
        $model = new class( 'products' ) extends MockDocuments
        {
            public function count( array $init = [] ) :int { return 42 ; }
        } ;
        $model->documentsResult = [ (object) [ '_key' => 'x' ] ] ; // would leak if list() ran

        $controller = $this->makeDocumentsController( $model , [ Arango::META_ONLY => true ] ) ;
        $this->assertTrue( $controller->metaOnly ) ; // the constructor stored the default

        $request = $this->makeRequest( [] ) ; // a request that carries no ?metaOnly= param
        $result  = $controller->list( $request , $this->makeResponse() , [] ) ;
        $payload = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( [] , $payload[ Output::RESULT ] ) ; // documents skipped by default
        $this->assertSame( 42 , $payload[ Output::TOTAL ] ) ;  // exact count() still computed
    }

    public function testListRequestOverridesTheMetaOnlyDefault() :void
    {
        // ?metaOnly=false always wins over the durable DI default, restoring the documents.
        $model = new MockDocuments( 'products' ) ;
        $model->documentsResult = [ (object) [ '_key' => '1' ] , (object) [ '_key' => '2' ] ] ;

        $controller = $this->makeDocumentsController( $model , [ Arango::META_ONLY => true ] ) ;

        $request = $this->makeRequest( [ Arango::META_ONLY => 'false' ] ) ;
        $result  = $controller->list( $request , null , [] ) ;

        $this->assertSame( $model->documentsResult , $result ) ; // documents restored
    }
}
