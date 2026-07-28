<?php

namespace tests\oihana\arango\integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\controllers\EdgesController;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\HttpStatusCode;

use PHPUnit\Framework\Attributes\Group;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

use org\schema\constants\Schema;

use tests\oihana\arango\controllers\mocks\ScopedEdgesController;

use function oihana\init\initConfig;

/**
 * Live validation of the **authorization seat** of {@see EdgesController} against a
 * real arangod — the half a double cannot give, because what matters here is not
 * the status returned but whether an edge was **written** or **removed**.
 *
 * The seeded graph, where `__from_scope` / `__to_scope` / `__scope` carry the
 * consumer rule of {@see ScopedEdgesController}:
 *
 * ```
 * users/visible  (in scope)     roles/visible  (in scope)
 * users/hidden   (out)          roles/hidden   (out)
 * edges : visible→visible (in scope) , visible→roles/hidden (out of the edge scope)
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class EdgesControllerScopeIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_edges_scope_it' ;

    private const string EDGES = 'user_has_roles' ;
    private const string ROLES = 'roles' ;
    private const string USERS = 'users' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection    ( self::USERS )->create() ;
        $db->collection    ( self::ROLES )->create() ;
        $db->edgeCollection( self::EDGES )->create() ;

        $db->collection( self::USERS )->insert( [ '_key' => 'visible' , '__from_scope' => 'visible' ] ) ;
        $db->collection( self::USERS )->insert( [ '_key' => 'hidden'  , '__from_scope' => 'hidden'  ] ) ;

        $db->collection( self::ROLES )->insert( [ '_key' => 'visible' , '__to_scope' => 'visible' ] ) ;
        $db->collection( self::ROLES )->insert( [ '_key' => 'hidden'  , '__to_scope' => 'hidden'  ] ) ;

        // An existing edge the edge scope hides : it must survive every deletion.
        $db->edgeCollection( self::EDGES )->insert
        ([
            '_from'   => 'users/visible' ,
            '_to'     => 'roles/hidden'  ,
            '__scope' => 'hidden'        ,
        ]) ;
    }

    // ---- the write surface ------------------------------------------------

    /**
     * A source the scope hides answers 404 — and, the assertion that matters,
     * **no edge is written**. Before the seat, this link was created.
     *
     * @throws ArangoException
     */
    public function testAHiddenSourceCannotBeLinked() :void
    {
        $before = $this->edgeCount() ;

        $response = $this->post( 'hidden' , 'visible' ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
        $this->assertSame( $before , $this->edgeCount() , 'an edge was created from a document the scope hides' ) ;
    }

    /**
     * @throws ArangoException
     */
    public function testAHiddenTargetCannotBeLinked() :void
    {
        $before = $this->edgeCount() ;

        $response = $this->post( 'visible' , 'hidden' ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
        $this->assertSame( $before , $this->edgeCount() , 'an edge was created towards a document the scope hides' ) ;
    }

    /**
     * The other half : inside the scope the controller still works.
     *
     * @throws ArangoException
     */
    public function testTwoVisibleDocumentsAreLinked() :void
    {
        $response = $this->post( 'visible' , 'visible' ) ;

        $this->assertSame( HttpStatusCode::CREATED , $response->getStatusCode() ) ;
        $this->assertSame( 1 , $this->edgeCount( 'users/visible' , 'roles/visible' ) ) ;
    }

    /**
     * The trap the design avoids: because the creation is left unhooked, its
     * uniqueness check stays blind to no scope — a second `POST` still conflicts
     * instead of writing a duplicate.
     *
     * @throws ArangoException
     */
    public function testASecondPostConflictsRatherThanDuplicating() :void
    {
        $this->post( 'visible' , 'visible' ) ;
        $response = $this->post( 'visible' , 'visible' ) ;

        $this->assertSame( HttpStatusCode::CONFLICT , $response->getStatusCode() ) ;
        $this->assertSame( 1 , $this->edgeCount( 'users/visible' , 'roles/visible' ) ) ;
    }

    // ---- the deletion -----------------------------------------------------

    /**
     * An edge outside the **edge** scope is reported missing and survives — the
     * probe and the deletion share one init, so they cannot disagree.
     *
     * @throws ArangoException
     */
    public function testAHiddenEdgeIsReportedMissingAndSurvives() :void
    {
        $response = $this->delete( 'visible' , 'hidden' ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
        $this->assertSame( 1 , $this->edgeCount( 'users/visible' , 'roles/hidden' ) , 'an edge the scope hides was deleted' ) ;
    }

    /**
     * @throws ArangoException
     */
    public function testAVisibleEdgeIsDeleted() :void
    {
        $this->post( 'visible' , 'visible' ) ;

        $response = $this->delete( 'visible' , 'visible' ) ;

        $this->assertSame( HttpStatusCode::OK , $response->getStatusCode() ) ;
        $this->assertSame( 0 , $this->edgeCount( 'users/visible' , 'roles/visible' ) ) ;
    }

    // ---- non-regression ---------------------------------------------------

    /**
     * The same call through a **plain** controller — no subclass, no scope — links
     * the hidden source, exactly as it did before the seat existed. The seat is
     * opt-in, and this is what proves it.
     *
     * @throws ArangoException
     */
    public function testAPlainControllerIsUnchanged() :void
    {
        $response = $this->post( 'hidden' , 'visible' , scoped: false ) ;

        $this->assertSame( HttpStatusCode::CREATED , $response->getStatusCode() ) ;
        $this->assertSame( 1 , $this->edgeCount( 'users/hidden' , 'roles/visible' ) ) ;
    }

    // ---- harness ----------------------------------------------------------

    /**
     * A controller over live models — the consumer subclass by default, the plain
     * lib class when `$scoped` is false.
     */
    private function controller( bool $scoped = true ) :EdgesController
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $users = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::USERS , AQL::LAZY => false ] ) ;
        $roles = new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::ROLES , AQL::LAZY => false ] ) ;

        $container->set( 'edges.service' , new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::EDGES ,
            AQL::FROM        => $users ,
            AQL::TO          => $roles ,
            AQL::LAZY        => false ,
        ]) ) ;

        $container->set( 'from.service' , $users ) ;
        $container->set( 'to.service'   , $roles ) ;

        $init =
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            EdgesController::EDGES  => 'edges.service' ,
            EdgesController::FROM   => 'from.service' ,
            EdgesController::TO     => 'to.service' ,
        ] ;

        return $scoped ? new ScopedEdgesController( $container , $init ) : new EdgesController( $container , $init ) ;
    }

    private function delete( string $from , string $to ) :Response
    {
        return $this->controller()->delete
        (
            $this->request( 'DELETE' ) ,
            $this->response() ,
            [ Schema::ID => $from , EdgesController::TARGET_ID => $to ]
        ) ;
    }

    /**
     * Counts edges straight from the database — a status can lie about a write,
     * the collection cannot.
     *
     * @throws ArangoException
     */
    private function edgeCount( ?string $from = null , ?string $to = null ) :int
    {
        $filter = $from === null ? '' : 'FILTER doc._from == "' . $from . '" && doc._to == "' . $to . '" ' ;

        foreach ( self::$db->query( 'RETURN LENGTH(FOR doc IN ' . self::EDGES . ' ' . $filter . 'RETURN 1)' ) as $row )
        {
            return (int) $row ;
        }

        return -1 ;
    }

    /**
     * Creates the edge, stamped with the attribute the edge scope reads — a
     * consumer that narrows a collection is the one that fills the attribute it
     * narrows on, so an edge created inside the scope stays inside it.
     */
    private function post( string $from , string $to , bool $scoped = true ) :Response
    {
        return $this->controller( $scoped )->post
        (
            $this->request( 'POST' )->withParsedBody( [ '__scope' => 'visible' ] ) ,
            $this->response() ,
            [ Schema::ID => $from , EdgesController::TARGET_ID => $to ]
        ) ;
    }

    private function request( string $method ) :Request
    {
        return new ServerRequestFactory()->createServerRequest( $method , '/' ) ;
    }

    private function response() :Response
    {
        return new ResponseFactory()->createResponse() ;
    }
}
