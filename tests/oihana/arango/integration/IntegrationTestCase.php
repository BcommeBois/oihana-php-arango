<?php

namespace tests\oihana\arango\integration;

use Throwable ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\TestCase ;

use function oihana\init\initConfig ;

/**
 * Base class for the live ArangoDB integration suite (group `integration`).
 *
 * These tests are **excluded from the default `phpunit.xml` run** and are
 * executed only via the dedicated config:
 *
 * ```
 * vendor/bin/phpunit -c phpunit-integration.xml
 * ```
 *
 * Connection settings are read from the `[arango]` section of
 * `configs/config.toml` (the same file the bundled `command:arangodb`
 * and `scripts/seed-playground.php` use). When that file is missing or the
 * server is unreachable, every test is **skipped** rather than failed — the
 * integration config sets `failOnSkipped="false"` so a developer without a
 * local ArangoDB still gets a green default suite.
 *
 * Lifecycle: a disposable database (named by {@see static::$database}) is
 * created once per test class, seeded by {@see static::seed()}, and dropped
 * in {@see tearDownAfterClass()} — it never touches your working database.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * The disposable database created for the test class. Override per class
     * to avoid collisions when several integration classes run together.
     */
    protected static string $database = 'oihana_integration' ;

    protected static ?ArangoClient $client = null ;

    protected static ?Database $db = null ;

    /**
     * Reason captured when the instance is unreachable, surfaced by setUp().
     */
    protected static ?string $unavailable = null ;

    public static function setUpBeforeClass() :void
    {
        $client = self::makeClient() ;
        if ( $client === null )
        {
            return ;
        }

        try
        {
            if ( $client->database( static::$database )->exists() )
            {
                $client->dropDatabase( static::$database ) ;
            }
            $client->createDatabase( static::$database ) ;

            self::$client = $client ;
            self::$db     = $client->database( static::$database ) ;

            static::seed( self::$db ) ;
        }
        catch ( Throwable $e )
        {
            self::$unavailable = 'ArangoDB setup failed: ' . $e->getMessage() ;
            self::$client = null ;
            self::$db     = null ;
        }
    }

    public static function tearDownAfterClass() :void
    {
        if ( self::$client !== null )
        {
            try { self::$client->dropDatabase( static::$database ) ; }
            catch ( Throwable ) { /* best effort */ }
        }
        self::$client      = null ;
        self::$db          = null ;
        self::$unavailable = null ;
    }

    protected function setUp() :void
    {
        if ( self::$db === null )
        {
            $this->markTestSkipped( self::$unavailable ?? 'ArangoDB instance not reachable (configs/config.toml).' ) ;
        }
    }

    /**
     * Seed the disposable database. Overridden by each concrete test class.
     */
    protected static function seed( Database $db ) :void {}

    /**
     * Builds a client from `configs/config.toml` and verifies the server
     * answers. Returns null (→ tests skip) when the config is absent or the
     * instance is down.
     */
    private static function makeClient() :?ArangoClient
    {
        try
        {
            $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
            $config    = initConfig( basePath: $configDir , file: 'config.toml' ) ;
            $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

            if ( empty( $arango ) )
            {
                return null ;
            }

            $client = new ArangoClient( ClientOptions::fromArray( $arango ) ) ;

            return $client->availability( true ) === false ? null : $client ;
        }
        catch ( Throwable )
        {
            return null ;
        }
    }
}
