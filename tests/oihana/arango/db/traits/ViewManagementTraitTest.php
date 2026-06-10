<?php

namespace tests\oihana\arango\db\traits;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\View;
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
}
