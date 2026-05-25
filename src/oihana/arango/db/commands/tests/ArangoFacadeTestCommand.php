<?php

namespace oihana\arango\db\commands\tests ;

use DI\Container ;
use DI\DependencyException ;
use DI\NotFoundException ;

use InvalidArgumentException ;
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

use oihana\arango\clients\exceptions\ArangoException ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\commands\tests\traits\ArangoClientTestTrait ;
use oihana\arango\clients\cursor\Cursor as NewCursor ;
use oihana\arango\clients\cursor\enums\CursorField ;
use oihana\arango\clients\options\ClientOptions ;

use oihana\arango\db\ArangoDB ;
use oihana\arango\db\enums\ArangoConfig ;
use oihana\arango\db\options\indexes\PersistentIndexOptions ;

use function oihana\core\strings\parseSteps ;

/**
 * Live end-to-end integration test for the high-level
 * {@see ArangoDB} façade (and its embedded
 * {@see \oihana\arango\db\traits\CollectionManagementTrait}).
 *
 * Complementary to `arango:test:clients`:
 *
 * - `arango:test:clients` exercises the low-level new client
 *   (`oihana/arango/clients/`: ArangoClient, Database, Collection,
 *   Cursor, …) directly.
 * - `arango:test:facade` exercises the high-level façade
 *   (`oihana/arango/db/ArangoDB`) which delegates internally to the
 *   new client. The whole point of this command is to verify that the
 *   19 public methods of the façade keep their legacy semantics
 *   byte-identical after the Lot 6.1 switchover.
 *
 * The command never touches production data: it spins up its own
 * ephemeral database (`arangodb_facade_test_<random>`) and drops it on
 * cleanup. The cleanup runs in a `finally` block so the database is
 * dropped even on unexpected exception. Pass `--no-cleanup` to keep
 * the database around for post-mortem inspection.
 *
 * Coverage matrix:
 *
 * | Step | Façade surface |
 * |-----:|----------------|
 * | 0    | (setup) ArangoClient + ephemeral database + ArangoDB façade |
 * | 1    | CollectionManagementTrait: collectionCreate / Exists / Rename / Truncate / Drop |
 * | 2    | CollectionManagementTrait: createIndex (with legacy IndexOptions) + getIndex + getIndexes + dropIndex |
 * | 3    | ArangoDB: prepare / execute / getCursor / getDocuments |
 * | 4    | ArangoDB: getFirstResult / getObject / getResult |
 * | 5    | ArangoDB: streamDocuments |
 * | 6    | ArangoDB: getFoundRows + getExtra (with `fullCount: true` → root/nested option splitting) |
 * | 7    | ArangoDB: invalid AQL surfaces as `oihana\arango\clients\exceptions\ArangoException` |
 *
 * Usage:
 * ```shell
 * bun arango:test:facade
 * bun arango:test:facade --step=1-3
 * bun arango:test:facade --step=6
 * bun arango:test:facade --no-cleanup --endpoint=tcp://127.0.0.1:8529
 * ```
 *
 * @package oihana\arango\db\commands\tests
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class ArangoFacadeTestCommand extends Kernel
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
    public const int MAX_STEP = 8 ;

    /**
     * Name of the `--step` option used to select a subset of steps to run.
     */
    public const string OPTION_STEP = 'step' ;

    /**
     * Prefix of the ephemeral database created for the run.
     */
    private const string TEST_DB_PREFIX = 'arangodb_facade_test_' ;

    /**
     * Configures the current command.
     */
    protected function configure() : void
    {
        $this->configureArangoTestOptions() ;
        $this->addOption
        (
            self::OPTION_STEP , 's' , InputOption::VALUE_OPTIONAL ,
            'Step range to execute (e.g. 1-3, 1,3,5, all)' , 'all' ,
        ) ;
    }

    /**
     * Executes the current command.
     *
     * @throws RandomException
     */
    protected function execute( InputInterface $input , OutputInterface $output ) : int
    {
        [ $io , $timestamp ] = $this->startCommand( $input , $output ) ;
        $io->title( 'arango/db/ArangoDB — Live E2E Façade Smoke Test' ) ;

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
            'arangodb'      => null ,
        ] ;

        try
        {
            try
            {
                [ $passed , $errors , $state ] = $this->runSetup( $io , $client , $input , $state , $passed , $errors ) ;

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
                            [ $passed , $errors , $state ] = $this->{$method}( $io , $state , $passed , $errors ) ;
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
     * Creates the ephemeral database via the low-level client, then
     * instantiates an {@see ArangoDB} façade pointing at it.
     *
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     *
     * @throws ArangoException
     */
    protected function runSetup( SymfonyStyle $io , ArangoClient $client , InputInterface $input , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Setup — create the ephemeral test database + instantiate the ArangoDB façade' ) ;

        $dbName = $state[ 'testDbName' ] ;
        $client->createDatabase( $dbName ) ;
        $state[ 'testDbCreated' ] = true ;

        // Build a config snapshot for the façade — same TOML shape used in
        // production, with the ephemeral database name swapped in and any
        // CLI override re-applied so the façade really talks to the same
        // server as the low-level client used for setup.
        $facadeConfig = $this->arangoConfig ;

        $endpoint = $input->getOption( self::OPTION_ENDPOINT ) ;
        $user     = $input->getOption( self::OPTION_USER     ) ;
        $password = $input->getOption( self::OPTION_PASSWORD ) ;

        if ( is_string( $endpoint ) && $endpoint !== '' ) { $facadeConfig[ ClientOptions::ENDPOINT ] = $endpoint ; }
        if ( is_string( $user     ) && $user     !== '' ) { $facadeConfig[ ClientOptions::USER     ] = $user     ; }
        if ( is_string( $password ) && $password !== '' ) { $facadeConfig[ ClientOptions::PASSWORD ] = $password ; }

        $facadeConfig[ ClientOptions::DATABASE ] = $dbName ;

        $state[ 'arangodb' ] = new ArangoDB( $facadeConfig ) ;

        [ $passed , $errors ] = $this->check( $io , $state[ 'arangodb' ] instanceof ArangoDB , "Instantiated ArangoDB façade on '$dbName'" , $passed , $errors ) ;

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
    // Step 1 — CollectionManagementTrait: create / exists / rename / truncate / drop
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     */
    protected function runStep1( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 1 — Collection lifecycle (collectionCreate / Exists / Rename / Truncate / Drop)' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        [ $passed , $errors ] = $this->check( $io , !$db->collectionExists( 'facade_demo' )                 , 'collectionExists() returns false on missing collection'                  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionCreate( 'facade_demo' )                  , 'collectionCreate() returns true on first create'                         , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$db->collectionCreate( 'facade_demo' )                 , 'collectionCreate() returns false on second create (idempotent)'          , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionExists( 'facade_demo' )                  , 'collectionExists() returns true after create'                            , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionRename( 'facade_demo' , 'facade_demo2' ) , 'collectionRename() returns true'                                         , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$db->collectionExists( 'facade_demo' )                 , 'collectionExists() returns false on old name'                            , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionExists( 'facade_demo2' )                 , 'collectionExists() returns true on new name'                             , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionTruncate( 'facade_demo2' )               , 'collectionTruncate() returns true'                                       , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionDrop( 'facade_demo2' )                   , 'collectionDrop() returns true'                                           , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , !$db->collectionDrop( 'facade_demo2' )                  , 'collectionDrop() returns false on second drop (already gone)'            , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 2 — CollectionManagementTrait: createIndex / getIndex / getIndexes / dropIndex
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     *
     * @throws ArangoException
     */
    protected function runStep2( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 2 — Index ops (createIndex with legacy IndexOptions + getIndex + getIndexes + dropIndex)' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $db->collectionCreate( 'facade_users' ) ;

        // createIndex via legacy PersistentIndexOptions DTO (same shape as the production seeds).
        $opts = new PersistentIndexOptions
        ([
            'fields' => [ 'email' ] ,
            'unique' => true ,
            'sparse' => true ,
            'name'   => 'idx_email' ,
        ]) ;

        $meta = $db->createIndex( 'facade_users' , $opts ) ;

        [ $passed , $errors ] = $this->check( $io , is_array( $meta )                                       , 'createIndex(IndexOptions) returns server metadata'        , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $meta[ 'type'   ] ?? null ) === 'persistent'          , 'createIndex meta carries type=persistent'                  , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $meta[ 'unique' ] ?? null ) === true                  , 'createIndex meta carries unique=true'                      , $passed , $errors ) ;

        $indexes = $db->getIndexes( 'facade_users' ) ;
        [ $passed , $errors ] = $this->check( $io , count( $indexes ) === 2                                 , 'getIndexes() lists primary + the persistent we just created' , $passed , $errors ) ;

        $byHandle = $db->getIndex( 'facade_users' , (string) ( $meta[ 'id' ] ?? '' ) ) ;
        [ $passed , $errors ] = $this->check( $io , ( $byHandle[ 'id' ] ?? null ) === ( $meta[ 'id' ] ?? null ) , 'getIndex(collection, fullHandle) returns the matching entry' , $passed , $errors ) ;

        // createIndex via raw array (alternate code path).
        $byArray = $db->createIndex
        (
            'facade_users' ,
            [
                'type'   => 'persistent' ,
                'fields' => [ 'username' ] ,
                'name'   => 'idx_username' ,
            ]
        ) ;
        [ $passed , $errors ] = $this->check( $io , is_array( $byArray ) && ( $byArray[ 'type' ] ?? null ) === 'persistent' , 'createIndex(array) accepts a raw body shape' , $passed , $errors ) ;

        [ $passed , $errors ] = $this->check( $io , $db->dropIndex( (string) ( $meta[ 'id' ] ?? '' ) )      , 'dropIndex(fullHandle) returns true'                        , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , count( $db->getIndexes( 'facade_users' ) ) === 2       , 'getIndexes() drops back to primary + the array-built index' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 3 — ArangoDB: prepare / execute / getCursor / getDocuments
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     *
     * @throws ArangoException
     */
    protected function runStep3( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 3 — Query: prepare / execute / getCursor / getDocuments' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $this->seedItems( $db , 'facade_items' , 5 ) ;

        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col SORT x.index ASC RETURN x.index' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
        ])->execute() ;

        $firstCursor = $db->getCursor() ;
        [ $passed , $errors ] = $this->check( $io , $firstCursor instanceof NewCursor              , 'getCursor() returns a clients/cursor/Cursor instance' , $passed , $errors ) ;

        $docs = $db->getDocuments() ;
        [ $passed , $errors ] = $this->check( $io , count( $docs ) === 5                            , 'getDocuments() returns the 5 seeded rows'             , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $docs === [ 1 , 2 , 3 , 4 , 5 ]                  , 'getDocuments() preserves the SORT order'              , $passed , $errors ) ;

        // Root option dispatch — pass count:true at the root of prepare();
        // expect the Cursor to report the server-side total via Countable.
        // If execute() failed to keep count:true at the body root (and moved
        // it under options.{...}), the server would silently ignore it and
        // count($cursor) would throw RuntimeException.
        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col SORT x.index ASC RETURN x.index' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
            CursorField::COUNT     => true ,
        ])->execute() ;

        [ $passed , $errors ] = $this->check( $io , count( $db->getCursor() ) === 5                  , 'count($cursor) reports server total (count:true root option dispatch)' , $passed , $errors ) ;

        // Multi-execute — a second prepare()/execute() must replace the
        // previous cursor reference, otherwise stale state could leak
        // between successive queries.
        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col FILTER x.index > 3 SORT x.index ASC RETURN x.index' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
        ])->execute() ;

        [ $passed , $errors ] = $this->check( $io , $db->getCursor() !== $firstCursor               , 'second execute() replaces the previous cursor reference' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $db->getDocuments() === [ 4 , 5 ]                , 'second execute() yields the new filtered result set'     , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 4 — ArangoDB: getFirstResult / getObject / getResult
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     *
     * @throws ArangoException
     */
    protected function runStep4( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 4 — Single result helpers (getFirstResult / getObject / getResult)' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $this->seedItems( $db , 'facade_items' , 3 ) ;

        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col SORT x.index ASC LIMIT 1 RETURN x' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
        ])->execute() ;

        $first = $db->getFirstResult() ;
        [ $passed , $errors ] = $this->check( $io , is_object( $first ) || is_array( $first )               , 'getFirstResult() returns a single row'      , $passed , $errors ) ;

        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col SORT x.index ASC LIMIT 1 RETURN x' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
        ])->execute() ;

        $obj = $db->getObject() ;
        [ $passed , $errors ] = $this->check( $io , is_object( $obj )                                       , 'getObject() returns an object'              , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , ( $obj->index ?? null ) === 1                            , 'getObject() exposes properties of the row'  , $passed , $errors ) ;

        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col SORT x.index ASC RETURN x.index' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
        ])->execute() ;

        $result = $db->getResult() ;
        [ $passed , $errors ] = $this->check( $io , $result === [ 1 , 2 , 3 ]                                , 'getResult() returns the full hydrated list' , $passed , $errors ) ;

        // Explicit INSERT … RETURN NEW round-trip — exercises the façade
        // as a write path (vs the read paths above) and proves that the
        // inserted row is visible through getFirstResult().
        $db->prepare
        ([
            CursorField::QUERY     => 'INSERT @doc INTO @@col RETURN NEW' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' , 'doc' => [ 'index' => 99 , 'label' => 'inserted-explicit' ] ] ,
        ])->execute() ;

        $inserted = $db->getFirstResult() ;
        [ $passed , $errors ] = $this->check( $io , is_object( $inserted ) && ( $inserted->label ?? null ) === 'inserted-explicit' , 'INSERT … RETURN NEW round-trip via getFirstResult()' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 5 — ArangoDB: streamDocuments (Generator)
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     *
     * @throws ArangoException
     */
    protected function runStep5( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 5 — streamDocuments() generator' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $this->seedItems( $db , 'facade_items' , 4 ) ;

        $db->prepare
        ([
            CursorField::QUERY     => 'FOR x IN @@col SORT x.index ASC RETURN x.index' ,
            CursorField::BIND_VARS => [ '@col' => 'facade_items' ] ,
        ])->execute() ;

        $collected = [] ;
        foreach ( $db->streamDocuments() as $row )
        {
            $collected[] = $row ;
        }

        [ $passed , $errors ] = $this->check( $io , $collected === [ 1 , 2 , 3 , 4 ]                         , 'streamDocuments() yields every row in order' , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 6 — ArangoDB: getFoundRows + getExtra (fullCount nesting under options)
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     *
     * @throws ArangoException
     */
    protected function runStep6( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 6 — getFoundRows + getExtra (fullCount nested under options.{...})' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $this->seedItems( $db , 'facade_items' , 10 ) ;

        // fullCount is a NESTED cursor option (must end up under options.fullCount in
        // the request body). The façade is expected to nest it automatically inside
        // prepare()/execute(). Without nesting, the server silently ignores the flag
        // and getFoundRows() returns 0.
        $db->prepare
        ([
            CursorField::QUERY      => 'FOR x IN @@col FILTER x.index > 2 SORT x.index ASC LIMIT 3 RETURN x.index' ,
            CursorField::BIND_VARS  => [ '@col' => 'facade_items' ] ,
            CursorField::FULL_COUNT => true ,
        ])->execute() ;

        $rows = $db->getDocuments() ;
        [ $passed , $errors ] = $this->check( $io , $rows === [ 3 , 4 , 5 ]                                  , 'getDocuments() with LIMIT yields the first 3 rows past the filter' , $passed , $errors ) ;

        $foundRows = $db->getFoundRows() ;
        [ $passed , $errors ] = $this->check( $io , $foundRows === 8                                         , 'getFoundRows() reports 8 (full count, ignoring LIMIT)'              , $passed , $errors ) ;

        $extra = $db->getExtra() ;
        [ $passed , $errors ] = $this->check( $io , is_array( $extra ) && isset( $extra[ 'stats' ] )         , 'getExtra() exposes the server stats payload'                        , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Step 7 — ArangoDB: legacy exception wrapping
    // =========================================================================

    /**
     * @return array{0: int, 1: int, 2: array<string, mixed>}
     */
    protected function runStep7( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 7 — Exception wrapping (new ArangoException → legacy oihana\\arango\\client\\Exception)' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $caught = null ;

        try
        {
            $db->prepare
            ([
                CursorField::QUERY     => 'NOT A VALID AQL QUERY' ,
                CursorField::BIND_VARS => [] ,
            ])->execute() ;
        }
        catch ( ArangoException $e )
        {
            $caught = $e ;
        }
        catch ( Throwable $e )
        {
            $caught = $e ;
        }

        [ $passed , $errors ] = $this->check( $io , $caught instanceof ArangoException                 , 'Invalid AQL surfaces as oihana\\arango\\clients\\exceptions\\ArangoException' , $passed , $errors ) ;
        [ $passed , $errors ] = $this->check( $io , $caught !== null && $caught->getPrevious() !== null      , '$previous chain preserves the underlying clients/ exception'         , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * (Re)creates the given collection and seeds it with `$count` rows of shape `{ index: N }`.
     *
     * @throws ArangoException
     */
    private function seedItems( ArangoDB $db , string $name , int $count ) : void
    {
        $db->collectionDrop( $name ) ;
        $db->collectionCreate( $name ) ;

        for ( $i = 1 ; $i <= $count ; $i++ )
        {
            $db->prepare
            ([
                CursorField::QUERY     => 'INSERT @doc INTO @@col' ,
                CursorField::BIND_VARS => [ '@col' => $name , 'doc' => [ 'index' => $i ] ] ,
            ])->execute() ;
        }
    }

    // =========================================================================
    // Step 8 — ArangoDB façade auth passthrough (login / useBearerAuth / useBasicAuth)
    // =========================================================================

    /**
     * Exercises the auth passthrough exposed on the façade in Lot 6.2c:
     * - `login(user, password)` returns a JWT, the façade now talks to the
     *   server through that JWT,
     * - `useBearerAuth(null)` falls back to the configured basic
     *   credentials,
     * - `useBasicAuth(user, password)` switches identity at runtime.
     *
     * Skipped (with a single passing assertion explaining why) when no
     * basic credentials are configured — `/_open/auth` requires them.
     *
     * @return array
     */
    protected function runStep8( SymfonyStyle $io , array $state , int $passed , int $errors ) : array
    {
        $io->section( 'Step 8 — Façade auth passthrough (login / useBearerAuth / useBasicAuth)' ) ;

        /** @var ArangoDB $db */
        $db = $state[ 'arangodb' ] ;

        $config = $this->arangoConfig ;
        $user   = is_string( $config[ 'user'     ] ?? null ) ? $config[ 'user'     ] : null ;
        $pwd    = is_string( $config[ 'password' ] ?? null ) ? $config[ 'password' ] : null ;

        if ( $user === null || $pwd === null )
        {
            [ $passed , $errors ] = $this->check( $io , true , 'facade auth step skipped: no basic credentials configured' , $passed , $errors ) ;
            return [ $passed , $errors , $state ] ;
        }

        // login() through the façade — exchange credentials for a JWT.
        $jwt = $db->login( $user , $pwd ) ;
        [ $passed , $errors ] = $this->check( $io , is_string( $jwt ) && $jwt !== ''      , 'login() returns a non-empty JWT through the façade'  , $passed , $errors ) ;

        // The façade now carries the JWT for subsequent operations.
        $db->collectionCreate( 'facade_auth_demo' ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionExists( 'facade_auth_demo' ) , 'collectionExists() works after login() through the façade' , $passed , $errors ) ;

        // useBearerAuth(null) → revert to basic.
        $db->useBearerAuth( null ) ;
        $db->collectionTruncate( 'facade_auth_demo' ) ;
        [ $passed , $errors ] = $this->check( $io , $db->collectionExists( 'facade_auth_demo' )   , 'collection still reachable after useBearerAuth(null) reverts to basic' , $passed , $errors ) ;

        // useBasicAuth() — explicit basic switch.
        $db->useBasicAuth( $user , $pwd ) ;
        $db->collectionDrop( 'facade_auth_demo' ) ;
        [ $passed , $errors ] = $this->check( $io , !$db->collectionExists( 'facade_auth_demo' )  , 'drop works after explicit useBasicAuth() through the façade'         , $passed , $errors ) ;

        return [ $passed , $errors , $state ] ;
    }
}
