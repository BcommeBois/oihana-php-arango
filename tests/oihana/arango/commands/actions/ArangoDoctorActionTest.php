<?php

namespace tests\oihana\arango\commands\actions;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\MockObject\Exception;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use stdClass;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\View;
use oihana\arango\commands\actions\ArangoDoctorAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoDoctorAction::doctor()} for testing — same seams
 * as {@see ArangoViewsActionHost}: the protected {@see buildDatabase()}
 * returns a caller-supplied fake {@see Database} (or null), the models are
 * resolved from a real PHP-DI container supplied by the test.
 */
class ArangoDoctorActionHost
{
    use ArangoDoctorAction ;
    use ArangoConfigTrait ;

    /** When true, the buildDatabase() seam returns null (no client). */
    public bool $returnNullDatabase = false ;

    /** When true, the buildDatabase() seam returns null from the second call on (orphans listed, prune unreachable). */
    public bool $returnNullDatabaseAfterFirst = false ;

    /** Number of buildDatabase() calls made by the action. */
    public int $databaseCalls = 0 ;

    /** The fake database returned by the buildDatabase() seam. */
    public ?Database $fakeDatabase = null ;

    /** The exit label of the interactive selections (mirrors ArangoCommand). */
    public string $exit = 'Exit the command.' ;

    /** The DI container the models are resolved from. */
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

    /**
     * Public proxy to the protected action under test.
     *
     * @param $input
     * @param $output
     *
     * @return int
     *
     * @throws ExitException
     * @throws ReflectionException
     */
    public function run( $input , $output ) :int
    {
        return $this->doctor( $input , $output ) ;
    }

    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        $this->databaseCalls++ ;

        if ( $this->returnNullDatabase || ( $this->returnNullDatabaseAfterFirst && $this->databaseCalls > 1 ) )
        {
            return null ;
        }

        return $this->fakeDatabase ;
    }
}

/**
 * Unit coverage for {@see ArangoDoctorAction}.
 */
