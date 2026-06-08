<?php

namespace tests\oihana\arango\clients\graph ;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Response ;

use Psr\Http\Message\RequestInterface ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\document\Document ;
use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\graph\Graph ;
use oihana\arango\clients\graph\GraphVertexCollection ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see GraphVertexCollection} — vertex-CRUD routed
 * through `/_api/gharial/{graph}/vertex/{collection}[/{key}]`.
 */
#[CoversClass( GraphVertexCollection::class )]
class GraphVertexCollectionTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int, Response>             $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function makeCollection
    (
        array  $responses ,
        array  &$history = [] ,
        string $graphName      = 'workplaces' ,
        string $collectionName = 'people' ,
        string $dbName         = 'mydb' ,
    )
    : GraphVertexCollection
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $options = new ClientOptions( database : $dbName , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        $client = new ArangoClient( options : $options , transport : $transport ) ;
        $db     = $client->database( $dbName ) ;
        $graph  = new Graph( $db , $graphName ) ;

        return $graph->vertexCollection( $collectionName ) ;
    }

    // =========================================================================
    // Construction
    // =========================================================================

    public function testGetNameAndGetGraph() :void
    {
        $coll = $this->makeCollection( [] ) ;

        $this->assertSame( 'people'      , $coll->getName()        ) ;
        $this->assertSame( 'people'      , $coll->name             ) ;
        $this->assertSame( 'workplaces'  , $coll->getGraph()->name ) ;
    }

    // =========================================================================
    // document() / documentExists()
    // =========================================================================

    public function testDocumentRoutesThroughGharialAndUnwrapsVertex() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"vertex":{"_key":"alice","_id":"people/alice","_rev":"r1","name":"Alice"}}' ) ] ,
            $history ,
        ) ;

        $doc = $coll->document( 'alice' ) ;

        $this->assertInstanceOf( Document::class , $doc ) ;
        $this->assertSame( 'alice'        , $doc->getKey() ) ;
        $this->assertSame( 'people/alice' , $doc->getId()  ) ;
        $this->assertSame( 'Alice'        , $doc->get( 'name' ) ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/vertex/people/alice'    , (string) $sent->getUri() ) ;
    }

    public function testDocumentExistsTrueOn2xx() :void
    {
        $coll = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"vertex":{"_key":"alice"}}' ) ] ,
        ) ;

        $this->assertTrue( $coll->documentExists( 'alice' ) ) ;
    }

    public function testDocumentExistsFalseOn404() :void
    {
        $coll = $this->makeCollection
        (
            [ new Response( 404 , [] , '{"error":true,"errorNum":1202}' ) ] ,
        ) ;

        $this->assertFalse( $coll->documentExists( 'missing' ) ) ;
    }

    public function testDocumentExistsRethrowsNon404Errors() :void
    {
        $coll = $this->makeCollection
        (
            [ new Response( 500 , [] , '{"error":true,"errorNum":1234,"errorMessage":"boom"}' ) ] ,
        ) ;

        $this->expectException( ArangoException::class ) ;
        $coll->documentExists( 'alice' ) ;
    }

    // =========================================================================
    // insert()
    // =========================================================================

    public function testInsertReturnsEmptyDocumentWhenBodyIsNotArray() :void
    {
        // A non-array decoded body (e.g. a bare JSON scalar) yields an empty
        // Document rather than blowing up in wrapWritten().
        $coll = $this->makeCollection
        (
            [ new Response( 200 , [] , '42' ) ] ,
        ) ;

        $doc = $coll->insert( [ 'name' => 'Alice' ] ) ;

        $this->assertNull( $doc->getKey() ) ;
    }

    public function testInsertPostsToCollectionPathAndUnwraps() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"vertex":{"_key":"alice","_id":"people/alice","_rev":"r1"}}' ) ] ,
            $history ,
        ) ;

        $doc = $coll->insert( [ 'name' => 'Alice' ] ) ;

        $this->assertSame( 'alice' , $doc->getKey() ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                                          , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/vertex/people'   , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( [ 'name' => 'Alice' ] , $payload ) ;
    }

    public function testInsertReturnNewMergesPayloadIntoDocument() :void
    {
        $coll = $this->makeCollection
        (
            [
                new Response
                (
                    201 , [] ,
                    '{"vertex":{"_key":"alice","_id":"people/alice","_rev":"r1"},' .
                    '"new":{"_key":"alice","_id":"people/alice","_rev":"r1","name":"Alice"}}' ,
                ) ,
            ] ,
        ) ;

        $doc = $coll->insert( [ 'name' => 'Alice' ] , [ 'returnNew' => true ] ) ;

        $this->assertSame( 'alice' , $doc->getKey() ) ;
        $this->assertSame( 'Alice' , $doc->get( 'name' ) ) ;
    }

    public function testInsertStringifiesBooleanOptions() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"vertex":{"_key":"alice"}}' ) ] ,
            $history ,
        ) ;

        $coll->insert
        (
            [ 'name' => 'Alice' ] ,
            [ 'returnNew' => true , 'waitForSync' => false ] ,
        ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        parse_str( $sent->getUri()->getQuery() , $q ) ;
        $this->assertSame( 'true'  , $q[ 'returnNew'   ] ) ;
        $this->assertSame( 'false' , $q[ 'waitForSync' ] ) ;
    }

    // =========================================================================
    // replace() / update() / remove()
    // =========================================================================

    public function testReplaceSendsPutOnDocumentPath() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"vertex":{"_key":"alice","_id":"people/alice","_rev":"r2"}}' ) ] ,
            $history ,
        ) ;

        $coll->replace( 'alice' , [ 'name' => 'Alice Liddell' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/vertex/people/alice' , (string) $sent->getUri() ) ;
    }

    public function testUpdateSendsPatchOnDocumentPath() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"vertex":{"_key":"alice","_rev":"r2"}}' ) ] ,
            $history ,
        ) ;

        $coll->update( 'alice' , [ 'role' => 'admin' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PATCH , $sent->getMethod() ) ;
    }

    public function testRemoveSendsDeleteOnDocumentPath() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"vertex":{"_key":"alice","_rev":"r2"}}' ) ] ,
            $history ,
        ) ;

        $coll->remove( 'alice' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                                                 , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/vertex/people/alice'      , (string) $sent->getUri() ) ;
    }

    public function testRemoveWithReturnOldMergesDeletedPayload() :void
    {
        $coll = $this->makeCollection
        (
            [
                new Response
                (
                    200 , [] ,
                    '{"vertex":{"_key":"alice","_rev":"r2"},' .
                    '"old":{"_key":"alice","_rev":"r1","name":"Alice"}}' ,
                ) ,
            ] ,
        ) ;

        $doc = $coll->remove( 'alice' , [ 'returnOld' => true ] ) ;
        $this->assertSame( 'Alice' , $doc->get( 'name' ) ) ;
    }

    // =========================================================================
    // URL-encoding
    // =========================================================================

    public function testCollectionAndKeyAreUrlEncoded() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"vertex":{}}' ) ] ,
            $history ,
            collectionName : 'weird coll/name' ,
        ) ;

        $coll->document( 'weird/key' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/vertex/weird%20coll%2Fname/weird%2Fkey' ,
            (string) $sent->getUri() ,
        ) ;
    }
}
