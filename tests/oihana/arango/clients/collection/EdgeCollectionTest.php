<?php

namespace tests\oihana\arango\clients\collection ;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Response ;

use Psr\Http\Message\RequestInterface ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\collection\Collection ;
use oihana\arango\clients\collection\EdgeCollection ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see EdgeCollection} — edge-typed collection on top of
 * {@see Collection}. Verifies the AQL-based replacement of the
 * deprecated `/_api/simple/*` endpoints (`inEdges`, `outEdges`, `edges`)
 * and the {@see CollectionType::EDGE} default applied by `create()`.
 */
#[CoversClass( EdgeCollection::class )]
class EdgeCollectionTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int, Response>             $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function makeEdgeCollection
    (
        array  $responses ,
        array  &$history       = [] ,
        string $collectionName = 'follows' ,
    )
    : EdgeCollection
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        $client = new ArangoClient( options : $options , transport : $transport ) ;

        return $client->database()->edgeCollection( $collectionName ) ;
    }

    // =========================================================================
    // Inheritance
    // =========================================================================

    public function testIsAlsoACollection() :void
    {
        $col = $this->makeEdgeCollection( [] ) ;
        $this->assertInstanceOf( Collection::class , $col ) ;
    }

    // =========================================================================
    // create() — defaults to type EDGE
    // =========================================================================

    public function testCreateDefaultsToEdgeType() :void
    {
        $history = [] ;
        $col     = $this->makeEdgeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->create() ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;

        $this->assertSame( HttpMethod::POST , $sent->getMethod() ) ;
        $this->assertSame( 'follows' , $payload[ 'name' ] ) ;
        $this->assertSame( 3         , $payload[ 'type' ] ) ; // CollectionType::EDGE
    }

    public function testCreateLetsCallerOverrideType() :void
    {
        $history = [] ;
        $col     = $this->makeEdgeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        // Defensive case: explicit type wins.
        $col->create( [ 'type' => 2 ] ) ;

        $payload = json_decode( (string) $history[ 0 ][ 'request' ]->getBody() , associative : true ) ;
        $this->assertSame( 2 , $payload[ 'type' ] ) ;
    }

    // =========================================================================
    // inEdges / outEdges / edges — AQL via POST /_api/cursor
    // =========================================================================

    public function testInEdgesIssuesAqlQueryFilteringOnTo() :void
    {
        $history = [] ;
        $col     = $this->makeEdgeCollection
        (
            [ new Response( 201 , [] , '{"result":[{"_id":"follows/1"}],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $cursor = $col->inEdges( 'users/alice' ) ;

        $this->assertInstanceOf( Cursor::class , $cursor ) ;

        /** @var RequestInterface $sent */
        $sent    = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/cursor'      , (string) $sent->getUri() ) ;

        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame
        (
            'FOR e IN @@col FILTER e._to == @vertex RETURN e' ,
            $body[ 'query' ] ,
        ) ;
        $this->assertSame( 'follows'     , $body[ 'bindVars' ][ '@col'   ] ) ;
        $this->assertSame( 'users/alice' , $body[ 'bindVars' ][ 'vertex' ] ) ;
    }

    public function testOutEdgesIssuesAqlQueryFilteringOnFrom() :void
    {
        $history = [] ;
        $col     = $this->makeEdgeCollection
        (
            [ new Response( 201 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->outEdges( 'users/alice' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame
        (
            'FOR e IN @@col FILTER e._from == @vertex RETURN e' ,
            $body[ 'query' ] ,
        ) ;
        $this->assertSame( 'follows'     , $body[ 'bindVars' ][ '@col'   ] ) ;
        $this->assertSame( 'users/alice' , $body[ 'bindVars' ][ 'vertex' ] ) ;
    }

    public function testEdgesIssuesAqlQueryFilteringOnEitherSide() :void
    {
        $history = [] ;
        $col     = $this->makeEdgeCollection
        (
            [ new Response( 201 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->edges( 'users/alice' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame
        (
            'FOR e IN @@col FILTER e._from == @vertex OR e._to == @vertex RETURN e' ,
            $body[ 'query' ] ,
        ) ;
    }
}
