<?php

namespace tests\oihana\arango\clients\http ;

use GuzzleHttp\Client ;
use GuzzleHttp\Exception\ConnectException ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Request ;
use GuzzleHttp\Psr7\Response ;

use Psr\Http\Message\RequestInterface ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\enums\AuthType ;
use oihana\arango\clients\exceptions\ConflictException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\exceptions\MaintenanceException ;
use oihana\arango\clients\exceptions\NetworkException ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpResponse ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see HttpTransport} — Guzzle-backed transport with retry +
 * host-ring failover.
 *
 * Every test injects a mocked Guzzle client built around {@see MockHandler}
 * so the transport never touches the network. A {@see RetryPolicy} with
 * `baseDelayMs: 0` is used to avoid `usleep()` blocking the test suite.
 */
#[CoversClass( HttpTransport::class )]
class HttpTransportTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int, Response|ConnectException> $responses Queued mock responses (or exceptions).
     * @param array<int, array<string, mixed>>      $history   Out-parameter populated with intercepted requests.
     * @return Client
     */
    private function mockClient( array $responses , array &$history = [] ) : Client
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        return new Client( [ 'handler' => $stack ] ) ;
    }

    /**
     * Builds a transport wrapping the given mocked Guzzle client. Uses a
     * zero-delay retry policy by default so tests never block.
     */
    private function transport
    (
        ClientOptions $options ,
        Client        $client ,
        ?RetryPolicy  $policy = null ,
    )
    : HttpTransport
    {
        return new HttpTransport
        (
            options     : $options ,
            httpClient  : $client ,
            retryPolicy : $policy ?? new RetryPolicy( maxAttempts : 3 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testRequestReturnsHttpResponseFor2xx() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [ 'Content-Type' => 'application/json' ] , '{"result":[1,2,3]}' ) ] ,
            $history ,
        ) ;

        $options   = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $transport = $this->transport( $options , $client ) ;

        $response = $transport->request( HttpMethod::GET , '/_api/version' ) ;

        $this->assertInstanceOf( HttpResponse::class , $response ) ;
        $this->assertSame( 200                       , $response->status ) ;
        $this->assertSame( [ 'result' => [ 1 , 2 , 3 ] ] , $response->body ) ;
        $this->assertTrue( $response->isSuccess() ) ;
    }

    public function testRequestBuildsAbsoluteUrlWithDatabasePrefix() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        $options   = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/collection' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection' , (string) $sent->getUri() ) ;
    }

    public function testRequestBuildsUrlWithoutDatabasePrefixWhenAbsent() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        // No database configured.
        $options = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/version' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/version' , (string) $sent->getUri() ) ;
    }

    public function testRequestSkipsDatabasePrefixWhenOverrideIsEmptyString() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        // database configured on the options but the request targets a global admin route.
        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $this->transport( $options , $client )->request
        (
            method           : HttpMethod::GET ,
            path             : '/_api/database' ,
            databaseOverride : '' ,
        ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/database' , (string) $sent->getUri() ) ;
    }

    public function testRequestUsesDatabaseOverrideWhenNonEmpty() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        // database configured on the options is "mydb", but this request targets "otherdb".
        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $this->transport( $options , $client )->request
        (
            method           : HttpMethod::GET ,
            path             : '/_api/collection' ,
            databaseOverride : 'otherdb' ,
        ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/otherdb/_api/collection' , (string) $sent->getUri() ) ;
    }

    public function testRequestSendsJsonBody() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 201 , [] , '{}' ) ] , $history ) ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $this->transport( $options , $client )->request
        (
            method : HttpMethod::POST ,
            path   : '/_api/document/users' ,
            body   : [ 'name' => 'Marc' , 'role' => 'admin' ] ,
        ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( '{"name":"Marc","role":"admin"}' , (string) $sent->getBody() ) ;
        $this->assertSame( 'application/json'               , $sent->getHeaderLine( 'Content-Type' ) ) ;
    }

    public function testRequestSendsBasicAuthHeader() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        $options = new ClientOptions
        (
            database  : 'mydb' ,
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::BASIC ,
            user      : 'root' ,
            password  : 'secret' ,
        ) ;

        $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/version' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'Basic ' . base64_encode( 'root:secret' ) , $sent->getHeaderLine( 'Authorization' ) ) ;
    }

    public function testRequestSendsJwtBearerAuthHeader() :void
    {
        $history = [] ;
        $client  = $this->mockClient( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        $options = new ClientOptions
        (
            database  : 'mydb' ,
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJfakeToken' ,
        ) ;

        $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/version' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'Bearer eyJfakeToken' , $sent->getHeaderLine( 'Authorization' ) ) ;
    }

    // =========================================================================
    // Retry + failover
    // =========================================================================

    public function testRequestRetriesOnConflictAndSucceeds() :void
    {
        $client = $this->mockClient
        (
            [
                new Response( 409 , [] , '{"error":true,"code":409,"errorNum":1200,"errorMessage":"conflict"}' ) ,
                new Response( 200 , [] , '{"result":"ok"}' ) ,
            ] ,
        ) ;

        $options  = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $response = $this->transport( $options , $client )->request( HttpMethod::POST , '/_api/document/users' , [ 'a' => 1 ] ) ;

        $this->assertSame( 200             , $response->status ) ;
        $this->assertSame( [ 'result' => 'ok' ] , $response->body ) ;
    }

    public function testRequestRetriesOnMaintenanceAndSucceeds() :void
    {
        $client = $this->mockClient
        (
            [
                new Response( 503 , [] , '{"error":true,"code":503,"errorNum":3002,"errorMessage":"maintenance"}' ) ,
                new Response( 200 , [] , '{"result":"ok"}' ) ,
            ] ,
        ) ;

        $options  = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $response = $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/version' ) ;

        $this->assertSame( 200 , $response->status ) ;
    }

    public function testRequestDoesNotRetryOn404() :void
    {
        $client = $this->mockClient
        (
            [
                new Response( 404 , [] , '{"error":true,"code":404,"errorNum":1202,"errorMessage":"document not found"}' ) ,
            ] ,
        ) ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $this->expectException( HttpException::class ) ;
        $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/document/users/missing' ) ;
    }

    public function testExhaustedRetryBudgetThrowsConflictException() :void
    {
        // 3 conflicts in a row, maxAttempts = 3 → final attempt re-throws.
        $client = $this->mockClient
        (
            [
                new Response( 409 , [] , '{"error":true,"code":409,"errorNum":1200,"errorMessage":"conflict"}' ) ,
                new Response( 409 , [] , '{"error":true,"code":409,"errorNum":1200,"errorMessage":"conflict"}' ) ,
                new Response( 409 , [] , '{"error":true,"code":409,"errorNum":1200,"errorMessage":"conflict"}' ) ,
            ] ,
        ) ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $this->expectException( ConflictException::class ) ;
        $this->transport( $options , $client )->request( HttpMethod::POST , '/_api/document/users' , [ 'a' => 1 ] ) ;
    }

    public function testConnectExceptionIsWrappedAsNetworkException() :void
    {
        $request = new Request( HttpMethod::GET , 'http://127.0.0.1:8529/_api/version' ) ;
        $client  = $this->mockClient
        (
            [
                new ConnectException( 'connection refused' , $request ) ,
            ] ,
        ) ;

        $options = new ClientOptions
        (
            database  : 'mydb' ,
            endpoints : [ 'http://127.0.0.1:8529' ] ,
        ) ;

        $this->expectException( NetworkException::class ) ;
        // NetworkException::isSafeToRetry() defaults to false → no retry.
        $this->transport( $options , $client )->request( HttpMethod::GET , '/_api/version' ) ;
    }

    public function testMaintenanceTriggersHostRingAdvance() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [
                new Response( 503 , [] , '{"error":true,"code":503,"errorNum":3002,"errorMessage":"maintenance"}' ) ,
                new Response( 200 , [] , '{"result":"ok"}' ) ,
            ] ,
            $history ,
        ) ;

        $options   = new ClientOptions
        (
            database  : 'mydb' ,
            endpoints : [ 'http://primary:8529' , 'http://failover:8529' ] ,
        ) ;
        $transport = $this->transport( $options , $client ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        $this->assertSame( 'http://primary:8529/_db/mydb/_api/version'  , (string) $history[ 0 ][ 'request' ]->getUri() ) ;
        $this->assertSame( 'http://failover:8529/_db/mydb/_api/version' , (string) $history[ 1 ][ 'request' ]->getUri() ) ;
    }

    public function testExhaustedRetryOnMaintenanceThrowsMaintenanceException() :void
    {
        $client = $this->mockClient
        (
            [
                new Response( 503 , [] , '{"error":true,"code":503,"errorNum":3002,"errorMessage":"maintenance"}' ) ,
                new Response( 503 , [] , '{"error":true,"code":503,"errorNum":3002,"errorMessage":"maintenance"}' ) ,
            ] ,
        ) ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $policy  = new RetryPolicy( maxAttempts : 2 , baseDelayMs : 0 , maxDelayMs : 0 ) ;

        $this->expectException( MaintenanceException::class ) ;
        $this->transport( $options , $client , $policy )->request( HttpMethod::GET , '/_api/version' ) ;
    }

    // =========================================================================
    // Auth — login() / setBearerToken() / setBasicAuth() / 401 auto-refresh
    // =========================================================================

    public function testLoginPostsCredentialsToOpenAuthAndReturnsJwt() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{"jwt":"eyJfresh","must_change_password":false}' ) ] ,
            $history ,
        ) ;

        $options   = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $transport = $this->transport( $options , $client ) ;

        $jwt = $transport->login( 'root' , 'secret' ) ;

        $this->assertSame( 'eyJfresh' , $jwt ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_open/auth'           , (string) $sent->getUri() ) ;
        $this->assertSame
        (
            [ 'username' => 'root' , 'password' => 'secret' ] ,
            json_decode( (string) $sent->getBody() , associative : true ) ,
        ) ;
        $this->assertFalse( $sent->hasHeader( 'authorization' ) , '/_open/auth must NOT carry an Authorization header' ) ;
    }

    public function testLoginStoresJwtForSubsequentRequests() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [
                new Response( 200 , [] , '{"jwt":"eyJfresh"}' ) ,
                new Response( 200 , [] , '{"server":"arango"}' ) ,
            ] ,
            $history ,
        ) ;

        $options   = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $transport = $this->transport( $options , $client ) ;

        $transport->login( 'root' , 'secret' ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 1 ][ 'request' ] ;
        $this->assertSame( 'Bearer eyJfresh' , $sent->getHeaderLine( 'authorization' ) ) ;
    }

    public function testLoginRaisesArangoExceptionOn401() :void
    {
        $client = $this->mockClient
        (
            [ new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"invalid credentials"}' ) ] ,
        ) ;

        $options   = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $transport = $this->transport( $options , $client ) ;

        $this->expectException( HttpException::class ) ;
        $transport->login( 'root' , 'wrong' ) ;
    }

    public function testSetBearerTokenOverridesInitialOptions() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::BASIC ,
            user      : 'root' ,
            password  : 'secret' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        $transport->setBearerToken( 'eyJoverride' ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'Bearer eyJoverride' , $sent->getHeaderLine( 'authorization' ) ) ;
    }

    public function testSetBearerTokenNullRevertsToBasicAuth() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJinitial' ,
            user      : 'root' ,
            password  : 'secret' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        $transport->setBearerToken( null ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        $sent     = $history[ 0 ][ 'request' ] ;
        $expected = 'Basic ' . base64_encode( 'root:secret' ) ;
        $this->assertSame( $expected , $sent->getHeaderLine( 'authorization' ) ) ;
    }

    public function testSetBasicAuthSwitchesIdentityAndClearsBearer() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJinitial' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        $transport->setBasicAuth( 'admin' , 'adminpass' ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        $sent     = $history[ 0 ][ 'request' ] ;
        $expected = 'Basic ' . base64_encode( 'admin:adminpass' ) ;
        $this->assertSame( $expected , $sent->getHeaderLine( 'authorization' ) ) ;
    }

    public function test401TriggersAutoRefreshWhenCredentialsKnown() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [
                // 1st request — JWT expired.
                new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"jwt expired"}' ) ,
                // Auto refresh: POST /_open/auth.
                new Response( 200 , [] , '{"jwt":"eyJfresh"}' ) ,
                // Retry of the original request — succeeds.
                new Response( 200 , [] , '{"server":"arango"}' ) ,
            ] ,
            $history ,
        ) ;

        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJexpired' ,
            user      : 'root' ,
            password  : 'secret' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        $response = $transport->request( HttpMethod::GET , '/_api/version' ) ;
        $this->assertSame( 200 , $response->status ) ;

        $this->assertCount( 3 , $history ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/version' , (string) $history[ 0 ][ 'request' ]->getUri() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_open/auth'   , (string) $history[ 1 ][ 'request' ]->getUri() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/version' , (string) $history[ 2 ][ 'request' ]->getUri() ) ;
        $this->assertSame( 'Bearer eyJfresh'                     , $history[ 2 ][ 'request' ]->getHeaderLine( 'authorization' ) ) ;
    }

    public function test401PropagatesWhenNoCredentialsForRefresh() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [ new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"jwt expired"}' ) ] ,
            $history ,
        ) ;

        // Only a bearer token — no basic credentials to refresh from.
        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJexpired' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        try
        {
            $transport->request( HttpMethod::GET , '/_api/version' ) ;
            $this->fail( 'Expected HttpException to be thrown' ) ;
        }
        catch ( HttpException $e )
        {
            $this->assertSame( 401 , $e->getCode() ) ;
        }

        $this->assertCount( 1 , $history , 'No refresh attempt expected when basic credentials are unknown' ) ;
    }

    public function test401RefreshHappensOnlyOnce() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [
                // 1st original request — 401.
                new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"jwt expired"}' ) ,
                // Refresh — succeeds (new JWT).
                new Response( 200 , [] , '{"jwt":"eyJfresh"}' ) ,
                // Retry — still 401 (e.g. role missing). Must NOT trigger a 2nd refresh.
                new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"still forbidden"}' ) ,
            ] ,
            $history ,
        ) ;

        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJexpired' ,
            user      : 'root' ,
            password  : 'secret' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        try
        {
            $transport->request( HttpMethod::GET , '/_api/version' ) ;
            $this->fail( 'Expected HttpException on second 401' ) ;
        }
        catch ( HttpException $e )
        {
            $this->assertSame( 401 , $e->getCode() ) ;
        }

        $this->assertCount( 3 , $history , 'Expected exactly 3 calls: original 401 + refresh + retry (no further refresh)' ) ;
    }

    public function test401WhenRefreshItselfFailsPropagatesOriginal() :void
    {
        $history = [] ;
        $client  = $this->mockClient
        (
            [
                // 1st original request — 401.
                new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"jwt expired"}' ) ,
                // Refresh — bad credentials (server rejected).
                new Response( 401 , [] , '{"error":true,"code":401,"errorMessage":"invalid credentials"}' ) ,
            ] ,
            $history ,
        ) ;

        $options = new ClientOptions
        (
            endpoints : [ 'http://127.0.0.1:8529' ] ,
            authType  : AuthType::JWT ,
            token     : 'eyJexpired' ,
            user      : 'root' ,
            password  : 'now-wrong' ,
        ) ;
        $transport = $this->transport( $options , $client ) ;

        $this->expectException( HttpException::class ) ;
        try
        {
            $transport->request( HttpMethod::GET , '/_api/version' ) ;
        }
        finally
        {
            $this->assertCount( 2 , $history , 'Expected original 401 + failed refresh, nothing else' ) ;
        }
    }

    // =========================================================================
    // allowDirtyRead — `x-arango-allow-dirty-read` header injection
    // =========================================================================

    public function testDirtyReadHeaderIsAbsentByDefault() :void
    {
        $history = [] ;
        $options = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{"version":"3.12.4"}' ) ] ,
            $history ,
        ) ;

        $transport = $this->transport( $options , $client ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertFalse( $sent->hasHeader( 'x-arango-allow-dirty-read' ) ) ;
    }

    public function testDirtyReadHeaderIsAddedWhenAllowDirtyReadIsTrue() :void
    {
        $history = [] ;
        $options = new ClientOptions
        (
            endpoints      : [ 'http://127.0.0.1:8529' ] ,
            allowDirtyRead : true ,
        ) ;
        $client = $this->mockClient
        (
            [ new Response( 200 , [] , '{"version":"3.12.4"}' ) ] ,
            $history ,
        ) ;

        $transport = $this->transport( $options , $client ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertTrue( $sent->hasHeader( 'x-arango-allow-dirty-read' ) ) ;
        $this->assertSame( 'true' , $sent->getHeaderLine( 'x-arango-allow-dirty-read' ) ) ;
    }

    // =========================================================================
    // transactionId — `x-arango-trx-id` header injection (per-request)
    // =========================================================================

    public function testTransactionIdHeaderIsAbsentWhenNotProvided() :void
    {
        $history = [] ;
        $options = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $transport = $this->transport( $options , $client ) ;
        $transport->request( HttpMethod::GET , '/_api/version' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertFalse( $sent->hasHeader( 'x-arango-trx-id' ) ) ;
    }

    public function testTransactionIdHeaderIsAddedWhenProvided() :void
    {
        $history = [] ;
        $options = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $client  = $this->mockClient
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $transport = $this->transport( $options , $client ) ;
        $transport->request
        (
            method        : HttpMethod::GET ,
            path          : '/_api/version' ,
            transactionId : 'trx-42-abc' ,
        ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertTrue( $sent->hasHeader( 'x-arango-trx-id' ) ) ;
        $this->assertSame( 'trx-42-abc' , $sent->getHeaderLine( 'x-arango-trx-id' ) ) ;
    }

    public function testTransactionIdHeaderIsRequestScoped() :void
    {
        // Two requests on the SAME transport: only the first carries the
        // trx-id header. The second (with $transactionId = null) must NOT.
        $history = [] ;
        $options = new ClientOptions( endpoints : [ 'http://127.0.0.1:8529' ] ) ;
        $client  = $this->mockClient
        (
            [
                new Response( 200 , [] , '{}' ) ,
                new Response( 200 , [] , '{}' ) ,
            ] ,
            $history ,
        ) ;

        $transport = $this->transport( $options , $client ) ;

        $transport->request
        (
            method        : HttpMethod::GET ,
            path          : '/_api/version' ,
            transactionId : 'trx-1' ,
        ) ;

        $transport->request( method : HttpMethod::GET , path : '/_api/version' ) ;

        $this->assertSame( 'trx-1' , $history[ 0 ][ 'request' ]->getHeaderLine( 'x-arango-trx-id' ) ) ;
        $this->assertFalse( $history[ 1 ][ 'request' ]->hasHeader( 'x-arango-trx-id' ) ) ;
    }
}
