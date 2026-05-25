<?php

namespace tests\oihana\arango\clients\analyzer ;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Response ;

use oihana\arango\clients\exceptions\ArangoException;
use Psr\Http\Message\RequestInterface ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\analyzer\Analyzer ;
use oihana\arango\clients\analyzer\IdentityAnalyzer ;
use oihana\arango\clients\analyzer\NormAnalyzer ;
use oihana\arango\clients\analyzer\TextAnalyzer ;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Analyzer} — analyzer lifecycle and wire-shape
 * assertions on the `/_api/analyzer` surface.
 */
#[CoversClass( Analyzer::class )]
class AnalyzerTest extends TestCase
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
    )
    : Database
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

        return $client->database( 'mydb' ) ;
    }

    // =========================================================================
    // Construction
    // =========================================================================

    public function testGetNameAndProperties() :void
    {
        $db = $this->makeDatabase( [] ) ;
        $a  = new Analyzer( $db , 'my_text' ) ;

        $this->assertSame( 'my_text' , $a->getName() ) ;
        $this->assertSame( 'my_text' , $a->name      ) ;
        $this->assertSame( $db                , $a->database  ) ;
    }

    // =========================================================================
    // create()
    // =========================================================================

    /**
     * @return void
     * @throws ArangoException
     */
    public function testCreatePostsIdentityAnalyzerWithoutFeatures() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"name":"raw","type":"identity","features":[],"properties":{}}' ) ] ,
            $history ,
        ) ;

        $description = new Analyzer( $db , 'raw' )->create( new IdentityAnalyzer() ) ;

        $this->assertSame( 'raw'      , $description[ 'name' ] ) ;
        $this->assertSame( 'identity' , $description[ 'type' ] ) ;

        $this->assertCount( 1 , $history ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                    , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/analyzer'           , $request->getUri()->getPath() ) ;

        $body = json_decode( (string) $request->getBody() , true ) ;
        $this->assertSame( 'raw'      , $body[ 'name' ] ) ;
        $this->assertSame( 'identity' , $body[ 'type' ] ) ;
        $this->assertArrayHasKey( 'properties' , $body ) ;
        // The features array is intentionally omitted when empty.
        $this->assertArrayNotHasKey( 'features' , $body ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testCreatePostsTextAnalyzerWithFeaturesAndProperties() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{"name":"text_fr","type":"text","features":["frequency","position"],"properties":{"locale":"fr"}}' ) ] ,
            $history ,
        ) ;

        new Analyzer( $db , 'text_fr' )->create
        (
            new TextAnalyzer( locale : 'fr' , stemming : true ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $body    = json_decode( (string) $request->getBody() , true ) ;

        $this->assertSame( 'text_fr'                       , $body[ 'name' ] ) ;
        $this->assertSame( 'text'                          , $body[ 'type' ] ) ;
        $this->assertSame( [ 'frequency' , 'position' ]    , $body[ 'features' ] ) ;
        $this->assertSame( [ 'locale' => 'fr' , 'stemming' => true ] , $body[ 'properties' ] ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testCreateReindexesFeaturesToZeroBasedArray() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 201 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new Analyzer( $db , 'norm_en' )->create
        (
            new NormAnalyzer( locale : 'en' ) ,
            [ 5 => AnalyzerFeature::FREQUENCY , 10 => AnalyzerFeature::NORM ] ,
        ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $body    = json_decode( (string) $request->getBody() , true ) ;

        // JSON-decoded as a list, not an object with numeric string keys.
        $this->assertSame( [ 'frequency' , 'norm' ] , $body[ 'features' ] ) ;
    }

    // =========================================================================
    // get()
    // =========================================================================

    /**
     * @return void
     * @throws ArangoException
     */
    public function testGetReturnsRawDescription() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"name":"text_en","type":"text","features":["frequency"],"properties":{"locale":"en","stemming":true}}' ) ] ,
            $history ,
        ) ;

        $description = new Analyzer( $db , 'text_en' )->get() ;

        $this->assertSame( 'text_en' , $description[ 'name' ] ) ;
        $this->assertSame( 'text'    , $description[ 'type' ] ) ;
        $this->assertSame( 'en'      , $description[ 'properties' ][ 'locale' ] ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                   , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/analyzer/text_en' , $request->getUri()->getPath() ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testGetUrlEncodesAnalyzerName() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        new Analyzer( $db , 'my db::weird name' )->get() ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            '/_db/mydb/_api/analyzer/my%20db%3A%3Aweird%20name' ,
            $request->getUri()->getPath() ,
        ) ;
    }

    // =========================================================================
    // exists()
    // =========================================================================

    /**
     * @return void
     * @throws ArangoException
     */
    public function testExistsReturnsTrueOnTwoHundred() :void
    {
        $db = $this->makeDatabase( [ new Response( 200 , [] , '{}' ) ] ) ;

        $this->assertTrue( new Analyzer( $db , 'raw' )->exists() ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testExistsReturnsFalseOnFourOhFour() :void
    {
        $db = $this->makeDatabase( [ new Response( 404 , [] , '{"error":true,"code":404,"errorNum":1202}' ) ] ) ;

        $this->assertFalse( new Analyzer( $db , 'missing' )->exists() ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testExistsRethrowsNonNotFoundErrors() :void
    {
        $this->expectException( HttpException::class ) ;

        $db = $this->makeDatabase
        ( [
            new Response( 500 , [] , '{"error":true,"code":500,"errorMessage":"server crashed"}' ) ,
        ] ) ;

        new Analyzer( $db , 'raw' )->exists() ;
    }

    // =========================================================================
    // drop()
    // =========================================================================

    /**
     * @return void
     * @throws ArangoException
     */
    public function testDropSendsDelete() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"name":"text_en"}' ) ] ,
            $history ,
        ) ;

        new Analyzer( $db , 'text_en' )->drop() ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                , $request->getMethod() ) ;
        $this->assertSame( '/_db/mydb/_api/analyzer/text_en' , $request->getUri()->getPath() ) ;
        $this->assertSame( ''                                , $request->getUri()->getQuery() ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testDropForwardsForceFlagOnQueryString() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"name":"text_en"}' ) ] ,
            $history ,
        ) ;

        new Analyzer( $db , 'text_en' )->drop( force : true ) ;

        /** @var RequestInterface $request */
        $request = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'force=true' , $request->getUri()->getQuery() ) ;
    }
}
