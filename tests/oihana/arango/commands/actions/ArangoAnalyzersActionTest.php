<?php

namespace tests\oihana\arango\commands\actions;

use oihana\arango\clients\analyzer\IdentityAnalyzer;
use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\clients\Database;
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
            new InputOption( ArangoCommandOption::FORCE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::SYNC     , null , InputOption::VALUE_OPTIONAL , '' , false ) ,
        ]) ;
    }

    private function input( array $options = [] ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( false ) ;
        return $input ;
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
}
