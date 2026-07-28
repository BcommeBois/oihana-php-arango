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
use oihana\arango\enums\Field;
use oihana\arango\models\Documents;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\Output;

use PHPUnit\Framework\Attributes\Group;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

use org\schema\constants\Schema;

use tests\oihana\arango\controllers\mocks\GatedDocumentsController;

use function oihana\init\initConfig;

/**
 * Live validation that a **write response is projected under the same gates as a
 * read** — the guarantee no unit test can give, because it is about two responses
 * of the same controller agreeing with each other.
 *
 * `post()` and `update()` do not return what they wrote: they **re-read** the
 * document and return that. That reload is a read, but it was built by hand from
 * four keys and went through none of the machinery a real `GET` goes through — so
 * a field hidden by `Field::REQUIRES` came straight back in the write response:
 *
 * ```
 * GET   -> {"name":"public"}
 * PATCH -> {"name":"patched","secret":"CLASSIFIED"}   ← measured, before the fix
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class GatedWriteResponseIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_gated_write_it' ;

    private const string DOCUMENTS = 'documents' ;

    /**
     * The permission the `secret` field requires — refused by the double.
     */
    private const string PERMISSION = 'docs.secret:read' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::DOCUMENTS )->create() ;
    }

    /**
     * Each case writes, so the document is restored before every one of them.
     *
     * @throws ArangoException
     */
    protected function setUp() :void
    {
        parent::setUp() ;

        self::$db->query( 'FOR doc IN ' . self::DOCUMENTS . ' REMOVE doc IN ' . self::DOCUMENTS ) ;

        self::$db->collection( self::DOCUMENTS )->insert
        ([
            '_key'   => 'k1' ,
            'name'   => 'public' ,
            'secret' => 'CLASSIFIED' ,
        ]) ;
    }

    /**
     * The control: on the read side the gate has always held.
     */
    public function testAGatedFieldIsAbsentFromTheRead() :void
    {
        $this->assertArrayNotHasKey( 'secret' , $this->resultOf( $this->get() ) ) ;
    }

    /**
     * The leak itself: the very value the read refuses, handed back by the write.
     */
    public function testAGatedFieldIsAbsentFromThePatchResponse() :void
    {
        $result = $this->resultOf( $this->patch( [ 'name' => 'patched' ] ) ) ;

        $this->assertSame( 'patched' , $result[ 'name' ] ?? null , 'the write did not happen' ) ;
        $this->assertArrayNotHasKey( 'secret' , $result , 'the write response projected a field the read hides' ) ;
    }

    public function testAGatedFieldIsAbsentFromThePostResponse() :void
    {
        $result = $this->resultOf( $this->post( [ 'name' => 'created' ] ) ) ;

        $this->assertSame( 'created' , $result[ 'name' ] ?? null ) ;
        $this->assertArrayNotHasKey( 'secret' , $result ) ;
    }

    /**
     * The other half — closing the gate must not close everything: an ungated
     * field still comes back, otherwise the write response would be useless.
     */
    public function testAnUngatedFieldStillComesBack() :void
    {
        $this->assertSame( 'patched' , $this->resultOf( $this->patch( [ 'name' => 'patched' ] ) )[ 'name' ] ?? null ) ;
    }

    /**
     * Without an authorization stack the projection falls open, on the write
     * response as on the read — the backward-compatibility promise.
     */
    public function testAPlainControllerStillSeesEverything() :void
    {
        $result = $this->resultOf( $this->patch( [ 'name' => 'patched' ] , gated: false ) ) ;

        $this->assertSame( 'CLASSIFIED' , $result[ 'secret' ] ?? null ) ;
    }

    /**
     * The documented boundary: `Arango::RAW` returns the write's own result and
     * skips the reload entirely, so no projection applies — internal attributes
     * included. It is a server-side opt-out declared in the route, never a client
     * parameter, and it is **incompatible with a projection gate**. Pinned so the
     * trade-off is stated rather than discovered.
     */
    public function testTheRawModeStaysUngated() :void
    {
        $result = $this->resultOf( $this->patch( [ 'name' => 'raw' ] , raw: true ) ) ;

        $this->assertSame( 'CLASSIFIED' , $result[ 'secret' ] ?? null ) ;
        $this->assertArrayHasKey( '_rev' , $result ) ;
    }

    // ---- harness ----------------------------------------------------------

    /**
     * A controller over a live model whose `secret` field requires a permission.
     */
    private function controller( bool $gated = true ) :DocumentsController
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        AppFactory::setContainer( $container ) ;
        $app = AppFactory::create() ;

        $model = new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::DOCUMENTS ,
            AQL::LAZY        => false ,
            'fillable'       => [ 'name' ] , // PrepareDocumentTrait::FILLABLE, a trait constant
        ]) ;

        $model->fields = [ 'name' => [] , 'secret' => [ Field::REQUIRES => self::PERMISSION ] ] ;

        $init =
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ,
        ] ;

        return $gated ? new GatedDocumentsController( $container , $init ) : new DocumentsController( $container , $init ) ;
    }

    private function get() :Response
    {
        return $this->controller()->get( $this->request( 'GET' ) , $this->response() , [ Schema::ID => 'k1' ] ) ;
    }

    private function patch( array $body , bool $gated = true , bool $raw = false ) :Response
    {
        return $this->controller( $gated )->update
        (
            $this->request( 'PATCH' )->withParsedBody( $body ) ,
            $this->response() ,
            [ Schema::ID => 'k1' ] ,
            $raw ? [ Arango::RAW => true ] : []
        ) ;
    }

    private function post( array $body ) :Response
    {
        return $this->controller()->post( $this->request( 'POST' )->withParsedBody( $body ) , $this->response() , [] ) ;
    }

    private function request( string $method ) :Request
    {
        return new ServerRequestFactory()->createServerRequest( $method , '/' ) ;
    }

    private function response() :Response
    {
        return new ResponseFactory()->createResponse() ;
    }

    /**
     * The `result` payload of a response, as an array.
     *
     * @return array<string,mixed>
     */
    private function resultOf( Response $response ) :array
    {
        $payload = json_decode( (string) $response->getBody() , true ) ;
        $result  = $payload[ Output::RESULT ] ?? null ;

        return is_array( $result ) ? $result : [] ;
    }
}
