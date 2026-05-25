<?php

namespace oihana\arango\clients\commands\tests ;

use DI\Container ;
use DI\DependencyException ;
use DI\NotFoundException ;

use InvalidArgumentException ;
use JsonException ;
use Random\RandomException ;
use Throwable ;

use Psr\Container\ContainerExceptionInterface ;
use Psr\Container\NotFoundExceptionInterface ;

use Symfony\Component\Console\Input\InputInterface ;
use Symfony\Component\Console\Input\InputOption ;
use Symfony\Component\Console\Output\OutputInterface ;
use Symfony\Component\Console\Style\SymfonyStyle ;

use oihana\commands\enums\ExitCode ;
use oihana\commands\Kernel ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\analyzer\Analyzer ;
use oihana\arango\clients\analyzer\IdentityAnalyzer ;
use oihana\arango\clients\analyzer\NormAnalyzer ;
use oihana\arango\clients\analyzer\StemAnalyzer ;
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\analyzer\enums\AnalyzerType ;
use oihana\arango\clients\collection\indexes\PersistentIndex ;
use oihana\arango\clients\collection\indexes\TtlIndex ;
use oihana\arango\clients\commands\tests\traits\ArangoClientTestTrait ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\enums\ServerMode ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\graph\EdgeDefinition ;
use oihana\arango\clients\graph\Graph ;
use oihana\arango\clients\transaction\Transaction ;
use oihana\arango\clients\transaction\enums\TransactionStatus ;
use oihana\arango\clients\view\ArangoSearchLink ;
use oihana\arango\clients\view\View ;

use function oihana\arango\clients\aql\helpers\aql ;
use function oihana\arango\clients\aql\helpers\aqlLiteral ;
use function oihana\core\strings\parseSteps ;

