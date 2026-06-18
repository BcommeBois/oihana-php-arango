<?php

namespace tests\oihana\arango\controllers ;

use DI\Container ;

use oihana\arango\controllers\FederatedSearchController ;
use oihana\arango\enums\Arango ;
use oihana\arango\search\FederatedSearch ;

use oihana\auth\CapabilityEnforcerInterface ;
use oihana\auth\enums\CapabilityAction ;
use oihana\auth\PermissionSubjectResolverInterface ;

use oihana\controllers\enums\ControllerParam ;
use oihana\enums\http\RequestAttribute ;
use oihana\enums\Output ;

use Psr\Http\Message\ServerRequestInterface as Request ;

use RuntimeException ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

use Slim\Factory\AppFactory ;
use Slim\Psr7\Factory\ResponseFactory ;
use Slim\Psr7\Factory\ServerRequestFactory ;

/**
 * Unit coverage for the {@see FederatedSearchController} — the read-only HTTP
 * entry point of the federated search engine. The engine is replaced by a
 * capturing double so the assertions target the controller's own logic: the
 * prepared engine `$init`, the authorizer injection under `Arango::AUTHORIZER`,
 * the total exposed through `foundRows()`, the response shape and the failure
 * branch.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz (ekameleon)
 */
#[CoversClass( FederatedSearchController::class )]
final class FederatedSearchControllerTest extends TestCase
{
    /**
     * Builds a controller over a real (minimal) Slim app + container, wiring the
     * given engine double as a service. Extra container services (an enforcer /
     * resolver) can be registered through `$services`.
     *
     * @param FederatedSearch          $engine
     * @param array<string,mixed>      $services
     * @param array<string,mixed>      $init
     *
     * @return FederatedSearchController
     */
    private function makeController( FederatedSearch $engine , array $services = [] , array $init = [] ) :FederatedSearchController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( 'search.engine' , $engine ) ;

        foreach ( $services as $id => $service )
        {
            $container->set( $id , $service ) ;
        }

        $defaults =
        [
            ControllerParam::APP        => $app ,
            ControllerParam::ROUTER     => $app->getRouteCollector()->getRouteParser() ,
            FederatedSearchController::ENGINE => 'search.engine' ,
        ] ;

