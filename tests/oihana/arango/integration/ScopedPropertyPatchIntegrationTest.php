<?php

namespace tests\oihana\arango\integration;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use DI\Container;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use oihana\controllers\enums\ControllerParam;
use oihana\enums\http\HttpStatusCode;
use oihana\enums\Output;

use PHPUnit\Framework\Attributes\Group;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

use org\schema\constants\Schema;

use tests\oihana\arango\controllers\mocks\ScopedPropertyController;

use function oihana\init\initConfig;

/**
 * Live validation of the **scoped `PropertyController::patch()`** — the seam the
 * unit suite exercises against a double, driven here against a real arangod.
 *
 * `patch()` gates its write behind `exist()`, both enriched by the consumer hook
 * ({@see ScopedPropertyController} appends `doc.__scope == @__scope` and its bind).
 * Until the bind was declared in the probe's query, that probe raised and the
 * handler answered **400** — the server's own status, surfaced by
 * `HttpStatusCode::fromException()` — instead of the 404 the scope means, so the
 * whole scoped write path was unusable in practice. Nothing on a model double
 * could reveal it, since a double never assembles the AQL.
 *
 * What this pins, end to end:
 * - a document **inside** the scope is patched and the value lands in the database ;
 * - a document **outside** it answers 404 — the frozen wording of an unknown id,
 *   so the two stay indistinguishable — and the stored value is untouched ;
 * - `get()` on that same hidden document answers with a null property, never 404.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ScopedPropertyPatchIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_scoped_patch_it' ;

    private const string DOCUMENTS = 'documents' ;

    /**
     * Two documents differing only by the attribute the consumer scope reads.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::DOCUMENTS )->create() ;

        $db->collection( self::DOCUMENTS )->insert( [ '_key' => 'visible' , '__scope' => 'visible' , 'emails' => [ 'stored@x' ] ] ) ;
        $db->collection( self::DOCUMENTS )->insert( [ '_key' => 'hidden'  , '__scope' => 'hidden'  , 'emails' => [ 'stored@x' ] ] ) ;
    }

    /**
     * @throws ArangoException
     */
    public function testAPatchInsideTheScopeWritesTheProperty() :void
    {
        $response = $this->patch( 'visible' , [ 'patched@x' ] ) ;

        $this->assertSame( HttpStatusCode::OK , $response->getStatusCode() ) ;
        $this->assertSame( [ 'patched@x' ] , $this->storedEmailsOf( 'visible' ) ) ;
    }

    /**
     * The one this whole lot exists for: **404, not 500**, and nothing written.
     *
     * @throws ArangoException
     */
    public function testAPatchOutsideTheScopeAnswers404AndWritesNothing() :void
    {
        $response = $this->patch( 'hidden' , [ 'patched@x' ] ) ;

        $this->assertSame( HttpStatusCode::NOT_FOUND , $response->getStatusCode() ) ;
        $this->assertSame( [ 'stored@x' ] , $this->storedEmailsOf( 'hidden' ) , 'the scoped write reached a document it must not see' ) ;
    }

    /**
     * An unknown id answers exactly like the hidden one — the frozen decision:
     * a caller cannot tell "masked" from "absent".
     */
    public function testAnUnknownIdAnswersLikeAHiddenOne() :void
    {
        $this->assertSame
        (
            $this->patch( 'hidden'  , [ 'patched@x' ] )->getStatusCode() ,
            $this->patch( 'unknown' , [ 'patched@x' ] )->getStatusCode()
        ) ;
    }

    /**
     * The read side of the same rule: 200 with a null property, never a 404.
     */
    public function testAScopedGetHidesThePropertyWithoutA404() :void
    {
        $response = $this->controller()->get( $this->request( 'GET' ) , $this->response() , [ Schema::ID => 'hidden' ] ) ;

        $this->assertSame( HttpStatusCode::OK , $response->getStatusCode() ) ;

        $payload = json_decode( (string) $response->getBody() , true ) ;

        $this->assertArrayHasKey( Output::RESULT , $payload , 'body : ' . (string) $response->getBody() ) ;
        $this->assertNull( $payload[ Output::RESULT ] ) ;
    }

    /**
     * A {@see ScopedPropertyController} — the consumer double — over a live model.
     */
    private function controller() :ScopedPropertyController
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
            'fillable'       => [ 'emails' ] , // PrepareDocumentTrait::FILLABLE — a trait constant, fatal as Trait::CONST on PHP 8.4
        ]) ;

        return new ScopedPropertyController( $container ,
        [
            ControllerParam::APP    => $app ,
            ControllerParam::ROUTER => $app->getRouteCollector()->getRouteParser() ,
            ControllerParam::MODEL  => $model ,
            'property'              => 'emails' ,
        ]) ;
    }

    /**
     * Drives a real `PATCH /{id}/emails` through the controller.
     */
    private function patch( string $key , array $emails ) :Response
    {
        // a property endpoint receives the property VALUE as the body, not a
        // `{ property : value }` envelope — propertyPayload() keys it itself.
        return $this->controller()->patch
        (
            $this->request( 'PATCH' )->withParsedBody( $emails ) ,
            $this->response() ,
            [ Schema::ID => $key ]
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

    /**
     * Reads the property straight from the database, bypassing the controller —
     * a write that must not happen is proved by the stored document, not by a status.
     *
     * @throws ArangoException
     */
    private function storedEmailsOf( string $key ) :?array
    {
        foreach ( self::$db->query( 'FOR doc IN ' . self::DOCUMENTS . ' FILTER doc._key == "' . $key . '" RETURN doc.emails' ) as $row )
        {
            return json_decode( json_encode( $row ) , true ) ;
        }

        return null ;
    }
}
