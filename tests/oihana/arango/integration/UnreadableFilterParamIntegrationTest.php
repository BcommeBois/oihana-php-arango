<?php

namespace tests\oihana\arango\integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\controllers\DocumentsController;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\Output;
use oihana\enums\http\HttpStatusCode;

use PHPUnit\Framework\Attributes\Group;

use Psr\Http\Message\ResponseInterface as Response;

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

use function oihana\init\initConfig;

/**
 * Live proof, through a real `DocumentsController` and a real store, that a `?filter=` or
 * a `?facets=` that cannot be read answers `400` — the reader of `oihana/php-controllers`
 * refuses it now (it used to log a warning and answer every document), and the refusal
 * travels through the capability overlay of `oihana/php-auth` (`prepareFilterBase()`).
 *
 * Ten people, aged 20 to 29 : a read filter answers a part of them, a dropped one all ten.
 */
#[Group( 'integration' )]
final class UnreadableFilterParamIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_unreadable_filter_param_it' ;

    private const string COLLECTION = 'people' ;

    protected static function seed( Database $db ) :void
    {
        $people = $db->collection( self::COLLECTION ) ;
        $people->create() ;
        for ( $i = 0 ; $i < 10 ; $i++ )
        {
            $people->insert( [ '_key' => "p$i" , 'name' => "p$i" , 'age' => 20 + $i ] ) ;
        }
    }

    private function list( array $query ) :Response
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $model = new Documents( $container ,
        [
            Arango::DATABASE => new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::LAZY        => false ,
            AQL::FILTERS     => [ 'age' => FilterType::NUMBER , 'name' => FilterType::STRING ] ,
        ]) ;

        $controller = new DocumentsController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ,
        ]) ;

        $request = new ServerRequestFactory()->createServerRequest( 'GET' , '/' )->withQueryParams( $query ) ;

        return $controller->list( $request , new ResponseFactory()->createResponse() ) ;
    }

    private static function body( Response $response ) :array
    {
        $payload = json_decode( (string) $response->getBody() , true ) ;
        return is_array( $payload ) ? $payload : [] ;
    }

    /**
     * The counter-proofs : no filter answers everyone, a read one a part, and an empty
     * `?filter=` — a form field left blank — is no filter.
     */
    public function testTheSeedTellsAReadFilterFromADroppedOne() :void
    {
        $this->assertCount( 10 , self::body( $this->list( [] ) )[ Output::RESULT ] ?? [] ) ;
        $this->assertCount( 5  , self::body( $this->list( [ ControllerParam::FILTER => '{"key":"age","op":"ge","val":25}' ] ) )[ Output::RESULT ] ?? [] ) ;
        $this->assertCount( 10 , self::body( $this->list( [ ControllerParam::FILTER => '' ] ) )[ Output::RESULT ] ?? [] ) ;
    }

    public function testAFilterThatIsNotJsonAnswersABadRequest() :void
    {
        $response = $this->list( [ ControllerParam::FILTER => '{"key":"age","op":"ge","val":25' ] ) ;

        $this->assertSame( HttpStatusCode::BAD_REQUEST , $response->getStatusCode() ) ;
        $this->assertStringContainsString( 'The filter parameter is not valid JSON.' , (string) $response->getBody() ) ;
    }

    public function testAFilterThatIsNotAnObjectAnswersABadRequest() :void
    {
        $response = $this->list( [ ControllerParam::FILTER => '42' ] ) ;

        $this->assertSame( HttpStatusCode::BAD_REQUEST , $response->getStatusCode() ) ;
        $this->assertStringContainsString( 'The filter parameter must be a JSON object or list, int given.' , (string) $response->getBody() ) ;
    }

    public function testFacetsThatAreNotJsonAnswerABadRequest() :void
    {
        $response = $this->list( [ ControllerParam::FACETS => '{"age":25' ] ) ;

        $this->assertSame( HttpStatusCode::BAD_REQUEST , $response->getStatusCode() ) ;
        $this->assertStringContainsString( 'The facets parameter is not valid JSON.' , (string) $response->getBody() ) ;
    }

    /**
     * The model's own refusal travels the same way : JSON that reads, a filter the model
     * cannot.
     */
    public function testAFilterTheModelCannotReadAnswersABadRequest() :void
    {
        $response = $this->list( [ ControllerParam::FILTER => '{"age":{"$gte":25}}' ] ) ;

        $this->assertSame( HttpStatusCode::BAD_REQUEST , $response->getStatusCode() ) ;
        $this->assertStringContainsString( 'speaks no filter grammar' , (string) $response->getBody() ) ;
    }
}
