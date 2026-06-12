<?php

namespace tests\oihana\arango\integration;

use Psr\Log\NullLogger;
use Throwable;

use Devium\Toml\TomlError;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\migrations\enums\MigrationKind;
use oihana\arango\migrations\enums\MigrationStatus;
use oihana\arango\migrations\MigrationRunner;
use oihana\arango\migrations\MigrationStore;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the migration engine (Lot M3): a hand-written migration
 * transforms real documents, is recorded once in the tracking collection,
 * rolls back through `down()`, and `--forget` drops the tracking row without
 * undoing the data.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class MigrateIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_migrate_it' ;

    private const string COLLECTION = 'contacts' ;

    private const string TRACKING = 'migrations' ;

    private const string FIXTURES_NS = 'tests\\oihana\\arango\\migrations\\fixtures\\live' ;

    private const string VERSION = '20260601000000_RenamePhone' ;

    /**
     * Seeds two contacts carrying a `tel` field (the migration renames it).
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $contacts = $db->collection( self::COLLECTION ) ;
        $contacts->create() ;

        $contacts->insert( [ '_key' => 'a' , 'tel' => '0102030405' ] ) ;
        $contacts->insert( [ '_key' => 'b' , 'tel' => '0607080910' ] ) ;
    }

    /**
     * A runner wired to the disposable database and the live migration
     * fixtures directory.
     *
     * @throws TomlError
     * @throws Throwable
     */
    private function runner() :MigrationRunner
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $facade = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;
        $store  = new MigrationStore( $facade->database() , self::TRACKING ) ;

        return new MigrationRunner
        (
            db        : $facade ,
            store     : $store ,
            path      : dirname( __DIR__ ) . '/migrations/fixtures/live' ,
            namespace : self::FIXTURES_NS ,
            gitCommit : 'commit42' ,
            agent     : 'marc@host' ,
        ) ;
    }

    /**
     * Reads a contact document.
     *
     * @throws ArangoException
     */
    private function contact( string $key ) :array
    {
        return iterator_to_array( self::$db->query( 'RETURN DOCUMENT(@id)' , [ 'id' => self::COLLECTION . '/' . $key ] ) )[0] ;
    }

    /**
     * Reads the tracking row of the migration, or null.
     *
     * @throws ArangoException
     */
    private function tracking() :?array
    {
        $rows = iterator_to_array( self::$db->query( 'RETURN DOCUMENT(@id)' , [ 'id' => self::TRACKING . '/' . self::VERSION ] ) ) ;
        return $rows[0] ?? null ;
    }

    /**
     * The full M3 lifecycle on a real server:
     *
     * 1. one pending migration → status sees it pending ;
     * 2. apply renames the field on every document and records a `completed`
     *    tracking row (with the agent / gitCommit) ;
     * 3. a second apply is a no-op (the migration runs once per database) ;
     * 4. `down()` reverses the rename and drops the tracking row ;
     * 5. re-apply, then `forget()` drops the tracking row WITHOUT reversing
     *    the data.
     */
    public function testMigrateLifecycle() :void
    {
        $runner = $this->runner() ;

        // 1 — pending.
        $status = $runner->status() ;
        $this->assertCount( 1 , $status ) ;
        $this->assertFalse( $status[0][ 'applied' ] ) ;

        // 2 — apply : the data is transformed and the run is tracked.
        $recorded = $runner->apply() ;
        $this->assertCount( 1 , $recorded ) ;
        $this->assertSame( MigrationStatus::COMPLETED , $recorded[0]->actionStatus ) ;

        $a = $this->contact( 'a' ) ;
        $this->assertArrayNotHasKey( 'tel' , $a ) ;
        $this->assertSame( '0102030405' , $a[ 'phone' ] ) ;

        $track = $this->tracking() ;
        $this->assertSame( MigrationStatus::COMPLETED , $track[ 'actionStatus' ] ) ;
        $this->assertSame( MigrationKind::MIGRATE , $track[ 'additionalType' ] ) ;
        $this->assertSame( 'commit42' , $track[ 'gitCommit' ] ) ;
        $this->assertSame( 'marc@host' , $track[ 'agent' ] ) ;

        // 3 — second apply is a no-op.
        $this->assertSame( [] , $runner->apply() ) ;

        // 4 — down reverses the rename and untracks.
        $runner->down( 1 ) ;
        $b = $this->contact( 'b' ) ;
        $this->assertArrayNotHasKey( 'phone' , $b ) ;
        $this->assertSame( '0607080910' , $b[ 'tel' ] ) ;
        $this->assertNull( $this->tracking() ) ;

        // 5 — re-apply then forget : tracking gone, data kept.
        $runner->apply() ;
        $this->assertNotNull( $this->tracking() ) ;

        $runner->forget( self::VERSION ) ;
        $this->assertNull( $this->tracking() ) ;
        $this->assertArrayHasKey( 'phone' , $this->contact( 'a' ) , 'forget must NOT undo the data.' ) ;
    }
}
