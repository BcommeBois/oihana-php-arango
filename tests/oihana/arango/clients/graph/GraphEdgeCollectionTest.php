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
use oihana\arango\clients\document\Edge ;
use oihana\arango\clients\graph\Graph ;
use oihana\arango\clients\graph\GraphEdgeCollection ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see GraphEdgeCollection} — edge-CRUD routed through
 * `/_api/gharial/{graph}/edge/{collection}[/{key}]`.
 */
#[CoversClass( GraphEdgeCollection::class )]
class GraphEdgeCollectionTest extends TestCase
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
        string $collectionName = 'employs' ,
        string $dbName         = 'mydb' ,
    )
    : GraphEdgeCollection
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

        return $graph->edgeCollection( $collectionName ) ;
    }

    // =========================================================================
    // Construction
    // =========================================================================

    public function testGetNameAndGetGraph() :void
    {
        $coll = $this->makeCollection( [] ) ;

        $this->assertSame( 'employs'    , $coll->getName()        ) ;
        $this->assertSame( 'employs'    , $coll->name             ) ;
        $this->assertSame( 'workplaces' , $coll->getGraph()->name ) ;
    }

    // =========================================================================
    // document() / documentExists()
    // =========================================================================

    public function testDocumentRoutesThroughGharialEdgeAndUnwrapsEdge() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"edge":{"_key":"e1","_id":"employs/e1","_rev":"r1","_from":"companies/acme","_to":"people/alice"}}' ) ] ,
            $history ,
        ) ;

        $doc = $coll->document( 'e1' ) ;

        $this->assertInstanceOf( Edge::class , $doc ) ;
        $this->assertSame( 'e1'             , $doc->getKey() ) ;
        $this->assertSame( 'employs/e1'     , $doc->getId()  ) ;
        $this->assertSame( 'companies/acme' , $doc->getFrom() ) ;
        $this->assertSame( 'people/alice'   , $doc->getTo()   ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/edge/employs/e1'    , (string) $sent->getUri() ) ;
    }

    public function testDocumentExistsTrueOn2xx() :void
    {
        $coll = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"edge":{"_key":"e1"}}' ) ] ,
        ) ;

        $this->assertTrue( $coll->documentExists( 'e1' ) ) ;
    }

    public function testDocumentExistsFalseOn404() :void
    {
        $coll = $this->makeCollection
        (
            [ new Response( 404 , [] , '{"error":true,"errorNum":1202}' ) ] ,
        ) ;

        $this->assertFalse( $coll->documentExists( 'missing' ) ) ;
    }

    // =========================================================================
    // insert()
    // =========================================================================

    public function testInsertPostsToEdgeCollectionPathAndUnwraps() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"edge":{"_key":"e1","_id":"employs/e1","_rev":"r1","_from":"companies/acme","_to":"people/alice"}}' ) ] ,
            $history ,
        ) ;

        $doc = $coll->insert
        ([
            '_from' => 'companies/acme' ,
            '_to'   => 'people/alice'   ,
            'since' => '2024-01-01'     ,
        ]) ;

        $this->assertSame( 'e1' , $doc->getKey() ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/edge/employs' , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'companies/acme' , $payload[ '_from' ] ) ;
        $this->assertSame( 'people/alice'   , $payload[ '_to'   ] ) ;
    }

    public function testInsertReturnNewMergesPayloadIntoDocument() :void
    {
        $coll = $this->makeCollection
        (
            [
                new Response
                (
                    201 , [] ,
                    '{"edge":{"_key":"e1","_id":"employs/e1","_rev":"r1","_from":"companies/acme","_to":"people/alice"},' .
                    '"new":{"_key":"e1","_id":"employs/e1","_rev":"r1","_from":"companies/acme","_to":"people/alice","since":"2024-01-01"}}' ,
                ) ,
            ] ,
        ) ;

        $doc = $coll->insert
        (
            [ '_from' => 'companies/acme' , '_to' => 'people/alice' , 'since' => '2024-01-01' ] ,
            [ 'returnNew' => true ] ,
        ) ;

        $this->assertSame( 'e1'         , $doc->getKey() ) ;
        $this->assertSame( '2024-01-01' , $doc->get( 'since' ) ) ;
    }

    // =========================================================================
    // replace() / update() / remove()
    // =========================================================================

    public function testReplaceSendsPutOnDocumentPath() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"edge":{"_key":"e1","_id":"employs/e1","_rev":"r2"}}' ) ] ,
            $history ,
        ) ;

        $coll->replace
        (
            'e1' ,
            [ '_from' => 'companies/acme' , '_to' => 'people/alice' , 'since' => '2024-06-01' ] ,
        ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/edge/employs/e1'    , (string) $sent->getUri() ) ;
    }

    public function testUpdateSendsPatchOnDocumentPath() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"edge":{"_key":"e1","_rev":"r2"}}' ) ] ,
            $history ,
        ) ;

        $coll->update( 'e1' , [ 'since' => '2024-06-01' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PATCH , $sent->getMethod() ) ;
    }

    public function testRemoveSendsDeleteOnDocumentPath() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"edge":{"_key":"e1","_rev":"r2"}}' ) ] ,
            $history ,
        ) ;

        $coll->remove( 'e1' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                                           , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/workplaces/edge/employs/e1'    , (string) $sent->getUri() ) ;
    }

    public function testRemoveWithReturnOldMergesDeletedPayload() :void
    {
        $coll = $this->makeCollection
        (
            [
                new Response
                (
                    200 , [] ,
                    '{"edge":{"_key":"e1","_rev":"r2"},' .
                    '"old":{"_key":"e1","_rev":"r1","_from":"companies/acme","_to":"people/alice","since":"2024-01-01"}}' ,
                ) ,
            ] ,
        ) ;

        $doc = $coll->remove( 'e1' , [ 'returnOld' => true ] ) ;
        $this->assertSame( '2024-01-01' , $doc->get( 'since' ) ) ;
    }

    public function testStringifiesBooleanOptions() :void
    {
        $history = [] ;
        $coll    = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"edge":{"_key":"e1"}}' ) ] ,
            $history ,
        ) ;

        $coll->insert
        (
            [ '_from' => 'companies/acme' , '_to' => 'people/alice' ] ,
            [ 'returnNew' => true , 'waitForSync' => false ] ,
        ) ;

        parse_str( $history[ 0 ][ 'request' ]->getUri()->getQuery() , $q ) ;
        $this->assertSame( 'true'  , $q[ 'returnNew'   ] ) ;
        $this->assertSame( 'false' , $q[ 'waitForSync' ] ) ;
    }
}
