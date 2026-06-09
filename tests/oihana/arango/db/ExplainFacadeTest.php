<?php

namespace tests\oihana\arango\db;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Psr7\Response ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\db\results\ExplainResult ;

/**
 * Unit coverage for {@see \oihana\arango\db\ArangoDB::explain()} — verifies the
 * façade forwards the query to the client's `/_api/explain` and wraps the raw
 * plan into an {@see ExplainResult}. A real {@see \oihana\arango\clients\Database}
 * is wired around a mocked Guzzle handler (the `readonly` client class is not mocked).
 */
class ExplainFacadeTest extends ArangoDBTestCase
{
    public function testExplainWrapsClientResponse() : void
    {
        $raw =
        [
            'plan' =>
            [
                'nodes'       => [ [ 'type' => 'SingletonNode' ] , [ 'type' => 'ReturnNode' ] ] ,
                'rules'       => [ 'use-indexes' ] ,
                'collections' => [ [ 'name' => 'users' , 'type' => 'read' ] ] ,
            ] ,
            'cacheable' => true ,
            'warnings'  => [] ,
            'error'     => false ,
            'code'      => 200 ,
        ] ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $stack = HandlerStack::create( new MockHandler
        ([
            new Response( 200 , [ 'Content-Type' => 'application/json' ] , json_encode( $raw ) ) ,
        ]) ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        $client   = new ArangoClient( options : $options , transport : $transport ) ;
        $database = $client->database( 'mydb' ) ;

        $arangoDB = $this->newArangoDB( database : $database , client : $client ) ;

        $result = $arangoDB->explain( 'FOR u IN users RETURN u' , [ 'a' => 1 ] ) ;

        $this->assertInstanceOf( ExplainResult::class , $result ) ;
        $this->assertSame( [ 'use-indexes' ] , $result->rules() ) ;
        $this->assertSame( [ 'users' ] , $result->collections() ) ;
        $this->assertSame( [ 'SingletonNode' , 'ReturnNode' ] , $result->nodeTypes() ) ;
        $this->assertTrue( $result->isCacheable() ) ;
    }
}
