<?php

namespace tests\oihana\arango\clients ;

use InvalidArgumentException ;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Response ;

use Psr\Http\Message\RequestInterface ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ArangoClient} — entry point of the ArangoDB client.
 *
 * Each test wires an `HttpTransport` around a mocked Guzzle client so the
 * HTTP layer is exercised end-to-end without touching the network.
 */
#[CoversClass( ArangoClient::class )]
class ArangoClientTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int, Response>             $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function makeClient
    (
        ClientOptions $options ,
        array         $responses ,
        array         &$history = [] ,
    )
    : ArangoClient
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        return new ArangoClient( options : $options , transport : $transport ) ;
    }

    private function defaultOptions( ?string $database = null ) : ClientOptions
    {
        return new ClientOptions
        (
            database  : $database ,
            endpoints : [ 'http://127.0.0.1:8529' ] ,
        ) ;
    }

    // =========================================================================
    // Construction
    // =========================================================================

    public function testConstructUsesProvidedTransport() :void
    {
        $options   = $this->defaultOptions() ;
        $transport = new HttpTransport( $options ) ;
        $client    = new ArangoClient( $options , $transport ) ;

        $this->assertSame( $transport , $client->transport ) ;
        $this->assertSame( $options   , $client->options   ) ;
    }

    public function testConstructCreatesDefaultTransportWhenNoneProvided() :void
    {
        $client = new ArangoClient( $this->defaultOptions() ) ;

        $this->assertInstanceOf( HttpTransport::class , $client->transport ) ;
    }

    // =========================================================================
    // version()
    // =========================================================================

    public function testVersionReturnsDecodedServerInfo() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [ new Response( 200 , [] , '{"server":"arango","version":"3.12.4","license":"community"}' ) ] ,
            $history ,
        ) ;

        $version = $client->version() ;

        $this->assertSame
        (
            [ 'server' => 'arango' , 'version' => '3.12.4' , 'license' => 'community' ] ,
            $version ,
        ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                     , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/version'      , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // time()
    // =========================================================================

    public function testTimeReturnsServerTimestampAsFloat() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [ new Response( 200 , [] , '{"time":1716559283.987654,"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $time = $client->time() ;

        $this->assertIsFloat( $time ) ;
        $this->assertEqualsWithDelta( 1716559283.987654 , $time , 0.000001 ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                          , $sent->getMethod() ) ;
        // Server-global endpoint: the /_db/{name} prefix must NOT be applied
        // even when a database is configured in the options.
        $this->assertSame( 'http://127.0.0.1:8529/_admin/time'      , (string) $sent->getUri() ) ;
    }

    public function testTimeReturnsZeroWhenTimeFieldIsMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( 0.0 , $client->time() ) ;
    }

    public function testTimeCoercesIntegerSecondsToFloat() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"time":1716559283,"error":false,"code":200}' ) ] ,
        ) ;

        $time = $client->time() ;

        $this->assertIsFloat( $time ) ;
        $this->assertSame( 1716559283.0 , $time ) ;
    }

    // =========================================================================
    // availability()
    // =========================================================================

    public function testAvailabilityReturnsServerModeOn200() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [ new Response( 200 , [] , '{"mode":"default","error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $this->assertSame( 'default' , $client->availability() ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                          , $sent->getMethod() ) ;
        // Server-global endpoint: /_db/{name} prefix must NOT be applied.
        $this->assertSame( 'http://127.0.0.1:8529/_admin/server/availability'       , (string) $sent->getUri() ) ;
    }

    public function testAvailabilityReturnsReadonlyMode() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"mode":"readonly","error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( 'readonly' , $client->availability() ) ;
    }

    public function testAvailabilityReturnsFalseWhenModeFieldIsMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertFalse( $client->availability() ) ;
    }

    public function testAvailabilityGracefullySwallows503AsFalse() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 503 , [] , '{"error":true,"code":503,"errorNum":0,"errorMessage":"server is shutting down"}' ) ] ,
        ) ;

        $this->assertFalse( $client->availability() ) ;
    }

    public function testAvailabilityRethrows503WhenGracefulIsFalse() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 503 , [] , '{"error":true,"code":503,"errorNum":0,"errorMessage":"server is shutting down"}' ) ] ,
        ) ;

        $this->expectException( HttpException::class ) ;
        $client->availability( graceful : false ) ;
    }

    public function testAvailabilityPropagatesNon503Errors() :void
    {
        // graceful=true must NOT swallow a non-503 failure.
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 500 , [] , '{"error":true,"code":500,"errorMessage":"internal"}' ) ] ,
        ) ;

        $this->expectException( HttpException::class ) ;
        $client->availability() ;
    }

    // =========================================================================
    // listDatabases()
    // =========================================================================

    public function testListDatabasesExtractsResultArray() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [ new Response( 200 , [] , '{"result":["_system","mydb","another"],"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $databases = $client->listDatabases() ;

        $this->assertSame( [ '_system' , 'mydb' , 'another' ] , $databases ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                  , $sent->getMethod() ) ;
        // database in options must be ignored: /_db prefix never applied.
        $this->assertSame( 'http://127.0.0.1:8529/_api/database'  , (string) $sent->getUri() ) ;
    }

    public function testListDatabasesReturnsEmptyArrayWhenResultMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $client->listDatabases() ) ;
    }

    // =========================================================================
    // createDatabase() / dropDatabase()
    // =========================================================================

    public function testCreateDatabaseSendsPostWithName() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":true,"error":false,"code":201}' ) ] ,
            $history ,
        ) ;

        $client->createDatabase( 'newdb' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                 , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/database'  , (string) $sent->getUri() ) ;
        $this->assertSame( '{"name":"newdb"}'                     , (string) $sent->getBody() ) ;
    }

    public function testDropDatabaseSendsDeleteWithEncodedName() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":true,"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $client->dropDatabase( 'old db with spaces' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_api/database/old%20db%20with%20spaces' ,
            (string) $sent->getUri() ,
        ) ;
    }

    // =========================================================================
    // database() factory
    // =========================================================================

    public function testDatabaseFactoryUsesProvidedName() :void
    {
        $client = new ArangoClient( $this->defaultOptions( 'configured' ) ) ;

        $db = $client->database( 'otherdb' ) ;

        $this->assertInstanceOf( Database::class , $db ) ;
        $this->assertSame( 'otherdb' , $db->name ) ;
        $this->assertSame( $client   , $db->client ) ;
    }

    public function testDatabaseFactoryFallsBackToOptionsDatabase() :void
    {
        $client = new ArangoClient( $this->defaultOptions( 'configured' ) ) ;

        $db = $client->database() ;

        $this->assertSame( 'configured' , $db->name ) ;
    }

    public function testDatabaseFactoryThrowsWhenNoNameAvailable() :void
    {
        $client = new ArangoClient( $this->defaultOptions() ) ; // no database in options

        $this->expectException( InvalidArgumentException::class ) ;
        $client->database() ;
    }

    public function testDatabaseFactoryThrowsWhenNameIsEmptyString() :void
    {
        $client = new ArangoClient( $this->defaultOptions( '' ) ) ;

        $this->expectException( InvalidArgumentException::class ) ;
        $client->database() ;
    }
}
