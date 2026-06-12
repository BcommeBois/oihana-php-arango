<?php

namespace tests\oihana\arango\integration;

use Throwable;

use oihana\arango\clients\Database;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\traits\ArangoDumpTrait;
use oihana\arango\commands\traits\ArangoProfileTrait;

use PHPUnit\Framework\Attributes\Group;

use function oihana\files\deleteDirectory;
use function oihana\files\makeTemporaryDirectory;
use function oihana\init\initConfig;

/**
 * Bare host running the real `arangodump` binary through {@see ArangoDumpTrait},
 * with the profile resolver wired in.
 */
class DumpRestoreIntegrationHost
{
    use ArangoDumpTrait ;
    use ArangoProfileTrait ;
}

/**
 * Live coverage for the D1 dump options against a real `arangodump`.
 *
 * Proves end-to-end that the curated options actually reach the binary and
 * change its output:
 *
 *  - `includeSystemCollections = true` pulls the system collections
 *    (`_analyzers`, `_graphs`, …) into the dump — the Lot S completeness gap.
 *  - the default (false) leaves them out.
 *  - `threads` is accepted by the binary without breaking the run.
 *
 * Skipped (never failed) when `configs/config.toml`, the server or the
 * `arangodump` binary are unavailable.
 */
#[Group('integration')]
class DumpRestoreIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_dump_d1' ;

    private static array $arango = [] ;

    private string $tmp = '' ;

    protected static function seed( Database $db ) :void
    {
        foreach ( [ 'widgets' , 'gadgets' , 'secrets' ] as $name )
        {
            $collection = $db->collection( $name ) ;
            $collection->create() ;
            $collection->insert( [ '_key' => 'k1' , 'name' => $name ] ) ;
        }
    }

    public static function setUpBeforeClass() :void
    {
        parent::setUpBeforeClass() ;

        try
        {
            $configDir    = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
            $config       = initConfig( basePath: $configDir , file: 'config.toml' ) ;
            self::$arango = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;
        }
        catch ( Throwable )
        {
            self::$arango = [] ;
        }
    }

    protected function setUp() :void
    {
        parent::setUp() ;

        if ( self::$arango === [] )
        {
            $this->markTestSkipped( 'No [arango] configuration available.' ) ;
        }

        if ( @exec( 'command -v arangodump' ) === '' )
        {
            $this->markTestSkipped( 'The arangodump binary is not available.' ) ;
        }

        $this->tmp = makeTemporaryDirectory( [ 'dump_d1' , bin2hex( random_bytes( 6 ) ) ] ) ;
    }

    protected function tearDown() :void
    {
        if ( $this->tmp !== '' && is_dir( $this->tmp ) )
        {
            try { deleteDirectory( $this->tmp ) ; } catch ( Throwable ) {}
        }
    }

    /** Common connection options for the dump host. */
    private function connection( string $outputDirectory ) :array
    {
        return
        [
            ArangoDumpOption::SERVER_ENDPOINT  => self::$arango[ 'endpoint' ] ?? 'tcp://127.0.0.1:8529' ,
            ArangoDumpOption::SERVER_DATABASE  => static::$database ,
            ArangoDumpOption::SERVER_USERNAME  => self::$arango[ 'user' ]     ?? 'root' ,
            ArangoDumpOption::SERVER_PASSWORD  => self::$arango[ 'password' ] ?? '' ,
            ArangoDumpOption::OUTPUT_DIRECTORY => $outputDirectory ,
            ArangoDumpOption::OVERWRITE        => true ,
        ] ;
    }

    /** Returns the basenames of the `*.structure.json` files produced by a dump. */
    private function structures( string $directory ) :array
    {
        $names = [] ;
        foreach ( glob( $directory . DIRECTORY_SEPARATOR . '*.structure.json' ) ?: [] as $path )
        {
            $names[] = basename( $path ) ;
        }
        return $names ;
    }

    /** Returns the collection names declared by a dump (read from `parameters.name`). */
    private function structureNames( string $directory ) :array
    {
        $names = [] ;
        foreach ( glob( $directory . DIRECTORY_SEPARATOR . '*.structure.json' ) ?: [] as $path )
        {
            $data = json_decode( (string) file_get_contents( $path ) , true ) ;
            $name = is_array( $data ) ? ( $data[ 'parameters' ][ 'name' ] ?? null ) : null ;
            if ( is_string( $name ) && $name !== '' )
            {
                $names[] = $name ;
            }
        }
        sort( $names ) ;
        return $names ;
    }

    public function testIncludeSystemCollectionsPullsTheSystemCollections() :void
    {
        $host = new DumpRestoreIntegrationHost() ;

        $out = $this->tmp . DIRECTORY_SEPARATOR . 'with-system' ;

        $status = $host->arangoDump
        (
            $this->connection( $out ) + [ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS => true , ArangoDumpOption::THREADS => 2 ] ,
            silent : true ,
        ) ;

        $this->assertSame( 0 , $status ) ;

        $structures = implode( ',' , $this->structures( $out ) ) ;
        $this->assertStringContainsString( 'widgets' , $structures ) ;
        // At least one system collection (name starting with "_") is present.
        $this->assertMatchesRegularExpression( '/(^|,)_/', $structures , 'Expected system collections in the dump.' ) ;
    }

    public function testDefaultDumpLeavesSystemCollectionsOut() :void
    {
        $host = new DumpRestoreIntegrationHost() ;

        $out = $this->tmp . DIRECTORY_SEPARATOR . 'no-system' ;

        $status = $host->arangoDump( $this->connection( $out ) , silent : true ) ;

        $this->assertSame( 0 , $status ) ;

        $structures = $this->structures( $out ) ;
        $this->assertNotEmpty( $structures ) ;
        foreach ( $structures as $name )
        {
            $this->assertStringStartsNotWith( '_' , $name , 'A default dump must not contain system collections.' ) ;
        }
    }

    public function testProfileDumpsOnlyTheSelectedCollections() :void
    {
        $host = new DumpRestoreIntegrationHost() ;

        // A profile selecting widgets + gadgets, excluding secrets — resolved
        // exactly as the dump action does, then run through a real arangodump.
        $profile =
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'widgets' , 'gadgets' , 'secrets' ] ,
            ArangoCommandParam::PROFILE_EXCLUDE     => [ 'secrets' ] ,
        ] ;

        $selection = $host->profileSelection( $profile ) ;
        $this->assertSame( [ 'widgets' , 'gadgets' ] , $selection ) ;

        $out = $this->tmp . DIRECTORY_SEPARATOR . 'profile' ;

        $status = $host->arangoDump
        (
            $this->connection( $out ) + [ ArangoDumpOption::COLLECTION => $selection ] ,
            silent : true ,
        ) ;

        $this->assertSame( 0 , $status ) ;

        $this->assertSame
        (
            [ 'gadgets' , 'widgets' ] ,
            $this->structureNames( $out ) ,
            'The profile dump must contain exactly the selected collections.' ,
        ) ;
    }
}
