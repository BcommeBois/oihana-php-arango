<?php

namespace tests\oihana\arango\commands\actions;

use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\Database;
use oihana\arango\commands\actions\ArangoMigrateAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\ArangoDB;
use oihana\arango\migrations\enums\MigrationKind;
use oihana\arango\migrations\enums\MigrationStatus;

use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoMigrateAction::migrate()} for testing: the only
 * external seam is {@see resolveFacade()}, overridden to return a façade
 * whose low-level client is mocked — the real store and runner run against
 * the committed fixture migrations.
 */
class ArangoMigrateActionHost
{
    use ArangoMigrateAction ;
    use ArangoConfigTrait ;

    public ?ArangoDB $facade = null ;
    public bool $facadeNull = false ;
    public string $exit = 'Exit the command.' ;

    public function __construct()
    {
        $this->database = 'mydb' ;
        $this->endpoint = 'tcp://127.0.0.1:8529' ;
        $this->password = 'secret' ;
        $this->username = 'root' ;
    }

    public function getQuestionHelper() :QuestionHelper
    {
        return new QuestionHelper() ;
    }

    public function run( $input , $output ) :int
    {
        return $this->migrate( $input , $output ) ;
    }

    protected function resolveFacade( $input ) :?ArangoDB
    {
        return $this->facadeNull ? null : $this->facade ;
    }

    protected function agent() :string { return 'marc@host' ; }
    protected function gitCommit() :?string { return 'commit42' ; }
}

/**
 * Unit coverage for {@see ArangoMigrateAction}.
 */
#[CoversTrait( ArangoMigrateAction::class )]
#[AllowMockObjectsWithoutExpectations]
class ArangoMigrateActionTest extends TestCase
{
    private const string OK_NS = 'tests\\oihana\\arango\\migrations\\fixtures\\ok' ;

