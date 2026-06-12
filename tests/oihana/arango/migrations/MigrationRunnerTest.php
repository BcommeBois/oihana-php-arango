<?php

namespace tests\oihana\arango\migrations;

use RuntimeException;

use oihana\arango\db\ArangoDB;
use oihana\arango\migrations\enums\MigrationKind;
use oihana\arango\migrations\enums\MigrationStatus;
use oihana\arango\migrations\MigrationAction;
use oihana\arango\migrations\MigrationRunner;
use oihana\arango\migrations\MigrationStore;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use tests\oihana\arango\migrations\fixtures\ok\Version20260101000000_Alpha;
use tests\oihana\arango\migrations\fixtures\ok\Version20260102000000_Beta;
use tests\oihana\arango\migrations\fixtures\boom\Version20260101000000_Gamma;
use tests\oihana\arango\migrations\fixtures\boom\Version20260102000000_Boom;
use tests\oihana\arango\migrations\fixtures\boom\Version20260103000000_Delta;

/**
 * Unit coverage for {@see MigrationRunner} — discovery, status, pending,
 * apply (with the stop-on-failure), down and forget, driven by committed
 * fixture migrations and a mocked {@see MigrationStore}.
 *
 * @package tests\oihana\arango\migrations
 * @author  Marc Alcaraz
 */
#[CoversClass( MigrationRunner::class )]
#[AllowMockObjectsWithoutExpectations]
class MigrationRunnerTest extends TestCase
{
    private const string OK_NS   = 'tests\\oihana\\arango\\migrations\\fixtures\\ok' ;
    private const string BOOM_NS = 'tests\\oihana\\arango\\migrations\\fixtures\\boom' ;

    private string $okDir ;
    private string $boomDir ;

    protected function setUp() :void
    {
        $base = dirname( __DIR__ ) . '/migrations/fixtures' ;
        $this->okDir   = __DIR__ . '/fixtures/ok' ;
        $this->boomDir = __DIR__ . '/fixtures/boom' ;

        Version20260101000000_Alpha::$ran = [] ;
        Version20260102000000_Beta::$ran  = [] ;
        Version20260101000000_Gamma::$ran = [] ;
        Version20260102000000_Boom::$ran  = [] ;
        Version20260103000000_Delta::$ran = [] ;
    }

    private function runner( string $dir , string $namespace , MigrationStore $store ) :MigrationRunner
    {
        return new MigrationRunner
        (
            db        : $this->createMock( ArangoDB::class ) ,
            store     : $store ,
            path      : $dir ,
            namespace : $namespace ,
            gitCommit : 'commit42' ,
            agent     : 'marc@host' ,
        ) ;
    }

    private function storeApplied( array $applied = [] ) :MigrationStore
    {
        $store = $this->createMock( MigrationStore::class ) ;
        $store->method( 'applied' )->willReturn( $applied ) ;
        return $store ;
    }

    // ---- discover / pending ----------------------------------------------

    public function testDiscoverLoadsAndOrdersByVersion() :void
    {
        $discovered = $this->runner( $this->okDir , self::OK_NS , $this->storeApplied() )->discover() ;

        $this->assertSame( [ '20260101000000_Alpha' , '20260102000000_Beta' ] , array_keys( $discovered ) ) ;
    }

    public function testDiscoverReturnsEmptyWhenTheDirectoryIsMissing() :void
    {
        $runner = $this->runner( $this->okDir . '/nope' , self::OK_NS , $this->storeApplied() ) ;
        $this->assertSame( [] , $runner->discover() ) ;
    }

    public function testPendingExcludesTheAppliedVersions() :void
    {
        $applied = [ '20260101000000_Alpha' => new MigrationAction() ] ;

        $pending = $this->runner( $this->okDir , self::OK_NS , $this->storeApplied( $applied ) )->pending() ;

        $this->assertSame( [ '20260102000000_Beta' ] , array_keys( $pending ) ) ;
    }

    // ---- status -----------------------------------------------------------

    public function testStatusCrossesFilesWithTracking() :void
    {
        $applied = [ '20260101000000_Alpha' => $this->action( '20260101000000_Alpha' , MigrationStatus::COMPLETED ) ] ;

        $rows = $this->runner( $this->okDir , self::OK_NS , $this->storeApplied( $applied ) )->status() ;

        $this->assertCount( 2 , $rows ) ;
        $this->assertTrue ( $rows[0][ 'applied' ] ) ;
        $this->assertFalse( $rows[1][ 'applied' ] ) ;
        $this->assertFalse( $rows[0][ 'missingFile' ] ) ;
    }

    public function testStatusFlagsAnAppliedVersionWithNoFile() :void
    {
        $applied = [ '20990101000000_Ghost' => $this->action( '20990101000000_Ghost' , MigrationStatus::COMPLETED ) ] ;

        $rows = $this->runner( $this->okDir , self::OK_NS , $this->storeApplied( $applied ) )->status() ;

        $ghost = array_values( array_filter( $rows , fn( $r ) => $r[ 'version' ] === '20990101000000_Ghost' ) )[0] ;
        $this->assertTrue( $ghost[ 'missingFile' ] ) ;
        $this->assertTrue( $ghost[ 'applied' ] ) ;
    }

