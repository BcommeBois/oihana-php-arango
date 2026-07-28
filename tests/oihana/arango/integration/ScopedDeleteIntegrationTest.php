<?php

namespace tests\oihana\arango\integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\controllers\DocumentsController;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\HttpStatusCode;

use PHPUnit\Framework\Attributes\Group;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

use org\schema\constants\Schema;

use tests\oihana\arango\controllers\mocks\ScopedDocumentsController;

use function oihana\init\initConfig;

/**
 * Live validation that a scoped `DELETE` cannot be used as an **existence
 * oracle** — the guarantee no test on a model double can give, since it is about
 * two answers being *indistinguishable* rather than about either one alone.
 *
 * The rule: a caller must not be able to tell a document its scope hides from one
 * that does not exist. Both must answer the same status, with the same wording.
 *
 * ```
 * DELETE /documents/unknown  -> 404
 * DELETE /documents/hidden   -> 404   (and the document survives)
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ScopedDeleteIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_scoped_delete_it' ;

    private const string DOCUMENTS = 'documents' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::DOCUMENTS )->create() ;
    }

    /**
     * Every case here **deletes** — so the two documents are restored before each
     * one. The base harness seeds once per class, which would leave a test
     * asserting on what its predecessor removed (and did, until this was added).
     *
     * @throws ArangoException
     */
    protected function setUp() :void
    {
        parent::setUp() ; // skips when no server is reachable

        self::$db->query( 'FOR doc IN ' . self::DOCUMENTS . ' REMOVE doc IN ' . self::DOCUMENTS ) ;

        self::$db->collection( self::DOCUMENTS )->insert( [ '_key' => 'visible' , '__scope' => 'visible' ] ) ;
        self::$db->collection( self::DOCUMENTS )->insert( [ '_key' => 'hidden'  , '__scope' => 'hidden'  ] ) ;
    }

    /**
     * The oracle itself: the two refusals must be **byte-identical**, status and
     * wording alike. A 200 on one of them tells the caller which is which.
     *
     * @throws ArangoException
     */
    public function testAHiddenDocumentAnswersExactlyLikeAnUnknownOne() :void
    {
        $hidden  = $this->delete( 'hidden'  ) ;
        $unknown = $this->delete( 'unknown' ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $hidden->getStatusCode() ) ;
        $this->assertSame( HttpStatusCode::NOT_FOUND , $unknown->getStatusCode() ) ;

        // the id is echoed in the details, so compare everything else
        $this->assertSame
        (
            str_replace( 'unknown' , '<id>' , (string) $unknown->getBody() ) ,
            str_replace( 'hidden'  , '<id>' , (string) $hidden ->getBody() )
        ) ;
    }

    /**
     * @throws ArangoException
     */
    public function testAHiddenDocumentSurvivesItsDeletion() :void
    {
        $this->delete( 'hidden' ) ;

        $this->assertTrue( $this->exists( 'hidden' ) ) ;
    }

    /**
     * The other half : inside the scope, the deletion still works.
     *
     * @throws ArangoException
     */
    public function testAVisibleDocumentIsDeleted() :void
    {
        $response = $this->delete( 'visible' ) ;

        $this->assertSame( HttpStatusCode::OK , $response->getStatusCode() ) ;
        $this->assertFalse( $this->exists( 'visible' ) ) ;
    }

    /**
     * A **batch** in which one id is hidden is refused whole — the same answer the
     * batch already gets when one of its ids simply does not exist, so the caller
     * still cannot tell the two apart.
     *
     * @throws ArangoException
     */
    public function testABatchHoldingAHiddenIdIsRefusedWhole() :void
    {
        $response = $this->delete( [ 'visible' , 'hidden' ] ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
        $this->assertTrue( $this->exists( 'visible' ) , 'the visible half of a refused batch was deleted' ) ;
        $this->assertTrue( $this->exists( 'hidden'  ) ) ;
    }

    /**
     * Non-regression : a plain controller, no scope, keeps deleting whatever it is
     * given — the seat is opt-in.
     *
     * @throws ArangoException
     */
    public function testAPlainControllerStillDeletesAHiddenDocument() :void
    {
        $response = $this->delete( 'hidden' , scoped: false ) ;

        $this->assertSame( HttpStatusCode::OK , $response->getStatusCode() ) ;
        $this->assertFalse( $this->exists( 'hidden' ) ) ;
    }

    // ---- harness ----------------------------------------------------------

    private function controller( bool $scoped = true ) :DocumentsController
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $init =
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => new Documents( $container ,
            [
                Arango::DATABASE => $arangodb ,
                AQL::COLLECTION  => self::DOCUMENTS ,
                AQL::LAZY        => false ,
            ]) ,
        ] ;

        return $scoped ? new ScopedDocumentsController( $container , $init ) : new DocumentsController( $container , $init ) ;
    }

    /**
     * @param string|array<int,string> $id
     */
    private function delete( string|array $id , bool $scoped = true ) :Response
    {
        return $this->controller( $scoped )->delete
        (
            $this->request() ,
            $this->response() ,
            [ Schema::ID => $id ]
        ) ;
    }

    /**
     * Reads the collection directly — a status can lie about a deletion, the
     * document cannot.
     *
     * @throws ArangoException
     */
    private function exists( string $key ) :bool
    {
        foreach ( self::$db->query( 'RETURN LENGTH(FOR doc IN ' . self::DOCUMENTS . ' FILTER doc._key == "' . $key . '" RETURN 1)' ) as $row )
        {
            return (int) $row > 0 ;
        }

        return false ;
    }

    private function request() :Request
    {
        return new ServerRequestFactory()->createServerRequest( 'DELETE' , '/' ) ;
    }

    private function response() :Response
    {
        return new ResponseFactory()->createResponse() ;
    }
}
