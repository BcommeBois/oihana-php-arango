<?php

namespace tests\oihana\arango\commands\actions;

use oihana\arango\clients\analyzer\Analyzer;
use oihana\arango\clients\analyzer\IdentityAnalyzer;
use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\actions\ArangoAnalyzersAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\db\results\DiffReport;

use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoAnalyzersAction::analyzers()} for testing — the
 * `resolveDatabase()` / `resolveFacade()` seams are overridden to return
 * caller-supplied doubles, so no network I/O happens.
 */
class ArangoAnalyzersActionHost
{
    use ArangoAnalyzersAction ;
    use ArangoConfigTrait ;

    public ?Database $fakeDatabase = null ;
    public ?ArangoDB $fakeFacade   = null ;
    public bool      $returnNullDatabase = false ;
    public bool      $returnNullFacade   = false ;

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
        return $this->analyzers( $input , $output ) ;
    }

    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->returnNullDatabase ? null : $this->fakeDatabase ;
    }

    protected function resolveFacade( InputInterface $input ) :?ArangoDB
    {
        return $this->returnNullFacade ? null : $this->fakeFacade ;
    }
}

/**
 * Unit coverage for {@see ArangoAnalyzersAction} (Lot A3a — list / diff / sync).
 *
 * @package tests\oihana\arango\commands\actions
 * @author  Marc Alcaraz
 */