#[CoversTrait( ArangoDoctorAction::class )]
#[AllowMockObjectsWithoutExpectations]
class ArangoDoctorActionTest extends TestCase
{
    /**
     * Full option surface read by doctor(), so a plain ArrayInput can
     * answer every getOption() call.
     */
    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::APPLY    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FORCE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::PRUNE    , null , InputOption::VALUE_NONE ) ,
        ]) ;
    }

    private function input( array $options = [] , bool $interactive = false ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( $interactive ) ;
        return $input ;
    }

    /**
     * A Documents model declaring collection + index + View, bound to the given façade.
     *
     * @param ArangoDB $facade
     *
     * @throws ContainerExceptionInterface
     * @return Documents
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function model( ArangoDB $facade ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => false ,
            AQL::INDEXES     => [ [ 'type' => 'persistent' , 'name' => 'id' , 'fields' => [ 'id' ] , 'unique' => true ] ] ,
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;
    }

    /** A façade double answering the three reports. */
    private function facade( DiffReport $collection , DiffReport $indexes , DiffReport $view ) :ArangoDB
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'collectionDiff'   )->willReturn( $collection ) ;
        $facade->method( 'indexesDiff'      )->willReturn( $indexes ) ;
        $facade->method( 'indexesSync'      )->willReturn( $indexes ) ;
        $facade->method( 'analyzerExists'   )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( $view ) ;
        $facade->method( 'viewSync'         )->willReturn( $view ) ;
        return $facade ;
    }

    /** A fully in-sync façade. */
    private function healthyFacade() :ArangoDB
    {
        return $this->facade
        (
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::IN_SYNC )
        ) ;
    }

    /** A host whose container maps the given ids to the given models. */
    private function host( array $models = [] ) :ArangoDoctorActionHost
    {
        $container = new Container() ;
        foreach( $models as $id => $model )
        {
            $container->set( $id , $model ) ;
        }

        $host = new ArangoDoctorActionHost( $container ) ;
        $host->models = array_keys( $models ) ;
        return $host ;
    }

    /** A bare collection-like object exposing only getName(). */
    private function namedCollection( string $name ) :object
    {
        return new readonly class( $name )
        {
            public function __construct( private string $name ) {}
            public function getName() :string { return $this->name ; }
        } ;
    }

    // ---- report mode --------------------------------------------------------

    /**
     * @return void
     * @throws ExitException
     * @throws ReflectionException
     */
    public function testDoctorWarnsWithoutConfiguredModels() :void
    {
        $host = new ArangoDoctorActionHost() ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'No models configured' , $output->fetch() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testDoctorIsGreenWhenEverythingIsInSync() :void
    {
        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ places [collection] — in sync' , $text ) ;
        $this->assertStringContainsString( '✓ places [indexes] — in sync' , $text ) ;
        $this->assertStringContainsString( '✓ placesView [view] — in sync' , $text ) ;
        $this->assertStringContainsString( '1 model(s) — 3 in sync, 0 missing, 0 drifted, 0 invalid, 0 unreachable ; 0 orphan(s).' , $text ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testDoctorFailsOnDriftInReportMode() :void
    {
        $facade = $this->facade
        (
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::DRIFTED , [ 'id : missing on the server' ] , kind : DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::IN_SYNC )
        ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '~ places [indexes] — drifted' , $text ) ;
        $this->assertStringContainsString( '· id : missing on the server' , $text ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testDoctorFailsOnMissingInReportMode() :void
    {
        $facade = $this->facade
        (
            new DiffReport( 'places' , DiffStatus::MISSING , kind : DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::INVALID , [ "collection 'places' not found on the server" ] , kind : DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::MISSING )
        ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '✗ places [collection] — missing on the server' , $text ) ;
        $this->assertStringContainsString( '! places [indexes] — invalid' , $text ) ;
    }

    /**
     * @return void
     * @throws ExitException
     * @throws ReflectionException
     */
    public function testDoctorSkipsNonDocumentsAndReportsResolutionFailures() :void
    {
        $host = $this->host( [ 'models.alien' => new stdClass() ] ) ;
        $host->models = [ 'models.alien' , 'models.ghost' ] ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'models.alien — not a Documents model, skipped' , $text ) ;
        $this->assertStringContainsString( '✗ models.ghost' , $text ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testDoctorDisablesTheLazyProvisioningThroughTheContainer() :void
    {
        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $output = new BufferedOutput() ;

        $host->run( $this->input() , $output ) ;

        $this->assertFalse( $host->container->get( Arango::LAZY ) ) ;
    }

    // ---- apply mode ---------------------------------------------------------

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testApplyRendersCreatedAndRepaired() :void
    {
        $facade = $this->facade
        (
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::DRIFTED , [ 'id.unique : server false ≠ declared true (drop + recreate required)' ] , true , DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::MISSING , [] , true )
        ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::APPLY => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Repair the declared structure' , $text ) ;
        $this->assertStringContainsString( '✓ places [indexes] — repaired' , $text ) ;
        $this->assertStringContainsString( '✓ placesView [view] — created' , $text ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Exception
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testApplyJournalsCreateActionsInTheTrackingCollection() :void
    {
        $facade = $this->facade
        (
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::MISSING , [ 'byName : missing on the server' ] , true , DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::MISSING , [] , true )
        ) ;

        // the journal appends one CreateAction per applied report.
        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->expects( $this->exactly( 2 ) )->method( 'insert' ) ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'collection' )->willReturn( $collection ) ;
        $db->method( 'collections' )->willReturn( [] ) ;
        $db->method( 'listViews' )->willReturn( [] ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $host->run( $this->input( [ '--' . ArangoCommandOption::APPLY => true ] ) , $output ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Exception
     */
    public function testApplyJournalSwallowsAWriteFailure() :void
    {
        $facade = $this->facade
        (
            new DiffReport( 'places' , DiffStatus::MISSING , [] , true , DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::IN_SYNC )
        ) ;

        $facade->method( 'collectionCreate' )->willReturn( true ) ;

        $collection = $this->createMock( Collection::class ) ;
        $collection->method( 'exists' )->willReturn( true ) ;
        $collection->method( 'insert' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'collection' )->willReturn( $collection ) ;
        $db->method( 'collections' )->willReturn( [] ) ;
        $db->method( 'listViews' )->willReturn( [] ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        // the audit journal must never fail the doctor run
        $this->assertSame( ExitCode::SUCCESS , $host->run( $this->input( [ '--' . ArangoCommandOption::APPLY => true ] ) , $output ) ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testApplyFlagsAndFailsOnAnUnrepairedDrift() :void
    {
        $facade = $this->facade
        (
            new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::COLLECTION ) ,
            new DiffReport( 'places' , DiffStatus::DRIFTED , [ 'id.unique : server false ≠ declared true (drop + recreate required)' ] , false , DiffKind::INDEXES ) ,
            new DiffReport( 'placesView' , DiffStatus::IN_SYNC )
        ) ;

        $host = $this->host( [ 'models.places' => $this->model( $facade ) ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::APPLY => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '~ places [indexes] — drifted (not repaired)' , $text ) ;
    }

    // ---- orphans / prune ----------------------------------------------------

    /** A Database double listing one orphan collection and one orphan view. */
    private function databaseWithOrphans() :Database
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willReturn( [ $this->namedCollection( 'places' ) , $this->namedCollection( 'ghost' ) ] ) ;
        $db->method( 'listViews'   )->willReturn( [ [ 'name' => 'placesView' ] , [ 'name' => 'legacyView' ] ] ) ;
        return $db ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testOrphansAreReportedButDoNotFail() :void
    {
        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $this->databaseWithOrphans() ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '· collection : ghost' , $text ) ;
        $this->assertStringContainsString( '· view : legacyView' , $text ) ;
        $this->assertStringNotContainsString( 'collection : places' , $text ) ;
        $this->assertStringNotContainsString( 'view : placesView' , $text ) ;
        $this->assertStringContainsString( '2 orphan(s)' , $text ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testPruneWarnsWhenNotInteractive() :void
    {
        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $this->databaseWithOrphans() ;
        $output = new BufferedOutput() ;

        $host->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] ) , $output ) ;

        $this->assertStringContainsString( 'interactive only' , $output->fetch() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Exception
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testPruneDropsTheSelectedOrphan() :void
    {
        $ghost = $this->createMock( Collection::class ) ;
        $ghost->expects( $this->once() )->method( 'drop' ) ;

        $view = $this->createMock( View::class ) ;
        $view->expects( $this->never() )->method( 'drop' ) ;

        $db = $this->databaseWithOrphans() ;
        $db->method( 'collection' )->willReturn( $ghost ) ;
        $db->method( 'view' )->willReturn( $view ) ;

        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $db ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive : true ) ;
        $input->setStream( $this->stream( "collection : ghost\n" ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '✓ collection : ghost — dropped' , $output->fetch() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testPruneExitThrowsTheExitException() :void
    {
        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $this->databaseWithOrphans() ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive : true ) ;
        $input->setStream( $this->stream( $host->exit . "\n" ) ) ;

        $this->expectException( ExitException::class ) ;

        $host->run( $input , new BufferedOutput() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Exception
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testDoctorRendersAnUnreachableModelAndFails() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'collectionDiff' )->willReturn( new DiffReport( 'places' , DiffStatus::UNREACHABLE , [ 'boom' ] , kind : DiffKind::COLLECTION ) ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $model = new Documents( $container , [ Arango::DATABASE => $facade , AQL::COLLECTION => 'places' , AQL::LAZY => false ] ) ;

        $host = $this->host( [ 'models.places' => $model ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( '! places [collection] — unreachable' , $text ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Exception
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testOrphansAreSkippedWhenTheListingFails() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willThrowException( new ArangoException( 'boom' ) ) ;

        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '0 orphan(s)' , $output->fetch() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testPruneStopsWhenTheHttpClientVanishes() :void
    {
        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $this->databaseWithOrphans() ;
        $host->returnNullDatabaseAfterFirst = true ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive : true ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringNotContainsString( 'dropped' , $output->fetch() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Exception
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testPruneDropsTheSelectedView() :void
    {
        $view = $this->createMock( View::class ) ;
        $view->expects( $this->once() )->method( 'drop' ) ;

        $db = $this->databaseWithOrphans() ;
        $db->method( 'view' )->willReturn( $view ) ;

        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $db ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive : true ) ;
        $input->setStream( $this->stream( "view : legacyView\n" ) ) ;
        $output = new BufferedOutput() ;

        $host->run( $input , $output ) ;

        $this->assertStringContainsString( '✓ view : legacyView — dropped' , $output->fetch() ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws Exception
     * @throws ExitException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testPruneReportsADropFailure() :void
    {
        $ghost = $this->createMock( Collection::class ) ;
        $ghost->method( 'drop' )->willThrowException( new ArangoException( 'locked' ) ) ;

        $db = $this->databaseWithOrphans() ;
        $db->method( 'collection' )->willReturn( $ghost ) ;

        $host = $this->host( [ 'models.places' => $this->model( $this->healthyFacade() ) ] ) ;
        $host->fakeDatabase = $db ;

        $input = $this->input( [ '--' . ArangoCommandOption::PRUNE => true ] , interactive : true ) ;
        $input->setStream( $this->stream( "collection : ghost\n" ) ) ;
        $output = new BufferedOutput() ;

        $host->run( $input , $output ) ;

        $this->assertStringContainsString( '✗ collection : ghost — locked' , $output->fetch() ) ;
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
