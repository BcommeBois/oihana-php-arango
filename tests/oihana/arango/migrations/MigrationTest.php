<?php

namespace tests\oihana\arango\migrations;

use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\Database;
use oihana\arango\db\ArangoDB;
use oihana\arango\migrations\Migration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A concrete probe exposing the protected toolbox of {@see Migration}.
 */
class MigrationToolboxProbe extends Migration
{
    public function up() :void {}

    public function callQuery( string $aql ) :void { $this->query( $aql ) ; }
    public function callRename( string $c , string $f , string $t ) :void { $this->renameField( $c , $f , $t ) ; }
    public function callDrop( string $c , string $f ) :void { $this->dropField( $c , $f ) ; }
    public function callSetDefault( string $c , string $f , mixed $v ) :void { $this->setDefault( $c , $f , $v ) ; }
}

/**
 * Unit coverage for {@see Migration} — the default description / down and the
 * toolbox delegating to the façade's low-level query.
 *
 * @package tests\oihana\arango\migrations
 * @author  Marc Alcaraz
 */
#[CoversClass( Migration::class )]
#[AllowMockObjectsWithoutExpectations]
class MigrationTest extends TestCase
{
    /**
     * A façade whose `database()->query()` expects a single AQL string.
     */
    private function probeExpecting( string $aql ) :MigrationToolboxProbe
    {
        $database = $this->createMock( Database::class ) ;
        $database->expects( $this->once() )
                 ->method( 'query' )
                 ->with( $aql , [] )
                 ->willReturn( $this->createMock( Cursor::class ) ) ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'database' )->willReturn( $database ) ;

        return new MigrationToolboxProbe( $facade ) ;
    }

    public function testDescriptionDefaultsToTheClassShortName() :void
    {
        $migration = new MigrationToolboxProbe( $this->createMock( ArangoDB::class ) ) ;
        $this->assertSame( 'MigrationToolboxProbe' , $migration->description() ) ;
    }

    public function testDownIsANoOpByDefault() :void
    {
        $migration = new MigrationToolboxProbe( $this->createMock( ArangoDB::class ) ) ;
        $migration->down() ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testQueryDelegatesToTheLowLevelClient() :void
    {
        $this->probeExpecting( 'FOR doc IN x RETURN doc' )->callQuery( 'FOR doc IN x RETURN doc' ) ;
    }

    public function testRenameFieldRunsTheRenameQuery() :void
    {
        $this->probeExpecting
        (
            'FOR doc IN places FILTER HAS( doc , "tel" ) UPDATE doc WITH { phone: doc.tel, tel: null } IN places OPTIONS { keepNull: false }'
        )->callRename( 'places' , 'tel' , 'phone' ) ;
    }

    public function testDropFieldRunsTheDropQuery() :void
    {
        $this->probeExpecting
        (
            'FOR doc IN places FILTER HAS( doc , "legacy" ) UPDATE doc WITH { legacy: null } IN places OPTIONS { keepNull: false }'
        )->callDrop( 'places' , 'legacy' ) ;
    }

    public function testSetDefaultRunsTheBackfillQuery() :void
    {
        $this->probeExpecting
        (
            'FOR doc IN orders FILTER doc.status == null UPDATE doc WITH { status: "pending" } IN orders'
        )->callSetDefault( 'orders' , 'status' , 'pending' ) ;
    }
}
