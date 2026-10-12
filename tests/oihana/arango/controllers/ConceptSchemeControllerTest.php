<?php

namespace tests\oihana\arango\controllers;

use Throwable;

use DI\Container;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\controllers\ConceptSchemeController;
use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\FilterQuantifier;

use oihana\controllers\enums\ControllerParam;

use oihana\enums\Output;
use oihana\enums\http\HttpStatusCode;

use org\schema\constants\Schema;

use xyz\oihana\schema\constants\Oihana;
use xyz\oihana\schema\thesaurus\ConceptScheme;

use PHPUnit\Framework\Attributes\CoversClass;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Slim\Factory\AppFactory;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Coverage for {@see ConceptSchemeController} — assembles a SKOS
 * {@see ConceptScheme} whose `hasTopConcept` is the thesaurus roots.
 *
 * The model is a hand-written {@see MockDocuments} double whose `list()` is
 * overridden to capture the init and return canned roots.
 *
 * @package tests\oihana\arango\controllers
 * @author  Marc Alcaraz
 */
#[CoversClass( ConceptSchemeController::class )]
final class ConceptSchemeControllerTest extends ControllerTestCase
{
    /**
     * A MockDocuments whose list() captures its init and returns canned roots.
     */
    private function model( array $roots , ?Throwable $throws = null ) :MockDocuments
    {
        return new class( $roots , $throws ) extends MockDocuments
        {
            public array $listInit = [] ;

            public function __construct( private array $roots , private ?Throwable $throws ) { parent::__construct( 'categories' ) ; }

            public function list( array $init = [] ) :array
            {
                $this->listInit = $init ;
                if ( $this->throws !== null )
                {
                    throw $this->throws ;
                }
                return $this->roots ;
            }
        } ;
    }

