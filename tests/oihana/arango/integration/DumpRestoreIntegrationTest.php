<?php

namespace tests\oihana\arango\integration;

use Phar;
use PharData;
use Throwable;

use oihana\arango\clients\Database;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\RetentionOption;
use oihana\arango\commands\actions\ArangoDumpAction;
use oihana\arango\commands\actions\ArangoRestoreAction;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\commands\traits\ArangoDumpTrait;
use oihana\arango\commands\traits\ArangoMaskingTrait;
use oihana\arango\commands\traits\ArangoProfileTrait;
use oihana\arango\commands\traits\ArangoRotationTrait;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\HelperTrait;
use oihana\commands\traits\IOTrait;

use oihana\date\traits\DateTrait;

use PHPUnit\Framework\Attributes\Group;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

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
    use ArangoMaskingTrait ;
    use ArangoProfileTrait ;
    use ArangoRotationTrait ;
}

/**
 * Host driving the real {@see ArangoRestoreAction::restore()} end-to-end — the
 * actual `arangorestore` binary, untar and guard-rails — against a live server.
 */
class RestoreIntegrationHost
{
    use ArangoRestoreAction ;
    use DateTrait ;
    use HelperTrait ;

    public string $id = 'live' ;

    public function __construct( array $arango , string $database , string $directory )
    {
        $this->directory = $directory ;
        $this->encrypt   = false ;
        $this->database  = $database ;
        $this->endpoint  = $arango[ 'endpoint' ] ?? 'tcp://127.0.0.1:8529' ;
        $this->password  = $arango[ 'password' ] ?? '' ;
        $this->username  = $arango[ 'user' ]     ?? 'root' ;
    }

    public function getName() :string
    {
        return 'restore' ;
    }
}

/**
 * Host driving the real {@see ArangoDumpAction::dump()} end-to-end — the actual
 * `arangodump` binary, profile resolution and the tar/move flow — against a live
 * server. Used to prove the per-profile output directory (D8) routes the real
 * archive to the right place.
 */
class DumpActionIntegrationHost
{
    use ArangoDumpAction ;
    use ArangoConfigTrait ;
    use DateTrait ;
    use IOTrait ;

    public string $id = 'live' ;

    public function __construct( array $arango , string $database , string $directory )
    {
        $this->directory = $directory ;
        $this->encrypt   = false ;
        $this->database  = $database ;
        $this->endpoint  = $arango[ 'endpoint' ] ?? 'tcp://127.0.0.1:8529' ;
        $this->password  = $arango[ 'password' ] ?? '' ;
        $this->username  = $arango[ 'user' ]     ?? 'root' ;
    }

    public function getName() :string
    {
        return 'dump' ;
    }
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

    public function testCompleteBackupAddsOnlyAnalyzersAndGraphs() :void
    {
        $host = new DumpRestoreIntegrationHost() ;

        // The collection list of a --complete backup: every user collection
        // plus the _analyzers / _graphs system collections present.
        $targets = [ '_analyzers' , '_graphs' ] ;
        $all     = array_map( fn( $c ) => $c->getName() , self::$db->collections( true ) ) ;
        $list    = array_values( array_filter
        (
            $all ,
            fn( string $name ) => !str_starts_with( $name , '_' ) || in_array( $name , $targets , true ) ,
        ) ) ;

        $out = $this->tmp . DIRECTORY_SEPARATOR . 'complete' ;

        $status = $host->arangoDump
        (
            $this->connection( $out ) + [ ArangoDumpOption::COLLECTION => $list , ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS => true ] ,
            silent : true ,
        ) ;

        $this->assertSame( 0 , $status ) ;

        $names  = $this->structureNames( $out ) ;
        $system = array_values( array_filter( $names , fn( string $n ) => str_starts_with( $n , '_' ) ) ) ;
        sort( $system ) ;

        // Surgical: only _analyzers + _graphs, never _jobs / _queues / _apps / …
        $this->assertSame( [ '_analyzers' , '_graphs' ] , $system ) ;
        $this->assertContains( 'widgets' , $names ) ;
    }

    // ------------------------------------------------------------------ D5b masking (PHP engine, any edition)

