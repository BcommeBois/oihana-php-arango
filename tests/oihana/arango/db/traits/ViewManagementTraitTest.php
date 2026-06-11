<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\clients\view\View;
use oihana\arango\db\enums\ViewDiffStatus;
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

    public function testViewDiffReportsMissingWhenTheViewDoesNotExist() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( false ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::MISSING , $report->status ) ;
        $this->assertSame( 'placesView' , $report->name ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testViewDiffReportsUnreachableOnClientException() :void
    {
        $database = $this->createMock( Database::class ) ;
        $database->method( 'view' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $report = $this->newArangoDB( $database )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::UNREACHABLE , $report->status ) ;
        $this->assertSame( [ 'connection refused' ] , $report->changes ) ;
    }

    public function testViewDiffReportsInvalidOnViewTypeConflict() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [] , type : 'search-alias' ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::INVALID , $report->status ) ;
        $this->assertStringContainsString( "'search-alias'" , $report->changes[0] ) ;
    }

    public function testViewDiffIsInSyncWhenTheServerMatchesTheDeclaredSubset() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::IN_SYNC , $report->status ) ;
        $this->assertTrue( $report->inSync() ) ;
        $this->assertSame( [] , $report->changes ) ;
    }

    public function testViewDiffComparesAnalyzersOrderInsensitively() :void
    {
        $links = [ 'places' => new ArangoSearchLink( fields : [ 'name' => new ArangoSearchLink( analyzers : [ 'text_fr' , 'identity' ] ) ] ) ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'identity' , 'text_fr' ] ] ] ) ) ;

        $this->assertTrue( $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links )->inSync() ) ;
    }

    public function testViewDiffReportsAFieldMissingOnTheServer() :void
    {
        $links = [ 'places' => new ArangoSearchLink( fields :
        [
            'name'        => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ,
            'description' => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ,
        ] ) ] ;

        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.fields.description : not indexed on the server' ] , $report->changes ) ;
    }

    public function testViewDiffReportsAnAnalyzerMismatch() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_en' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.fields.name.analyzers : server ["text_en"] ≠ declared ["text_fr"]' ] , $report->changes ) ;
    }

    public function testViewDiffReportsAFieldIndexedButNotDeclared() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties(
        [
            'name' => [ 'analyzers' => [ 'text_fr' ] ] ,
            'old'  => [ 'analyzers' => [ 'text_fr' ] ] ,
        ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.fields.old : indexed on the server but not declared' ] , $report->changes ) ;
    }

    public function testViewDiffReportsAScalarMismatch() :void
    {
        $links = [ 'places' => new ArangoSearchLink( includeAllFields : true ) ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'places.includeAllFields : server false ≠ declared true' ] , $report->changes ) ;
    }

    public function testViewDiffReportsAMissingCollectionLink() :void
    {
        $links = [ ...$this->declaredLinks() , 'reviews' => new ArangoSearchLink( fields : [ 'body' => new ArangoSearchLink( analyzers : [ 'text_fr' ] ) ] ) ] ;
        $view  = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $links ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertSame( [ 'reviews : not linked on the server' ] , $report->changes ) ;
    }

    public function testViewDiffReportsACollectionLinkedButNotDeclared() :void
    {
        $properties = $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ;
        $properties[ 'links' ][ 'reviews' ] = [ 'fields' => [] ] ;

        $view = $this->viewWithProperties( $properties ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewDiff( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
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

        $this->assertSame( ViewDiffStatus::MISSING , $report->status ) ;
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

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
        $this->assertNotSame( [] , $report->changes ) ;
    }

    public function testViewSyncLeavesAnInSyncViewUntouched() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_fr' ] ] ] ) ) ;
        $view->expects( $this->never() )->method( 'updateProperties' ) ;
        $view->expects( $this->never() )->method( 'create' ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewSync( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::IN_SYNC , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testViewSyncReportsAFailedUpdate() :void
    {
        $view = $this->viewWithProperties( $this->serverProperties( [ 'name' => [ 'analyzers' => [ 'text_en' ] ] ] ) ) ;
        $view->method( 'updateProperties' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $report = $this->newArangoDB( $this->databaseReturning( $view ) )->viewSync( 'placesView' , $this->declaredLinks() ) ;

        $this->assertSame( ViewDiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
        $this->assertContains( 'sync failed : boom' , $report->changes ) ;
    }
}