/**
 * Live end-to-end integration test for the new
 * `api/src/oihana/arango/clients/` client.
 *
 * Talks to a real ArangoDB server (whose connection settings come from
 * the project's `[arango]` config, with CLI overrides) and exercises
 * the full surface of the client: connection, database lifecycle,
 * collection lifecycle, document CRUD, edges, AQL + cursor, indexes,
 * and error mapping.
 *
 * Every step runs on its own ephemeral database
 * (`arango_clients_test_<random>`) created at setup and dropped at
 * cleanup — production data is never touched. The cleanup is in a
 * `finally` block so the database is dropped even on unexpected
 * exception. Pass `--no-cleanup` to keep the database around for
 * post-mortem inspection.
 *
 * Coverage matrix:
 *
 * | Step | Surface |
 * |-----:|---------|
 * | 0    | (setup) build client, create test database |
 * | 1    | server: version + time + availability + listDatabases |
 * | 2    | database: exists, collections() |
 * | 3    | collection: create/properties/rename/drop |
 * | 4    | documents: insert/get/exists/count/update/replace/remove/truncate |
 * | 5    | edge collection: create + inEdges/outEdges/edges |
 * | 6    | AQL + Cursor (single batch + lazy multi-batch) + pipeline + explain / parse |
 * | 7    | indexes: PersistentIndex (unique/sparse) + TtlIndex + drop |
 * | 8    | error mapping: HttpException on 404, ConflictException on 409 |
 * | 9    | auth: login / useBearerAuth / useBasicAuth |
 * | 10   | import: bulk JSON Lines + onDuplicate + overwrite + details |
 * | 11   | transactions: begin / commit / abort / status / exists / step / list / withTransaction |
 * | 12   | graphs: create / get / exists / vertex+edge collection mgmt / edge definition mgmt / drop |
 * | 13   | analyzers: 4 types CRUD (identity/text/norm/stem) + listing + get round-trip + drop force |
 * | 14   | views (arangosearch): create + links + AQL SEARCH/PHRASE/BM25 + properties round-trip + drop |
 *
 * Usage:
 * ```shell
 * bun arango:test:clients
 * bun arango:test:clients --step=1-3
 * bun arango:test:clients --step=4
 * bun arango:test:clients --no-cleanup --endpoint=tcp://127.0.0.1:8529
 * ```
 *
 * @package oihana\arango\clients\commands\tests
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ArangoTestClientsCommand extends Kernel
{
    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct( ?string $name , ?Container $container = null , array $init = [] )
    {
        parent::__construct( $name , $container , $init ) ;
        $this->initializeArangoTestClient( $init ) ;
    }

    use ArangoClientTestTrait ;

    /**
     * Total number of business steps exposed by the `--step` option.
     */
    public const int MAX_STEP = 14 ;

    /**
     * Name of the `--step` option used to select a subset of steps to run.
     */
    public const string OPTION_STEP = 'step' ;

    /**
     * Prefix of the ephemeral database created for the run.
     */
    private const string TEST_DB_PREFIX = 'arango_clients_test_' ;

    /**
     * Configures the current command.
     */
    protected function configure() : void
    {
        $this->configureArangoTestOptions() ;
        $this->addOption
        (
            self::OPTION_STEP , 's' , InputOption::VALUE_OPTIONAL ,
            'Step range to execute (e.g. 4-6, 1,3,5, all)' , 'all' ,
        ) ;
    }

    /**
     * Executes the current command.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws RandomException
     */
    protected function execute( InputInterface $input , OutputInterface $output ) : int
    {
        [ $io , $timestamp ] = $this->startCommand( $input , $output ) ;
        $io->title( 'arango/clients/ — Live E2E Smoke Test' ) ;

        $stepInput = $input->getOption( self::OPTION_STEP ) ;
        $stepInput = is_string( $stepInput ) ? $stepInput : null ;

        try
        {
            $steps = parseSteps( $stepInput , self::MAX_STEP ) ;
        }
        catch ( InvalidArgumentException $e )
        {
            $io->error( 'Invalid --step value: ' . $e->getMessage() ) ;
            return $this->endCommand( $input , $output , ExitCode::FAILURE , $timestamp ) ;
        }

        $io->writeln( '  Steps to run: ' . implode( ',' , $steps ) ) ;

        $client = $this->buildArangoClient( $input , $io ) ;
        if ( !$client )
        {
            return $this->endCommand( $input , $output , ExitCode::FAILURE , $timestamp ) ;
        }

        $errors = 0 ;
        $passed = 0 ;
        $state  =
        [
            'testDbName'    => self::TEST_DB_PREFIX . bin2hex( random_bytes( 4 ) ) ,
            'testDbCreated' => false ,
            'testDb'        => null ,
        ] ;

        try
        {
            try
            {
                [ $passed , $errors , $state ] = $this->runSetup( $io , $client , $state , $passed , $errors ) ;

                if ( !$state[ 'testDbCreated' ] )
                {
                    $io->error( 'Setup failed — could not create the test database.' ) ;
                    $errors++ ;
                }
                else
                {
                    foreach ( $steps as $step )
                    {
                        $method = sprintf( 'runStep%d' , $step ) ;
                        if ( method_exists( $this , $method ) )
                        {
                            [ $passed , $errors , $state ] = $this->{$method}( $io , $client , $state , $passed , $errors ) ;
                        }
                    }
                }
            }
            catch ( Throwable $e )
            {
                $io->error( 'Unexpected error: ' . $e->getMessage() ) ;
                $errors++ ;
            }
        }
        finally
        {
            if ( $this->shouldCleanup( $input ) )
            {
                $this->runCleanup( $io , $client , $state ) ;
            }
            else
            {
                $io->writeln( '' ) ;
                $io->writeln( '  <comment>--no-cleanup set, test database ' . $state[ 'testDbName' ] . ' kept around.</comment>' ) ;
            }
        }

        $io->writeln( '' ) ;

        if ( $errors === 0 )
        {
            $io->success( "All $passed tests passed!" ) ;
        }
        else
        {
            $io->warning( "$passed passed, $errors failed" ) ;
        }

        return $this->endCommand( $input , $output , $errors === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE , $timestamp ) ;
    }

    // =========================================================================
    // Setup / cleanup
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     * @throws ArangoException
     */
    protected function runSetup( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Setup — create the ephemeral test database' ) ;

        $dbName = $state[ 'testDbName' ] ;
        $client->createDatabase( $dbName ) ;
        $state[ 'testDbCreated' ] = true ;
        $state[ 'testDb' ]        = $client->database( $dbName ) ;

        [ $passed , $errors ] = $this->check( $io , true , "Created test database '$dbName'" , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    protected function runCleanup( SymfonyStyle $io , ArangoClient $client , array $state ) : void
    {
        if ( !$state[ 'testDbCreated' ] )
        {
            return ;
        }

        $io->section( 'Cleanup — drop the test database' ) ;

        try
        {
            $client->dropDatabase( $state[ 'testDbName' ] ) ;
            $io->writeln( "  <info>✓</info> Dropped '" . $state[ 'testDbName' ] . "'" ) ;
        }
        catch ( Throwable $e )
        {
            $io->writeln( "  <error>✗</error> Failed to drop '" . $state[ 'testDbName' ] . "': " . $e->getMessage() ) ;
        }
    }

    // =========================================================================
    // Step 1 — server: version + listDatabases
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     * @throws ArangoException
     */
    protected function runStep1( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 1 — server: version + time + listDatabases' ) ;

        $version = $client->version() ;
        [ $passed , $errors ] = $this->check( $io , is_string( $version[ 'version' ] ?? null ) , 'version() returns a version string' , $passed , $errors ) ;

        $clientNow = microtime( true ) ;
        $serverNow = $client->time() ;
        [ $passed , $errors ] = $this->check( $io , is_float( $serverNow ) && $serverNow > 0       , 'time() returns a positive Unix timestamp as float'       , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , abs( $serverNow - $clientNow ) < 60            , 'time() is within 60s of the local clock (no major skew)' , $passed , $errors ) ;

        $mode = $client->availability() ;
        [ $passed , $errors ] = $this->check( $io , in_array( $mode , [ ServerMode::DEFAULT , ServerMode::READONLY ] , true )  , 'availability() returns a known ServerMode'              , $passed , $errors ) ;

        $databases = $client->listDatabases() ;
        [ $passed , $errors ] = $this->check( $io , in_array( $state[ 'testDbName' ] , $databases , true ) , 'listDatabases() includes the test database' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 2 — database: exists + collections() empty
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep2( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 2 — database: exists + empty collections()' ) ;

        /** @var Database $db */
        $db = $state[ 'testDb' ] ;

        [ $passed , $errors ] = $this->check( $io , $db->exists() , 'database.exists() is true' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collections() === [] , 'collections() (no system) is empty on a brand-new database' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 3 — collection lifecycle
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep3( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 3 — collection lifecycle (create / properties / rename / drop)' ) ;

        /** @var Database $db */
        $db = $state[ 'testDb' ] ;

        $col = $db->collection( 'lifecycle_test' ) ;

        [ $passed , $errors ] = $this->check( $io , !$col->exists() , 'exists() returns false before create' , $passed , $errors ) ;

        $col->create() ;

        [ $passed , $errors ] = $this->check( $io , $col->exists() , 'exists() returns true after create' , $passed , $errors ) ;

        $properties = $col->properties() ;
        [ $passed , $errors ] = $this->check( $io , ( $properties[ 'name' ] ?? null ) === 'lifecycle_test' , 'properties() returns the collection name' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $properties[ 'type' ] ?? null ) === 2               , 'properties() reports type DOCUMENT (2)'  , $passed , $errors ) ;

        $renamed = $col->rename( 'lifecycle_renamed' ) ;
        [ $passed , $errors ] = $this->check( $io , $renamed->getName() === 'lifecycle_renamed' , 'rename() returns a new instance with the new name' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$col->exists()                              , 'exists() returns false on the old name after rename' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $renamed->exists()                           , 'exists() returns true on the new name after rename'  , $passed , $errors ) ;

        $renamed->drop() ;
        [ $passed , $errors ] = $this->check( $io , !$renamed->exists()         , 'exists() returns false after drop'        , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collections() === [] , 'collections() is empty again after drop' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 4 — documents CRUD
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep4( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 4 — documents CRUD (insert/get/exists/count/update/replace/remove/truncate)' ) ;

        /** @var Database $db */
        $db = $state[ 'testDb' ] ;

        $users = $db->collection( 'users' ) ;
        $users->create() ;

        // insert + returnNew
        $marc = $users->insert( [ 'name' => 'Marc' , 'role' => 'admin' ] , [ 'returnNew' => true ] ) ;
        [ $passed , $errors ] = $this->check( $io , is_string( $marc->getKey() ) , 'insert() returns a Document with a server-assigned _key' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $marc->get( 'name' ) === 'Marc' && $marc->get( 'role' ) === 'admin' , 'returnNew payload is merged into the resulting Document'                   , $passed , $errors ) ;

        // document() + documentExists()
        $fetched = $users->document( $marc->getKey() ) ;
        [ $passed , $errors ] = $this->check( $io , $fetched->getKey() === $marc->getKey() , 'document(key) round-trip'         , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $users->documentExists( $marc->getKey() ) , 'documentExists() true on existing'      , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$users->documentExists( 'missing-key' )   , 'documentExists() false on missing' , $passed , $errors ) ;

        // count()
        $users->insert( [ 'name' => 'Alice' ] ) ;
        $users->insert( [ 'name' => 'Bob'   ] ) ;
        [ $passed , $errors ] = $this->check( $io , $users->count() === 3 , 'count() reports 3 documents' , $passed , $errors ) ;

        // all() / byExample() / firstExample()
        $everyone = iterator_to_array( $users->all() , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $everyone ) === 3 , 'all() yields the 3 inserted documents' , $passed , $errors ) ;

        $admins = iterator_to_array( $users->byExample( [ 'role' => 'admin' ] ) , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $admins ) === 1                         , 'byExample(["role" => "admin"]) yields 1 document' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $admins[ 0 ][ 'name' ] ?? null ) === 'Marc'  , 'byExample(["role" => "admin"]) returns Marc'      , $passed , $errors ) ;

        $allUsers = iterator_to_array( $users->byExample( [] ) , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $allUsers ) === 3 , 'byExample([]) matches every document (no FILTER)' , $passed , $errors ) ;

        $alice = $users->firstExample( [ 'name' => 'Alice' ] ) ;
        [ $passed , $errors ] = $this->check( $io , $alice !== null && $alice->get( 'name' ) === 'Alice' , 'firstExample({name: Alice}) returns the matching Document' , $passed , $errors ) ;

        $missing = $users->firstExample( [ 'role' => 'ghost' ] ) ;
        [ $passed , $errors ] = $this->check( $io , $missing === null , 'firstExample(no match) returns null' , $passed , $errors ) ;

        // update + returnNew
        $updated = $users->update( $marc->getKey() , [ 'role' => 'super-admin' ] , [ 'returnNew' => true ] ) ;
        [ $passed , $errors ] = $this->check( $io , $updated->get( 'role' ) === 'super-admin' , 'update() with returnNew reflects the patch'    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $updated->get( 'name' ) === 'Marc'         , 'update() preserves untouched fields (PATCH)'   , $passed , $errors ) ;

        // replace
        $replaced = $users->replace( $marc->getKey() , [ 'name' => 'Marc Alcaraz' ] , [ 'returnNew' => true ] ) ;
        [ $passed , $errors ] = $this->check( $io , $replaced->get( 'name' ) === 'Marc Alcaraz' , 'replace() returns the full new document'        , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $replaced->get( 'role' ) === null            , 'replace() drops attributes absent of the body' , $passed , $errors ) ;

        // remove + returnOld
        $removed = $users->remove( $marc->getKey() , [ 'returnOld' => true ] ) ;
        [ $passed , $errors ] = $this->check( $io , $removed->get( 'name' ) === 'Marc Alcaraz' , 'remove() with returnOld carries the deleted payload' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$users->documentExists( $marc->getKey() ) , 'document gone after remove()' , $passed , $errors ) ;


        // truncate
        $users->truncate() ;
        [ $passed , $errors ] = $this->check( $io , $users->count() === 0 , 'truncate() empties the collection' , $passed , $errors ) ;

        // Batch ops (saveAll / updateAll / replaceAll / removeAll) — Lot 6.2b.
        // Collection is empty after the truncate above; we work on a fresh
        // set of 3 documents.
        $batchInserted = $users->saveAll
        (
            [
                [ 'name' => 'Eve'     , 'role' => 'auditor' ] ,
                [ 'name' => 'Frank'   , 'role' => 'auditor' ] ,
                [ 'name' => 'Grace'   , 'role' => 'auditor' ] ,
            ] ,
            [ 'returnNew' => true ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , count( $batchInserted ) === 3                                  , 'saveAll() returns one Document per input row'                    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $batchInserted[ 0 ]->get( 'name' ) ?? null ) === 'Eve'  , 'saveAll() with returnNew merges the inserted payload (1st row)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $users->count() === 3                                          , 'count() reflects the 3 batch-inserted documents'                , $passed , $errors ) ;

        $evePatch    = [ '_key' => $batchInserted[ 0 ]->getKey() , 'role' => 'lead-auditor' ] ;
        $frankPatch  = [ '_key' => $batchInserted[ 1 ]->getKey() , 'role' => 'lead-auditor' ] ;
        $batchUpdated = $users->updateAll( [ $evePatch , $frankPatch ] , [ 'returnNew' => true ] ) ;
        [ $passed , $errors ] = $this->check( $io , count( $batchUpdated ) === 2                                           , 'updateAll() returns one Document per patch'                     , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $batchUpdated[ 0 ]->get( 'role' ) ?? null ) === 'lead-auditor' , 'updateAll() with returnNew reflects the patched field'         , $passed , $errors ) ;

        $batchReplaced = $users->replaceAll
        (
            [ [ '_key' => $batchInserted[ 2 ]->getKey() , 'name' => 'Grace Hopper' ] ] ,
            [ 'returnNew' => true ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , count( $batchReplaced ) === 1                                  , 'replaceAll() returns one Document per replacement'              , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $batchReplaced[ 0 ]->get( 'role' ) ?? null ) === null   , 'replaceAll() drops attributes absent from the body'             , $passed , $errors ) ;

        $batchRemoved = $users->removeAll
        (
            [ $batchInserted[ 0 ]->getKey() , $batchInserted[ 1 ]->getKey() ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , count( $batchRemoved ) === 2 , 'removeAll() returns one Document per selector'                 , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $users->count() === 1        , 'count() reflects the 2 batch-removed documents'                , $passed , $errors ) ;

        $batchPartial = $users->removeAll( [ $batchInserted[ 2 ]->getKey() , 'never-existed' ] ) ;
        [ $passed , $errors ] = $this->check( $io , count( $batchPartial ) === 2                                    , 'removeAll() returns 2 entries even when one selector misses'   , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $batchPartial[ 1 ]->get( 'error' ) ?? null ) === true    , 'removeAll() surfaces the per-row error on the missing selector', $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $batchPartial[ 1 ]->get( 'errorNum' ) ?? null ) === 1202 , 'removeAll() error entry carries errorNum=1202 (not found)'     , $passed , $errors ) ;

        $users->truncate() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 5 — EdgeCollection
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep5( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 5 — edge collection: create + inEdges/outEdges/edges' ) ;

        /** @var Database $db */
        $db = $state[ 'testDb' ] ;

        // Vertices for the edge tests.
        $users = $db->collection( 'users' ) ;
        $alice = $users->insert( [ 'name' => 'Alice' ] , [ 'returnNew' => true ] ) ;
        $bob   = $users->insert( [ 'name' => 'Bob' ]   , [ 'returnNew' => true ] ) ;
        $carol = $users->insert( [ 'name' => 'Carol' ] , [ 'returnNew' => true ] ) ;

        $follows = $db->edgeCollection( 'follows' ) ;
        $follows->create() ;

        [ $passed , $errors ] = $this->check( $io , ( $follows->properties()[ 'type' ] ?? null ) === 3 , 'edgeCollection.create() defaults to type EDGE (3)' , $passed , $errors ) ;

        $follows->insert( [ '_from' => $alice->getId() , '_to' => $bob->getId()   ] ) ;
        $follows->insert( [ '_from' => $alice->getId() , '_to' => $carol->getId() ] ) ;
        $follows->insert( [ '_from' => $bob->getId()   , '_to' => $alice->getId() ] ) ;

        $aliceOut = iterator_to_array( $follows->outEdges( $alice->getId() ) , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $aliceOut ) === 2 , 'outEdges(alice) returns 2 edges' , $passed , $errors ) ;

        $aliceIn = iterator_to_array( $follows->inEdges( $alice->getId() ) , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $aliceIn ) === 1 , 'inEdges(alice) returns 1 edge'   , $passed , $errors ) ;

        $aliceBoth = iterator_to_array( $follows->edges( $alice->getId() ) , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $aliceBoth ) === 3 , 'edges(alice) returns 3 edges (both sides)' , $passed , $errors ) ;

        $follows->drop() ;
        $users->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 6 — AQL + Cursor
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep6( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 6 — AQL + Cursor (single batch + lazy multi-batch)' ) ;

        /** @var Database $db */
        $db    = $state[ 'testDb' ] ;
        $items = $db->collection( 'items' ) ;
        $items->create() ;

        for ( $i = 1 ; $i <= 25 ; $i++ )
        {
            $items->insert( [ 'index' => $i ] ) ;
        }

        // Build the query manually because `aql()` only handles `?`
        // value placeholders — collection binds (`@@col`) must come from
        // a hand-built AqlQuery (the typical query-builder output too).
        $cursor = $db->query
        (
            'FOR x IN @@col FILTER x.index > @threshold SORT x.index ASC RETURN x.index' ,
            [ '@col' => 'items' , 'threshold' => 10 ] ,
            [ 'count' => true , 'batchSize' => 5 ] ,
        ) ;

        [ $passed , $errors ] = $this->check( $io , count( $cursor ) === 15 , 'cursor.count() reports 15 (with count:true)' , $passed , $errors ) ;

        $rows = iterator_to_array( $cursor , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $rows ) === 15                 , 'cursor iterates 15 rows across multiple batches' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $rows === range( 11 , 25 )  , 'rows are sorted ascending and correctly filtered' , $passed , $errors ) ;

        // Second query: same filter but with LIMIT and fullCount:true so we
        // can exercise Cursor::getFullCount (Lot 6.0bis). The fullCount flag
        // belongs to the nested `options` sub-object on POST /_api/cursor —
        // top-level cursor options are only count/batchSize/ttl/cache/memoryLimit.
        // Without LIMIT the server reports fullCount = 0.
        $limited = $db->query
        (
            'FOR x IN @@col FILTER x.index > @threshold SORT x.index ASC LIMIT 5 RETURN x.index' ,
            [ '@col' => 'items' , 'threshold' => 10 ] ,
            [ CursorField::OPTIONS => [ CursorField::FULL_COUNT => true ] ] ,
        ) ;

        $limitedRows = iterator_to_array( $limited , false ) ;

        [ $passed , $errors ] = $this->check( $io , $limited->getFullCount() === 15 , 'getFullCount() reports 15 (total before LIMIT, with fullCount:true)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $limitedRows === range( 11 , 15 ) , 'LIMIT 5 yields the first 5 filtered rows'                            , $passed , $errors ) ;

        // Cursor pipeline — map / forEach / reduce / flatMap (Lot 6.3e).
        // Rebuild a fresh cursor since the previous ones were depleted.
        $pipelineCursor = $db->query
        (
            'FOR x IN @@col FILTER x.index >= @threshold SORT x.index ASC LIMIT 3 RETURN x.index' ,
            [ '@col' => 'items' , 'threshold' => 1 ] ,
        ) ;
        $doubled = iterator_to_array( $pipelineCursor->map( static fn( int $row ) : int => $row * 2 ) , false ) ;
        [ $passed , $errors ] = $this->check( $io , $doubled === [ 2 , 4 , 6 ]                       , 'Cursor::map() lazily yields transformed rows from a real cursor'   , $passed , $errors ) ;

        $visited = [] ;
        $db->query
        (
            'FOR x IN @@col FILTER x.index <= @max SORT x.index ASC RETURN x.index' ,
            [ '@col' => 'items' , 'max' => 3 ] ,
        )->forEach( function ( int $row ) use ( &$visited ) : void { $visited[] = $row ; } ) ;
        [ $passed , $errors ] = $this->check( $io , $visited === [ 1 , 2 , 3 ]                       , 'Cursor::forEach() visits every row in order'                       , $passed , $errors ) ;

        $sum = $db->query
        (
            'FOR x IN @@col FILTER x.index <= @max SORT x.index ASC RETURN x.index' ,
            [ '@col' => 'items' , 'max' => 5 ] ,
        )->reduce( static fn( int $acc , int $row ) : int => $acc + $row , 0 ) ;
        [ $passed , $errors ] = $this->check( $io , $sum === 15                                       , 'Cursor::reduce() folds rows (sum 1..5 = 15)'                       , $passed , $errors ) ;

        $flat = $db->query
        (
            'FOR x IN @@col FILTER x.index <= @max SORT x.index ASC RETURN x.index' ,
            [ '@col' => 'items' , 'max' => 3 ] ,
        )->flatMap( static fn( int $row ) : array => [ $row , $row * 10 ] ) ;
        [ $passed , $errors ] = $this->check( $io , $flat === [ 1 , 10 , 2 , 20 , 3 , 30 ]            , 'Cursor::flatMap() spreads array returns one level deep'           , $passed , $errors ) ;

        // explain() + parse() — AQL diagnostics (Lot 6.3d).
        $plan = $db->explain
        (
            'FOR x IN @@col FILTER x.index > @threshold RETURN x.index' ,
            [ '@col' => 'items' , 'threshold' => 10 ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , is_array( $plan[ 'plan' ] ?? null )            , 'explain() returns a plan tree under "plan"'                       , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , is_array( $plan[ 'warnings' ] ?? null )        , 'explain() exposes the optimizer warnings array'                   , $passed , $errors ) ;

        $parsed = $db->parse( 'FOR x IN items RETURN x' ) ;
        [ $passed , $errors ] = $this->check( $io , ( $parsed[ 'parsed' ] ?? null ) === true       , 'parse() reports parsed=true on a syntactically valid query'      , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( 'items' , $parsed[ 'collections' ] ?? [] , true ) , 'parse() lists the referenced collection'   , $passed , $errors ) ;

        $items->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 7 — indexes
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep7( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 7 — indexes (Persistent unique sparse + Ttl + drop)' ) ;

        /** @var Database $db */
        $db    = $state[ 'testDb' ] ;
        $users = $db->collection( 'users' ) ;
        $users->create() ;

        $meta = $users->createIndex
        (
            new PersistentIndex
            (
                fields : [ 'email' ] ,
                unique : true ,
                sparse : true ,
                name   : 'idx_email' ,
            )
        ) ;

        [ $passed , $errors ] = $this->check( $io , ( $meta[ 'type' ] ?? null ) === 'persistent'  , 'createIndex(PersistentIndex) returns type=persistent' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $meta[ 'unique' ] ?? null ) === true        , 'index meta carries unique=true'                       , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $meta[ 'sparse' ] ?? null ) === true        , 'index meta carries sparse=true'                       , $passed , $errors ) ;

        $users->createIndex
        (
            new TtlIndex
            (
                fields      : [ 'createdAt' ] ,
                expireAfter : 3600 ,
                name        : 'idx_ttl' ,
            )
        ) ;

        $indexes = $users->indexes() ;
        // primary + edge (none, document collection) + 2 created = 3 in total
        [ $passed , $errors ] = $this->check( $io , count( $indexes ) === 3 , 'indexes() lists primary + 2 secondary indexes' , $passed , $errors ) ;

        $persistent = null ;
        foreach ( $indexes as $entry )
        {
            if ( ( $entry[ 'type' ] ?? null ) === 'persistent' )
            {
                $persistent = $entry ;
                break ;
            }
        }
        [ $passed , $errors ] = $this->check( $io , $persistent !== null , 'persistent index is present in indexes()' , $passed , $errors ) ;

        if ( $persistent !== null )
        {
            $byHandle = $users->index( (string) $persistent[ 'id' ] ) ;
            [ $passed , $errors ] = $this->check( $io , ( $byHandle[ 'id' ]   ?? null ) === $persistent[ 'id' ] , 'index(full handle) returns the matching entry'  , $passed , $errors ) ;
            [ $passed , $errors ] = $this->check( $io , ( $byHandle[ 'type' ] ?? null ) === 'persistent'         , 'index(full handle) carries the index type'      , $passed , $errors ) ;

            $byBareKey = $users->index( 'idx_email' ) ;
            [ $passed , $errors ] = $this->check( $io , ( $byBareKey[ 'id' ] ?? null ) === $persistent[ 'id' ] , 'index(bare key) resolves the collection prefix' , $passed , $errors ) ;

            $users->dropIndex( (string) $persistent[ 'id' ] ) ;
            [ $passed , $errors ] = $this->check( $io , count( $users->indexes() ) === 2 , 'dropIndex(full handle) removes the index' , $passed , $errors ) ;
        }

        $users->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 8 — error mapping
    // =========================================================================

    /**
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     * @return array
     * @throws ArangoException
     */
    protected function runStep8( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 8 — error mapping (404 / 409)' ) ;

        /** @var Database $db */
        $db    = $state[ 'testDb' ] ;
        $users = $db->collection( 'errors' ) ;
        $users->create() ;

        // 404 — document not found
        try
        {
            $users->document( 'does-not-exist' ) ;
            [ $passed , $errors ] = $this->check( $io , false , 'document(missing) throws an ArangoException' , $passed , $errors ) ;
        }
        catch ( HttpException $e )
        {
            [ $passed , $errors ] = $this->check( $io , $e->getCode() === 404 , 'HttpException carries HTTP status 404'         , $passed , $errors ) ;
            [ $passed , $errors ] = $this->check( $io , $e->errorNum === 1202 , 'HttpException carries errorNum 1202'           , $passed , $errors ) ;
            [ $passed , $errors ] = $this->check( $io , !$e->isSafeToRetry()           , 'document-not-found is NOT safe to retry'      , $passed , $errors ) ;
        }
        catch ( ArangoException $e )
        {
            [ $passed , $errors ] = $this->check( $io , false , 'expected HttpException, got ' . $e::class , $passed , $errors ) ;
        }

        // 409 — duplicate key
        $users->insert( [ '_key' => 'alpha' , 'value' => 1 ] ) ;
        try
        {
            $users->insert( [ '_key' => 'alpha' , 'value' => 2 ] ) ;
            [ $passed , $errors ] = $this->check( $io , false , 'duplicate _key insert throws an ArangoException' , $passed , $errors ) ;
        }
        catch ( ArangoException $e )
        {
            [ $passed , $errors ] = $this->check( $io , $e->getCode() === 409 || $e->getCode() === 1210 || $e->errorNum === 1210 , 'unique constraint violation raises 409 / errorNum 1210' , $passed , $errors ) ;
        }

        $users->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 9 — auth (login / useBearerAuth / useBasicAuth)
    // =========================================================================

    /**
     * Exercises the runtime auth surface added in Lot 6.2c:
     * - `login(user, password)` against `/_open/auth` returns a JWT and
     *   makes the transport carry it on subsequent requests,
     * - a second {@see ArangoClient} built bearer-only (no basic
     *   credentials) can talk to the server through the same JWT,
     * - `useBearerAuth(null)` falls back to the configured basic
     *   credentials,
     * - `useBasicAuth(user, password)` switches the identity at runtime.
     *
     * Skipped (with a single passing assertion explaining why) when no
     * basic credentials are configured — `/_open/auth` requires them.
     *
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array        $state
     * @param int          $passed
     * @param int          $errors
     *
     * @return array
     *
     * @throws ArangoException
     */
    protected function runStep9( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 9 — auth (login / useBearerAuth / useBasicAuth)' ) ;

        $user     = $client->options->user ;
        $password = $client->options->password ;

        if ( $user === null || $password === null )
        {
            [ $passed , $errors ] = $this->check( $io , true , 'auth step skipped: no basic credentials configured (set [arango].user/password)' , $passed , $errors ) ;
            return [ $passed , $errors , $state ] ;
        }

        // login() — exchange credentials for a JWT.
        $jwt = $client->login( $user , $password ) ;
        [ $passed , $errors ] = $this->check( $io , $jwt !== '' , 'login() returns a non-empty JWT' , $passed , $errors ) ;

        // The primary client now carries the JWT — any request should succeed.
        $version = $client->version() ;
        [ $passed , $errors ] = $this->check( $io , is_string( $version[ 'version' ] ?? null )    , 'version() works through the freshly-obtained JWT' , $passed , $errors ) ;

        // Build a second client in bearer-only mode (no basic credentials).
        $bearerOnly = new ArangoClient
        (
            new ClientOptions
            (
                database  : $client->options->database ,
                endpoints : $client->options->endpoints ,
                authType  : AuthType::JWT ,
                token     : $jwt ,
            )
        ) ;

        $databases = $bearerOnly->listDatabases() ;
        [ $passed , $errors ] = $this->check
        (
            $io ,
            in_array( $state[ 'testDbName' ] , $databases , true ) ,
            'bearer-only client can list databases through the JWT' ,
            $passed ,
            $errors
        ) ;

        // useBearerAuth(null) on the primary client → revert to configured basic.
        $client->useBearerAuth( null ) ;
        $versionAfterRevert = $client->version() ;
        [ $passed , $errors ] = $this->check
        (
            $io ,
            is_string( $versionAfterRevert[ 'version' ] ?? null ) ,
            'version() still works after useBearerAuth(null) reverts to basic' ,
            $passed ,
            $errors
        ) ;

        // useBasicAuth(user, password) — explicit basic switch.
        $client->useBasicAuth( $user , $password ) ;
        $versionAfterBasic = $client->version() ;
        [ $passed , $errors ] = $this->check
        (
            $io ,
            is_string( $versionAfterBasic[ 'version' ] ?? null ) ,
            'version() works after explicit useBasicAuth()' ,
            $passed ,
            $errors
        ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 10 — bulk import (Lot 6.2d)
    // =========================================================================

    /**
     * Exercises {@see \oihana\arango\clients\collection\Collection::import()}
     * — the bulk-load fast path that streams JSON Lines to the dedicated
     * `/_api/import` endpoint.
     *
     * - 3 documents imported through a plain `import()` populate the
     *   collection and return `created: 3`, every other counter at 0,
     * - `onDuplicate: ignore` lets the same batch be re-imported with
     *   the duplicates surfaced through the `ignored` counter,
     * - `onDuplicate: update` patches the existing documents (the
     *   server bumps `updated`) and the change is observable through a
     *   subsequent `document()` round-trip,
     * - `onDuplicate: error` + `details: true` surfaces every duplicate
     *   as a server-side error message in {@see ImportResult::$details},
     * - `overwrite: true` truncates the collection before importing —
     *   {@see Collection::count()} confirms the previous content is gone,
     * - an empty input still hits the server (no client-side
     *   short-circuit) and produces a zeroed {@see ImportResult}.
     *
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     *
     * @return array
     *
     * @throws ArangoException
     * @throws JsonException
     */
    protected function runStep10( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 10 — import: bulk JSON Lines + onDuplicate + overwrite + details' ) ;

        /** @var Database $db */
        $db = $state[ 'testDb' ] ;

        $items = $db->collection( 'items' ) ;
        $items->create() ;

        // Baseline bulk import.
        $first = $items->import
        ([
            [ '_key' => 'a' , 'name' => 'Alpha'   , 'price' => 10 ] ,
            [ '_key' => 'b' , 'name' => 'Bravo'   , 'price' => 20 ] ,
            [ '_key' => 'c' , 'name' => 'Charlie' , 'price' => 30 ] ,
        ]) ;
        [ $passed , $errors ] = $this->check( $io , $first->created === 3  , 'import(3 docs) returns created=3'          , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $first->errors  === 0  , 'import(3 docs) returns errors=0'           , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $first->empty   === 0  , 'import(3 docs) returns empty=0'            , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$first->hasErrors()            , 'hasErrors() is false on a clean import'    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count()  === 3 , 'count() reflects the 3 imported documents' , $passed , $errors ) ;

        // onDuplicate: ignore — re-import the same keys, none should land.
        $ignored = $items->import
        (
            [
                [ '_key' => 'a' , 'name' => 'Alpha (dup)'   ] ,
                [ '_key' => 'b' , 'name' => 'Bravo (dup)'   ] ,
            ] ,
            [ 'onDuplicate' => 'ignore' ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , $ignored->ignored >= 2   , 'onDuplicate=ignore bumps the ignored counter (>=2)'     , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $ignored->errors  === 0  , 'onDuplicate=ignore reports no errors'                   , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count()   === 3  , 'collection still holds 3 docs after onDuplicate=ignore' , $passed , $errors ) ;

        // onDuplicate: update — patch the existing documents.
        $updated = $items->import
        (
            [
                [ '_key' => 'a' , 'role' => 'lead'    ] ,
                [ '_key' => 'b' , 'role' => 'support' ] ,
            ] ,
            [ 'onDuplicate' => 'update' ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , $updated->updated >= 2   , 'onDuplicate=update bumps the updated counter (>=2)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $updated->errors  === 0  , 'onDuplicate=update reports no errors'               , $passed , $errors ) ;

        $patched = $items->document( 'a' ) ;
        [ $passed , $errors ] = $this->check( $io , $patched->get( 'role' )  === 'lead'  , 'onDuplicate=update patched the existing payload'             , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $patched->get( 'name' )  === 'Alpha' , 'onDuplicate=update preserves untouched fields (PATCH-like)'  , $passed , $errors ) ;

        // onDuplicate: error + details — surface per-row errors verbatim.
        $detailed = $items->import
        (
            [
                [ '_key' => 'a' , 'name' => 'Alpha (clash)' ] ,
            ] ,
            [ 'onDuplicate' => 'error' , 'details' => true ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , $detailed->hasErrors()                       , 'onDuplicate=error surfaces hasErrors()=true on duplicate' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , count( $detailed->details ) > 0              , 'details=true populates ImportResult::$details'   , $passed , $errors ) ;

        // overwrite=true — truncate before importing.
        $overwritten = $items->import
        (
            [
                [ '_key' => 'x' , 'name' => 'X-Ray'  ] ,
                [ '_key' => 'y' , 'name' => 'Yankee' ] ,
            ] ,
            [ 'overwrite' => true ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , $overwritten->created === 2 , 'overwrite=true import returns created=2'                , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count()      === 2  , 'overwrite=true wiped the previous content (count = 2)'  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$items->documentExists( 'a' )  , 'overwrite=true dropped the legacy keys'                 , $passed , $errors ) ;

        // Empty input — no short-circuit; the request still hits the server.
        $emptyResult = $items->import( [] ) ;
        [ $passed , $errors ] = $this->check( $io , $emptyResult->created === 0 && $emptyResult->errors === 0 , 'import([]) returns a zeroed ImportResult'        , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count()       === 2                               , 'collection content unchanged by an empty import' , $passed , $errors ) ;

        $items->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 11 — streaming transactions (Lot 7.0b)
    // =========================================================================

    /**
     * Exercises the streaming-transaction surface shipped in Lot 7.0b:
     *
     * - `Database::beginTransaction()` returns a `Transaction` whose
     *   `id` is the server-assigned id and whose initial `status()` is
     *   `running`.
     * - `Transaction::exists()` is true for the live trx and false for
     *   a bogus id.
     * - `Transaction::step()` propagates the `x-arango-trx-id` header
     *   transparently: a `Collection::insert()` called inside the
     *   callback is part of the transaction (an outside reader does
     *   NOT see the pending row through the server-side count — this
     *   would only be testable with strict isolation; we instead
     *   verify the row IS visible **inside** the transaction).
     * - `Transaction::commit()` makes the staged write durable.
     * - A second transaction with `abort()` discards its staged write
     *   — the count is unchanged after the abort.
     * - `Database::listTransactions()` includes the running trx while
     *   it is open.
     *
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     *
     * @return array
     *
     * @throws ArangoException
     * @throws Throwable
     */
    protected function runStep11( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 11 — transactions: begin / commit / abort / status / exists / step / list' ) ;

        /** @var Database $db */
        $db    = $state[ 'testDb' ] ;
        $items = $db->collection( 'trx_items' ) ;
        $items->create() ;

        // Commit path.
        $trx = $db->beginTransaction( write : [ 'trx_items' ] ) ;
        [ $passed , $errors ] = $this->check( $io , $trx->id !== ''                              , 'beginTransaction() returns a non-empty server-assigned id'  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $trx->status() === TransactionStatus::RUNNING , 'status() reports running on a freshly-started trx'         , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $trx->exists()                                , 'exists() is true on the live transaction'                   , $passed , $errors ) ;

        // listTransactions: the running trx must show up.
        $listed = $db->listTransactions() ;
        $listedIds = array_column( $listed , 'id' ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $trx->id , $listedIds , true )      , 'listTransactions() includes the running trx'               , $passed , $errors ) ;

        // step() — the insert inside the callback is part of the trx.
        $trx->step( static function () use ( $items ) : void
        {
            $items->insert( [ '_key' => 'in-trx-a' , 'name' => 'Alpha' ] ) ;
            $items->insert( [ '_key' => 'in-trx-b' , 'name' => 'Bravo' ] ) ;
        } ) ;

        // Inside the trx, the rows are visible to a step() reader.
        $insideCount = $trx->step( static fn() : int => $items->count() ) ;
        [ $passed , $errors ] = $this->check( $io , $insideCount === 2                            , 'step() reader sees the 2 staged rows inside the trx'        , $passed , $errors ) ;

        // commit() makes the writes durable; the count outside is now 2 too.
        $commitStatus = $trx->commit() ;
        [ $passed , $errors ] = $this->check( $io , $commitStatus === TransactionStatus::COMMITTED , 'commit() returns COMMITTED'                                 , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $trx->status() === TransactionStatus::COMMITTED , 'status() after commit reports COMMITTED'                   , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count() === 2                          , 'count() outside reflects the 2 committed rows'              , $passed , $errors ) ;

        // Abort path: stage a write, abort, verify the row did NOT land.
        $trx2 = $db->beginTransaction( write : [ 'trx_items' ] ) ;
        $trx2->step( static function () use ( $items ) : void
        {
            $items->insert( [ '_key' => 'should-not-land' , 'name' => 'Charlie' ] ) ;
        } ) ;
        $abortStatus = $trx2->abort() ;
        [ $passed , $errors ] = $this->check( $io , $abortStatus === TransactionStatus::ABORTED   , 'abort() returns ABORTED'                                    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count() === 2                          , 'count() unchanged after abort (staged row discarded)'       , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$items->documentExists( 'should-not-land' )   , 'aborted row never landed in the collection'                 , $passed , $errors ) ;

        // exists() on a well-formed but unknown trx id returns false (404 swallowed).
        // ArangoDB validates the id format server-side — it expects a numeric
        // string — so we pick a value with the right shape but unlikely to exist.
        $bogus = $db->transaction( '999999999999' ) ;
        [ $passed , $errors ] = $this->check( $io , !$bogus->exists()                              , 'exists() is false on an unknown trx id (404 swallowed)'     , $passed , $errors ) ;

        // withTransaction() — commit path.
        $items->truncate() ;

        $resultKey = $db->withTransaction
        (
            callback : static function ( Transaction $t ) use ( $items ) : string
            {
                $items->insert( [ '_key' => 'wt-1' , 'name' => 'Delta' ] ) ;
                $items->insert( [ '_key' => 'wt-2' , 'name' => 'Echo'  ] ) ;
                return $t->id ;
            } ,
            write : [ 'trx_items' ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , $resultKey !== ''                              , 'withTransaction(commit) returns the callback result (trx id)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count() === 2                          , 'withTransaction(commit) persisted the 2 staged rows'           , $passed , $errors ) ;

        // withTransaction() — abort path: callback throws, helper must
        // abort the trx and rethrow the original exception verbatim.
        $items->truncate() ;

        $caught = null ;
        try
        {
            $db->withTransaction
            (
                callback : static function () use ( $items ) : void
                {
                    $items->insert( [ '_key' => 'wt-abort' , 'name' => 'Foxtrot' ] ) ;
                    throw new \RuntimeException( 'rollback please' ) ;
                } ,
                write : [ 'trx_items' ] ,
            ) ;
        }
        catch ( \RuntimeException $e )
        {
            $caught = $e ;
        }

        [ $passed , $errors ] = $this->check( $io , $caught !== null && $caught->getMessage() === 'rollback please' , 'withTransaction(abort) rethrows the original exception'    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $items->count() === 0                                            , 'withTransaction(abort) discarded the staged row (count=0)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$items->documentExists( 'wt-abort' )                           , 'aborted row never landed in the collection'                , $passed , $errors ) ;

        $items->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 12 — named graphs (Lot 7.1a)
    // =========================================================================

    /**
     * Exercises the named-graph surface shipped in Lot 7.1a:
     *
     * - `Database::createGraph()` posts a graph with edge definitions
     *   and returns a `Graph` handle.
     * - `Graph::exists()` / `get()` / `edgeDefinitions()` /
     *   `vertexCollections()` / `edgeCollections()` /
     *   `orphanCollections()` reflect the server state.
     * - `Database::graphs()` / `listGraphs()` include the freshly
     *   created graph.
     * - Vertex collection management (`addVertexCollection` /
     *   `removeVertexCollection`) lives on its own (the collection
     *   becomes an "orphan" until referenced by an edge definition).
     * - Edge definition management (`addEdgeDefinition` /
     *   `replaceEdgeDefinition` / `removeEdgeDefinition`).
     * - `Graph::drop(dropCollections: true)` wipes the graph AND its
     *   underlying vertex/edge collections.
     *
     * The vertex/edge CRUD (insert/get/replace/update/remove via
     * gharial endpoints) lands separately on `GraphVertexCollection` /
     * `GraphEdgeCollection` in Lot 7.1b — Step 12 will be enriched
     * then.
     *
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     *
     * @return array
     *
     * @throws ArangoException
     * @throws RandomException
     */
    protected function runStep12( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 12 — graphs: lifecycle + vertex / edge collection mgmt + edge definition mgmt' ) ;

        /** @var Database $db */
        $db        = $state[ 'testDb' ] ;
        $graphName = 'workplaces_' . bin2hex( random_bytes( 3 ) ) ;

        $employs = new EdgeDefinition( 'employs_' . bin2hex( random_bytes( 2 ) ) , [ 'companies' ] , [ 'people' ] ) ;
        $graph   = $db->createGraph( $graphName , [ $employs ] ) ;

        [ $passed , $errors ] = $this->check( $io , $graph->name === $graphName , 'returned graph carries the requested name'      , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $graph->exists()                     , 'exists() is true on the freshly-created graph'  , $passed , $errors ) ;

        $description = $graph->get() ;
        [ $passed , $errors ] = $this->check( $io , ( $description[ 'name' ] ?? null ) === $graphName , 'get() returns the graph description with the right name' , $passed , $errors ) ;

        $defs = $graph->edgeDefinitions() ;
        [ $passed , $errors ] = $this->check( $io , count( $defs ) === 1                            , 'edgeDefinitions() reports 1 entry'                      , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $defs[ 0 ]->collection === $employs->collection , 'edge definition matches the one registered at creation' , $passed , $errors ) ;

        $vertices = $graph->vertexCollections() ;
        [ $passed , $errors ] = $this->check( $io , in_array( 'companies' , $vertices , true ) && in_array( 'people' , $vertices , true ) , 'vertexCollections() lists both ends of the edge definition' , $passed , $errors ) ;

        $edges = $graph->edgeCollections() ;
        [ $passed , $errors ] = $this->check( $io , in_array( $employs->collection , $edges , true ) , 'edgeCollections() lists the edge collection of the definition' , $passed , $errors ) ;

        // listGraphs() includes our graph.
        $listed = array_column( $db->listGraphs() , 'name' ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $graphName , $listed , true ) , 'listGraphs() includes the newly created graph' , $passed , $errors ) ;

        $graphs    = $db->graphs() ;
        $graphNames = array_map( static fn( Graph $g ) : string => $g->name , $graphs ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $graphName , $graphNames , true )  , 'graphs() returns typed handles including the new graph' , $passed , $errors ) ;

        // Vertex collection management — add an orphan, then remove it.
        $orphanName = 'tags_' . bin2hex( random_bytes( 2 ) ) ;
        $graph->addVertexCollection( $orphanName ) ;
        $afterAdd  = $graph->orphanCollections() ;
        [ $passed , $errors ] = $this->check( $io , in_array( $orphanName , $afterAdd , true ) , "addVertexCollection() registers '$orphanName' as orphan" , $passed , $errors ) ;

        $graph->removeVertexCollection( $orphanName , dropCollection : true ) ;
        $afterRemove = $graph->orphanCollections() ;
        [ $passed , $errors ] = $this->check( $io , !in_array( $orphanName , $afterRemove , true ) , 'removeVertexCollection() drops the orphan from the graph' , $passed , $errors ) ;

        // Edge definition management — add a 2nd one, replace it (broaden from),
        // then remove it (and the underlying edge collection).
        $reportsTo = new EdgeDefinition( 'reports_to_' . bin2hex( random_bytes( 2 ) ) , [ 'people' ] , [ 'people' ] ) ;
        $graph->addEdgeDefinition( $reportsTo ) ;
        [ $passed , $errors ] = $this->check( $io , count( $graph->edgeDefinitions() ) === 2  , 'addEdgeDefinition() bumps the count to 2' , $passed , $errors ) ;

        $broader = new EdgeDefinition( $reportsTo->collection , [ 'people' , 'contractors' ] , [ 'people' ] ) ;
        $graph->replaceEdgeDefinition( $broader ) ;
        $replaced = null ;
        foreach ( $graph->edgeDefinitions() as $candidate )
        {
            if ( $candidate->collection === $reportsTo->collection )
            {
                $replaced = $candidate ;
                break ;
            }
        }
        [ $passed , $errors ] = $this->check( $io , $replaced !== null && in_array( 'contractors' , $replaced->from , true ) , 'replaceEdgeDefinition() broadened the from list'    , $passed , $errors ) ;

        $graph->removeEdgeDefinition( $reportsTo->collection , dropCollection : true ) ;
        [ $passed , $errors ] = $this->check( $io , count( $graph->edgeDefinitions() ) === 1 , 'removeEdgeDefinition() brings the count back to 1' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Vertex / edge CRUD via gharial endpoints (Lot 7.1b)
        // -----------------------------------------------------------------

        $companies = $graph->vertexCollection( 'companies' ) ;
        $people    = $graph->vertexCollection( 'people'    ) ;
        $employs   = $graph->edgeCollection  ( $employs->collection ) ;

        // Insert vertices via gharial.
        $acme = $companies->insert( [ '_key' => 'acme'  , 'name' => 'ACME Corp' ] , [ 'returnNew' => true ] ) ;
        $alice = $people  ->insert( [ '_key' => 'alice' , 'name' => 'Alice'    ] , [ 'returnNew' => true ] ) ;

        [ $passed , $errors ] = $this->check( $io , $acme ->getKey()        === 'acme'      , 'vertexCollection->insert() returns the inserted key (companies)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $alice->getKey()        === 'alice'     , 'vertexCollection->insert() returns the inserted key (people)'    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $acme ->get( 'name' )   === 'ACME Corp' , 'returnNew payload merged into vertex Document'                    , $passed , $errors ) ;

        // Round-trip a vertex through document() / documentExists().
        $fetchedAlice = $people->document( 'alice' ) ;
        [ $passed , $errors ] = $this->check( $io , $fetchedAlice->getKey() === 'alice'                                                      , 'vertexCollection->document(key) round-trip'                            , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $people->documentExists( 'alice' )                                                       , 'vertexCollection->documentExists(alice) true'                          , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$people->documentExists( 'missing-vertex' )                                              , 'vertexCollection->documentExists(missing) false (404 swallowed)'      , $passed , $errors ) ;

        // Insert an edge that respects the edge definition (companies → people).
        $edge = $employs->insert
        (
            [ '_from' => 'companies/acme' , '_to' => 'people/alice' , 'since' => '2024-01-01' ] ,
            [ 'returnNew' => true ] ,
        ) ;
        [ $passed , $errors ] = $this->check( $io , is_string( $edge->getKey() )                          , 'edgeCollection->insert() returns a Document with a server-assigned key' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $edge->getFrom() === 'companies/acme'                  , 'edge _from preserved through gharial'                                  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $edge->getTo()   === 'people/alice'                    , 'edge _to preserved through gharial'                                    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $edge->get( 'since' ) === '2024-01-01'                 , 'edge returnNew payload merged into Document'                           , $passed , $errors ) ;

        // Patch the edge.
        $patched = $employs->update( $edge->getKey() , [ 'since' => '2024-06-01' ] , [ 'returnNew' => true ] ) ;
        [ $passed , $errors ] = $this->check( $io , $patched->get( 'since' ) === '2024-06-01'              , 'edgeCollection->update() reflects the patched field'                    , $passed , $errors ) ;

        // Remove the edge and one vertex (gharial cascade rules guard the rest).
        $employs->remove( $edge->getKey() ) ;
        [ $passed , $errors ] = $this->check( $io , !$employs->documentExists( $edge->getKey() )           , 'edgeCollection->remove() drops the edge'                                , $passed , $errors ) ;

        // Cleanup — drop the graph and its underlying collections.
        $graph->drop( dropCollections : true ) ;
        [ $passed , $errors ] = $this->check( $io , !$graph->exists() , 'exists() is false after drop()' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 13 — ArangoSearch analyzers (Lot 7.2a)
    // =========================================================================

    /**
     * Exercises the ArangoSearch analyzer surface shipped in Lot 7.2a:
     *
     * - `Database::createAnalyzer()` posts an analyzer with typed
     *   options and returns an `Analyzer` handle.
     * - All four V1 must-have types (`identity`, `text`, `norm`,
     *   `stem`) round-trip through `Analyzer::get()` with their
     *   `properties` preserved server-side.
     * - `Analyzer::exists()` distinguishes a created analyzer from
     *   a missing one (404 swallowed cleanly).
     * - `Database::listAnalyzers()` and `Database::analyzers()`
     *   include the user-created entries (each name prefixed with
     *   the test database name server-side, e.g. `mydb::raw`).
     * - `Analyzer::drop()` removes the analyzer, with and without
     *   the `force` flag (force is harmless when no view / inverted
     *   index references the analyzer).
     *
     * The arangosearch View + AQL `SEARCH` live coverage lands
     * separately in Step 14 with Lot 7.2b.
     *
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     *
     * @return array
     *
     * @throws ArangoException
     * @throws RandomException
     */
    protected function runStep13( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 13 — analyzers: identity / text / norm / stem CRUD + listing + drop force' ) ;

        /** @var Database $db */
        $db     = $state[ 'testDb' ] ;
        $dbName = $state[ 'testDbName' ] ;
        $suffix = bin2hex( random_bytes( 3 ) ) ;

        $names =
        [
            'raw'  => 'raw_'    . $suffix ,
            'text' => 'text_'   . $suffix ,
            'norm' => 'norm_'   . $suffix ,
            'stem' => 'stem_'   . $suffix ,
        ] ;

        // -----------------------------------------------------------------
        // Missing analyzer — exists() must swallow the 404.
        // -----------------------------------------------------------------

        [ $passed , $errors ] = $this->check( $io , !$db->analyzer( 'absent_' . $suffix )->exists() , 'exists() is false on a never-created analyzer (404 swallowed)' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Identity — no properties, no features.
        // -----------------------------------------------------------------

        $rawHandle = $db->createAnalyzer( $names[ 'raw' ] , new IdentityAnalyzer() ) ;

        [ $passed , $errors ] = $this->check( $io , $rawHandle->exists() , 'identity analyzer exists() true after create' , $passed , $errors ) ;

        $rawDescription = $rawHandle->get() ;
        [ $passed , $errors ] = $this->check( $io , ( $rawDescription[ 'type' ] ?? null ) === AnalyzerType::IDENTITY           , 'identity analyzer get() echoes type "identity"'      , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $rawDescription[ 'name' ] ?? null ) === $dbName . '::' . $names[ 'raw' ] , 'identity analyzer name is prefixed by db (db::name)' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Text — locale + stemming + features.
        // -----------------------------------------------------------------

        $textHandle = $db->createAnalyzer
        (
            $names[ 'text' ] ,
            new TextAnalyzer( locale : 'en' , stemming : true , accent : false ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
        ) ;

        $textDescription = $textHandle->get() ;
        [ $passed , $errors ] = $this->check( $io , ( $textDescription[ 'type' ]                          ?? null ) === AnalyzerType::TEXT            , 'text analyzer get() echoes type "text"'                          , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $textDescription[ 'properties' ][ 'locale' ]        ?? null ) === 'en'                          , 'text analyzer properties.locale round-trips through the server'  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $textDescription[ 'properties' ][ 'stemming' ]      ?? null ) === true                          , 'text analyzer properties.stemming round-trips'                    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( AnalyzerFeature::FREQUENCY , $textDescription[ 'features' ] ?? [] , true )  , 'text analyzer features include frequency'                         , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Norm — locale + case + accent.
        // -----------------------------------------------------------------

        $normHandle = $db->createAnalyzer
        (
            $names[ 'norm' ] ,
            new NormAnalyzer( locale : 'fr.utf-8' , case : 'lower' , accent : false ) ,
        ) ;

        $normDescription = $normHandle->get() ;
        [ $passed , $errors ] = $this->check( $io , ( $normDescription[ 'type' ]                   ?? null ) === AnalyzerType::NORM , 'norm analyzer get() echoes type "norm"'       , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $normDescription[ 'properties' ][ 'case' ]   ?? null ) === 'lower'            , 'norm analyzer properties.case round-trips'    , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $normDescription[ 'properties' ][ 'accent' ] ?? null ) === false              , 'norm analyzer properties.accent round-trips'  , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Stem — single field.
        // -----------------------------------------------------------------

        $stemHandle = $db->createAnalyzer( $names[ 'stem' ] , new StemAnalyzer( locale : 'en' ) ) ;

        $stemDescription = $stemHandle->get() ;
        [ $passed , $errors ] = $this->check( $io , ( $stemDescription[ 'type' ]                     ?? null ) === AnalyzerType::STEM  , 'stem analyzer get() echoes type "stem"'      , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $stemDescription[ 'properties' ][ 'locale' ]   ?? null ) === 'en'                , 'stem analyzer properties.locale round-trips' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Listing — listAnalyzers() raw + analyzers() typed handles.
        // -----------------------------------------------------------------

        $listed = array_column( $db->listAnalyzers() , 'name' ) ;

        $allCreatedListed = true ;
        foreach ( $names as $local )
        {
            if ( !in_array( $dbName . '::' . $local , $listed , true ) )
            {
                $allCreatedListed = false ;
                break ;
            }
        }
        [ $passed , $errors ] = $this->check( $io , $allCreatedListed , 'listAnalyzers() includes all 4 freshly created analyzers (db-prefixed)' , $passed , $errors ) ;

        $handleNames = array_map( static fn( Analyzer $a ) : string => $a->name , $db->analyzers() ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $dbName . '::' . $names[ 'text' ] , $handleNames , true ) , 'analyzers() returns typed handles including the text analyzer' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Drop — plain + force.
        // -----------------------------------------------------------------

        $rawHandle->drop() ;
        [ $passed , $errors ] = $this->check( $io , !$rawHandle->exists() , 'identity analyzer exists() false after drop()' , $passed , $errors ) ;

        // force=true is harmless when no view / inverted index
        // references the analyzer — the server still honors the drop.
        $textHandle->drop( force : true ) ;
        [ $passed , $errors ] = $this->check( $io , !$textHandle->exists() , 'text analyzer exists() false after drop(force:true) when no reference exists' , $passed , $errors ) ;

        // Cleanup remaining analyzers for the next runs.
        $normHandle->drop() ;
        $stemHandle->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 14 — ArangoSearch views + full-text SEARCH (Lot 7.2b)
    // =========================================================================

    /**
     * Exercises the ArangoSearch view surface shipped in Lot 7.2b
     * plus the resulting full-text query path through AQL `SEARCH`:
     *
     * - `Database::createView()` posts an arangosearch view with
     *   `ArangoSearchLink` typed links and returns a {@see View}
     *   handle.
     * - `View::get()` returns the simple description (4 top-level
     *   fields); `View::properties()` returns the full per-view
     *   configuration including the normalised `links` echo.
     * - `Database::views()` / `listViews()` include the freshly
     *   created view.
     * - The actual indexing path: insert documents in a linked
     *   collection, then run AQL `SEARCH ANALYZER(...)` and
     *   `SEARCH PHRASE(...)` queries through the view; both must
     *   return the expected documents. `BM25()` scoring round-trips
     *   too.
     * - `View::updateProperties()` (PATCH) bumps the
     *   `cleanupIntervalStep`; `properties()` echoes the new value.
     * - `View::drop()` removes the view (the source collection
     *   itself is untouched).
     *
     * ArangoSearch indexes documents asynchronously — the view
     * created here is configured with short `commitIntervalMsec` /
     * `consolidationIntervalMsec` so the queries can be polled
     * inside a short window. {@see waitForSearchHits()} retries the
     * SEARCH up to ~2 seconds before giving up.
     *
     * @param SymfonyStyle $io
     * @param ArangoClient $client
     * @param array $state
     * @param int $passed
     * @param int $errors
     *
     * @return array
     *
     * @throws ArangoException
     * @throws RandomException
     */
    protected function runStep14( SymfonyStyle $io , ArangoClient $client , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 14 — views: arangosearch lifecycle + AQL SEARCH (ANALYZER/PHRASE/BM25) + properties round-trip' ) ;

        /** @var Database $db */
        $db       = $state[ 'testDb' ] ;
        $suffix   = bin2hex( random_bytes( 3 ) ) ;
        $collName = 'articles_'      . $suffix ;
        $anaName  = 'text_en_'       . $suffix ;
        $viewName = 'articles_view_' . $suffix ;

        // -----------------------------------------------------------------
        // Fixture — source collection + 3 documents.
        // -----------------------------------------------------------------

        $collection = $db->collection( $collName ) ;
        $collection->create() ;

        $collection->insert( [ '_key' => 'a1' , 'title' => 'Hello World'        , 'body' => 'First article about greetings' ] ) ;
        $collection->insert( [ '_key' => 'a2' , 'title' => 'Database Tutorial'  , 'body' => 'Learn ArangoDB step by step'    ] ) ;
        $collection->insert( [ '_key' => 'a3' , 'title' => 'Hello Universe'     , 'body' => 'Cosmic salutations'             ] ) ;

        // -----------------------------------------------------------------
        // Fixture — text analyzer (frequency + position + norm features
        // are required for BM25 / PHRASE).
        // -----------------------------------------------------------------

        $analyzer = $db->createAnalyzer
        (
            $anaName ,
            new TextAnalyzer( locale : 'en' , stemming : true ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ,
        ) ;

        // -----------------------------------------------------------------
        // Create the view — links title + body fields on the analyzer.
        // -----------------------------------------------------------------

        $view = $db->createView
        (
            $viewName ,
            links :
            [
                $collName => new ArangoSearchLink
                (
                    fields :
                    [
                        'title' => new ArangoSearchLink( analyzers : [ $anaName ] ) ,
                        'body'  => new ArangoSearchLink( analyzers : [ $anaName ] ) ,
                    ] ,
                ) ,
            ] ,
            options :
            [
                'commitIntervalMsec'        => 100 ,
                'consolidationIntervalMsec' => 100 ,
            ] ,
        ) ;

        [ $passed , $errors ] = $this->check( $io , $view instanceof View                              , 'createView returns a View handle'                   , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $view->exists()                                    , 'view exists() true after create'                    , $passed , $errors ) ;

        $description = $view->get() ;
        [ $passed , $errors ] = $this->check( $io , ( $description[ 'type' ] ?? null ) === 'arangosearch'   , 'view get() echoes type "arangosearch"'              , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $description[ 'name' ] ?? null ) === $viewName        , 'view get() echoes the requested name (not db-prefixed)' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , is_string( $description[ 'globallyUniqueId' ] ?? null ) , 'view get() carries a globallyUniqueId'                    , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Listing — listViews() + views() include the new view.
        // -----------------------------------------------------------------

        $listed = array_column( $db->listViews() , 'name' ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $viewName , $listed , true )                            , 'listViews() includes the newly created view'        , $passed , $errors ) ;

        $viewHandleNames = array_map( static fn( View $v ) : string => $v->name , $db->views() ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $viewName , $viewHandleNames , true )                   , 'views() returns typed handles including the new view' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Properties round-trip — links normalised echo back.
        // -----------------------------------------------------------------

        $properties = $view->properties() ;
        [ $passed , $errors ] = $this->check( $io , isset( $properties[ 'links' ][ $collName ][ 'fields' ][ 'title' ][ 'analyzers' ] ) , 'properties() echoes the links structure with the title field analyzer' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( $anaName , $properties[ 'links' ][ $collName ][ 'fields' ][ 'title' ][ 'analyzers' ] ?? [] , true ) , 'properties() echoes the analyzer name attached to title' , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // SEARCH ANALYZER — 'hello' should hit a1 ("Hello World") + a3 ("Hello Universe").
        // -----------------------------------------------------------------

        $helloHits = $this->waitForSearchHits
        (
            $db ,
            aql
            (
                'FOR doc IN ? SEARCH ANALYZER(doc.title IN TOKENS(?, ?), ?) SORT doc._key RETURN doc._key' ,
                aqlLiteral( $viewName ) ,
                'hello' ,
                $anaName ,
                $anaName ,
            ) ,
            expectedCount : 2 ,
        ) ;

        [ $passed , $errors ] = $this->check( $io , count( $helloHits ) === 2                          , 'SEARCH ANALYZER (title IN TOKENS "hello") returns 2 rows'   , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( 'a1' , $helloHits , true )               , 'SEARCH result includes a1 ("Hello World")'                  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , in_array( 'a3' , $helloHits , true )               , 'SEARCH result includes a3 ("Hello Universe")'               , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !in_array( 'a2' , $helloHits , true )              , 'SEARCH result excludes a2 ("Database Tutorial")'            , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // SEARCH PHRASE — 'Hello World' should hit a1 only.
        // -----------------------------------------------------------------

        $phraseHits = $this->waitForSearchHits
        (
            $db ,
            aql
            (
                'FOR doc IN ? SEARCH PHRASE(doc.title, ?, ?) RETURN doc._key' ,
                aqlLiteral( $viewName ) ,
                'Hello World' ,
                $anaName ,
            ) ,
            expectedCount : 1 ,
        ) ;

        [ $passed , $errors ] = $this->check( $io , $phraseHits === [ 'a1' ]                           , 'SEARCH PHRASE("Hello World") returns exactly a1'           , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // SEARCH + BM25 scoring — both a1 + a3 ranked above 0.
        // -----------------------------------------------------------------

        $bm25Cursor = $db->query
        (
            aql
            (
                'FOR doc IN ? SEARCH ANALYZER(doc.title IN TOKENS(?, ?), ?) SORT BM25(doc) DESC RETURN { key: doc._key, score: BM25(doc) }' ,
                aqlLiteral( $viewName ) ,
                'hello' ,
                $anaName ,
                $anaName ,
            ) ,
        ) ;

        $bm25Rows = iterator_to_array( $bm25Cursor , false ) ;
        [ $passed , $errors ] = $this->check( $io , count( $bm25Rows ) === 2                                          , 'BM25 ranking query returns the same 2 rows'                 , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $bm25Rows[ 0 ][ 'score' ] ?? 0 ) > 0                            , 'BM25 score is strictly positive for the top result'         , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // updateProperties — PATCH bumps cleanupIntervalStep, properties() echoes.
        // -----------------------------------------------------------------

        $view->updateProperties( [ 'cleanupIntervalStep' => 5 ] ) ;
        $reread = $view->properties() ;
        [ $passed , $errors ] = $this->check( $io , ( $reread[ 'cleanupIntervalStep' ] ?? null ) === 5  , 'updateProperties() bumps cleanupIntervalStep + properties() echoes back' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , isset( $reread[ 'links' ][ $collName ] )            , 'updateProperties() did NOT drop the existing link (additive merge)'      , $passed , $errors ) ;

        // -----------------------------------------------------------------
        // Cleanup — drop view + analyzer + source collection.
        // -----------------------------------------------------------------

        $view->drop() ;
        [ $passed , $errors ] = $this->check( $io , !$view->exists()                                    , 'view exists() false after drop()'                           , $passed , $errors ) ;

        $analyzer->drop( force : true ) ;
        $collection->drop() ;

        return [ $passed , $errors , $state ] ;
    }

    /**
     * Polls an AQL `SEARCH` query until it returns the expected
     * number of rows or the deadline elapses (~2 seconds in 10
     * attempts of 200 ms).
     *
     * ArangoSearch indexes documents asynchronously: a freshly
     * inserted doc is not immediately visible to `SEARCH`. The
     * view is configured with short commit / consolidation
     * intervals in Step 14, but a small wait is still needed.
     *
     * @param Database                  $db
     * @param \oihana\arango\clients\aql\AqlQuery $query
     * @param int                       $expectedCount
     *
     * @return array<int, string> The result rows (typically `_key` strings).
     *
     * @throws ArangoException
     */
    private function waitForSearchHits( Database $db , \oihana\arango\clients\aql\AqlQuery $query , int $expectedCount ) : array
    {
        $rows = [] ;

        for ( $attempt = 0 ; $attempt < 10 ; $attempt++ )
        {
            $cursor = $db->query( $query ) ;
            $rows   = iterator_to_array( $cursor , false ) ;

            if ( count( $rows ) === $expectedCount )
            {
                return $rows ;
            }

            usleep( 200_000 ) ; // 200 ms
        }

        return $rows ;
    }
}
