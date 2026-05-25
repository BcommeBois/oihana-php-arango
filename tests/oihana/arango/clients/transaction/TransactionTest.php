<?php

namespace tests\oihana\arango\clients\transaction ;

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
use oihana\arango\clients\transaction\Transaction ;
use oihana\arango\clients\transaction\enums\TransactionStatus ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Transaction} — streaming-transaction handle bound
 * to a server-side id.
 *
 * Every test mocks the Guzzle client so the wire is fully observable
 * and the transport never touches the network.
 */
#[CoversClass( Transaction::class )]
class TransactionTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int, Response>             $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function makeDatabase
    (
        array  $responses ,
        array  &$history = [] ,
        string $name     = 'mydb' ,
    )
    : Database
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $options = new ClientOptions( database : $name , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        $client = new ArangoClient( options : $options , transport : $transport ) ;

        return $client->database( $name ) ;
    }

    // =========================================================================
    // Construction
    // =========================================================================

    public function testConstructionExposesIdAndDatabase() :void
    {
        $db  = $this->makeDatabase( [] ) ;
        $trx = new Transaction( $db , 'trx-42' ) ;

        $this->assertSame( 'trx-42' , $trx->id       ) ;
        $this->assertSame( $db      , $trx->database ) ;
    }

    // =========================================================================
    // status()
    // =========================================================================

    public function testStatusGetsCurrentLifecycleState() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"code":200,"error":false,"result":{"id":"trx-42","status":"running"}}' ) ] ,
            $history ,
        ) ;

        $status = ( new Transaction( $db , 'trx-42' ) )->status() ;

        $this->assertSame( TransactionStatus::RUNNING , $status ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                          , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/transaction/trx-42' , (string) $sent->getUri() ) ;
    }

    public function testStatusUrlEncodesTheTransactionId() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":{"id":"trx weird/id","status":"running"}}' ) ] ,
            $history ,
        ) ;

        ( new Transaction( $db , 'trx weird/id' ) )->status() ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/transaction/trx%20weird%2Fid' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testStatusReturnsEmptyStringWhenBodyIsMalformed() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"unexpected":true}' ) ] ,
        ) ;

        $this->assertSame( '' , ( new Transaction( $db , 'trx-42' ) )->status() ) ;
    }

    // =========================================================================
    // commit()
    // =========================================================================

    public function testCommitSendsPutAndReturnsTerminalStatus() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":{"id":"trx-42","status":"committed"}}' ) ] ,
            $history ,
        ) ;

        $status = ( new Transaction( $db , 'trx-42' ) )->commit() ;

        $this->assertSame( TransactionStatus::COMMITTED , $status ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                          , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/transaction/trx-42' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // abort()
    // =========================================================================

    public function testAbortSendsDeleteAndReturnsTerminalStatus() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":{"id":"trx-42","status":"aborted"}}' ) ] ,
            $history ,
        ) ;

        $status = ( new Transaction( $db , 'trx-42' ) )->abort() ;

        $this->assertSame( TransactionStatus::ABORTED , $status ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/transaction/trx-42' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // exists()
    // =========================================================================

    public function testExistsTrueOn2xx() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":{"id":"trx-42","status":"running"}}' ) ] ,
        ) ;

        $this->assertTrue( ( new Transaction( $db , 'trx-42' ) )->exists() ) ;
    }

    public function testExistsFalseOn404() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 404 , [] , '{"error":true,"errorNum":1655,"errorMessage":"transaction not found"}' ) ] ,
        ) ;

        $this->assertFalse( ( new Transaction( $db , 'trx-missing' ) )->exists() ) ;
    }

    public function testExistsRethrowsNon404Errors() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 500 , [] , '{"error":true,"errorNum":1234,"errorMessage":"server boom"}' ) ] ,
        ) ;

        $this->expectException( HttpException::class ) ;
        ( new Transaction( $db , 'trx-42' ) )->exists() ;
    }

    // =========================================================================
    // step() — runs callback with the trx-id installed as the active scope
    // =========================================================================

    public function testStepInstallsActiveTransactionIdForTheDurationOfTheCallback() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $trx = new Transaction( $db , 'trx-step-id' ) ;

        // Inside the callback: any plain Database::request() must carry the trx-id.
        $trx->step( function () use ( $db ) : void
        {
            $db->request( HttpMethod::GET , '/_api/version' ) ;
        } ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'trx-step-id' , $sent->getHeaderLine( 'x-arango-trx-id' ) ) ;
    }

    public function testStepRestoresPreviousScopeAfterCallback() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) , new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $trx = new Transaction( $db , 'trx-99' ) ;

        $trx->step( function () use ( $db ) : void { $db->request( HttpMethod::GET , '/_api/version' ) ; } ) ;

        // After the step: a fresh request must NOT carry the trx-id anymore.
        $db->request( HttpMethod::GET , '/_api/version' ) ;

        $this->assertSame( 'trx-99' , $history[ 0 ][ 'request' ]->getHeaderLine( 'x-arango-trx-id' ) ) ;
        $this->assertFalse(           $history[ 1 ][ 'request' ]->hasHeader( 'x-arango-trx-id' ) ) ;
    }

    public function testStepRestoresScopeEvenWhenCallbackThrows() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $trx = new Transaction( $db , 'trx-99' ) ;

        try
        {
            $trx->step( static fn() => throw new \RuntimeException( 'boom' ) ) ;
            $this->fail( 'step() must rethrow the callback exception' ) ;
        }
        catch ( \RuntimeException )
        {
            // expected
        }

        // After the failed step: a request must NOT carry the trx-id.
        $db->request( HttpMethod::GET , '/_api/version' ) ;
        $this->assertFalse( $history[ 0 ][ 'request' ]->hasHeader( 'x-arango-trx-id' ) ) ;
    }

    public function testStepReturnsCallbackResult() :void
    {
        $db  = $this->makeDatabase( [] ) ;
        $trx = new Transaction( $db , 'trx-99' ) ;

        $this->assertSame( 42 , $trx->step( static fn() : int => 42 ) ) ;
    }
}