    private function makeController( MockDocuments $model , array $init = [] ) :ConceptSchemeController
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $container->set( 'thesaurus.model'      , $model ) ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new ConceptSchemeController( $container ,
        [
            ControllerParam::APP           => $app ,
            ControllerParam::ROUTER        => $app->getRouteCollector()->getRouteParser() ,
            ConceptSchemeController::MODEL => 'thesaurus.model' ,
            ConceptSchemeController::TITLE => 'Product categories' ,
            ...$init ,
        ]) ;
    }

    public function testBuildsAConceptSchemeFromTheRoots() :void
    {
        $roots = [ [ '_key' => '1' , 'name' => 'Agencement' ] , [ '_key' => '5' , 'name' => 'Libre service' ] ] ;

        $result = $this->makeController( $this->model( $roots ) )->get() ;

        $this->assertInstanceOf( ConceptScheme::class , $result ) ;

        $json = $result->jsonSerialize() ;
        $this->assertSame( 'ConceptScheme'      , $json[ '@type' ] ) ;
        $this->assertSame( 'Product categories' , $json[ Schema::NAME ] ) ;
        $this->assertSame( $roots               , $json[ ConceptScheme::HAS_TOP_CONCEPT ] ) ;
    }

    public function testRootsAreFetchedByTheBroaderRelationAbsence() :void
    {
        $model = $this->model( [] ) ;

        $this->makeController( $model )->get() ;

        $this->assertSame
        (
            [ FilterParam::KEY => Oihana::BROADER , FilterParam::QUANT => FilterQuantifier::NONE ] ,
            $model->listInit[ Arango::FILTER ] ?? null
        ) ;
        // No ?sort → the model's own default is used (Schema::NAME).
        $this->assertSame( Schema::NAME , $model->listInit[ Arango::SORT ] ?? null ) ;
    }

    public function testHonoursACustomRelationKey() :void
    {
        $model = $this->model( [] ) ;

        $this->makeController( $model , [ ConceptSchemeController::RELATION => 'parentOf' ] )->get() ;

        $this->assertSame( 'parentOf' , $model->listInit[ Arango::FILTER ][ FilterParam::KEY ] ?? null ) ;
    }

    public function testEnvelopeCarriesCountAndTotalOfTheTopConcepts() :void
    {
        $roots = [ [ '_key' => '1' ] , [ '_key' => '5' ] , [ '_key' => '9' ] ] ;

        $result  = $this->makeController( $this->model( $roots ) )
            ->get( $this->makeRequest() , $this->makeResponse() ) ;

        $payload = json_decode( (string) $result->getBody() , true ) ;

        // The scheme is not paginated : count == total == the number of top concepts.
        $this->assertSame( 3 , $payload[ Output::COUNT ] ) ;
        $this->assertSame( 3 , $payload[ Output::TOTAL ] ) ;
    }

    public function testEmptyRootsYieldAnEmptyHasTopConcept() :void
    {
        $result = $this->makeController( $this->model( [] ) )->get() ;

        $this->assertSame( [] , $result->jsonSerialize()[ ConceptScheme::HAS_TOP_CONCEPT ] ) ;
    }

    public function testUrlFilterIsAndedWithTheRootConstraint() :void
    {
        $model = $this->model( [] ) ;

        $urlFilter = [ FilterParam::KEY => 'inScheme' , FilterParam::OP => 'eq' , FilterParam::VAL => 'animals' ] ;

        $this->makeController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => json_encode( $urlFilter ) ] ) ) ;

        $root = [ FilterParam::KEY => Oihana::BROADER , FilterParam::QUANT => FilterQuantifier::NONE ] ;

        // The root constraint stays FIRST, the URL filter is the trailing operand.
        $this->assertSame( [ FilterLogic::AND , $root , $urlFilter ] , $model->listInit[ Arango::FILTER ] ?? null ) ;
    }

    public function testAClientOrGroupCannotDegradeTheRootScope() :void
    {
        $model = $this->model( [] ) ;

        // A disjunctive client filter : without the single-operand wrapping it would
        // splice as `root || a || b` (every concept, roots or not).
        $orGroup =
        [
            FilterLogic::OR ,
            [ FilterParam::KEY => 'a' , FilterParam::VAL => 1 ] ,
            [ FilterParam::KEY => 'b' , FilterParam::VAL => 2 ] ,
        ] ;

        $this->makeController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => json_encode( $orGroup ) ] ) ) ;

        $root = [ FilterParam::KEY => Oihana::BROADER , FilterParam::QUANT => FilterQuantifier::NONE ] ;
        $got  = $model->listInit[ Arango::FILTER ] ?? null ;

        // `root && ( a || b )` : the OR group is one intact operand, never head-spliced.
        $this->assertSame( FilterLogic::AND , $got[ 0 ] ?? null ) ;
        $this->assertSame( $root            , $got[ 1 ] ?? null ) ;
        $this->assertSame( $orGroup         , $got[ 2 ] ?? null ) ;
        $this->assertCount( 3 , $got ) ;
    }

    /**
     * Invalid JSON used to degrade to « no filter » — every root came back. The reader
     * refuses it now (php-controllers), and the refusal must leave the controller as a
     * `400` : it used to escape the method, and Slim's own error handler answers `500`.
     */
    public function testMalformedFilterJsonFailsWithABadRequest() :void
    {
        $model    = $this->model( [] ) ;
        $response = $this->makeController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => '{ not json' ] ) , $this->makeResponse() ) ;

        $this->assertSame( HttpStatusCode::BAD_REQUEST , $response->getStatusCode() ) ;
        $this->assertStringContainsString( 'The filter parameter is not valid JSON.' , (string) $response->getBody() ) ;
        $this->assertSame( [] , $model->listInit ) ;
    }

    /**
     * A filter the model refuses leaves the controller as the model's own `400`.
     */
    public function testAFilterTheModelRefusesFailsWithABadRequest() :void
    {
        $model    = $this->model( [] , new RequestValidationException( 'The filter key "zzz" is not filterable.' ) ) ;
        $response = $this->makeController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => json_encode( [ 'key' => 'zzz' , 'val' => 1 ] ) ] ) , $this->makeResponse() ) ;

        $this->assertSame( HttpStatusCode::BAD_REQUEST , $response->getStatusCode() ) ;
        $this->assertStringContainsString( 'The filter key \"zzz\" is not filterable.' , (string) $response->getBody() ) ;
    }

    public function testAFailingModelFailsWithAServerError() :void
    {
        $response = $this->makeController( $this->model( [] , new ArangoException( 'the store is down' ) ) )->get( $this->makeRequest() , $this->makeResponse() ) ;

        $this->assertSame( HttpStatusCode::INTERNAL_SERVER_ERROR , $response->getStatusCode() ) ;
    }

    public function testAnExplicitAuthorizerIsThreadedIntoTheModelCall() :void
    {
        $model      = $this->model( [] ) ;
        $authorizer = fn( string $subject ) : bool => false ;

        // A caller-supplied authorizer wins over buildPermissionAuthorizer() and is
        // forwarded to the model, where it gates Field::REQUIRES on ?filter=.
        $this->makeController( $model )->get( null , null , [] , [ Arango::AUTHORIZER => $authorizer ] ) ;

        $this->assertSame( $authorizer , $model->listInit[ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testWithoutAnAuthorizationStackTheAuthorizerFallsOpen() :void
    {
        $model = $this->model( [] ) ;

        // No enforcer/resolver in the container and no request → the built authorizer
        // is null : the projection layer falls open (backward compatible).
        $this->makeController( $model )->get() ;

        $this->assertArrayHasKey( Arango::AUTHORIZER , $model->listInit ) ;
        $this->assertNull( $model->listInit[ Arango::AUTHORIZER ] ) ;
    }

    public function testMissingModelReturnsNull() :void
    {
        $container = new Container() ;
        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        // No model registered → fail() with a null response returns null.
        $controller = new ConceptSchemeController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
        ]) ;

        $this->assertNull( $controller->get() ) ;
    }
}
