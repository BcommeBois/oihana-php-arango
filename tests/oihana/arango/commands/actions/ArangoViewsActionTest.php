<?php

namespace tests\oihana\arango\commands\actions;

use DI\Container;
use stdClass;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\View;
use oihana\arango\commands\actions\ArangoViewsAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\views\SearchAliasView;
use oihana\arango\db\results\DiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;

use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoViewsAction::views()} for testing.
 *
 * The HTTP bridge is the only external dependency of the list/drop/orphan
 * paths, so the protected {@see buildDatabase()} seam is overridden to
 * return a caller-supplied fake {@see Database} (or null) — no network I/O
 * happens. The diff/sync paths resolve their models from a real PHP-DI
 * container supplied by the test.
 */
class ArangoViewsActionHost
{
    use ArangoViewsAction ;
    use ArangoConfigTrait ;

    /** When true, the buildDatabase() seam returns null (no client). */
    public bool $returnNullDatabase = false ;

    /** The fake database returned by the buildDatabase() seam. */
    public ?Database $fakeDatabase = null ;

    /** When true, the resolveFacade() seam returns null (no façade). */
    public bool $returnNullFacade = false ;

    /** The fake façade returned by the resolveFacade() seam. */
    public ?ArangoDB $fakeFacade = null ;

    /** The exit label of the interactive selections (mirrors ArangoCommand). */
    public string $exit = 'Exit the command.' ;

    /** The DI container the diff/sync paths resolve the models from. */
    public Container $container ;

    public function __construct( ?Container $container = null )
    {
        $this->container = $container ?? new Container() ;
        $this->database  = 'mydb' ;
        $this->endpoint  = 'tcp://127.0.0.1:8529' ;
        $this->password  = 'secret' ;
        $this->username  = 'root' ;
    }

    /** The question helper of the interactive selections (mirrors Kernel). */
    public function getQuestionHelper() :QuestionHelper
    {
        return new QuestionHelper() ;
    }