    private function okDir() :string
    {
        return dirname( __DIR__ , 2 ) . '/migrations/fixtures/ok' ;
    }

    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::CREATE   , null , InputOption::VALUE_REQUIRED ) ,
            new InputOption( ArangoCommandOption::FORGET   , null , InputOption::VALUE_REQUIRED ) ,
            new InputOption( ArangoCommandOption::DOWN     , null , InputOption::VALUE_OPTIONAL , '' , false ) ,
            new InputOption( ArangoCommandOption::STATUS   , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::DRY_RUN  , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::YES      , null , InputOption::VALUE_NONE ) ,
        ]) ;
    }

    private function input( array $options = [] , bool $interactive = false ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( $interactive ) ;
        return $input ;
    }

    /** A Cursor double over the given rows. */
    private function cursor( array $rows ) :Cursor
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getIterator' )->willReturnCallback( fn() => ( function() use ( $rows ) { yield from $rows ; } )() ) ;
        return $cursor ;
    }

    /**
     * A façade whose low-level client answers the tracking queries: the
     * `applied()` SELECT returns the given applied versions, everything else
     * (UPSERT / REMOVE) is a no-op cursor.
     */
    private function facadeWithApplied( array $appliedVersions = [] ) :ArangoDB
    {
        $rows = array_map( fn( $v ) => [ '_key' => $v , 'additionalType' => MigrationKind::MIGRATE , 'actionStatus' => 'completed' ] , $appliedVersions ) ;

        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;
        $database->method( 'query' )->willReturnCallback(
            fn( string $aql ) => $this->cursor( str_contains( $aql , 'FILTER m.additionalType' ) ? $rows : [] )
        ) ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'database' )->willReturn( $database ) ;
        return $facade ;
    }

    private function host( ?ArangoDB $facade , array $extra = [] ) :ArangoMigrateActionHost
    {
        $host = new ArangoMigrateActionHost() ;
        $host->facade = $facade ;
        $host->migrationsPath      = $extra[ 'path' ]      ?? $this->okDir() ;
        $host->migrationsNamespace = $extra[ 'namespace' ] ?? self::OK_NS ;
        return $host ;
    }

    /** A façade whose tracking queries all throw — to exercise the unreachable path. */
    private function unreachableFacade() :ArangoDB
    {
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willThrowException( new \oihana\arango\clients\exceptions\ArangoException( 'connection refused' ) ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'collection' )->willReturn( $collection ) ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'database' )->willReturn( $database ) ;
        return $facade ;
    }

    // ---- guards -----------------------------------------------------------

    public function testFailsWithoutAMigrationsPath() :void
    {
        $host = new ArangoMigrateActionHost() ; // migrationsPath stays null
        $host->facade = $this->facadeWithApplied() ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::STATUS => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No migrations path configured' , $output->fetch() ) ;
    }

    public function testFailsWithoutAFacade() :void
    {
        $host = $this->host( null ) ;
        $host->facadeNull = true ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::STATUS => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB HTTP client available' , $output->fetch() ) ;
    }

    // ---- create -----------------------------------------------------------

    public function testCreateGeneratesAShellWithoutADatabase() :void
    {
        $dir = sys_get_temp_dir() . '/oihana_migact_' . uniqid() ;
        mkdir( $dir ) ;

        $host = new ArangoMigrateActionHost() ;
        $host->facadeNull = true ;          // no database at all
        $host->migrationsPath = $dir ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::CREATE => 'add place kind' ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ Created' , $output->fetch() ) ;
        $this->assertNotEmpty( glob( $dir . '/Version*_AddPlaceKind.php' ) ) ;

        array_map( unlink( ... ) , glob( $dir . '/*' ) ?: [] ) ;
        @rmdir( $dir ) ;
    }

    public function testCreateFailsWithoutAPath() :void
    {
        $host = new ArangoMigrateActionHost() ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::CREATE => 'x' ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No migrations path configured' , $output->fetch() ) ;
    }

    // ---- status / dry-run -------------------------------------------------

    public function testStatusShowsAppliedAndPending() :void
    {
        $host = $this->host( $this->facadeWithApplied( [ '20260101000000_Alpha' ] ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::STATUS => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '20260101000000_Alpha — alpha (applied)' , $text ) ;
        $this->assertStringContainsString( '20260102000000_Beta — beta (pending)' , $text ) ;
    }

    public function testDryRunListsPendingWithoutRunning() :void
    {
        $host = $this->host( $this->facadeWithApplied() ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '20260101000000_Alpha — alpha' , $text ) ;
        $this->assertStringContainsString( '20260102000000_Beta — beta' , $text ) ;
    }

    // ---- apply ------------------------------------------------------------

    public function testApplyWithYesRunsThePending() :void
    {
        $host = $this->host( $this->facadeWithApplied() ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ 20260101000000_Alpha — applied' , $text ) ;
        $this->assertStringContainsString( '✓ 20260102000000_Beta — applied' , $text ) ;
    }

    public function testApplyIsUpToDate() :void
    {
        $host = $this->host( $this->facadeWithApplied( [ '20260101000000_Alpha' , '20260102000000_Beta' ] ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'up to date' , $output->fetch() ) ;
    }

    public function testApplyRefusesWithoutConfirmationInNonInteractive() :void
    {
        $host = $this->host( $this->facadeWithApplied() ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'rerun with --yes' , $output->fetch() ) ;
    }

    public function testApplyInteractiveDeclineAborts() :void
    {
        $host = $this->host( $this->facadeWithApplied() ) ;

        $input = $this->input( [] , interactive : true ) ;
        $input->setStream( $this->stream( "no\n" ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Aborted' , $output->fetch() ) ;
    }

    public function testApplyInteractiveConfirmRuns() :void
    {
        $host = $this->host( $this->facadeWithApplied() ) ;

        $input = $this->input( [] , interactive : true ) ;
        $input->setStream( $this->stream( "yes\n" ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ 20260101000000_Alpha — applied' , $output->fetch() ) ;
    }

    // ---- down / forget ----------------------------------------------------

    public function testDownRollsBack() :void
    {
        $host = $this->host( $this->facadeWithApplied( [ '20260101000000_Alpha' , '20260102000000_Beta' ] ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DOWN => null ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ 20260102000000_Beta — rolled back' , $text ) ;
    }

    public function testDownNothingToRollBack() :void
    {
        $host = $this->host( $this->facadeWithApplied() ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DOWN => '2' ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'nothing to roll back' , $output->fetch() ) ;
    }

    public function testForgetRemovesTrackingWithoutRunningDown() :void
    {
        $host = $this->host( $this->facadeWithApplied( [ '20260102000000_Beta' ] ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::FORGET => '20260102000000_Beta' ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'forgotten' , $text ) ;
    }

    public function testStatusReportsNoMigrations() :void
    {
        $empty = sys_get_temp_dir() . '/oihana_migempty_' . uniqid() ;
        mkdir( $empty ) ;

        $host = $this->host( $this->facadeWithApplied() , [ 'path' => $empty , 'namespace' => 'tests\\empty' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::STATUS => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'no migrations' , $output->fetch() ) ;

        @rmdir( $empty ) ;
    }

    public function testDryRunIsUpToDate() :void
    {
        $host = $this->host( $this->facadeWithApplied( [ '20260101000000_Alpha' , '20260102000000_Beta' ] ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'up to date' , $output->fetch() ) ;
    }

    public function testApplyReportsAFailedMigrationAndFails() :void
    {
        $host = $this->host
        (
            $this->facadeWithApplied() ,
            [ 'path' => dirname( __DIR__ , 2 ) . '/migrations/fixtures/boom' , 'namespace' => 'tests\\oihana\\arango\\migrations\\fixtures\\boom' ]
        ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '✗ 20260102000000_Boom — failed : kaboom' , $text ) ;
    }

    public function testCreateReportsAGeneratorError() :void
    {
        $host = new ArangoMigrateActionHost() ;
        $host->facadeNull = true ;
        $host->migrationsPath = '/no/such/dir/at/all' ; // exists but generator throws
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::CREATE => 'x' ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'does not exist' , $output->fetch() ) ;
    }

    public function testReportsAnInvalidMigrationFile() :void
    {
        $dir = sys_get_temp_dir() . '/oihana_migbad_' . uniqid() ;
        mkdir( $dir ) ;
        file_put_contents( $dir . '/Version20260101000000_NotOne.php' , "<?php\n" ) ;

        $host = $this->host( $this->facadeWithApplied() , [ 'path' => $dir , 'namespace' => 'tests\\nope' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::STATUS => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'does not define' , $output->fetch() ) ;

        @unlink( $dir . '/Version20260101000000_NotOne.php' ) ;
        @rmdir( $dir ) ;
    }

    public function testReportsAnUnreachableDatabase() :void
    {
        $host = $this->host( $this->unreachableFacade() ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::STATUS => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'unreachable' , $output->fetch() ) ;
    }

    /** An in-memory stream feeding the interactive question helper. */
    private function stream( string $answer )
    {
        $stream = fopen( 'php://memory' , 'r+' ) ;
        fwrite( $stream , $answer ) ;
        rewind( $stream ) ;
        return $stream ;
    }
}