    public function testPhpEngineAnonymizesARealDump() :void
    {
        // A document carrying real PII, dumped uncompressed, then masked in PHP.
        self::$db->collection( 'widgets' )->insert
        (
            [ '_key' => 'pii1' , 'email' => 'real.person@example.com' , 'profile' => [ 'phone' => '+33 6 12 34 56' ] ] ,
            [ 'overwrite' => true ] ,
        ) ;

        $host = new DumpRestoreIntegrationHost() ;

        $out    = $this->tmp . DIRECTORY_SEPARATOR . 'masked' ;
        $status = $host->arangoDump
        (
            $this->connection( $out ) +
            [
                ArangoDumpOption::COLLECTION      => [ 'widgets' ] ,
                ArangoDumpOption::COMPRESS_OUTPUT => false ,   // the PHP engine reads plain data files
            ] ,
            silent : true ,
        ) ;
        $this->assertSame( 0 , $status ) ;

        // The portable PHP engine anonymizes the dumped data — no Enterprise edition needed.
        $count = $host->maskDumpDirectory( $out , $host->compileMaskings
        ([
            'widgets.email'         => 'email' ,
            'widgets.profile.phone' => 'phone' ,
        ]) ) ;
        $this->assertSame( 1 , $count ) ;

        $data = '' ;
        foreach ( glob( $out . DIRECTORY_SEPARATOR . '*widgets*.data.json' ) ?: [] as $path )
        {
            $data .= (string) file_get_contents( $path ) ;
        }

        $this->assertNotSame( '' , $data , 'Expected a widgets data file in the dump.' ) ;
        $this->assertStringContainsString( 'pii1' , $data , 'The masked document is still present (only its values change).' ) ;
        $this->assertStringNotContainsString( 'real.person@example.com' , $data , 'The email must be anonymized.' ) ;
        $this->assertStringNotContainsString( '+33 6 12 34 56' , $data , 'The nested phone must be anonymized.' ) ;
    }

    // ------------------------------------------------------------------ D6 rotation (real files)

    public function testRotationPrunesRealArchivesOnDisk() :void
    {
        // A real arangodump-produced .tar.gz, copied under two ISO-dated names.
        $real = $this->buildArchive() ;
        $dir  = $this->tmp . DIRECTORY_SEPARATOR . 'rotate_' . bin2hex( random_bytes( 4 ) ) ;
        mkdir( $dir , 0o777 , true ) ;

        $old = $dir . DIRECTORY_SEPARATOR . '2020-01-01T00:00:00-' . static::$database . '.tar.gz' ;
        $new = $dir . DIRECTORY_SEPARATOR . '2026-01-01T00:00:00-' . static::$database . '.tar.gz' ;
        copy( $real , $old ) ;
        copy( $real , $new ) ;

        $host = new DumpRestoreIntegrationHost() ;
        $io   = new SymfonyStyle( new ArrayInput( [] ) , new BufferedOutput() ) ;

        $count = $host->pruneDumps( $dir , $host->resolveRetentionPolicy( [ RetentionOption::KEEP => 1 ] ) , null , false , $io ) ;

        $this->assertSame( 1 , $count ) ;
        $this->assertFileDoesNotExist( $old , 'The older archive must be pruned.' ) ;
        $this->assertFileExists( $new , 'The most recent archive must be kept.' ) ;
    }

    // ------------------------------------------------------------------ D4 restore guard-rails

    /** Dumps the seeded database and tars it into a fresh `.tar.gz` the restore action can read. */
    private function buildArchive() :string
    {
        $source = $this->tmp . DIRECTORY_SEPARATOR . 'src_' . bin2hex( random_bytes( 4 ) ) ;
        new DumpRestoreIntegrationHost()->arangoDump( $this->connection( $source ) , silent : true ) ;

        $tarPath = $this->tmp . DIRECTORY_SEPARATOR . 'build_' . bin2hex( random_bytes( 4 ) ) . '.tar' ;
        $tar     = new PharData( $tarPath ) ;
        foreach ( glob( $source . DIRECTORY_SEPARATOR . '*' ) ?: [] as $path )
        {
            if ( is_file( $path ) )
            {
                $tar->addFile( $path , basename( $path ) ) ;   // flatten at the archive root
            }
        }
        $tar->compress( Phar::GZ ) ;
        unset( $tar ) ;

        // Rename to a distinct base: untar() derives the inner `.tar` name from
        // the `.tar.gz` name, and PharData caches phar names per process — a
        // shared base would collide with the build tar created just above.
        @unlink( $tarPath ) ;
        $archive = $this->tmp . DIRECTORY_SEPARATOR . 'restore_' . bin2hex( random_bytes( 4 ) ) . '.tar.gz' ;
        rename( $tarPath . '.gz' , $archive ) ;
        return $archive ;
    }