        return new FederatedSearchController( $container , [ ...$defaults , ...$init ] ) ;
    }

    /**
     * Builds a GET request carrying the given query params (and optionally an
     * authenticated user id).
     *
     * @param array<string,string> $query
     * @param string|null          $userId
     *
     * @return Request
     */
    private function request( array $query = [] , ?string $userId = null ) :Request
    {
        $request = ( new ServerRequestFactory() )->createServerRequest( 'GET' , '/search' )->withQueryParams( $query ) ;

        if ( $userId !== null )
        {
            $request = $request->withAttribute( RequestAttribute::USER_ID , $userId ) ;
        }

        return $request ;
    }

    public function testSearchPassesThePreparedInitToTheEngine() :void
    {
        $engine     = new FakeFederatedSearch() ;
        $controller = $this->makeController( $engine ) ;

        $controller->search( null , null , [] ,
        [
            ControllerParam::SEARCH => 'dupont' ,
            ControllerParam::LIMIT  => 10 ,
            ControllerParam::OFFSET => 5 ,
            ControllerParam::SKIN   => 'compact' ,
        ]) ;

        $this->assertSame( 'dupont'  , $engine->captured[ Arango::SEARCH ] ) ;
        $this->assertSame( 10        , $engine->captured[ Arango::LIMIT  ] ) ;
        $this->assertSame( 5         , $engine->captured[ Arango::OFFSET ] ) ;
        $this->assertSame( 'compact' , $engine->captured[ Arango::SKIN   ] ) ;
    }

    public function testSearchReadsTheQueryParamsFromTheRequest() :void
    {
        $engine     = new FakeFederatedSearch() ;
        $controller = $this->makeController( $engine ) ;

        $controller->search( $this->request( [ ControllerParam::SEARCH => 'dupont' , ControllerParam::LIMIT => '7' ] ) , null , [] , [] ) ;

        $this->assertSame( 'dupont' , $engine->captured[ Arango::SEARCH ] ) ;
        $this->assertSame( 7        , $engine->captured[ Arango::LIMIT  ] ) ;
    }

    public function testSearchReturnsTheEngineRowsThroughTheNullResponseBranch() :void
    {
        $rows   = [ [ Arango::COLLECTION => 'customers' , FederatedSearch::SCORE => 4.2 , FederatedSearch::DOCUMENT => [ '_key' => 'a' ] ] ] ;
        $engine = new FakeFederatedSearch( $rows , 47 ) ;

        $result = $this->makeController( $engine )->search( null , null , [] , [ ControllerParam::SEARCH => 'dupont' ] ) ;

        $this->assertSame( $rows , $result ) ;
    }

    public function testSearchWrapsTheResponseWithTheTotal() :void
    {
        $rows   = [ [ Arango::COLLECTION => 'customers' , FederatedSearch::SCORE => 4.2 , FederatedSearch::DOCUMENT => [ '_key' => 'a' ] ] ] ;
        $engine = new FakeFederatedSearch( $rows , 47 ) ;

        $response = ( new ResponseFactory() )->createResponse() ;
        $result   = $this->makeController( $engine )->search( $this->request() , $response , [] , [ ControllerParam::SEARCH => 'dupont' ] ) ;

        $payload = json_decode( (string) $result->getBody() , true ) ;

        $this->assertSame( 47 , $payload[ Output::TOTAL ] ) ;
        $this->assertSame( 1  , $payload[ Output::COUNT ] ) ;
        $this->assertSame( $rows , $payload[ Output::RESULT ] ) ;
    }

    public function testSearchInjectsTheAuthorizerWhenEnforcerAndResolverArePresent() :void
    {
        $engine = new FakeFederatedSearch() ;

        $controller = $this->makeController( $engine ,
        [
            CapabilityEnforcerInterface::class        => new FakeEnforcer() ,
            PermissionSubjectResolverInterface::class => new FakeResolver() ,
        ]) ;

        $controller->search( $this->request( [ ControllerParam::SEARCH => 'dupont' ] , 'user-1' ) , null , [] , [] ) ;

        $this->assertArrayHasKey( Arango::AUTHORIZER , $engine->captured ) ;
        $this->assertIsCallable( $engine->captured[ Arango::AUTHORIZER ] ) ;
    }

    public function testSearchOmitsTheAuthorizerWhenNoEnforcerIsRegistered() :void
    {
        $engine = new FakeFederatedSearch() ;

        $this->makeController( $engine )->search( $this->request( [ ControllerParam::SEARCH => 'dupont' ] , 'user-1' ) , null , [] , [] ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $engine->captured ) ;
    }

    public function testSearchReturnsAnEmptySetWithoutAnEngine() :void
    {
        $engine     = new FakeFederatedSearch() ;
        $controller = $this->makeController( $engine , [] , [ FederatedSearchController::ENGINE => 'missing.engine' ] ) ;

        $this->assertSame( [] , $controller->search( null , null , [] , [ ControllerParam::SEARCH => 'dupont' ] ) ) ;
    }

    public function testSearchResolvesTheEngineFromAnInstance() :void
    {
        $rows   = [ [ Arango::COLLECTION => 'customers' , FederatedSearch::SCORE => 1.0 , FederatedSearch::DOCUMENT => [ '_key' => 'a' ] ] ] ;
        $engine = new FakeFederatedSearch( $rows ) ;

        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $controller = new FederatedSearchController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            FederatedSearchController::ENGINE => $engine , // an instance, not a service id
        ]) ;

        $this->assertSame( $rows , $controller->search( null , null , [] , [ ControllerParam::SEARCH => 'dupont' ] ) ) ;
    }

    public function testSearchFailsGracefullyWhenTheEngineThrows() :void
    {
        $engine   = new FakeFederatedSearch( throws : true ) ;
        $response = ( new ResponseFactory() )->createResponse() ;

        $result = $this->makeController( $engine )->search( $this->request() , $response , [] , [ ControllerParam::SEARCH => 'dupont' ] ) ;

        $this->assertSame( 500 , $result->getStatusCode() ) ;
    }
}

/**
 * A capturing {@see FederatedSearch} double: records the `$init` it receives,
 * returns canned rows + total, or throws on demand. `createMock()` chokes on
 * this hierarchy, so a real subtype is used (the parent constructor tolerates a
 * bare container).
 */
final class FakeFederatedSearch extends FederatedSearch
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @param int                            $total
     * @param bool                           $throws
     */
    public function __construct( private array $rows = [] , private int $total = 0 , private bool $throws = false )
    {
        // bypass the parent initialisation : the engine is never run for real here
    }

    /**
     * @var array<string,mixed> The last `$init` passed to {@see search()}.
     */
    public array $captured = [] ;

    public function search( array $init = [] ) :array
    {
        $this->captured = $init ;

        if ( $this->throws )
        {
            throw new RuntimeException( 'boom' ) ;
        }

        return $this->rows ;
    }

    public function foundRows() :int
    {
        return $this->total ;
    }
}

/**
 * A no-op {@see CapabilityEnforcerInterface} granting everything — enough to let
 * the controller build a non-null permission authorizer.
 */
final class FakeEnforcer implements CapabilityEnforcerInterface
{
    public function check( string $userId , string $object , string $capability , string $mode , string $actionPrefix = CapabilityAction::PARAM ) :bool
    {
        return true ;
    }

    public function enforceObjectAction( string $userId , string $object , string $action ) :bool
    {
        return true ;
    }

    public function has( string $userId , string $object , string $capability , string $actionPrefix = CapabilityAction::PARAM ) :bool
    {
        return true ;
    }

    public function isDenied( string $userId , string $object , string $capability , string $actionPrefix = CapabilityAction::PARAM ) :bool
    {
        return false ;
    }
}

/**
 * A {@see PermissionSubjectResolverInterface} resolving every subject to a
 * dummy `(object, action)` couple.
 */
final class FakeResolver implements PermissionSubjectResolverInterface
{
    public function resolve( string $subject ) :?array
    {
        return [ 'object' => '/search' , 'action' => 'list' ] ;
    }

    public function getMap() :array
    {
        return [] ;
    }
}
