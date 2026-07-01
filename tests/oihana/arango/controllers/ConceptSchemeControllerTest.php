<?php

namespace tests\oihana\arango\controllers;

use DI\Container;

use oihana\arango\controllers\ConceptSchemeController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\enums\filters\FilterQuantifier;

use oihana\controllers\enums\ControllerParam;

use org\schema\constants\Schema;

use xyz\oihana\schema\constants\Oihana;
use xyz\oihana\schema\thesaurus\ConceptScheme;

use PHPUnit\Framework\Attributes\CoversClass;

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

        $container->set( 'thesaurus.model' , $model ) ;

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

    public function testEmptyRootsYieldAnEmptyHasTopConcept() :void
    {
        $result = $this->makeController( $this->model( [] ) )->get() ;

        $this->assertSame( [] , $result->jsonSerialize()[ ConceptScheme::HAS_TOP_CONCEPT ] ) ;
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
