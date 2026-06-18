<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\clients\view\View;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\views\SearchAliasView;
use oihana\arango\db\traits\ViewManagementTrait;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;

use tests\oihana\arango\db\ArangoDBTestCase;

/**
 * Characterization coverage for {@see ViewManagementTrait} — the View
 * management surface delegated to the `clients/Database` + `clients/View`
 * layer, mirroring {@see CollectionManagementTraitTest}.
 *
 * @package tests\oihana\arango\db\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( ViewManagementTrait::class )]
#[AllowMockObjectsWithoutExpectations]
class ViewManagementTraitTest extends ArangoDBTestCase
{
    /**
     * A Database double whose `view()` always returns the given View.
     *
     * @param View $view
     *
     * @return Database
     */
    private function databaseReturning( View $view ) :Database
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willReturn( $view ) ;
        return $database ;
    }

    // ---- viewCreate -------------------------------------------------------

    public function testViewCreateCreatesWhenAbsent() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;
        $view->expects( $this->once() )
             ->method( 'create' )
             ->with( [ 'places' => [ 'fields' => [ 'name' => [] ] ] ] , [ 'commitIntervalMsec' => 100 ] ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $view ) ) ;

        $this->assertTrue( $db->viewCreate( 'placesView' , [ 'places' => [ 'fields' => [ 'name' => [] ] ] ] , [ 'commitIntervalMsec' => 100 ] ) ) ;
    }

    public function testViewCreateReturnsFalseWhenAlreadyPresent() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( true ) ;
        $view->expects( $this->never() )->method( 'create' ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $view ) ) ;

        $this->assertFalse( $db->viewCreate( 'placesView' ) ) ;
    }

    public function testViewCreateSwallowsClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $this->assertFalse( $this->newArangoDB( $database )->viewCreate( 'placesView' ) ) ;
    }

    // ---- viewDrop ---------------------------------------------------------

    public function testViewDropDropsWhenPresent() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( true ) ;
        $view->expects( $this->once() )->method( 'drop' ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $view ) )->viewDrop( 'placesView' ) ) ;
    }

    public function testViewDropReturnsFalseWhenAbsent() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;

        $this->assertFalse( $this->newArangoDB( $this->databaseReturning( $view ) )->viewDrop( 'placesView' ) ) ;
    }

    public function testViewDropSwallowsClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->viewDrop( 'placesView' ) ) ;
    }

    // ---- viewExists -------------------------------------------------------

    public function testViewExistsForwardsTheBoolean() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( true ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $view ) )->viewExists( 'placesView' ) ) ;
    }

    public function testViewExistsReturnsFalseOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException() ) ;

        $this->assertFalse( $this->newArangoDB( $database )->viewExists( 'placesView' ) ) ;
    }

    // ---- viewDiff ---------------------------------------------------------

    /**
     * The declared link of the fixtures : `name` indexed with `text_fr`.
     */
    private function declaredLinks() :array
    {
        return [ 'places' => new ArangoSearchLink( fields : [ 'name' => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ] ) ] ;
    }

    /**
     * A server-side `properties()` payload, normalised the way the server
     * answers : link-level defaults filled in, declared fields under `fields`.
     */
    private function serverProperties( array $fields , string $type = 'arangosearch' ) :array
    {
        return
        [
            'name'  => 'placesView' ,
            'type'  => $type ,
            'links' =>
            [
                'places' =>
                [
                    'analyzers'          => [ 'identity' ] ,
                    'includeAllFields'   => false ,
                    'storeValues'        => 'none' ,
                    'trackListPositions' => false ,
                    'fields'             => $fields ,
                ] ,
            ] ,
            'commitIntervalMsec' => 1000 ,
        ] ;
    }

    /**
     * A View double answering `exists()` true and `properties()` with the
     * given payload.
     */
    private function viewWithProperties( array $properties ) :View
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( true ) ;
        $view->method( 'properties' )->willReturn( $properties ) ;
        return $view ;
    }

    public function testDiffReportsMissingWhenTheViewDoesNotExist() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertSame( 'placesView' , $report->name ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testDiffReportsUnreachableOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $report = $this->newArangoDB( $database )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
        $this->assertSame( [ 'connection refused' ] , $report->changes ) ;
    }

    public function testDiffReportsInvalidOnViewTypeConflict() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [] , type : 'search-alias' ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertStringContainsString( "'search-alias'" , $report->changes[0] ) ;
    }

    public function testViewDiffIsInSyncWhenTheServerMatchesTheDeclaredSubset() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertTrue( $report->inSync() ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testViewDiffComparesAnalyzersOrderInsensitively() :void
    {
        $links = [ 'places' => new ArangoSearchLink( fields : [ 'name' => new ArangoSearchLink( analyzers : [ 'text_fr' , 'identity' ] ) ] ) ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'identity' , 'text_fr' ] ] ] ) ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links )->inSync() ) ;
    }

    public function testDiffReportsAFieldMissingOnTheServer() :void
    {
        $links = [ 'places' => new ArangoSearchLink( fields :
        [
            'name'        => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ,
            'description' => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ,
        ] ) ] ;

        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.fields.description : not indexed on the server' ] , $report->changes ) ;
    }

    public function testDiffReportsAnAnalyzerMismatch() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_en' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.fields.name.analyzers : server ["text_en"] ≠ declared ["text_fr"]' ] , $report->changes ) ;
    }

    public function testDiffReportsAFieldIndexedButNotDeclared() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties(
        [
            'name' => [ 'analyzers' => [ 'text_fr' ] ] ,
            'old'  => [ 'analyzers' => [ 'text_fr' ] ] ,
        ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.fields.old : indexed on the server but not declared' ] , $report->changes ) ;
    }

    public function testDiffReportsAScalarMismatch() :void
    {
        $links = [ 'places' => new ArangoSearchLink( includeAllFields : true ) ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.includeAllFields : server false ≠ declared true' ] , $report->changes ) ;
    }

    public function testDiffReportsAMissingCollectionLink() :void
    {
        $links = [ ...$this->declaredLinks() , 'reviews' => new ArangoSearchLink( fields : [ 'body' => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ] ) ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'reviews : not linked on the server' ] , $report->changes ) ;
    }

    public function testDiffReportsACollectionLinkedButNotDeclared() :void
    {
        $properties = $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ;
        $properties[ 'links' ][ 'reviews' ] = [ 'fields' => [] ] ;

        $view = $this->viewWithProperties( $properties ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'reviews : linked on the server but not declared' ] , $report->changes ) ;
    }

    public function testViewDiffAcceptsPlainArrayLinks() :void
    {
        $links = [ 'places' => [ 'fields' => [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ] ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links )->inSync() ) ;
    }

    // ---- viewSync ---------------------------------------------------------

    public function testViewSyncCreatesAMissingView() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;
        $view->expects( $this->once() )->method( 'create' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewSync( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
    }

    public function testViewSyncRepairsADriftWithUpdateProperties() :void
    {
        $links = $this->declaredLinks() ;

        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_en' ] ] ] ) ) ;
        $view->expects( $this->once() )
             ->method( 'updateProperties' )
             ->with( [ 'links' => $links ] )
             ->willReturn( [ 'links' => [] ] ) ;
        $view->expects( $this->never() )->method( 'replaceProperties' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewSync( 'placesView' , $links ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
        $this->assertNotSame( [] , $report->changes ) ;
    }

    public function testViewSyncLeavesAnInSyncViewUntouched() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;
        $view->expects( $this->never() )->method( 'updateProperties' ) ;
        $view->expects( $this->never() )->method( 'create' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewSync( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testViewSyncReportsAFailedUpdate() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_en' ] ] ] ) ) ;
        $view->method( 'updateProperties' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewSync( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
        $this->assertContains( 'sync failed : boom' , $report->changes ) ;
    }

    // ---- search-alias views ----------------------------------------------

    /**
     * The declared search-alias view of the fixtures : customers + products,
     * each aliasing an `inv_search` inverted index.
     */
    private function declaredSearchAlias() :SearchAliasView
    {
        return new SearchAliasView( 'global_search' , [ 'customers' => 'inv_search' , 'products' => 'inv_search' ] ) ;
    }

    /**
     * A server-side search-alias `properties()` payload.
     */
    private function serverAliasProperties( array $indexes , string $type = 'search-alias' ) :array
    {
        return [ 'name' => 'global_search' , 'type' => $type , 'indexes' => $indexes ] ;
    }

    public function testSearchAliasCreateCreatesWhenAbsent() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;
        $view->expects( $this->once() )
             ->method( 'createSearchAlias' )
             ->with
             (
                 [
                     [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
                     [ 'collection' => 'products'  , 'index' => 'inv_search' ] ,
                 ] ,
                 [] ,
             ) ;

        $db = $this->newArangoDB( $this->databaseReturning( $view ) ) ;

        $this->assertTrue( $db->searchAliasViewCreate( $this->declaredSearchAlias() ) ) ;
    }

    public function testSearchAliasCreateReturnsFalseWhenAlreadyPresent() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( true ) ;
        $view->expects( $this->never() )->method( 'createSearchAlias' ) ;

        $this->assertFalse( $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewCreate( $this->declaredSearchAlias() ) ) ;
    }

    public function testSearchAliasCreateSwallowsClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $this->assertFalse( $this->newArangoDB( $database )->searchAliasViewCreate( $this->declaredSearchAlias() ) ) ;
    }

    public function testSearchAliasDiffReportsMissing() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewDiff( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertSame( 'global_search'    , $report->name ) ;
    }

    public function testSearchAliasDiffReportsUnreachable() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $report = $this->newArangoDB( $database )->searchAliasViewDiff( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
        $this->assertSame( [ 'connection refused' ] , $report->changes ) ;
    }

    public function testSearchAliasDiffReportsInvalidOnTypeConflict() :void
    {
        $view = $this->viewWithProperties( $this->serverAliasProperties( [] , type : 'arangosearch' ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewDiff( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertStringContainsString( "'arangosearch'" , $report->changes[0] ) ;
    }

    public function testSearchAliasDiffIsInSyncRegardlessOfOrder() :void
    {
        $view = $this->viewWithProperties( $this->serverAliasProperties(
        [
            [ 'collection' => 'products'  , 'index' => 'inv_search' ] ,
            [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
        ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewDiff( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testSearchAliasDiffReportsDriftBothWays() :void
    {
        // Server is missing `products` and aliases an undeclared `sellers`.
        $view = $this->viewWithProperties( $this->serverAliasProperties(
        [
            [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
            [ 'collection' => 'sellers'   , 'index' => 'inv_search' ] ,
        ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewDiff( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'products : index "inv_search" not aliased on the server' , $report->changes ) ;
        $this->assertContains( 'sellers : index "inv_search" aliased on the server but not declared' , $report->changes ) ;
    }

    public function testSearchAliasDiffSkipsMalformedServerEntries() :void
    {
        // A bogus server entry (not a {collection, index} object) is ignored,
        // not counted as drift.
        $view = $this->viewWithProperties( $this->serverAliasProperties(
        [
            [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
            'bogus' ,
            [ 'collection' => 'products'  , 'index' => 'inv_search' ] ,
        ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewDiff( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
    }

    public function testSearchAliasSyncCreatesWhenMissing() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;
        $view->expects( $this->once() )->method( 'createSearchAlias' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewSync( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
    }

    public function testSearchAliasSyncRecreatesOnDrift() :void
    {
        $view = $this->viewWithProperties( $this->serverAliasProperties(
        [
            [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
        ] ) ) ;
        $view->expects( $this->once() )->method( 'drop' ) ;
        $view->expects( $this->once() )->method( 'createSearchAlias' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewSync( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
    }

    public function testSearchAliasSyncLeavesInSyncUntouched() :void
    {
        $view = $this->viewWithProperties( $this->serverAliasProperties(
        [
            [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
            [ 'collection' => 'products'  , 'index' => 'inv_search' ] ,
        ] ) ) ;
        $view->expects( $this->never() )->method( 'drop' ) ;
        $view->expects( $this->never() )->method( 'createSearchAlias' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewSync( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testSearchAliasSyncReportsAFailedRecreate() :void
    {
        $view = $this->viewWithProperties( $this->serverAliasProperties(
        [
            [ 'collection' => 'customers' , 'index' => 'inv_search' ] ,
        ] ) ) ;
        $view->method( 'drop' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->searchAliasViewSync( $this->declaredSearchAlias() ) ;

        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
        $this->assertContains( 'sync failed : boom' , $report->changes ) ;
    }
}
