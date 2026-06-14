<?php

namespace tests\oihana\arango\commands\actions;

use InvalidArgumentException;
use RuntimeException;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\actions\ArangoDumpAction;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\ArangoDumpOptions;
use oihana\arango\commands\options\RetentionOption;
use oihana\arango\commands\traits\ArangoConfigTrait;

use oihana\commands\enums\CommandArg;
use oihana\commands\enums\ExitCode;
use oihana\commands\options\CommandOption;
use oihana\commands\traits\IOTrait;

use oihana\date\traits\DateTrait;

use oihana\files\enums\CompressionType;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoDumpAction::dump()} for integration testing.
 *
 * Two external dependencies are stubbed: the `arangodump` process
 * ({@see arangoDump()}, which instead drops a marker file into the output
 * directory so the real tar step has something to archive) and the HTTP
 * bridge ({@see buildDatabase()}). The real file-system flow (temp dir,
 * timestamped dir, tar.gz, move) is exercised for real, with encryption
 * disabled.
 */
class ArangoDumpActionHost
{
    use ArangoDumpAction ;
    use ArangoConfigTrait ;
    use DateTrait ;
    use IOTrait ;

    public string $id = 'test' ;

    /** When true, the buildDatabase() seam returns null (no client). */
    public bool $returnNullDatabase = false ;

    /** The fake database returned by the buildDatabase() seam. */
    public ?Database $fakeDatabase = null ;

    /** Captured state of the stubbed dump process. */
    public bool  $dumpCalled          = false ;
    public array $capturedDumpOptions = [] ;

    public function __construct( string $directory )
    {
        $this->directory   = $directory ;
        $this->compression = CompressionType::GZIP ;
        $this->encrypt     = false ;                  // skip the OpenSSL branch
        $this->database    = 'mydb' ;
        $this->endpoint    = 'tcp://127.0.0.1:8529' ;
        $this->password    = 'secret' ;
        $this->username    = 'root' ;
    }

    public function getName() :string
    {
        return 'dump' ;
    }

    /** External passphrase seam (provided by the real command composition). */
    public function getPassphrase( $input , $output ) :string
    {
        return 'test-passphrase' ;
    }

    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->returnNullDatabase ? null : $this->fakeDatabase ;
    }

    /** Stub the proc_open seam: no binary is launched, but a dump file is faked. */
    public function arangoDump( array|ArangoDumpOptions|null $options = null , bool $silent = false ) :int
    {
        $this->dumpCalled          = true ;
        $this->capturedDumpOptions = (array) $options ;

        $directory = $this->capturedDumpOptions[ ArangoDumpOption::OUTPUT_DIRECTORY ] ?? null ;
        if ( is_string( $directory ) && is_dir( $directory ) )
        {
            file_put_contents( $directory . DIRECTORY_SEPARATOR . 'dump.json' , '{"_key":"1"}' ) ;

            // A realistic collection pair so the PHP masking engine has data to anonymize.
            file_put_contents( $directory . DIRECTORY_SEPARATOR . 'people_h.structure.json' , json_encode( [ 'parameters' => [ 'name' => 'people' ] ] ) ) ;
            file_put_contents( $directory . DIRECTORY_SEPARATOR . 'people_h.data.json' , json_encode( [ '_key' => 'a' , 'email' => 'real@example.com' , 'name' => 'Jane' ] ) . "\n" ) ;
        }

        return ExitCode::SUCCESS ;
    }
}

/**
 * Unit coverage for {@see ArangoDumpAction}.
 */
#[CoversTrait(ArangoDumpAction::class)]
#[AllowMockObjectsWithoutExpectations]
class ArangoDumpActionTest extends TestCase
{
    private string $dir = '' ;