    // ---- apply ------------------------------------------------------------

    public function testApplyRunsPendingInOrderAndRecordsCompleted() :void
    {
        $saved = [] ;
        $store = $this->storeApplied() ;
        $store->method( 'save' )->willReturnCallback( function( MigrationAction $a ) use ( &$saved ) { $saved[] = $a->actionStatus ; } ) ;

        $recorded = $this->runner( $this->okDir , self::OK_NS , $store )->apply( '2026-06-12T09:00:00+00:00' ) ;

        $this->assertSame( [ 'alpha.up' ] , Version20260101000000_Alpha::$ran ) ;
        $this->assertSame( [ 'beta.up' ] , Version20260102000000_Beta::$ran ) ;
        $this->assertCount( 2 , $recorded ) ;
        $this->assertSame( MigrationStatus::COMPLETED , $recorded[0]->actionStatus ) ;
        $this->assertSame( 'commit42' , $recorded[0]->gitCommit ) ;
        $this->assertSame( 'marc@host' , $recorded[0]->agent ) ;
        // active then completed, per migration
        $this->assertSame( [ MigrationStatus::ACTIVE , MigrationStatus::COMPLETED , MigrationStatus::ACTIVE , MigrationStatus::COMPLETED ] , $saved ) ;
    }

    public function testApplyStopsAtTheFirstFailure() :void
    {
        $store = $this->storeApplied() ;

        $recorded = $this->runner( $this->boomDir , self::BOOM_NS , $store )->apply() ;

        $this->assertSame( [ 'gamma.up' ] , Version20260101000000_Gamma::$ran ) ;
        $this->assertSame( [ 'boom.up' ] , Version20260102000000_Boom::$ran ) ;
        $this->assertSame( [] , Version20260103000000_Delta::$ran , 'Delta must never run after Boom failed.' ) ;

        $this->assertCount( 2 , $recorded ) ;
        $this->assertSame( MigrationStatus::COMPLETED , $recorded[0]->actionStatus ) ;
        $this->assertSame( MigrationStatus::FAILED , $recorded[1]->actionStatus ) ;
        $this->assertSame( 'kaboom' , $recorded[1]->error ) ;
    }

    public function testApplyDoesNothingWhenUpToDate() :void
    {
        $applied =
        [
            '20260101000000_Alpha' => new MigrationAction() ,
            '20260102000000_Beta'  => new MigrationAction() ,
        ] ;

        $recorded = $this->runner( $this->okDir , self::OK_NS , $this->storeApplied( $applied ) )->apply() ;

        $this->assertSame( [] , $recorded ) ;
    }

    // ---- down / forget ----------------------------------------------------

    public function testDownRollsBackTheLastAppliedLifo() :void
    {
        $applied =
        [
            '20260101000000_Alpha' => new MigrationAction() ,
            '20260102000000_Beta'  => new MigrationAction() ,
        ] ;

        $removed = [] ;
        $store = $this->storeApplied( $applied ) ;
        $store->method( 'remove' )->willReturnCallback( function( $v ) use ( &$removed ) { $removed[] = $v ; } ) ;

        $rolledBack = $this->runner( $this->okDir , self::OK_NS , $store )->down( 1 ) ;

        $this->assertSame( [ '20260102000000_Beta' ] , $rolledBack ) ;
        $this->assertSame( [ '20260102000000_Beta' ] , $removed ) ;
        $this->assertSame( [ 'beta.down' ] , Version20260102000000_Beta::$ran ) ;
        $this->assertSame( [] , Version20260101000000_Alpha::$ran , 'Alpha must not be rolled back when count is 1.' ) ;
    }

    public function testDownIsNoOpWhenNothingApplied() :void
    {
        $this->assertSame( [] , $this->runner( $this->okDir , self::OK_NS , $this->storeApplied() )->down( 3 ) ) ;
    }

    public function testForgetRemovesTheTrackingRow() :void
    {
        $store = $this->createMock( MigrationStore::class ) ;
        $store->expects( $this->once() )->method( 'remove' )->with( '20260102000000_Beta' ) ;

        $this->runner( $this->okDir , self::OK_NS , $store )->forget( '20260102000000_Beta' ) ;
    }

    // ---- guards -----------------------------------------------------------

    public function testDiscoverThrowsOnAFileThatIsNotAMigration() :void
    {
        $dir = sys_get_temp_dir() . '/oihana_badmig_' . uniqid() ;
        mkdir( $dir ) ;
        file_put_contents( $dir . '/Version20260101000000_NotOne.php' , "<?php\n" ) ;

        try
        {
            $this->expectException( RuntimeException::class ) ;
            $this->runner( $dir , 'tests\\nope' , $this->storeApplied() )->discover() ;
        }
        finally
        {
            @unlink( $dir . '/Version20260101000000_NotOne.php' ) ;
            @rmdir( $dir ) ;
        }
    }

    /**
     * A tracking action with a version and status.
     */
    private function action( string $version , string $status ) :MigrationAction
    {
        $action = new MigrationAction() ;
        $action->_key         = $version ;
        $action->actionStatus = $status ;
        $action->description  = 'x' ;
        return $action ;
    }
}
