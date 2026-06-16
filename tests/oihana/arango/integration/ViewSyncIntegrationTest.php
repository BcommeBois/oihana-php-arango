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

use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the View diff & sync primitives (Lot M1): a declared
 * View drifts when its `AQL::VIEW` block evolves (the new field is silently
 * not indexed — the motivating bug), `viewDiff()` detects it, `viewSync()`
 * repairs it through `updateProperties()` (and a removed field really does
 * unindex, PATCH replaces the collection link wholesale), and an unknown
 * analyzer resolves to an `INVALID` report instead of a broken View.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ViewSyncIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_viewsync_it' ;

    private const string COLLECTION = 'places' ;

    private const string VIEW = 'placesView' ;

    /**
     * Seeds three documents — the View itself is provisioned later, by the
     * first model construction.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $places = $db->collection( self::COLLECTION ) ;
        $places->create() ;

        $places->insert( [ 'label' => 'A' , 'name' => 'Scierie de la Loire' , 'description' => 'le bois de sapin' ] ) ;
        $places->insert( [ 'label' => 'B' , 'name' => 'Atelier du bois'     , 'description' => 'menuiserie fine'  ] ) ;
        $places->insert( [ 'label' => 'C' , 'name' => 'Ferronnerie'         , 'description' => 'le metal forge'   ] ) ;
    }

    /**
     * A Documents model wired to the disposable database, declaring the
     * given searched fields on the shared View.
     *
     * @throws TomlError
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    private function model( array $fields , string $view = self::VIEW , string $analyzer = 'text_fr' ) :Documents
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
            AQL::VIEW        =>
            [
                Search::NAME     => $view ,
                Search::ANALYZER => $analyzer ,
                Search::FIELDS   => $fields ,
            ] ,
        ]) ;
    }

    /**
     * Polls until the search returns the expected match count (eventual
     * consistency of the View indexing).
     *
     * @throws ArangoException When the count is still wrong after ~15 seconds.
     */
    private function waitForSearchCount( Documents $model , string $term , int $expected ) :void
    {
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            if ( $model->count( [ Arango::SEARCH => $term ] ) === $expected )
            {
                return ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        throw new ArangoException( sprintf( "The search '%s' never reached %d matches." , $term , $expected ) ) ;
    }

    /**
     * The full M1 lifecycle on a real server:
     *
     * 1. the first model (name only) provisions the View and is in sync ;
     * 2. the declaration gains `description` → the field is silently not
     *    indexed (the S4a drift), `viewDiff()` says DRIFTED with the exact
     *    change line ;
     * 3. `viewSync()` repairs through `updateProperties()` → in sync, and the
     *    search now finds the description-only document ;
     * 4. reverting to the narrow declaration and syncing again really does
     *    unindex the removed field (PATCH replaces the link wholesale).
     */
    public function testViewDiffAndSyncLifecycle() :void
    {
        // 1 — provision (lazy) and verify the in-sync baseline.

        $narrow = $this->model( [ 'name' => 1 ] ) ;

        $this->assertTrue( $narrow->viewExists( self::VIEW ) , 'The model construction must create the declared View.' ) ;
        $this->waitForSearchCount( $narrow , 'bois' , 1 ) ; // B (name) only — A.description is not indexed
        $this->assertSame( DiffStatus::IN_SYNC , $narrow->viewDiff()->status ) ;

        // 2 — the declaration evolves : drift detected, field silently unsearchable.

        $wide = $this->model( [ 'name' => 1 , 'description' => 1 ] ) ;

        $this->assertSame( 0 , $wide->count( [ Arango::SEARCH => 'sapin' ] ) , 'The new field must NOT be searchable before the sync (the drift).' ) ;

        $report = $wide->viewDiff() ;
        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( 'places.fields.description : not indexed on the server' , $report->changes ) ;

        // 3 — sync repairs : the report is applied, the View converges, the search finds A.

        $report = $wide->viewSync() ;
        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertTrue( $report->applied ) ;

        $this->waitForSearchCount( $wide , 'sapin' , 1 ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $wide->viewDiff()->status ) ;

        $rows   = $wide->list( [ Arango::SEARCH => 'sapin' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;
        $this->assertSame( [ 'A' ] , $labels ) ;

        // 4 — narrowing the declaration and syncing unindexes the removed field.

        $this->assertSame( DiffStatus::DRIFTED , $narrow->viewDiff()->status , 'The narrow declaration must now drift (extra indexed field).' ) ;

        $report = $narrow->viewSync() ;
        $this->assertTrue( $report->applied ) ;

        $this->waitForSearchCount( $narrow , 'sapin' , 0 ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $narrow->viewDiff()->status ) ;
    }

    /**
     * A field declared with the identity analyzer (the link default) must
     * round-trip idempotently: the server stores such a field as `{}`, so the
     * declaration has to omit its `analyzers` too — otherwise the declared
     * `["identity"]` never matches the stored `{}` and the View drifts forever.
     *
     * Mixing an identity field with a `text_fr` one, syncing, and diffing must
     * report ZERO changes, and a second sync/diff must not resurrect the drift.
     */
    public function testIdentityFieldDoesNotDrift() :void
    {
        $model = $this->model
        (
            [
                'name' => 1 ,                                              // inherits text_fr
                'code' => [ Search::ANALYZER => BuiltinAnalyzer::IDENTITY ] , // exact token match
            ] ,
            view : 'identityView' ,
        ) ;

        // 1 — provision (lazy) : the View exists and is immediately in sync,
        // with no false drift on the identity field.

        $this->assertTrue( $model->viewExists( 'identityView' ) ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $model->viewDiff()->status , 'An identity field must not drift right after provisioning.' ) ;

        // 2 — an explicit sync stays a no-op (nothing to repair).

        $this->assertSame( DiffStatus::IN_SYNC , $model->viewSync()->status ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $model->viewDiff()->status , 'The drift must not reappear after a sync.' ) ;

        // 3 — the empty `{}` node still indexes the field : the identity field
        // is token-exact searchable (proves `{}` was not silently dropped).

        self::$db->collection( self::COLLECTION )->insert( [ 'label' => 'D' , 'code' => 'ZX-99' ] ) ;
        $this->waitForSearchCount( $model , 'ZX-99' , 1 ) ;
    }

    /**
     * An unknown analyzer resolves to an INVALID report (with the analyzer
     * named in the changes) and never creates a broken View.
     */
    public function testUnknownAnalyzerIsReportedAsInvalid() :void
    {
        $model = $this->model( [ 'name' => 1 ] , view : 'badView' , analyzer : 'no_such_analyzer' ) ;

        $this->assertFalse( $model->viewExists( 'badView' ) , 'The defensive lazy provisioning must not leave a broken View behind.' ) ;

        $report = $model->viewDiff() ;
        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( "analyzer 'no_such_analyzer' not found on the server" , $report->changes ) ;

        $this->assertFalse( $model->viewSync()->applied , 'An INVALID report must never be applied.' ) ;

        $this->assertTrue ( $model->analyzerExists( 'text_fr' ) ) ;
        $this->assertFalse( $model->analyzerExists( 'no_such_analyzer' ) ) ;
    }
}
