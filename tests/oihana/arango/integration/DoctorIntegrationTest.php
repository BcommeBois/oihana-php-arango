<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;
use Throwable;

use Devium\Toml\TomlError;

use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\actions\ArangoDoctorAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;

use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\Group;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

use function oihana\init\initConfig;

/**
 * Live validation of the structure doctor (Lot M2): the lazy provisioning
 * only creates indexes with the collection, so an index added to the
 * `AQL::INDEXES` of an existing model is silently never created (the
 * motivating bug) — `diagnose()` detects it, `repair()` creates it; a
 * drifted index definition is announced and only rebuilt when forced
 * (drop + recreate on a real server); the collection and View reports
 * aggregate in the same walk.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class DoctorIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_doctor_it' ;

    private const string COLLECTION = 'places' ;

    private const string VIEW = 'placesView' ;

    /**
     * Nothing is seeded — the first model construction provisions the
     * collection, its declared index and the View (that is the baseline).
     */
    protected static function seed( Database $db ) :void
    {
    }

    /**
     * A Documents model wired to the disposable database, declaring the
     * given indexes on the shared collection (plus the fixture View).
     *
     * @throws TomlError
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    private function model( array $indexes ) :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::INDEXES     => $indexes ,
            AQL::VIEW        =>
            [
                Search::NAME     => self::VIEW ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;
    }

    /**
     * The named unique persistent index on `id` — the baseline declaration.
     */
    private function idIndex() :PersistentIndexOptions
    {
        return new PersistentIndexOptions(
        [
            IndexOptions::NAME   => 'id' ,
            IndexOptions::FIELDS => [ 'id' ] ,
            IndexOptions::UNIQUE => true ,
        ]) ;
    }

    /**
     * A named persistent index on `name`, unique or not.
     */
    private function nameIndex( bool $unique = false ) :PersistentIndexOptions
    {
        return new PersistentIndexOptions(
        [
            IndexOptions::NAME   => 'byName' ,
            IndexOptions::FIELDS => [ 'name' ] ,
            IndexOptions::UNIQUE => $unique ,
        ]) ;
    }

    /**
     * The full M2 lifecycle on a real server:
     *
     * 1. the first model provisions everything → diagnose() fully in sync
     *    (collection, indexes, View — in that order);
     * 2. the declaration gains a second index → it is silently NOT created
     *    (the motivating bug), diagnose() says MISSING with the exact line,
     *    repair() creates it and the server now carries it;
     * 3. the index definition then drifts (`unique` flips) → announced with
     *    the drop + recreate reminder, left alone by repair(), rebuilt by
     *    repair( force: true ) — and the server agrees.
     */
    public function testDiagnoseAndRepairLifecycle() :void
    {
        // 1 — baseline : lazy provisioning then a fully green diagnosis.

        $baseline = $this->model( [ $this->idIndex() ] ) ;

        $reports = $baseline->diagnose() ;

        $this->assertSame
        (
            [ DiffKind::COLLECTION , DiffKind::INDEXES , DiffKind::VIEW ] ,
            array_map( fn( $r ) => $r->kind , $reports )
        ) ;
        $this->assertSame( [ true , true , true ] , array_map( fn( $r ) => $r->inSync() , $reports ) ) ;

        // 2 — the declaration gains an index : never created by the lazy
        //     provisioning (the collection already exists), repaired by doctor.

        $wider = $this->model( [ $this->idIndex() , $this->nameIndex() ] ) ;

        $this->assertEmpty( array_filter
        (
            self::$db->collection( self::COLLECTION )->indexes() ,
            fn( $index ) => ( $index[ 'name' ] ?? '' ) === 'byName'
        ) , 'The added index must NOT exist before the repair (the drift).' ) ;

        $indexes = $wider->diagnose()[1] ;
        $this->assertSame( DiffStatus::MISSING , $indexes->status ) ;
        $this->assertContains( 'byName : missing on the server' , $indexes->changes ) ;

        $repaired = $wider->repair()[1] ;
        $this->assertTrue( $repaired->applied ) ;

        $this->assertTrue( $wider->diagnose()[1]->inSync() ) ;
        $this->assertNotEmpty( array_filter
        (
            self::$db->collection( self::COLLECTION )->indexes() ,
            fn( $index ) => ( $index[ 'name' ] ?? '' ) === 'byName'
        ) , 'The repaired index must exist on the server.' ) ;

        // 3 — the index definition drifts : announced, only rebuilt when forced.

        $drifted = $this->model( [ $this->idIndex() , $this->nameIndex( unique : true ) ] ) ;

        $report = $drifted->diagnose()[1] ;
        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'byName.unique : server false ≠ declared true (drop + recreate required)' , $report->changes ) ;

        $this->assertFalse( $drifted->repair()[1]->applied , 'A drifted index must not be rebuilt without force.' ) ;
        $this->assertTrue ( $drifted->repair( force : true )[1]->applied , 'force must rebuild the drifted index.' ) ;

        $this->assertTrue( $drifted->diagnose()[1]->inSync() ) ;

        $rebuilt = array_values( array_filter
        (
            self::$db->collection( self::COLLECTION )->indexes() ,
            fn( $index ) => ( $index[ 'name' ] ?? '' ) === 'byName'
        ) ) ;
        $this->assertTrue( $rebuilt[0][ 'unique' ] ?? false , 'The rebuilt index must be unique on the server.' ) ;
    }

    /**
     * A same-name model on a missing collection : diagnose() reports the
     * collection MISSING and the indexes INVALID, repair() creates the
     * collection with its declared indexes in one pass.
     */
    public function testRepairCreatesAMissingCollectionWithItsIndexes() :void
    {
        $model = $this->model( [ $this->idIndex() ] ) ;

        // drop everything behind the model's back.
        try { self::$db->view( self::VIEW )->drop() ; } catch ( ArangoException ) {}
        self::$db->collection( self::COLLECTION )->drop() ;

        $reports = $model->diagnose() ;
        $this->assertSame( DiffStatus::MISSING , $reports[0]->status ) ;
        $this->assertSame( DiffStatus::INVALID , $reports[1]->status ) ;

        $reports = $model->repair() ;
        $this->assertTrue( $reports[0]->applied , 'The missing collection must be recreated.' ) ;

        $this->assertSame( [ true , true , true ] , array_map( fn( $r ) => $r->inSync() , $model->diagnose() ) ) ;
    }

    public static function databaseName() :string
    {
        return static::$database ;
    }

    /**
     * A `doctor` command host (the real action) wired to the disposable
     * database with the given analyzer registry — no model, no index registry.
     *
     * @param array<int, AnalyzerDefinition> $analyzers
     *
     * @throws TomlError
     * @throws Throwable
     */
    private function doctorHost( array $analyzers ) :object
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        return new class( $arango , $analyzers )
        {
            use ArangoDoctorAction ;
            use ArangoConfigTrait ;

            public Container $container ;

            public function __construct( array $arango , array $analyzers )
            {
                $this->container = new Container() ;
                $this->endpoint  = $arango[ ArangoConfig::ENDPOINT ] ?? '' ;
                $this->username  = $arango[ ArangoConfig::USER ]     ?? '' ;
                $this->password  = $arango[ ArangoConfig::PASSWORD ] ?? '' ;
                $this->database  = DoctorIntegrationTest::databaseName() ;
                $this->analyzers = $analyzers ;
            }

            public function getQuestionHelper() :QuestionHelper
            {
                return new QuestionHelper() ;
            }

            public function run( $input , $output ) :int
            {
                return $this->doctor( $input , $output ) ;
            }
        } ;
    }

    private function doctorInput( array $options = [] ) :ArrayInput
    {
        $definition = new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::APPLY    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FORCE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::PRUNE    , null , InputOption::VALUE_NONE ) ,
        ]) ;

        $input = new ArrayInput( $options , $definition ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /**
     * `doctor` reconciles the custom-analyzer registry on a real server: a
     * declared analyzer is reported MISSING (run fails), created by `--apply`,
     * then IN_SYNC; a drift is signalled and **never** repaired — even with
     * `--apply --force` — pointing to the dedicated `arango:analyzers` action.
     *
     * @throws TomlError
     * @throws Throwable
     */
    public function testDoctorProvisionsAndSignalsTheAnalyzerRegistry() :void
    {
        $declared = new AnalyzerDefinition( 'doctor_az' , new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: true ) , [] ) ;

        // A fresh host per run : getIO() memoizes its SymfonyStyle on first use.

        // 1 — report : the declared analyzer is missing → the run fails.
        $output = new BufferedOutput() ;
        $code   = $this->doctorHost( [ $declared ] )->run( $this->doctorInput() , $output ) ;
        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'doctor_az [analyzer] — missing on the server' , $output->fetch() ) ;

        // 2 — apply : it gets created.
        $output = new BufferedOutput() ;
        $this->doctorHost( [ $declared ] )->run( $this->doctorInput( [ '--' . ArangoCommandOption::APPLY => true ] ) , $output ) ;
        $this->assertStringContainsString( 'doctor_az [analyzer] — created' , $output->fetch() ) ;

        // 3 — report again : now in sync.
        $output = new BufferedOutput() ;
        $this->doctorHost( [ $declared ] )->run( $this->doctorInput() , $output ) ;
        $this->assertStringContainsString( 'doctor_az [analyzer] — in sync' , $output->fetch() ) ;

        // 4 — a changed declaration drifts : signalled, never repaired (even
        //     with --apply --force), with the pointer to arango:analyzers.
        $drift  = new AnalyzerDefinition( 'doctor_az' , new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: false ) , [] ) ;
        $output = new BufferedOutput() ;
        $code   = $this->doctorHost( [ $drift ] )->run( $this->doctorInput( [ '--' . ArangoCommandOption::APPLY => true , '--' . ArangoCommandOption::FORCE => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'doctor_az [analyzer] — drifted (not repaired)' , $text ) ;
        $this->assertStringContainsString( 'arango:analyzers --fix' , $text ) ;
    }
}