    /** Public proxy to the protected action under test. */
    public function run( $input , $output ) :int
    {
        return $this->views( $input , $output ) ;
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
 * Unit coverage for {@see ArangoViewsAction}.
 */
#[CoversTrait( ArangoViewsAction::class )]
#[AllowMockObjectsWithoutExpectations]
class ArangoViewsActionTest extends TestCase
{
    /**
     * Full option surface read by views(), so a plain ArrayInput can answer
     * every getOption() call.
     */
    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DIFF     , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::DROP     , null , InputOption::VALUE_OPTIONAL , '' , false ) ,
            new InputOption( ArangoCommandOption::SYNC     , null , InputOption::VALUE_OPTIONAL , '' , false ) ,
        ]) ;
    }

    private function input( array $options = [] , bool $interactive = false ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( $interactive ) ;
        return $input ;
    }

    /** A View double for the list/drop paths. */
    private function view( bool $exists = true , array $properties = [] ) :View
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'exists' )->willReturn( $exists ) ;
        $view->method( 'properties' )->willReturn( $properties ) ;
        return $view ;
    }

    /** A Database double whose listViews() returns the given descriptions. */
    private function databaseListing( array $views ) :Database
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listViews' )->willReturn( $views ) ;
        return $db ;
    }

    /** A Documents model declaring the fixture View, bound to the given façade. */
    private function model( ArangoDB $facade , array $view = [] , array $init = [] ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => false ,
            AQL::VIEW        =>
            [
                Search::NAME     => 'placesView' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 ] ,
                ...$view ,
            ] ,
            ...$init ,
        ]) ;
    }

    /** A façade double answering a healthy server with the given report. */
    private function facade( DiffReport $report , ?DiffReport $synced = null ) :ArangoDB
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerExists'   )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( $report ) ;
        if( $synced !== null )
        {
            $facade->method( 'viewSync' )->willReturn( $synced ) ;
        }
        return $facade ;
    }

    /** A host whose container maps the given ids to the given models. */
    private function host( array $models = [] ) :ArangoViewsActionHost
    {
        $container = new Container() ;
        foreach( $models as $id => $model )
        {
            $container->set( $id , $model ) ;
        }

        $host = new ArangoViewsActionHost( $container ) ;
        $host->models = array_keys( $models ) ;
        return $host ;
    }

    // ---- list (default) ---------------------------------------------------

    public function testListPrintsViewsSortedWithLinkedCollections() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listViews' )->willReturn(
        [
            [ 'name' => 'zView' , 'type' => 'arangosearch' ] ,
            [ 'name' => 'aView' , 'type' => 'arangosearch' ] ,
        ]) ;
        $db->method( 'view' )->willReturn( $this->view( properties : [ 'links' => [ 'places' => [] ] ] ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ aView (arangosearch) — places' , $text ) ;
        $this->assertStringContainsString( '→ zView (arangosearch) — places' , $text ) ;
        $this->assertLessThan( strpos( $text , 'zView' ) , strpos( $text , 'aView' ) ) ;
    }

    public function testListSkipsTheLinksSuffixWhenPropertiesFail() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->method( 'properties' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $db = $this->databaseListing( [ [ 'name' => 'aView' , 'type' => 'arangosearch' ] ] ) ;
        $db->method( 'view' )->willReturn( $view ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ aView (arangosearch)' , $output->fetch() ) ;
    }

    public function testListReportsWhenThereAreNoViews() :void
    {
        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $this->databaseListing( [] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'no views' , $output->fetch() ) ;
    }

    public function testListFailsWhenNoHttpClientIsAvailable() :void
    {
        $host = new ArangoViewsActionHost() ;
        $host->returnNullDatabase = true ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB HTTP client available' , $output->fetch() ) ;
    }

    public function testListFailsWhenTheHttpApiIsUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listViews' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Unable to list the views' , $output->fetch() ) ;
    }

    // ---- diff -------------------------------------------------------------

    public function testDiffWarnsWithoutConfiguredModels() :void
    {
        $host = new ArangoViewsActionHost() ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'No models or search-alias views configured' , $output->fetch() ) ;
    }

    public function testDiffRendersInSyncAndTheOrphanFootnote() :void
    {
        $model = $this->model( $this->facade( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ) ;

        $host = $this->host( [ 'models.places' => $model ] ) ;
        $host->fakeDatabase = $this->databaseListing(
        [
            [ 'name' => 'placesView' , 'type' => 'arangosearch' ] ,
            [ 'name' => 'ghostView'  , 'type' => 'arangosearch' ] ,
        ]) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ placesView (models.places) — in sync' , $text ) ;
        $this->assertStringContainsString( 'Orphan views' , $text ) ;
        $this->assertStringContainsString( 'ghostView' , $text ) ;
        $this->assertStringNotContainsString( 'placesView,' , $text ) ;
    }

    public function testDiffRendersADriftWithItsChanges() :void
    {
        $report = new DiffReport( 'placesView' , DiffStatus::DRIFTED , [ 'places.fields.name : not indexed on the server' ] ) ;
        $model  = $this->model( $this->facade( $report ) ) ;

        $host = $this->host( [ 'models.places' => $model ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '~ placesView (models.places) — drifted' , $text ) ;
        $this->assertStringContainsString( '· places.fields.name : not indexed on the server' , $text ) ;
    }

    public function testDiffRendersMissingAndInvalid() :void
    {
        $missing = $this->model( $this->facade( new DiffReport( 'placesView' , DiffStatus::MISSING ) ) ) ;

        $invalidFacade = $this->createMock( ArangoDB::class ) ;
        $invalidFacade->method( 'analyzerExists'   )->willReturn( false ) ;
        $invalidFacade->method( 'collectionExists' )->willReturn( true ) ;
        $invalidFacade->method( 'viewDiff' )->willReturn( new DiffReport( 'badView' , DiffStatus::IN_SYNC ) ) ;

        $invalid = $this->model( $invalidFacade , view : [ Search::NAME => 'badView' ] ) ;

        $host = $this->host( [ 'models.a' => $missing , 'models.b' => $invalid ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✗ placesView (models.a) — missing on the server' , $text ) ;
        $this->assertStringContainsString( '! badView (models.b) — invalid' , $text ) ;
        $this->assertStringContainsString( "analyzer 'text_fr' not found on the server" , $text ) ;
    }

    public function testDiffMarksUnreachableAsFailure() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewDiff' )->willReturn( new DiffReport( 'placesView' , DiffStatus::UNREACHABLE , [ 'boom' ] ) ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '! placesView (models.places) — unreachable' , $text ) ;
    }

    public function testDiffSkipsNonDocumentsEntriesAndModelsWithoutView() :void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $plain = new Documents( $container , [ AQL::COLLECTION => 'places' , AQL::LAZY => false ] ) ;

        $host = $this->host( [ 'models.alien' => new stdClass() , 'models.plain' => $plain ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'models.alien — not a Documents model, skipped' , $text ) ;
        $this->assertStringContainsString( 'models.plain — no View declared' , $text ) ;
    }

    public function testDiffReportsAContainerResolutionFailure() :void
    {
        $host = new ArangoViewsActionHost( new Container() ) ;
        $host->models = [ 'models.ghost' ] ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '✗ models.ghost' , $text ) ;
    }

    public function testDiffDisablesTheLazyProvisioningThroughTheContainer() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewExists' )->willReturn( false ) ;
        $facade->method( 'viewDiff' )->willReturn( new DiffReport( 'placesView' , DiffStatus::MISSING ) ) ;
        $facade->method( 'analyzerExists' )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->expects( $this->never() )->method( 'viewCreate' ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        $container->set( 'models.places' , fn( Container $c ) => new Documents( $c ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => true , // would provision without the kill-switch
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ) ;

        $host = new ArangoViewsActionHost( $container ) ;
        $host->models = [ 'models.places' ] ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertFalse( $container->get( Arango::LAZY ) ) ;
        $this->assertStringContainsString( '✗ placesView (models.places) — missing on the server' , $output->fetch() ) ;
    }

    public function testDiffSkipsTheOrphanFootnoteWhenEveryViewIsDeclared() :void
    {
        $model = $this->model( $this->facade( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ) ;

        $host = $this->host( [ 'models.places' => $model ] ) ;
        $host->fakeDatabase = $this->databaseListing( [ [ 'name' => 'placesView' , 'type' => 'arangosearch' ] ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringNotContainsString( 'Orphan views' , $output->fetch() ) ;
    }

    public function testDiffSkipsTheOrphanFootnoteWhenTheListIsUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listViews' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $model = $this->model( $this->facade( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ) ;

        $host = $this->host( [ 'models.places' => $model ] ) ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringNotContainsString( 'Orphan views' , $output->fetch() ) ;
    }

    // ---- sync -------------------------------------------------------------

    public function testSyncRendersCreatedAndResynchronized() :void
    {
        $created = $this->model( $this->facade
        (
            new DiffReport( 'placesView' , DiffStatus::MISSING ) ,
            new DiffReport( 'placesView' , DiffStatus::MISSING , [] , true )
        ) ) ;

        $repaired = $this->model( $this->facade
        (
            new DiffReport( 'reviewsView' , DiffStatus::DRIFTED , [ 'places.fields.name : not indexed on the server' ] ) ,
            new DiffReport( 'reviewsView' , DiffStatus::DRIFTED , [ 'places.fields.name : not indexed on the server' ] , true )
        ) , view : [ Search::NAME => 'reviewsView' ] ) ;

        $host = $this->host( [ 'models.a' => $created , 'models.b' => $repaired ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ placesView (models.a) — created' , $text ) ;
        $this->assertStringContainsString( '✓ reviewsView (models.b) — resynchronized' , $text ) ;
    }

    public function testSyncFlagsAFailedApply() :void
    {
        $report = new DiffReport( 'placesView' , DiffStatus::DRIFTED , [ 'sync failed : boom' ] ) ;
        $model  = $this->model( $this->facade( $report , $report ) ) ;

        $host = $this->host( [ 'models.places' => $model ] ) ;
        $output = new BufferedOutput() ;

        $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;

        $this->assertStringContainsString( '(sync failed)' , $output->fetch() ) ;
    }

    public function testSyncFilterRestrictsTheRunAndSkipsTheOrphanFootnote() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->expects( $this->never() )->method( 'viewDiff' ) ;
        $facade->expects( $this->never() )->method( 'viewSync' ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $host->fakeDatabase = $this->databaseListing( [ [ 'name' => 'ghostView' , 'type' => 'arangosearch' ] ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => 'otherView' ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringNotContainsString( 'placesView' , $text ) ;
        $this->assertStringNotContainsString( 'Orphan views' , $text ) ;
    }

    // ---- drop -------------------------------------------------------------

    public function testDropDropsTheGivenNames() :void
    {
        $existing = $this->view( exists : true ) ;
        $existing->expects( $this->once() )->method( 'drop' ) ;

        $missing = $this->view( exists : false ) ;
        $missing->expects( $this->never() )->method( 'drop' ) ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'view' )->willReturnMap( [ [ 'aView' , $existing ] , [ 'bView' , $missing ] ] ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DROP => 'aView, bView' ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ aView — dropped' , $text ) ;
        $this->assertStringContainsString( '· bView — not found' , $text ) ;
    }

    public function testDropFailsOnClientException() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'view' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DROP => 'aView' ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '✗ aView — boom' , $output->fetch() ) ;
    }

    public function testDropFailsWithoutHttpClient() :void
    {
        $host = new ArangoViewsActionHost() ;
        $host->returnNullDatabase = true ;
        $output = new BufferedOutput() ;

        $this->assertSame( ExitCode::FAILURE , $host->run( $this->input( [ '--' . ArangoCommandOption::DROP => 'aView' ] ) , $output ) ) ;
    }

    public function testDropWithoutNamesFailsWhenNotInteractive() :void
    {
        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $this->databaseListing( [] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DROP => null ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No view names provided' , $output->fetch() ) ;
    }

    public function testDropInteractiveSelectionDropsTheChosenView() :void
    {
        $view = $this->view( exists : true ) ;
        $view->expects( $this->once() )->method( 'drop' ) ;

        $db = $this->databaseListing( [ [ 'name' => 'aView' , 'type' => 'arangosearch' ] ] ) ;
        $db->method( 'view' )->willReturn( $view ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;

        $input = $this->input( [ '--' . ArangoCommandOption::DROP => null ] , interactive : true ) ;
        $input->setStream( $this->stream( "aView\n" ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ aView — dropped' , $output->fetch() ) ;
    }

    public function testDropInteractiveExitThrowsTheExitException() :void
    {
        $db = $this->databaseListing( [ [ 'name' => 'aView' , 'type' => 'arangosearch' ] ] ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;

        $input = $this->input( [ '--' . ArangoCommandOption::DROP => null ] , interactive : true ) ;
        $input->setStream( $this->stream( $host->exit . "\n" ) ) ;

        $this->expectException( ExitException::class ) ;

        $host->run( $input , new BufferedOutput() ) ;
    }

    public function testDropInteractiveReportsWhenThereAreNoViews() :void
    {
        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $this->databaseListing( [] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DROP => null ] , interactive : true ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'no views' , $output->fetch() ) ;
    }

    public function testDropInteractiveFailsWhenTheListIsUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listViews' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DROP => null ] , interactive : true ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Unable to list the views' , $output->fetch() ) ;
    }

    /** An in-memory stream feeding the interactive question helper. */
    private function stream( string $answer )
    {
        $stream = fopen( 'php://memory' , 'r+' ) ;
        fwrite( $stream , $answer ) ;
        rewind( $stream ) ;
        return $stream ;
    }

    // ---- search-alias registry reconciliation ----------------------------

    public function testDiffReconcilesTheSearchAliasRegistry() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'searchAliasViewDiff' )->willReturn( new DiffReport( 'global_search' , DiffStatus::IN_SYNC ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->searchAliasViews = new SearchAliasView( 'global_search' , [ 'customers' => 'inv' ] ) ;
        $host->fakeFacade       = $facade ;
        $host->fakeDatabase     = $this->databaseListing( [ [ 'name' => 'global_search' , 'type' => 'search-alias' ] ] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'global_search (search-alias) — in sync' , $output->fetch() ) ;
    }

    public function testSyncCreatesAMissingSearchAliasView() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'searchAliasViewSync' )->willReturn( new DiffReport( 'global_search' , DiffStatus::MISSING , [] , true ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->searchAliasViews = [ new SearchAliasView( 'global_search' , [ 'customers' => 'inv' ] ) ] ;
        $host->fakeFacade       = $facade ;
        $host->fakeDatabase     = $this->databaseListing( [ [ 'name' => 'global_search' , 'type' => 'search-alias' ] ] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'global_search (search-alias) — created' , $output->fetch() ) ;
    }

    public function testSearchAliasReportFailsWhenFacadeIsUnavailable() :void
    {
        $host = new ArangoViewsActionHost() ;
        $host->searchAliasViews = new SearchAliasView( 'global_search' , [ 'customers' => 'inv' ] ) ;
        $host->returnNullFacade = true ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB façade available' , $output->fetch() ) ;
    }

    public function testSearchAliasDiffMarksUnreachableAsFailure() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'searchAliasViewDiff' )->willReturn( new DiffReport( 'global_search' , DiffStatus::UNREACHABLE , [ 'boom' ] ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->searchAliasViews = new SearchAliasView( 'global_search' , [ 'customers' => 'inv' ] ) ;
        $host->fakeFacade       = $facade ;
        $host->fakeDatabase     = $this->databaseListing( [] ) ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
    }

    public function testSyncFilterSkipsUnselectedSearchAliasViews() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->expects( $this->never() )->method( 'searchAliasViewSync' ) ;

        $host = new ArangoViewsActionHost() ;
        $host->searchAliasViews = new SearchAliasView( 'global_search' , [ 'customers' => 'inv' ] ) ;
        $host->fakeFacade       = $facade ;

        $output = new BufferedOutput() ;
        // restrict the sync to another view → the declared search-alias is skipped
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::SYNC => 'other_view' ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringNotContainsString( 'global_search' , $output->fetch() ) ;
    }

    public function testListShowsSearchAliasCollections() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'listViews' )->willReturn( [ [ 'name' => 'global_search' , 'type' => 'search-alias' ] ] ) ;
        $db->method( 'view' )->willReturn( $this->view( properties :
        [
            'indexes' =>
            [
                [ 'collection' => 'customers' , 'index' => 'inv' ] ,
                [ 'collection' => 'products'  , 'index' => 'inv' ] ,
            ] ,
        ] ) ) ;

        $host = new ArangoViewsActionHost() ;
        $host->fakeDatabase = $db ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input() , $output ) ;

        $text = $output->fetch() ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'global_search (search-alias) — customers, products' , $text ) ;
    }
}