    /** A non-interactive ArrayInput exposing the option surface restore() reads. */
    private function restoreInput( array $options ) :ArrayInput
    {
        $definition = new InputDefinition
        ([
            new InputArgument( 'action' , InputArgument::OPTIONAL ) ,
            new InputOption( ArangoCommandOption::LIST           , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::LAST           , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::DIRECTORY      , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::FILE           , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DATE           , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::LABEL          , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::COLLECTION     , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::DATABASE       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER           , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::INCLUDE_SYSTEM , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::ALL_DATABASES  , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::THREADS        , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::VIEW           , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::PROFILE        , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DRY_RUN        , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FORCE          , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::YES            , null , InputOption::VALUE_NONE ) ,
            new InputOption( 'encrypt'    , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( 'passphrase' , null , InputOption::VALUE_OPTIONAL ) ,
        ]) ;

        $input = new ArrayInput( $options , $definition ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    public function testRestoreActionRoundTripWithYes() :void
    {
        $archive = $this->buildArchive() ;

        // Drop a collection: a real arangorestore must recreate it with its data.
        if ( self::$db->collection( 'gadgets' )->exists() )
        {
            self::$db->collection( 'gadgets' )->drop() ;
        }
        $this->assertFalse( self::$db->collection( 'gadgets' )->exists() ) ;

        $host = new RestoreIntegrationHost( self::$arango , static::$database , $this->tmp ) ;
        $code = $host->restore( $this->restoreInput
        ([
            '--' . ArangoCommandOption::FILE       => $archive ,
            '--' . ArangoCommandOption::COLLECTION => [ 'gadgets' ] ,
            '--' . ArangoCommandOption::YES        => true ,
        ]) , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( self::$db->collection( 'gadgets' )->exists() ) ;
        $this->assertSame( 1 , self::$db->collection( 'gadgets' )->count() ) ;
    }

    public function testProtectedCollectionGuardsTheLiveRestore() :void
    {
        // Archive the collection *while it still exists* (two distinct archives:
        // the blocked restore untars the first, PharData caches names per process).
        $blockedArchive = $this->buildArchive() ;
        $forcedArchive  = $this->buildArchive() ;

        if ( self::$db->collection( 'gadgets' )->exists() )
        {
            self::$db->collection( 'gadgets' )->drop() ;
        }

        $host = new RestoreIntegrationHost( self::$arango , static::$database , $this->tmp ) ;
        $host->initializeArangoOptions( [ ArangoCommandParam::RESTORE => [ ArangoCommandParam::PROTECTED => [ 'gadgets' ] ] ] ) ;

        // Blocked without --force: nothing is written, the archive is preserved.
        $code = $host->restore( $this->restoreInput
        ([
            '--' . ArangoCommandOption::FILE       => $blockedArchive ,
            '--' . ArangoCommandOption::COLLECTION => [ 'gadgets' ] ,
            '--' . ArangoCommandOption::YES        => true ,
        ]) , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertFalse( self::$db->collection( 'gadgets' )->exists() , 'A protected collection must not be restored without --force.' ) ;
        $this->assertFileExists( $blockedArchive , 'A refused restore must not consume the source archive.' ) ;

        // --force overrides the guard: the protected collection is restored.
        $code = $host->restore( $this->restoreInput
        ([
            '--' . ArangoCommandOption::FILE       => $forcedArchive ,
            '--' . ArangoCommandOption::COLLECTION => [ 'gadgets' ] ,
            '--' . ArangoCommandOption::FORCE      => true ,
            '--' . ArangoCommandOption::YES        => true ,
        ]) , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( self::$db->collection( 'gadgets' )->exists() ) ;
    }

    // ------------------------------------------------------------------ D8 per-profile output directory

    /** A non-interactive ArrayInput exposing the option surface dump() reads. */
    private function dumpInput( array $options ) :ArrayInput
    {
        $definition = new InputDefinition
        ([
            new InputArgument( 'action' , InputArgument::OPTIONAL ) ,
            new InputOption( ArangoCommandOption::LIST              , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::DATABASE          , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT          , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD          , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER              , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::COLLECTION        , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::IGNORE_COLLECTION , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::LABEL             , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DIRECTORY         , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DATE              , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::INCLUDE_SYSTEM    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::MASKINGS          , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::NO_VIEWS          , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::ALL_DATABASES     , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::OVERWRITE         , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::THREADS           , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PROFILE           , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::COMPLETE          , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::DRY_RUN           , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::PRUNE             , null , InputOption::VALUE_NONE ) ,
            new InputOption( 'encrypt'    , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( 'passphrase' , null , InputOption::VALUE_OPTIONAL ) ,
        ]) ;

        $input = new ArrayInput( $options , $definition ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    public function testProfileDirectoryRoutesTheRealArchive() :void
    {
        // A profile carrying its own output directory plus a positive selection.
        $profileOut = $this->tmp . DIRECTORY_SEPARATOR . 'profile_out_' . bin2hex( random_bytes( 4 ) ) ;

        $host = new DumpActionIntegrationHost( self::$arango , static::$database , $this->tmp ) ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::DIRECTORY           => $profileOut ,
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'widgets' , 'gadgets' ] ,
        ] ] ] ) ;

        $code = $host->dump( $this->dumpInput( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;

        // The real arangodump archive landed in the profile directory, not the global one.
        $this->assertCount( 1 , glob( $profileOut . DIRECTORY_SEPARATOR . '*.tar.gz' ) ?: [] , 'The archive must be written to the profile directory.' ) ;
        $this->assertCount( 0 , glob( $this->tmp . DIRECTORY_SEPARATOR . '*.tar.gz' ) ?: [] , 'No archive must land in the global directory.' ) ;
    }

    // ------------------------------------------------------------------ D10 list honors the profile directory

    public function testListWithProfileListsTheProfileDirectory() :void
    {
        // Dump into a dedicated profile directory, then list it back via --list --profile.
        $profileOut = $this->tmp . DIRECTORY_SEPARATOR . 'profile_list_' . bin2hex( random_bytes( 4 ) ) ;

        $profiles = [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::DIRECTORY           => $profileOut ,
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'widgets' , 'gadgets' ] ,
        ] ] ] ;

        $dumpHost = new DumpActionIntegrationHost( self::$arango , static::$database , $this->tmp ) ;
        $dumpHost->initializeArangoProfiles( $profiles ) ;

        $this->assertSame( ExitCode::SUCCESS , $dumpHost->dump( $this->dumpInput( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , new BufferedOutput() ) ) ;

        $archive = basename( ( glob( $profileOut . DIRECTORY_SEPARATOR . '*.tar.gz' ) ?: [ '' ] )[ 0 ] ) ;
        $this->assertNotSame( '' , $archive , 'The dump must have produced an archive in the profile directory.' ) ;

        // `dump --list --profile p` must read the profile directory and show that
        // archive. A fresh host (the listing is a separate process in real use).
        $listHost = new DumpActionIntegrationHost( self::$arango , static::$database , $this->tmp ) ;
        $listHost->initializeArangoProfiles( $profiles ) ;

        $output = new BufferedOutput() ;
        $code   = $listHost->dump( $this->dumpInput
        ([
            '--' . ArangoCommandOption::LIST    => true ,
            '--' . ArangoCommandOption::PROFILE => 'p' ,
        ]) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( $archive , $output->fetch() , 'The listing must show the profile-directory archive.' ) ;
    }
}
