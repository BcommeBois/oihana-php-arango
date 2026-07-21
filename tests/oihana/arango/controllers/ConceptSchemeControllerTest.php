<?php

namespace tests\oihana\arango\controllers;

use DI\Container;

use oihana\arango\controllers\ConceptSchemeController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\FilterQuantifier;

use oihana\controllers\enums\ControllerParam;

use oihana\enums\Output;

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
    private function model( array $roots ) :MockDocuments
    {
        return new class( $roots ) extends MockDocuments
        {
            public array $listInit = [] ;

            public function __construct( private array $roots ) { parent::__construct( 'categories' ) ; }

            public function list( array $init = [] ) :array
            {
                $this->listInit = $init ;
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

    public function testMalformedFilterJsonKeepsOnlyTheRootConstraint() :void
    {
        $model = $this->model( [] ) ;

        // Invalid JSON → PrepareFilter logs a warning and yields null → root only.
        $this->makeController( $model )->get( $this->makeRequest( [ ControllerParam::FILTER => '{ not json' ] ) ) ;

        $this->assertSame
        (
            [ FilterParam::KEY => Oihana::BROADER , FilterParam::QUANT => FilterQuantifier::NONE ] ,
            $model->listInit[ Arango::FILTER ] ?? null
        ) ;
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
