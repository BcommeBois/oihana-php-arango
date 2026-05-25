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
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\graph\EdgeDefinition ;
use oihana\arango\clients\graph\Graph ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Graph} — graph lifecycle, vertex collection
 * membership, and edge definition management on the `/_api/gharial`
 * surface.
 */
#[CoversClass( Graph::class )]
class GraphTest extends TestCase
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

    public function testGetNameAndProperties() :void
    {
        $db = $this->makeDatabase( [] ) ;
        $g  = new Graph( $db , 'workplaces' ) ;

        $this->assertSame( 'workplaces' , $g->getName() ) ;
        $this->assertSame( 'workplaces' , $g->name      ) ;
        $this->assertSame( $db           , $g->database  ) ;
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function testCreatePostsGraphAndEdgeDefinitions() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"graph":{"name":"workplaces","edgeDefinitions":[],"orphanCollections":[]}}' ) ] ,
            $history ,
        ) ;

        $employs = new EdgeDefinition( 'employs' , [ 'companies' ] , [ 'people' ] ) ;
        $graph   = ( new Graph( $db , 'workplaces' ) )->create( [ $employs ] ) ;

        $this->assertSame( 'workplaces' , $graph[ 'name' ] ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                 , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial'   , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'workplaces' , $payload[ 'name' ] ) ;
        $this->assertSame
        (
            [ [ 'collection' => 'employs' , 'from' => [ 'companies' ] , 'to' => [ 'people' ] ] ] ,
            $payload[ 'edgeDefinitions' ] ,
        ) ;
    }

    public function testCreateForwardsOptionsWithoutOverridingNameOrEdgeDefinitions() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"graph":{"name":"g","edgeDefinitions":[]}}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'g' ) )->create
        (
            [] ,
            [
                'orphanCollections' => [ 'tags' ] ,
                'numberOfShards'    => 3        ,
                'name'              => 'attacker' ,    // ignored: own name wins
                'edgeDefinitions'   => 'attacker' ,    // ignored: own edge defs win
            ] ,
        ) ;

        $payload = json_decode( (string) $history[ 0 ][ 'request' ]->getBody() , associative : true ) ;
        $this->assertSame( 'g' ,                            $payload[ 'name'              ] ) ;
        $this->assertSame( []                  ,            $payload[ 'edgeDefinitions'   ] ) ;
        $this->assertSame( [ 'tags' ]          ,            $payload[ 'orphanCollections' ] ) ;
        $this->assertSame( 3                   ,            $payload[ 'numberOfShards'    ] ) ;
    }

    // =========================================================================
    // get() / exists()
    // =========================================================================

    public function testGetFetchesAndUnwrapsGraph() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"graph":{"name":"g","edgeDefinitions":[{"collection":"e","from":["a"],"to":["b"]}],"orphanCollections":["o"]}}' ) ] ,
            $history ,
        ) ;

        $graph = ( new Graph( $db , 'g' ) )->get() ;

        $this->assertSame( 'g'                                              , $graph[ 'name'              ] ) ;
        $this->assertSame( [ 'o' ]                                           , $graph[ 'orphanCollections' ] ) ;
        $this->assertCount( 1                                                , $graph[ 'edgeDefinitions'   ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/g'  , (string) $sent->getUri() ) ;
    }

    public function testExistsTrueOn2xx() :void
    {
        $db = $this->makeDatabase( [ new Response( 200 , [] , '{"graph":{"name":"g"}}' ) ] ) ;
        $this->assertTrue( ( new Graph( $db , 'g' ) )->exists() ) ;
    }

    public function testExistsFalseOn404() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 404 , [] , '{"error":true,"errorNum":1924,"errorMessage":"graph not found"}' ) ] ,
        ) ;
        $this->assertFalse( ( new Graph( $db , 'missing' ) )->exists() ) ;
    }

    public function testExistsRethrowsNon404() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 500 , [] , '{"error":true,"errorNum":42,"errorMessage":"boom"}' ) ] ,
        ) ;

        $this->expectException( HttpException::class ) ;
        ( new Graph( $db , 'g' ) )->exists() ;
    }

    // =========================================================================
    // drop()
    // =========================================================================

    public function testDropSendsDeleteWithoutDropCollectionsByDefault() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"removed":true}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'g' ) )->drop() ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/g' , (string) $sent->getUri() ) ;
    }

    public function testDropPropagatesDropCollectionsParam() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"removed":true}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'g' ) )->drop( dropCollections : true ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/gharial/g?dropCollections=true' ,
            (string) $sent->getUri() ,
        ) ;
    }

    // =========================================================================
    // vertexCollections() / edgeCollections() / orphanCollections() / edgeDefinitions()
    // =========================================================================

    public function testVertexCollectionsReturnsNamesFromCollectionsField() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"collections":["companies","people"]}' ) ] ,
        ) ;

        $this->assertSame
        (
            [ 'companies' , 'people' ] ,
            ( new Graph( $db , 'workplaces' ) )->vertexCollections() ,
        ) ;
    }

    public function testEdgeCollectionsReturnsNamesFromCollectionsField() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"collections":["employs","reports_to"]}' ) ] ,
        ) ;

        $this->assertSame
        (
            [ 'employs' , 'reports_to' ] ,
            ( new Graph( $db , 'workplaces' ) )->edgeCollections() ,
        ) ;
    }

    public function testOrphanCollectionsExtractsFromGet() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"graph":{"name":"g","orphanCollections":["tags","notes"]}}' ) ] ,
        ) ;

        $this->assertSame( [ 'tags' , 'notes' ] , ( new Graph( $db , 'g' ) )->orphanCollections() ) ;
    }

    public function testEdgeDefinitionsTypesEntriesAsValueObjects() :void
    {
        $db = $this->makeDatabase
        (
            [
                new Response
                (
                    200 , [] ,
                    '{"graph":{"name":"g","edgeDefinitions":[' .
                    '{"collection":"employs","from":["companies"],"to":["people"]},' .
                    '{"collection":"reports_to","from":["people"],"to":["people"]}' .
                    ']}}' ,
                ) ,
            ] ,
        ) ;

        $defs = ( new Graph( $db , 'g' ) )->edgeDefinitions() ;

        $this->assertCount( 2 , $defs ) ;
        $this->assertInstanceOf( EdgeDefinition::class , $defs[ 0 ] ) ;
        $this->assertSame( 'employs'    , $defs[ 0 ]->collection ) ;
        $this->assertSame( 'reports_to' , $defs[ 1 ]->collection ) ;
    }

    // =========================================================================
    // addVertexCollection() / removeVertexCollection()
    // =========================================================================

    public function testAddVertexCollectionPostsCollectionPayload() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"graph":{"name":"g","orphanCollections":["tags"]}}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'g' ) )->addVertexCollection( 'tags' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/g/vertex' , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( [ 'collection' => 'tags' ] , $payload ) ;
    }

    public function testRemoveVertexCollectionPropagatesDropCollectionParam() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"graph":{"name":"g"}}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'g' ) )->removeVertexCollection( 'tags' , dropCollection : true ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/gharial/g/vertex/tags?dropCollection=true' ,
            (string) $sent->getUri() ,
        ) ;
    }

    // =========================================================================
    // addEdgeDefinition() / replaceEdgeDefinition() / removeEdgeDefinition()
    // =========================================================================

    public function testAddEdgeDefinitionPostsDefinitionPayload() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"graph":{"name":"g"}}' ) ] ,
            $history ,
        ) ;

        $def = new EdgeDefinition( 'employs' , [ 'companies' ] , [ 'people' ] ) ;
        ( new Graph( $db , 'g' ) )->addEdgeDefinition( $def ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                     , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/g/edge' , (string) $sent->getUri() ) ;
        $this->assertSame( $def->toArray() , json_decode( (string) $sent->getBody() , associative : true ) ) ;
    }

    public function testReplaceEdgeDefinitionPutsOnSpecificCollection() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"graph":{"name":"g"}}' ) ] ,
            $history ,
        ) ;

        $def = new EdgeDefinition( 'employs' , [ 'companies' , 'startups' ] , [ 'people' ] ) ;
        ( new Graph( $db , 'g' ) )->replaceEdgeDefinition( $def ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                                , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial/g/edge/employs'  , (string) $sent->getUri() ) ;
        $this->assertSame( $def->toArray() , json_decode( (string) $sent->getBody() , associative : true ) ) ;
    }

    public function testRemoveEdgeDefinitionPropagatesDropCollectionParam() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"graph":{"name":"g"}}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'g' ) )->removeEdgeDefinition( 'employs' , dropCollection : true ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/gharial/g/edge/employs?dropCollection=true' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testGraphNameIsUrlEncoded() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"graph":{"name":"weird"}}' ) ] ,
            $history ,
        ) ;

        ( new Graph( $db , 'weird name/with-slash' ) )->get() ;

        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/gharial/weird%20name%2Fwith-slash' ,
            (string) $history[ 0 ][ 'request' ]->getUri() ,
        ) ;
    }
}