#[CoversTrait( ArangoAnalyzersAction::class )]
#[AllowMockObjectsWithoutExpectations]
class ArangoAnalyzersActionTest extends TestCase
{
    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DIFF     , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FIX      , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FORCE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::PRUNE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::SYNC     , null , InputOption::VALUE_OPTIONAL , '' , false ) ,
            new InputOption( ArangoCommandOption::YES      , null , InputOption::VALUE_NONE ) ,
        ]) ;
    }

    private function input( array $options = [] , bool $interactive = false ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( $interactive ) ;
        return $input ;
    }

    /** @return resource A readable in-memory stream seeded with $answer (for the confirmation prompt). */
    private function stream( string $answer )
    {
        $stream = fopen( 'php://memory' , 'r+' ) ;
        fwrite( $stream , $answer ) ;
        rewind( $stream ) ;
        return $stream ;
    }

    private function databaseListing( array $analyzers ) :Database
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listAnalyzers' )->willReturn( $analyzers ) ;
        return $db ;
    }

    private function definitionFixture( string $name = 'az' ) :AnalyzerDefinition
    {
        return new AnalyzerDefinition( $name , new TextAnalyzer( locale: 'en' , stemming: true ) , [] ) ;
    }

    private function report( string $status , array $changes = [] , bool $applied = false ) :DiffReport
    {
        return new DiffReport( 'az' , $status , $changes , $applied , DiffKind::ANALYZER ) ;
    }

    // ---- list -------------------------------------------------------------

    public function testListsCustomAnalyzersAndCountsBuiltins() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeDatabase = $this->databaseListing(
        [
            [ 'name' => 'identity'    , 'type' => 'identity' ] , // built-in
            [ 'name' => 'text_fr'     , 'type' => 'text' ] ,     // built-in
            [ 'name' => 'mydb::az_b'  , 'type' => 'text' ] ,     // custom
            [ 'name' => 'mydb::az_a'  , 'type' => 'identity' ] , // custom
        ]) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input() , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ az_a (identity)' , $text ) ;
        $this->assertStringContainsString( '→ az_b (text)' , $text ) ;
        $this->assertStringContainsString( '(+ 2 built-in)' , $text ) ;
        // sorted : az_a before az_b
        $this->assertLessThan( strpos( $text , 'az_b' ) , strpos( $text , 'az_a' ) ) ;
    }

    public function testListErrorsWhenNoDatabase() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->returnNullDatabase = true ;

        $this->assertSame( ExitCode::FAILURE , $host->run( $this->input() , new BufferedOutput() ) ) ;
    }

    public function testListShowsMessageWhenNoCustomAnalyzer() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeDatabase = $this->databaseListing(
        [
            [ 'name' => 'identity' , 'type' => 'identity' ] ,
            [ 'name' => 'text_fr'  , 'type' => 'text' ] ,
        ]) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'There are no custom analyzers' , $output->fetch() ) ;
    }

    public function testListErrorsWhenServerUnreachable() :void
    {
        $host = new ArangoAnalyzersActionHost() ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'listAnalyzers' )->willThrowException( new ArangoException( 'down' ) ) ;
        $host->fakeDatabase = $db ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'unreachable' , $output->fetch() ) ;
    }

    // ---- diff -------------------------------------------------------------

    public function testDiffReportsEachDeclaredAnalyzerAndOrphans() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture( 'az' ) ] ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDiff' )->willReturn( $this->report( DiffStatus::IN_SYNC ) ) ;
        $host->fakeFacade = $facade ;

        // the orphan footnote reads the server analyzers through resolveDatabase
        $host->fakeDatabase = $this->databaseListing(
        [
            [ 'name' => 'mydb::az'      , 'type' => 'text' ] , // declared
            [ 'name' => 'mydb::ghost'   , 'type' => 'text' ] , // orphan
            [ 'name' => 'identity'      , 'type' => 'identity' ] , // built-in, never orphan
        ]) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'az — in sync' , $text ) ;
        $this->assertStringContainsString( 'Orphan custom analyzers' , $text ) ;
        $this->assertStringContainsString( 'ghost' , $text ) ;
        $this->assertStringNotContainsString( 'identity' , $text ) ;
    }

    public function testReportWarnsWhenNoAnalyzerConfigured() :void
    {
        $host   = new ArangoAnalyzersActionHost() ; // empty registry
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'No analyzers configured' , $output->fetch() ) ;
    }

    public function testDiffUnreachableWithoutFacadeFails() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture() ] ;
        $host->returnNullFacade = true ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'unreachable' , $output->fetch() ) ;
    }

    public function testDiffWithoutOrphansOmitsTheFootnote() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture( 'az' ) ] ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDiff' )->willReturn( $this->report( DiffStatus::IN_SYNC ) ) ;
        $host->fakeFacade = $facade ;

        $host->fakeDatabase = $this->databaseListing(
        [
            [ 'name' => 'mydb::az' , 'type' => 'text' ] ,     // declared : no orphan
            [ 'name' => 'identity' , 'type' => 'identity' ] ,
        ]) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringNotContainsString( 'Orphan custom analyzers' , $output->fetch() ) ;
    }

    public function testDiffSurvivesAnUnreachableOrphanListing() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture( 'az' ) ] ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDiff' )->willReturn( $this->report( DiffStatus::IN_SYNC ) ) ;
        $host->fakeFacade = $facade ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'listAnalyzers' )->willThrowException( new ArangoException( 'down' ) ) ;
        $host->fakeDatabase = $db ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'az — in sync' , $output->fetch() ) ;
    }

    // ---- sync -------------------------------------------------------------

    public function testSyncCreatesMissing() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture() ] ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->expects( $this->once() )->method( 'analyzerSync' )
               ->willReturn( $this->report( DiffStatus::MISSING , applied: true ) ) ;
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'az — created' , $output->fetch() ) ;
    }

    public function testSyncPassesForceFlagToTheFacade() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture() ] ;

        $captured = null ;
        $facade   = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerSync' )->willReturnCallback(
            function( AnalyzerDefinition $def , bool $force = false ) use ( &$captured ) : DiffReport
            {
                $captured = $force ;
                return $this->report( DiffStatus::DRIFTED , applied: true ) ;
            } ) ;
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null , '--' . ArangoCommandOption::FORCE => true ] ) , $output ) ;

        $this->assertTrue( $captured , 'The --force flag must be forwarded to analyzerSync().' ) ;
        $this->assertStringContainsString( 'az — repaired' , $output->fetch() ) ;
    }

    public function testSyncDriftedWithoutForceShowsTheImmutableHint() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture() ] ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerSync' )->willReturn( $this->report( DiffStatus::DRIFTED ) ) ; // not applied
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;

        $this->assertStringContainsString( 'use --force or --fix' , $output->fetch() ) ;
    }

    public function testSyncReportsACreateFailure() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture() ] ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerSync' )->willReturn( $this->report( DiffStatus::MISSING ) ) ; // not applied
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;

        $this->assertStringContainsString( 'create failed' , $output->fetch() ) ;
    }

    // ---- fix --------------------------------------------------------------

    private function fixDir() :string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oihana_analyzers_fix_' . uniqid() ;
        mkdir( $dir ) ;
        return $dir ;
    }

    private function rmDir( string $dir ) :void
    {
        foreach ( glob( $dir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $file )
        {
            unlink( $file ) ;
        }

        if ( is_dir( $dir ) )
        {
            rmdir( $dir ) ;
        }
    }

    public function testFixGeneratesARepairMigrationForADriftedAnalyzer() :void
    {
        $dir  = $this->fixDir() ;
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers           = [ $this->definitionFixture( 'az' ) ] ;
        $host->migrationsPath      = $dir ;
        $host->migrationsNamespace = 'app\\migrations' ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDiff' )->willReturn( $this->report( DiffStatus::DRIFTED ) ) ;
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'repair migration generated' , $text ) ;

        $files = glob( $dir . DIRECTORY_SEPARATOR . 'Version*.php' ) ;
        $this->assertCount( 1 , $files ) ;

        $body = file_get_contents( $files[ 0 ] ) ;
        $this->assertStringContainsString( 'namespace app\\migrations' , $body ) ;
        $this->assertStringContainsString( 'use oihana\\arango\\clients\\analyzer\\RawAnalyzer' , $body ) ;
        $this->assertStringContainsString( 'use oihana\\arango\\db\\options\\analyzers\\AnalyzerDefinition' , $body ) ;
        $this->assertStringContainsString( "new RawAnalyzer( 'text'" , $body ) ;
        $this->assertStringContainsString( "'locale' => 'en'" , $body ) ;
        $this->assertStringContainsString( '$this->db->analyzerSync( $definition , force: true ) ;' , $body ) ;
        $this->assertStringContainsString( 'not auto-reversible' , strtolower( $body ) ) ;

        $this->rmDir( $dir ) ;
    }

    public function testFixWritesNothingWhenNoDrift() :void
    {
        $dir  = $this->fixDir() ;
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers      = [ $this->definitionFixture( 'az' ) ] ;
        $host->migrationsPath = $dir ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDiff' )->willReturn( $this->report( DiffStatus::IN_SYNC ) ) ;
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'nothing to repair' , $output->fetch() ) ;
        $this->assertSame( [] , glob( $dir . DIRECTORY_SEPARATOR . 'Version*.php' ) ) ;

        $this->rmDir( $dir ) ;
    }

    public function testFixErrorsWithoutMigrationsPath() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers = [ $this->definitionFixture() ] ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No migrations path configured' , $output->fetch() ) ;
    }

    public function testFixWarnsWhenNoAnalyzerConfigured() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->migrationsPath = $this->fixDir() ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'No analyzers configured' , $output->fetch() ) ;

        $this->rmDir( $host->migrationsPath ) ;
    }

    public function testFixErrorsWithoutFacade() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers        = [ $this->definitionFixture() ] ;
        $host->migrationsPath   = $this->fixDir() ;
        $host->returnNullFacade = true ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB HTTP client' , $output->fetch() ) ;

        $this->rmDir( $host->migrationsPath ) ;
    }

    public function testFixFailsWhenTheGeneratorCannotWrite() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers      = [ $this->definitionFixture() ] ;
        $host->migrationsPath = DIRECTORY_SEPARATOR . 'oihana_no_such_dir_' . uniqid() ;

        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDiff' )->willReturn( $this->report( DiffStatus::DRIFTED ) ) ;
        $host->fakeFacade = $facade ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'migrations directory' , $output->fetch() ) ;
    }

    // ---- prune ------------------------------------------------------------

    /**
     * @param array<int, array<string,string>> $analyzers The listAnalyzers() rows.
     * @param array<string, Analyzer>          $droppers  Short name => Analyzer double returned by analyzer().
     */
    private function databasePrune( array $analyzers , array $droppers ) :Database
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listAnalyzers' )->willReturn( $analyzers ) ;
        $db->method( 'analyzer' )->willReturnCallback( fn( string $name ) :Analyzer => $droppers[ $name ] ?? $this->createMock( Analyzer::class ) ) ;
        return $db ;
    }

    /** An Analyzer double that expects drop($force) once, or never when $force is null. */
    private function dropper( ?bool $force ) :Analyzer
    {
        $analyzer = $this->createMock( Analyzer::class ) ;

        if ( $force === null )
        {
            $analyzer->expects( $this->never() )->method( 'drop' ) ;
        }
        else
        {
            $analyzer->expects( $this->once() )->method( 'drop' )->with( $force ) ;
        }

        return $analyzer ;
    }

    /** @param array<string, array<int,string>> $map Short name => dependent View names. */
    private function facadeDeps( array $map ) :ArangoDB
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerDependentViews' )->willReturnCallback( fn( string $name ) :array => $map[ $name ] ?? [] ) ;
        return $facade ;
    }

    public function testPruneDropsTheUnusedOrphan() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers  = [ $this->definitionFixture( 'kept' ) ] ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [
            [ 'name' => 'identity'    , 'type' => 'identity' ] , // built-in
            [ 'name' => 'mydb::kept'  , 'type' => 'text' ] ,     // declared
            [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ,     // orphan, unused
        ] ,
        [ 'ghost' => $this->dropper( false ) ] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::YES => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'ghost — dropped (orphan)' , $text ) ;
    }

    public function testPruneSignalsTheUsedOrphanWithoutForce() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [ 'ghost' => [ 'placesView' ] ] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [ [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ] ,
        [ 'ghost' => $this->dropper( null ) ] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::YES => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'used by placesView' , $text ) ;
        $this->assertStringContainsString( 'No orphan to drop' , $text ) ;
    }

    public function testPruneDropsTheUsedOrphanWithForce() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [ 'ghost' => [ 'placesView' ] ] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [ [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ] ,
        [ 'ghost' => $this->dropper( true ) ] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::FORCE => true , '--' . ArangoCommandOption::YES => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'dropped (orphan, was used by placesView)' , $text ) ;
    }

    public function testPruneRefusesNonInteractiveWithoutYes() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [ [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ] ,
        [ 'ghost' => $this->dropper( null ) ] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Refusing to drop analyzers without confirmation' , $output->fetch() ) ;
    }

    public function testPruneInteractiveConfirmYesDrops() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [ [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ] ,
        [ 'ghost' => $this->dropper( false ) ] ) ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive: true ) ;
        $input->setStream( $this->stream( "yes\n" ) ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'ghost — dropped (orphan)' , $output->fetch() ) ;
    }

    public function testPruneInteractiveConfirmNoAborts() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [ [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ] ,
        [ 'ghost' => $this->dropper( null ) ] ) ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive: true ) ;
        $input->setStream( $this->stream( "no\n" ) ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Aborted' , $output->fetch() ) ;
    }

    public function testPruneMessageWhenNoOrphan() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->analyzers  = [ $this->definitionFixture( 'kept' ) ] ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;
        $host->fakeDatabase = $this->databasePrune(
        [
            [ 'name' => 'identity'   , 'type' => 'identity' ] ,
            [ 'name' => 'mydb::kept' , 'type' => 'text' ] ,
        ] , [] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'no orphan custom analyzer to prune' , strtolower( $output->fetch() ) ) ;
    }

    public function testPruneErrorsWithoutDatabase() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;
        $host->returnNullDatabase = true ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB HTTP client' , $output->fetch() ) ;
    }

    public function testPruneErrorsWhenListUnreachable() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'listAnalyzers' )->willThrowException( new ArangoException( 'boom' ) ) ;
        $host->fakeDatabase = $db ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'unreachable' , $output->fetch() ) ;
    }

    public function testPruneReportsADropFailure() :void
    {
        $host = new ArangoAnalyzersActionHost() ;
        $host->fakeFacade = $this->facadeDeps( [] ) ;

        $analyzer = $this->createMock( Analyzer::class ) ;
        $analyzer->method( 'drop' )->willThrowException( new ArangoException( 'locked' ) ) ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'listAnalyzers' )->willReturn( [ [ 'name' => 'mydb::ghost' , 'type' => 'text' ] ] ) ;
        $db->method( 'analyzer' )->willReturn( $analyzer ) ;
        $host->fakeDatabase = $db ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'ghost — locked' , $output->fetch() ) ;
    }
}