    protected function setUp() :void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arango_dump_test_' . bin2hex( random_bytes( 6 ) ) ;
        mkdir( $this->dir , 0o777 , true ) ;
    }

    protected function tearDown() :void
    {
        foreach ( glob( $this->dir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $file )
        {
            @unlink( $file ) ;
        }
        @rmdir( $this->dir ) ;
    }

    /** Full option / argument surface read by dump(). */
    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputArgument( CommandArg::ACTION , InputArgument::OPTIONAL ) ,
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
            new InputOption( CommandOption::ENCRYPT                 , null , InputOption::VALUE_OPTIONAL ) ,
        ]) ;
    }

    private function input( array $options = [] ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /** A bare collection-like object exposing only getName(). */
    private function collection( string $name ) :object
    {
        return new class( $name )
        {
            public function __construct( private readonly string $name ) {}
            public function getName() :string { return $this->name ; }
        } ;
    }

    /** A Database double whose collections() returns the given names. */
    private function databaseReturning( array $names ) :Database
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willReturn( array_map( $this->collection( ... ) , $names ) ) ;
        return $db ;
    }

    private function host() :ArangoDumpActionHost
    {
        return new ArangoDumpActionHost( $this->dir ) ;
    }

    // ---------------------------------------------------------------- --list

    public function testListOptionDelegatesToListDumpsAndDoesNotDump() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::LIST => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertFalse( $host->dumpCalled ) ;
    }

    // ---------------------------------------------------------------- happy path

    public function testHappyPathDumpsCreatesArchiveAndForwardsConnection() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->dumpCalled ) ;
        $this->assertSame( 'mydb'                  , $host->capturedDumpOptions[ ArangoDumpOption::SERVER_DATABASE ] ) ;
        $this->assertSame( 'tcp://127.0.0.1:8529'  , $host->capturedDumpOptions[ ArangoDumpOption::SERVER_ENDPOINT ] ) ;
        $this->assertSame( 'secret'                , $host->capturedDumpOptions[ ArangoDumpOption::SERVER_PASSWORD ] ) ;
        $this->assertSame( 'root'                  , $host->capturedDumpOptions[ ArangoDumpOption::SERVER_USERNAME ] ) ;
        $this->assertArrayHasKey( ArangoDumpOption::OUTPUT_DIRECTORY , $host->capturedDumpOptions ) ;

        // a full-dump (no collection targeting) does not forward COLLECTION
        $this->assertArrayNotHasKey( ArangoDumpOption::COLLECTION , $host->capturedDumpOptions ) ;

        // the final tar.gz archive landed in the output directory
        $archives = glob( $this->dir . DIRECTORY_SEPARATOR . '*.tar.gz' ) ?: [] ;
        $this->assertCount( 1 , $archives ) ;
    }

    public function testEncryptedDumpProducesAnEncryptedArchive() :void
    {
        $host = $this->host() ;
        $host->encrypt = true ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->dumpCalled ) ;

        $encrypted = glob( $this->dir . DIRECTORY_SEPARATOR . '*.tar.gz.enc' ) ?: [] ;
        $this->assertCount( 1 , $encrypted ) ;
    }

    // ---------------------------------------------------------------- --collection

    public function testCollectionSubsetValidatesAndForwardsTheCollectionOption() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , 'orders' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::COLLECTION => [ 'users' ] ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Collections : users' , $output->fetch() ) ;
        $this->assertSame( [ 'users' ] , $host->capturedDumpOptions[ ArangoDumpOption::COLLECTION ] ) ;
    }

    public function testCollectionSubsetThrowsOnUnknownCollection() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' ] ) ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'Unknown collection' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::COLLECTION => [ 'ghost' ] ] ) , $output ) ;
    }

    public function testCollectionValidationSkippedWhenNoHttpClient() :void
    {
        $host = $this->host() ;
        $host->returnNullDatabase = true ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::COLLECTION => [ 'users' ] ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->dumpCalled ) ;
        $this->assertStringContainsString( 'Collection validation skipped' , $output->fetch() ) ;
    }

    public function testCollectionValidationSkippedWhenApiUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willThrowException( new ArangoException( 'down' ) ) ;

        $host = $this->host() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::COLLECTION => [ 'users' ] ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->dumpCalled ) ;
        $this->assertStringContainsString( 'Collection validation skipped' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- --ignore-collection

    public function testIgnoreCollectionComputesTheComplement() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , 'orders' , 'logs' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::IGNORE_COLLECTION => [ 'logs' ] ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertSame( [ 'users' , 'orders' ] , array_values( $host->capturedDumpOptions[ ArangoDumpOption::COLLECTION ] ) ) ;
    }

    public function testIgnoreCollectionRequiresAnHttpClient() :void
    {
        $host = $this->host() ;
        $host->returnNullDatabase = true ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'requires the ArangoDB HTTP API' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::IGNORE_COLLECTION => [ 'logs' ] ] ) , $output ) ;
    }

    public function testIgnoreCollectionThrowsWhenApiUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willThrowException( new ArangoException( 'down' ) ) ;

        $host = $this->host() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'unreachable' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::IGNORE_COLLECTION => [ 'logs' ] ] ) , $output ) ;
    }

    public function testIgnoreCollectionThrowsOnUnknownCollection() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' ] ) ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'Unknown collection' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::IGNORE_COLLECTION => [ 'ghost' ] ] ) , $output ) ;
    }

    public function testIgnoreCollectionThrowsWhenEverythingIsExcluded() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , 'orders' ] ) ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'Nothing to dump' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::IGNORE_COLLECTION => [ 'users' , 'orders' ] ] ) , $output ) ;
    }

    // ---------------------------------------------------------------- mutually exclusive

    public function testCollectionAndIgnoreCollectionTogetherThrow() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $this->expectException( InvalidArgumentException::class ) ;
        $host->dump( $this->input
        ([
            '--' . ArangoCommandOption::COLLECTION        => [ 'users' ] ,
            '--' . ArangoCommandOption::IGNORE_COLLECTION => [ 'logs' ] ,
        ]) , $output ) ;
    }

    // ---------------------------------------------------------------- D1 options

    public function testIncludeSystemFlagForwardsTheOption() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::INCLUDE_SYSTEM => true ] ) , $output ) ;

        $this->assertTrue( $host->capturedDumpOptions[ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS ] ) ;
    }

    public function testNoViewsFlagDisablesTheViewDump() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::NO_VIEWS => true ] ) , $output ) ;

        $this->assertFalse( $host->capturedDumpOptions[ ArangoDumpOption::DUMP_VIEWS ] ) ;
    }

    public function testThreadsFlagForwardsAsAnInteger() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::THREADS => '4' ] ) , $output ) ;

        $this->assertSame( 4 , $host->capturedDumpOptions[ ArangoDumpOption::THREADS ] ) ;
    }

    public function testAllDatabasesAndOverwriteFlagsForwardTrue() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input
        ([
            '--' . ArangoCommandOption::ALL_DATABASES => true ,
            '--' . ArangoCommandOption::OVERWRITE     => true ,
        ]) , $output ) ;

        $this->assertTrue( $host->capturedDumpOptions[ ArangoDumpOption::ALL_DATABASES ] ) ;
        $this->assertTrue( $host->capturedDumpOptions[ ArangoDumpOption::OVERWRITE ] ) ;
    }

    public function testDumpConfigDefaultsAreApplied() :void
    {
        $host = $this->host() ;
        $host->initializeArangoOptions
        ([
            ArangoCommandParam::DUMP =>
            [
                ArangoDumpOption::THREADS   => 2 ,
                ArangoDumpOption::OVERWRITE => true ,
            ]
        ]) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input() , $output ) ;

        $this->assertSame( 2 , $host->capturedDumpOptions[ ArangoDumpOption::THREADS ] ) ;
        $this->assertTrue( $host->capturedDumpOptions[ ArangoDumpOption::OVERWRITE ] ) ;
    }

    public function testCliFlagOverridesTheDumpConfigDefault() :void
    {
        $host = $this->host() ;
        $host->initializeArangoOptions( [ ArangoCommandParam::DUMP => [ ArangoDumpOption::THREADS => 2 ] ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::THREADS => '8' ] ) , $output ) ;

        $this->assertSame( 8 , $host->capturedDumpOptions[ ArangoDumpOption::THREADS ] ) ;
    }

    // ---------------------------------------------------------------- D2 profiles + dry-run

    public function testProfileSelectionForwardsTheCollectionList() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'test' => [ ArangoCommandParam::PROFILE_COLLECTIONS => [ 'users' , 'orders' ] ] ] ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'test' ] ) , $output ) ;

        $this->assertSame( [ 'users' , 'orders' ] , array_values( $host->capturedDumpOptions[ ArangoDumpOption::COLLECTION ] ) ) ;
    }

    public function testExcludeOnlyProfileComputesTheComplementFromTheServer() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , 'orders' , 'logs' ] ) ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' => [ ArangoCommandParam::PROFILE_EXCLUDE => [ 'logs' ] ] ] ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , $output ) ;

        $this->assertSame( [ 'users' , 'orders' ] , array_values( $host->capturedDumpOptions[ ArangoDumpOption::COLLECTION ] ) ) ;
    }

    public function testProfileSourceConnectionOverridesTheConfiguredDatabase() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'users' ] ,
            ArangoConfig::DATABASE                  => 'app_staging' ,
        ] ] ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , $output ) ;

        $this->assertSame( 'app_staging' , $host->capturedDumpOptions[ ArangoDumpOption::SERVER_DATABASE ] ) ;
    }

    public function testProfileAndCollectionAreMutuallyExclusive() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' => [ ArangoCommandParam::PROFILE_COLLECTIONS => [ 'users' ] ] ] ] ) ;
        $output = new BufferedOutput() ;

        $this->expectException( InvalidArgumentException::class ) ;
        $host->dump( $this->input
        ([
            '--' . ArangoCommandOption::PROFILE    => 'p' ,
            '--' . ArangoCommandOption::COLLECTION => [ 'orders' ] ,
        ]) , $output ) ;
    }

    public function testEmptyProfileSelectsNothingAndThrows() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' ] ) ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' => [ ArangoCommandParam::PROFILE_EXCLUDE => [ 'users' ] ] ] ] ) ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'profile selects no collection' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , $output ) ;
    }

    public function testDryRunDoesNotDump() :void
    {
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertFalse( $host->dumpCalled ) ;
        $this->assertStringContainsString( 'Dry run' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- D3 complete preset

    public function testCompleteDumpsUserCollectionsPlusTheSystemTargets() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , 'orders' , '_analyzers' , '_graphs' , '_jobs' ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::COMPLETE => true ] ) , $output ) ;

        $this->assertSame
        (
            [ 'users' , 'orders' , '_analyzers' , '_graphs' ] ,
            array_values( $host->capturedDumpOptions[ ArangoDumpOption::COLLECTION ] ) ,
        ) ;
        $this->assertTrue( $host->capturedDumpOptions[ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS ] ) ;
    }

    public function testCompleteConfigDefaultEnablesTheCompletePreset() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , '_graphs' ] ) ;
        $host->initializeArangoOptions( [ ArangoCommandParam::DUMP => [ ArangoCommandOption::COMPLETE => true ] ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input() , $output ) ;

        $this->assertSame( [ 'users' , '_graphs' ] , array_values( $host->capturedDumpOptions[ ArangoDumpOption::COLLECTION ] ) ) ;
        $this->assertTrue( $host->capturedDumpOptions[ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS ] ) ;
    }

    public function testCompleteRequiresTheHttpApi() :void
    {
        $host = $this->host() ;
        $host->returnNullDatabase = true ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( '--complete requires the ArangoDB HTTP API' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::COMPLETE => true ] ) , $output ) ;
    }

    public function testCompleteThrowsWhenTheApiIsUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willThrowException( new ArangoException( 'down' ) ) ;

        $host = $this->host() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'unreachable' ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::COMPLETE => true ] ) , $output ) ;
    }

    public function testCompleteIsMutuallyExclusiveWithACollectionSubset() :void
    {
        $host = $this->host() ;
        $output = new BufferedOutput() ;

        $this->expectException( InvalidArgumentException::class ) ;
        $host->dump( $this->input
        ([
            '--' . ArangoCommandOption::COMPLETE   => true ,
            '--' . ArangoCommandOption::COLLECTION => [ 'users' ] ,
        ]) , $output ) ;
    }

    public function testCompleteIsMutuallyExclusiveWithAProfile() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' => [ ArangoCommandParam::PROFILE_COLLECTIONS => [ 'users' ] ] ] ] ) ;
        $output = new BufferedOutput() ;

        $this->expectException( InvalidArgumentException::class ) ;
        $host->dump( $this->input
        ([
            '--' . ArangoCommandOption::COMPLETE => true ,
            '--' . ArangoCommandOption::PROFILE  => 'p' ,
        ]) , $output ) ;
    }

    public function testCompleteDryRunListsTheSelectionWithoutDumping() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'users' , '_analyzers' ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::COMPLETE => true , '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertFalse( $host->dumpCalled ) ;
        $this->assertStringContainsString( '_analyzers' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- D5 masking

    /** Writes a native maskings JSON fixture and returns its path. */
    private function nativeMaskingsFile() :string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'native_' . bin2hex( random_bytes( 4 ) ) . '.json' ;
        file_put_contents( $path , '{"users":{"type":"masked","maskings":[{"path":"email","type":"email"}]}}' ) ;
        return $path ;
    }

    public function testNativeMaskingsFileFromCliIsForwarded() :void
    {
        $file = $this->nativeMaskingsFile() ;
        $host = $this->host() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::MASKINGS => $file ] ) , new BufferedOutput() ) ;

        $this->assertSame( $file , $host->capturedDumpOptions[ ArangoDumpOption::MASKINGS ] ) ;
    }

    public function testMissingNativeMaskingsFileThrows() :void
    {
        $host = $this->host() ;

        $this->expectException( \oihana\files\exceptions\FileException::class ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::MASKINGS => $this->dir . DIRECTORY_SEPARATOR . 'nope.json' ] ) , new BufferedOutput() ) ;
    }

    /** Reads the masked `people` data file the stub wrote into the dump directory. */
    private function dumpedPeopleData( ArangoDumpActionHost $host ) :string
    {
        $directory = $host->capturedDumpOptions[ ArangoDumpOption::OUTPUT_DIRECTORY ] ?? '' ;
        return (string) @file_get_contents( $directory . DIRECTORY_SEPARATOR . 'people_h.data.json' ) ;
    }

    public function testProfileMaskingUsesThePhpEngine() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'people' ] ,
            ArangoCommandParam::MASKING             => [ 'people.email' => 'email' ] ,
        ] ] ] ) ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , new BufferedOutput() ) ;

        // PHP engine path: output kept uncompressed, no native maskings option.
        $this->assertFalse( $host->capturedDumpOptions[ ArangoDumpOption::COMPRESS_OUTPUT ] ) ;
        $this->assertArrayNotHasKey( ArangoDumpOption::MASKINGS , $host->capturedDumpOptions ) ;

        // The PII is actually anonymized in the dumped data; the document stays.
        $data = $this->dumpedPeopleData( $host ) ;
        $this->assertStringNotContainsString( 'real@example.com' , $data ) ;
        $this->assertStringContainsString( '"_key":"a"' , $data ) ;
    }

    public function testDumpMaskingDefaultUsesThePhpEngine() :void
    {
        $host = $this->host() ;
        $host->initializeArangoOptions( [ ArangoCommandParam::DUMP => [ ArangoCommandParam::MASKING => [ 'people.email' => 'email' ] ] ] ) ;

        $host->dump( $this->input() , new BufferedOutput() ) ;

        $this->assertFalse( $host->capturedDumpOptions[ ArangoDumpOption::COMPRESS_OUTPUT ] ) ;
        $this->assertStringNotContainsString( 'real@example.com' , $this->dumpedPeopleData( $host ) ) ;
    }

    public function testProfileMaskingOverridesTheDumpDefault() :void
    {
        $host = $this->host() ;
        $host->initializeArangoOptions ( [ ArangoCommandParam::DUMP     => [ ArangoCommandParam::MASKING => [ 'people.name' => 'xifyFront' ] ] ] ) ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'people' ] ,
            ArangoCommandParam::MASKING             => [ 'people.email' => 'email' ] ,
        ] ] ] ) ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , new BufferedOutput() ) ;

        // The profile masking wins: email is masked, but `name` is untouched
        // (the dump-default rule on `name` did not apply).
        $data = $this->dumpedPeopleData( $host ) ;
        $this->assertStringNotContainsString( 'real@example.com' , $data ) ;
        $this->assertStringContainsString( 'Jane' , $data ) ;
    }

    public function testCliNativeFileOverridesCompiledProfileMasking() :void
    {
        $file = $this->nativeMaskingsFile() ;
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'people' ] ,
            ArangoCommandParam::MASKING             => [ 'people.email' => 'email' ] ,
        ] ] ] ) ;

        $host->dump( $this->input
        ([
            '--' . ArangoCommandOption::PROFILE  => 'p' ,
            '--' . ArangoCommandOption::MASKINGS => $file ,
        ]) , new BufferedOutput() ) ;

        // Native file wins: forwarded to arangodump, PHP engine off (data untouched by us).
        $this->assertSame( $file , $host->capturedDumpOptions[ ArangoDumpOption::MASKINGS ] ) ;
        $this->assertStringContainsString( 'real@example.com' , $this->dumpedPeopleData( $host ) ) ;
    }

    public function testMaskingKeyNeverLeaksIntoTheDumpOptions() :void
    {
        $host = $this->host() ;
        $host->initializeArangoOptions( [ ArangoCommandParam::DUMP => [ ArangoCommandParam::MASKING => [ 'people.email' => 'email' ] ] ] ) ;

        $host->dump( $this->input() , new BufferedOutput() ) ;

        $this->assertArrayNotHasKey( ArangoCommandParam::MASKING , $host->capturedDumpOptions ) ;
    }

    public function testNonMaskedModeIsRejectedByThePhpEngine() :void
    {
        $host = $this->host() ;
        $host->initializeArangoOptions( [ ArangoCommandParam::DUMP => [ ArangoCommandParam::MASKING => [ 'people' => 'structure' ] ] ] ) ;

        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'not supported by the PHP masking engine' ) ;
        $host->dump( $this->input() , new BufferedOutput() ) ;
    }

    public function testDryRunReportsThePhpEngine() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'people' ] ,
            ArangoCommandParam::MASKING             => [ 'people.email' => 'email' ] ,
        ] ] ] ) ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' , '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertFalse( $host->dumpCalled ) ;
        $this->assertStringContainsString( 'PHP engine' , $output->fetch() ) ;
    }

    public function testDryRunReportsTheNativeMaskingsFile() :void
    {
        $file   = $this->nativeMaskingsFile() ;
        $host   = $this->host() ;
        $output = new BufferedOutput() ;

        $host->dump( $this->input( [ '--' . ArangoCommandOption::MASKINGS => $file , '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertFalse( $host->dumpCalled ) ;
        $this->assertStringContainsString( 'native arangodump' , $output->fetch() ) ;
    }

    public function testUnknownMaskerInProfileThrows() :void
    {
        $host = $this->host() ;
        $host->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => [ 'p' =>
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'people' ] ,
            ArangoCommandParam::MASKING             => [ 'people.email' => 'obfuscate' ] ,
        ] ] ] ) ;

        $this->expectException( InvalidArgumentException::class ) ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::PROFILE => 'p' ] ) , new BufferedOutput() ) ;
    }

    // ---------------------------------------------------------------- D6 rotation

    /** Touches a dump archive file in the dump directory. */
    private function touchArchive( string $name ) :string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name ;
        file_put_contents( $path , 'x' ) ;
        return $path ;
    }

    private function hostWithRetention( array $retention ) :ArangoDumpActionHost
    {
        $host = $this->host() ;
        $host->initializeArangoOptions( [ ArangoCommandParam::DUMP => [ ArangoCommandParam::RETENTION => $retention ] ] ) ;
        return $host ;
    }

    public function testPruneOnlyDeletesPerRetentionWithoutDumping() :void
    {
        $this->touchArchive( '2020-01-01T00:00:00-mydb.tar.gz' ) ;
        $this->touchArchive( '2021-01-01T00:00:00-mydb.tar.gz' ) ;
        $this->touchArchive( '2022-01-01T00:00:00-mydb.tar.gz' ) ;

        $host = $this->hostWithRetention( [ RetentionOption::KEEP => 1 ] ) ;
        $code = $host->dump( $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] ) , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertFalse( $host->dumpCalled ) ;
        $this->assertFileDoesNotExist( $this->dir . DIRECTORY_SEPARATOR . '2020-01-01T00:00:00-mydb.tar.gz' ) ;
        $this->assertFileDoesNotExist( $this->dir . DIRECTORY_SEPARATOR . '2021-01-01T00:00:00-mydb.tar.gz' ) ;
        $this->assertFileExists( $this->dir . DIRECTORY_SEPARATOR . '2022-01-01T00:00:00-mydb.tar.gz' ) ;
    }

    public function testPruneWithoutPolicyWarnsAndKeepsEverything() :void
    {
        $archive = $this->touchArchive( '2020-01-01T00:00:00-mydb.tar.gz' ) ;

        $host   = $this->host() ;   // no retention configured
        $output = new BufferedOutput() ;
        $code   = $host->dump( $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertFalse( $host->dumpCalled ) ;
        $this->assertFileExists( $archive ) ;
        $this->assertStringContainsString( 'No retention policy' , $output->fetch() ) ;
    }

    public function testPruneDryRunListsWithoutDeleting() :void
    {
        $old = $this->touchArchive( '2020-01-01T00:00:00-mydb.tar.gz' ) ;
        $this->touchArchive( '2021-01-01T00:00:00-mydb.tar.gz' ) ;

        $host   = $this->hostWithRetention( [ RetentionOption::KEEP => 1 ] ) ;
        $output = new BufferedOutput() ;
        $host->dump( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertFileExists( $old ) ;
        $this->assertStringContainsString( 'would delete' , $output->fetch() ) ;
    }

    public function testAutoPrunePrunesAfterDumpKeepingTheNewArchive() :void
    {
        $old = $this->touchArchive( '2020-01-01T00:00:00-mydb.tar.gz' ) ; // same bucket "mydb"

        $host = $this->hostWithRetention( [ RetentionOption::KEEP => 1 , RetentionOption::AUTO => true ] ) ;
        $code = $host->dump( $this->input() , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->dumpCalled ) ;
        $this->assertFileDoesNotExist( $old ) ;                                  // old archive pruned
        $this->assertCount( 1 , glob( $this->dir . DIRECTORY_SEPARATOR . '*-mydb.tar.gz' ) ) ; // only the new one survives
    }

    public function testAutoPruneOffByDefaultKeepsEverything() :void
    {
        $old = $this->touchArchive( '2020-01-01T00:00:00-mydb.tar.gz' ) ;

        $host = $this->hostWithRetention( [ RetentionOption::KEEP => 1 ] ) ; // no auto
        $host->dump( $this->input() , new BufferedOutput() ) ;

        $this->assertFileExists( $old ) ; // not pruned without autoPrune
    }

    public function testRetentionKeyNeverLeaksIntoTheDumpOptions() :void
    {
        $host = $this->hostWithRetention( [ RetentionOption::KEEP => 3 ] ) ;
        $host->dump( $this->input() , new BufferedOutput() ) ;

        $this->assertArrayNotHasKey( ArangoCommandParam::RETENTION , $host->capturedDumpOptions ) ;
    }
}
