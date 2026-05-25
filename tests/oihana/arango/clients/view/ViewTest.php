<?php

namespace tests\oihana\arango\clients\view ;

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
use oihana\arango\clients\view\ArangoSearchLink ;
use oihana\arango\clients\view\View ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see View} — arangosearch view lifecycle + per-view
 * configuration on the `/_api/view` surface.
 */
#[CoversClass( View::class )]
class ViewTest extends TestCase
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
        $v  = new View( $db , 'my_view' ) ;

        $this->assertSame( 'my_view' , $v->getName() ) ;
        $this->assertSame( 'my_view' , $v->name      ) ;
        $this->assertSame( $db       , $v->database  ) ;
    }

    // =========================================================================
    // create()
    // =========================================================================

    public function testCreateMinimalPostsTypeAndName() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"name":"my_view","type":"arangosearch","id":"1","globallyUniqueId":"abc"}' ) ] ,
            $history ,
        ) ;

        $description = new View( $db , 'my_view' )->create() ;

        $this->assertSame( 'my_view'      , $description[ 'name' ] ) ;
        $this->assertSame( 'arangosearch' , $description[ 'type' ] ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST              , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/view'         , $request->getUri()->getPath() ) ;

        $body = json_decode( (string) $request->getBody() , true ) ;
        $this->assertSame( 'my_view'      , $body[ 'name' ] ) ;
        $this->assertSame( 'arangosearch' , $body[ 'type' ] ) ;
        $this->assertArrayNotHasKey( 'links' , $body ) ;
    }

    public function testCreateNormalisesArangoSearchLinks() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'articles_view' )->create
        (
            links :
            [
                'articles' => new ArangoSearchLink
                (
                    analyzers : [ 'identity' ] ,
                    fields    :
                    [
                        'title' => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
                    ] ,
                ) ,
            ] ,
        ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $body    = json_decode( (string) $request->getBody() , true ) ;

        $this->assertSame
        (
            [
                'analyzers' => [ 'identity' ] ,
                'fields'    =>
                [
                    'title' => [ 'analyzers' => [ 'text_en' ] ] ,
                ] ,
            ] ,
            $body[ 'links' ][ 'articles' ] ,
        ) ;
    }

    public function testCreateAcceptsPlainArrayLinks() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'view_a' )->create
        (
            links :
            [
                'coll' => [ 'analyzers' => [ 'identity' ] ] ,
            ] ,
        ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $body    = json_decode( (string) $request->getBody() , true ) ;

        $this->assertSame
        (
            [ 'analyzers' => [ 'identity' ] ] ,
            $body[ 'links' ][ 'coll' ] ,
        ) ;
    }

    public function testCreateMergesOptionsAndForcesNameAndType() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'view_b' )->create
        (
            options :
            [
                'cleanupIntervalStep'       => 5 ,
                'consolidationIntervalMsec' => 5000 ,
                // These would be ignored — name + type are forced.
                'name'                      => 'OVERRIDE_ATTEMPT' ,
                'type'                      => 'search-alias' ,
            ] ,
        ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $body    = json_decode( (string) $request->getBody() , true ) ;

        $this->assertSame( 'view_b'      , $body[ 'name' ] ) ;
        $this->assertSame( 'arangosearch' , $body[ 'type' ] ) ;
        $this->assertSame( 5              , $body[ 'cleanupIntervalStep' ] ) ;
        $this->assertSame( 5000           , $body[ 'consolidationIntervalMsec' ] ) ;
    }

    // =========================================================================
    // get()
    // =========================================================================

    public function testGetReturnsDescription() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"name":"my_view","type":"arangosearch","id":"42","globallyUniqueId":"xyz"}' ) ] ,
            $history ,
        ) ;

        $description = new View( $db , 'my_view' )->get() ;

        $this->assertSame( 'my_view'      , $description[ 'name' ] ) ;
        $this->assertSame( 'arangosearch' , $description[ 'type' ] ) ;
        $this->assertSame( '42'           , $description[ 'id' ] ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/view/my_view' , $request->getUri()->getPath() ) ;
    }

    public function testGetUrlEncodesViewName() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase( [ new Response( 200 , [] , '{}' ) ] , $history ) ;

        new View( $db , 'weird name' )->get() ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( '/_db/mydb/_api/view/weird%20name' , $request->getUri()->getPath() ) ;
    }

    // =========================================================================
    // exists()
    // =========================================================================

    public function testExistsReturnsTrueOnTwoHundred() :void
    {
        $db = $this->makeDatabase( [ new Response( 200 , [] , '{}' ) ] ) ;

        $this->assertTrue( new View( $db , 'my_view' )->exists() ) ;
    }

    public function testExistsReturnsFalseOnFourOhFour() :void
    {
        $db = $this->makeDatabase( [ new Response( 404 , [] , '{"error":true,"code":404}' ) ] ) ;

        $this->assertFalse( new View( $db , 'missing' )->exists() ) ;
    }

    public function testExistsRethrowsNonNotFoundErrors() :void
    {
        $this->expectException( HttpException::class ) ;

        $db = $this->makeDatabase
        ( [
            new Response( 500 , [] , '{"error":true,"code":500}' ) ,
        ] ) ;

        new View( $db , 'my_view' )->exists() ;
    }

    // =========================================================================
    // properties() / updateProperties() / replaceProperties()
    // =========================================================================

    public function testPropertiesHitsPropertiesSubRoute() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"name":"my_view","type":"arangosearch","links":{}}' ) ] ,
            $history ,
        ) ;

        $properties = new View( $db , 'my_view' )->properties() ;

        $this->assertSame( [] , $properties[ 'links' ] ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                            , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/view/my_view/properties'  , $request->getUri()->getPath() ) ;
    }

    public function testUpdatePropertiesSendsPatchAndNormalisesLinks() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'my_view' )->updateProperties
        ( [
            'cleanupIntervalStep' => 4 ,
            'links'               =>
            [
                'coll' => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
            ] ,
        ] ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PATCH                          , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/view/my_view/properties'  , $request->getUri()->getPath() ) ;

        $body = json_decode( (string) $request->getBody() , true ) ;
        $this->assertSame( 4                                  , $body[ 'cleanupIntervalStep' ] ) ;
        $this->assertSame( [ 'analyzers' => [ 'text_en' ] ]   , $body[ 'links' ][ 'coll' ] ) ;
    }

    public function testReplacePropertiesSendsPutAndNormalisesLinks() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'my_view' )->replaceProperties
        ( [
            'links' =>
            [
                'coll' => new ArangoSearchLink( analyzers : [ 'identity' ] ) ,
            ] ,
        ] ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                            , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/view/my_view/properties'  , $request->getUri()->getPath() ) ;
    }

    public function testReplacePropertiesAcceptsEmptyBodyToResetEverything() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'my_view' )->replaceProperties() ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( '[]' , (string) $request->getBody() ) ;
    }

    // =========================================================================
    // drop()
    // =========================================================================

    public function testDropSendsDelete() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":true}' ) ] ,
            $history ,
        ) ;

        new View( $db , 'my_view' )->drop() ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE              , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/view/my_view'  , $request->getUri()->getPath() ) ;
    }
}
