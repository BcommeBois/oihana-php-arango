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
use oihana\arango\clients\collection\indexes\PersistentIndex ;
use oihana\arango\clients\document\Document ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Collection} — CRUD operations scoped to a single
 * ArangoDB collection (Lot 5.1).
 *
 * The HTTP layer is exercised end-to-end through a mocked Guzzle client;
 * each test asserts both the wire-level request shape and the parsed
 * domain-level return value.
 */
#[CoversClass( Collection::class )]
class CollectionTest extends TestCase
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
        array  &$history       = [] ,
        string $collectionName = 'users' ,
        string $databaseName   = 'mydb' ,
    )
    : Collection
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $options = new ClientOptions
        (
            database  : $databaseName ,
            endpoints : [ 'http://127.0.0.1:8529' ] ,
        ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        $client = new ArangoClient( options : $options , transport : $transport ) ;

        return $client->database( $databaseName )->collection( $collectionName ) ;
    }

    // =========================================================================
    // Construction
    // =========================================================================

    public function testGetNameReturnsCollectionName() :void
    {
        $col = $this->makeCollection( [] , collectionName : 'orders' ) ;

        $this->assertSame( 'orders' , $col->getName() ) ;
    }

    // =========================================================================
    // count()
    // =========================================================================

    public function testCountSendsGetAndExtractsCount() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"count":12,"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $this->assertSame( 12 , $col->count() ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                          , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection/users/count' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // all() / byExample() / firstExample()
    // =========================================================================

    public function testAllSendsAqlForCollection() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->all() ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/cursor'      , (string) $sent->getUri() ) ;

        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR doc IN @@col RETURN doc' , $body[ 'query'    ] ) ;
        $this->assertSame( [ '@col' => 'users' ]          , $body[ 'bindVars' ] ) ;
    }

    public function testAllWithLimitOnlyAppendsLimit() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->all( 5 ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR doc IN @@col LIMIT @limit RETURN doc'        , $body[ 'query'    ] ) ;
        $this->assertSame( [ '@col' => 'users' , 'limit' => 5 ]              , $body[ 'bindVars' ] ) ;
    }

    public function testAllWithLimitAndOffsetAppendsLimitWithOffset() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->all( limit : 5 , offset : 10 ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR doc IN @@col LIMIT @offset, @limit RETURN doc'                     , $body[ 'query'    ] ) ;
        $this->assertSame( [ '@col' => 'users' , 'offset' => 10 , 'limit' => 5 ]                   , $body[ 'bindVars' ] ) ;
    }

    public function testByExampleEmptyMatchesAll() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->byExample( [] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR doc IN @@col RETURN doc' , $body[ 'query' ] ) ;
    }

    public function testByExampleSingleKeyEmitsFilter() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->byExample( [ 'name' => 'Marc' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR doc IN @@col FILTER doc.name == @v0 RETURN doc' , $body[ 'query'    ] ) ;
        $this->assertSame( [ '@col' => 'users' , 'v0' => 'Marc' ]                , $body[ 'bindVars' ] ) ;
    }

    public function testByExampleMultipleKeysJoinedWithAnd() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->byExample( [ 'role' => 'admin' , 'active' => true ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame
        (
            'FOR doc IN @@col FILTER doc.role == @v0 AND doc.active == @v1 RETURN doc' ,
            $body[ 'query' ] ,
        ) ;
        $this->assertSame
        (
            [ '@col' => 'users' , 'v0' => 'admin' , 'v1' => true ] ,
            $body[ 'bindVars' ] ,
        ) ;
    }

    public function testByExampleSupportsDottedPaths() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->byExample( [ 'address.city' => 'Paris' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR doc IN @@col FILTER doc.address.city == @v0 RETURN doc' , $body[ 'query' ] ) ;
    }

    public function testByExampleRejectsInvalidKey() :void
    {
        $col = $this->makeCollection( [] ) ;

        $this->expectException( \InvalidArgumentException::class ) ;
        $col->byExample( [ '1bad' => 'x' ] ) ;
    }

    public function testByExampleRejectsKeyWithSpaceOrSpecialChar() :void
    {
        $col = $this->makeCollection( [] ) ;

        $this->expectException( \InvalidArgumentException::class ) ;
        $col->byExample( [ 'first name' => 'Marc' ] ) ;
    }

    public function testFirstExampleReturnsDocumentOnMatch() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    200 ,
                    [] ,
                    '{"result":[{"_key":"a1","_id":"users/a1","_rev":"r","name":"Marc"}],"hasMore":false}' ,
                ) ,
            ] ,
        ) ;

        $doc = $col->firstExample( [ 'name' => 'Marc' ] ) ;

        $this->assertInstanceOf( Document::class , $doc ) ;
        $this->assertSame( 'a1'   , $doc->getKey() ) ;
        $this->assertSame( 'Marc' , $doc->get( 'name' ) ) ;
    }

    public function testFirstExampleReturnsNullOnEmpty() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
        ) ;

        $this->assertNull( $col->firstExample( [ 'name' => 'Marc' ] ) ) ;
    }

    public function testFirstExampleSendsLimit1() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $col->firstExample( [ 'name' => 'Marc' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $body = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertStringContainsString( 'LIMIT @limit'                  , $body[ 'query'    ] ) ;
        $this->assertSame( 1                                                , $body[ 'bindVars' ][ 'limit' ] ) ;
    }

    // =========================================================================
    // document() / documentExists()
    // =========================================================================

    public function testDocumentSendsGetAndReturnsDocument() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"_key":"abc","_id":"users/abc","_rev":"r1","name":"Marc"}' ) ] ,
            $history ,
        ) ;

        $doc = $col->document( 'abc' ) ;

        $this->assertInstanceOf( Document::class , $doc ) ;
        $this->assertSame( 'abc'       , $doc->getKey() ) ;
        $this->assertSame( 'users/abc' , $doc->getId()  ) ;
        $this->assertSame( 'Marc'      , $doc->get( 'name' ) ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users/abc'    , (string) $sent->getUri() ) ;
    }

    public function testDocumentExistsTrueOn200() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 200 , [] , '' ) ] ,
        ) ;

        $this->assertTrue( $col->documentExists( 'abc' ) ) ;
    }

    public function testDocumentExistsSendsHead() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '' ) ] ,
            $history ,
        ) ;

        $col->documentExists( 'abc' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::HEAD , $sent->getMethod() ) ;
    }

    public function testDocumentExistsFalseOn404() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    404 ,
                    [] ,
                    '{"error":true,"code":404,"errorNum":1202,"errorMessage":"document not found"}' ,
                ) ,
            ] ,
        ) ;

        $this->assertFalse( $col->documentExists( 'missing' ) ) ;
    }

    public function testDocumentExistsRethrowsOnNon404Failure() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    500 ,
                    [] ,
                    '{"error":true,"code":500,"errorMessage":"boom"}' ,
                ) ,
            ] ,
        ) ;

        $this->expectException( HttpException::class ) ;
        $col->documentExists( 'abc' ) ;
    }

    // =========================================================================
    // insert()
    // =========================================================================

    public function testInsertSendsPostAndReturnsMetaDocument() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"_key":"abc","_id":"users/abc","_rev":"r1"}' ) ] ,
            $history ,
        ) ;

        $doc = $col->insert( [ 'name' => 'Marc' ] ) ;

        $this->assertSame( 'abc'       , $doc->getKey() ) ;
        $this->assertSame( 'users/abc' , $doc->getId()  ) ;
        // No returnNew → payload is not in the response, so Document does not carry it.
        $this->assertNull( $doc->get( 'name' ) ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                         , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users'    , (string) $sent->getUri() ) ;
        $this->assertSame( '{"name":"Marc"}'                                        , (string) $sent->getBody() ) ;
    }

    public function testInsertReturnsEmptyDocumentWhenBodyIsNotArray() :void
    {
        // A non-array decoded body (e.g. a bare JSON scalar) yields an empty
        // Document rather than blowing up in wrapWritten().
        $col = $this->makeCollection
        (
            [ new Response( 200 , [] , '42' ) ] ,
        ) ;

        $doc = $col->insert( [ 'name' => 'Marc' ] ) ;

        $this->assertNull( $doc->getKey() ) ;
    }

    public function testReplaceAllReturnsEmptyListWhenBodyIsNotArray() :void
    {
        // A non-array decoded body yields an empty list rather than blowing up
        // in wrapWrittenBatch().
        $col = $this->makeCollection
        (
            [ new Response( 200 , [] , '42' ) ] ,
        ) ;

        $this->assertSame( [] , $col->replaceAll( [ [ '_key' => 'a' ] ] ) ) ;
    }

    public function testInsertWithReturnNewMergesPayloadInDocument() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [
                new Response
                (
                    201 ,
                    [] ,
                    '{"_key":"abc","_id":"users/abc","_rev":"r1","new":{"_key":"abc","_id":"users/abc","_rev":"r1","name":"Marc","role":"admin"}}' ,
                ) ,
            ] ,
            $history ,
        ) ;

        $doc = $col->insert
        (
            [ 'name' => 'Marc' , 'role' => 'admin' ] ,
            [ 'returnNew' => true ] ,
        ) ;

        $this->assertSame( 'abc'   , $doc->getKey() ) ;
        $this->assertSame( 'Marc'  , $doc->get( 'name' ) ) ;
        $this->assertSame( 'admin' , $doc->get( 'role' ) ) ;
        // The `new` field itself is consumed away.
        $this->assertFalse( $doc->has( 'new' ) ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertStringContainsString( 'returnNew=true' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // update() / replace()
    // =========================================================================

    public function testUpdateSendsPatchOnDocumentKey() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '{"_key":"abc","_id":"users/abc","_rev":"r2"}' ) ] ,
            $history ,
        ) ;

        $col->update( 'abc' , [ 'role' => 'admin' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PATCH                                          , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users/abc' , (string) $sent->getUri() ) ;
        $this->assertSame( '{"role":"admin"}'                                         , (string) $sent->getBody() ) ;
    }

    public function testReplaceSendsPutOnDocumentKey() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '{"_key":"abc","_id":"users/abc","_rev":"r3"}' ) ] ,
            $history ,
        ) ;

        $col->replace( 'abc' , [ 'name' => 'Marc' , 'role' => 'admin' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                            , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users/abc' , (string) $sent->getUri() ) ;
        $this->assertSame( '{"name":"Marc","role":"admin"}'                          , (string) $sent->getBody() ) ;
    }

    // =========================================================================
    // remove()
    // =========================================================================

    public function testRemoveSendsDeleteOnDocumentKey() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '{"_key":"abc","_id":"users/abc","_rev":"r1"}' ) ] ,
            $history ,
        ) ;

        $doc = $col->remove( 'abc' ) ;

        $this->assertSame( 'abc' , $doc->getKey() ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                         , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users/abc' , (string) $sent->getUri() ) ;
    }

    public function testRemoveWithReturnOldMergesPayloadInDocument() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    202 ,
                    [] ,
                    '{"_key":"abc","_id":"users/abc","_rev":"r1","old":{"_key":"abc","_id":"users/abc","_rev":"r1","name":"Marc"}}' ,
                ) ,
            ] ,
        ) ;

        $doc = $col->remove( 'abc' , [ 'returnOld' => true ] ) ;

        $this->assertSame( 'abc'  , $doc->getKey() ) ;
        $this->assertSame( 'Marc' , $doc->get( 'name' ) ) ;
        $this->assertFalse( $doc->has( 'old' ) ) ;
    }

    // =========================================================================
    // saveAll() / updateAll() / replaceAll() / removeAll()
    // =========================================================================

    public function testSaveAllSendsPostWithArrayBodyOnCollectionPath() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '[{"_key":"a","_id":"users/a","_rev":"r1"},{"_key":"b","_id":"users/b","_rev":"r2"}]' ) ] ,
            $history ,
        ) ;

        $col->saveAll( [ [ 'name' => 'Alice' ] , [ 'name' => 'Bob' ] ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users'           , (string) $sent->getUri() ) ;
        $this->assertSame
        (
            [ [ 'name' => 'Alice' ] , [ 'name' => 'Bob' ] ] ,
            json_decode( (string) $sent->getBody() , associative : true ) ,
        ) ;
    }

    public function testSaveAllReturnsOneDocumentPerEntry() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 202 , [] , '[{"_key":"a","_id":"users/a","_rev":"r1"},{"_key":"b","_id":"users/b","_rev":"r2"}]' ) ] ,
        ) ;

        $docs = $col->saveAll( [ [ 'name' => 'Alice' ] , [ 'name' => 'Bob' ] ] ) ;

        $this->assertCount( 2 , $docs ) ;
        $this->assertInstanceOf( Document::class , $docs[ 0 ] ) ;
        $this->assertSame( 'a' , $docs[ 0 ]->getKey() ) ;
        $this->assertSame( 'b' , $docs[ 1 ]->getKey() ) ;
    }

    public function testSaveAllWithReturnNewMergesEachPayload() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    202 ,
                    [] ,
                    '[' .
                        '{"_key":"a","_id":"users/a","_rev":"r1","new":{"_key":"a","_id":"users/a","_rev":"r1","name":"Alice"}},' .
                        '{"_key":"b","_id":"users/b","_rev":"r2","new":{"_key":"b","_id":"users/b","_rev":"r2","name":"Bob"}}' .
                    ']' ,
                ) ,
            ] ,
        ) ;

        $docs = $col->saveAll
        (
            [ [ 'name' => 'Alice' ] , [ 'name' => 'Bob' ] ] ,
            [ 'returnNew' => true ] ,
        ) ;

        $this->assertSame( 'Alice' , $docs[ 0 ]->get( 'name' ) ) ;
        $this->assertSame( 'Bob'   , $docs[ 1 ]->get( 'name' ) ) ;
    }

    public function testSaveAllSurfacesPerRowErrorsAsDocuments() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    202 ,
                    [] ,
                    '[' .
                        '{"_key":"a","_id":"users/a","_rev":"r1"},' .
                        '{"error":true,"errorNum":1210,"errorMessage":"unique constraint violated"}' .
                    ']' ,
                ) ,
            ] ,
        ) ;

        $docs = $col->saveAll( [ [ 'name' => 'Alice' ] , [ 'name' => 'Alice' ] ] ) ;

        $this->assertCount( 2 , $docs ) ;
        $this->assertSame( 'a'                            , $docs[ 0 ]->getKey() ) ;
        $this->assertFalse( $docs[ 0 ]->has( 'error' ) ) ;
        $this->assertTrue( $docs[ 1 ]->get( 'error' ) ) ;
        $this->assertSame( 1210                            , $docs[ 1 ]->get( 'errorNum' ) ) ;
    }

    public function testUpdateAllSendsPatchWithArrayBodyOnCollectionPath() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '[{"_key":"a","_id":"users/a","_rev":"r2"}]' ) ] ,
            $history ,
        ) ;

        $col->updateAll
        (
            [ [ '_key' => 'a' , 'role' => 'admin' ] ] ,
            [ 'returnNew' => true ] ,
        ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PATCH                                       , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/document/users?returnNew=true' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testUpdateAllReturnsOneDocumentPerEntry() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    202 ,
                    [] ,
                    '[{"_key":"a","_id":"users/a","_rev":"r2"},{"_key":"b","_id":"users/b","_rev":"r3"}]' ,
                ) ,
            ] ,
        ) ;

        $docs = $col->updateAll
        ([
            [ '_key' => 'a' , 'role' => 'admin' ] ,
            [ '_key' => 'b' , 'role' => 'user'  ] ,
        ]) ;

        $this->assertCount( 2 , $docs ) ;
        $this->assertSame( 'r2' , $docs[ 0 ]->getRev() ) ;
        $this->assertSame( 'r3' , $docs[ 1 ]->getRev() ) ;
    }

    public function testReplaceAllSendsPutWithArrayBodyOnCollectionPath() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '[{"_key":"a","_id":"users/a","_rev":"r2"}]' ) ] ,
            $history ,
        ) ;

        $col->replaceAll( [ [ '_key' => 'a' , 'name' => 'Replaced' ] ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users'   , (string) $sent->getUri() ) ;
    }

    public function testReplaceAllReturnsOneDocumentPerEntry() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 202 , [] , '[{"_key":"a","_id":"users/a","_rev":"r2"}]' ) ] ,
        ) ;

        $docs = $col->replaceAll( [ [ '_key' => 'a' , 'name' => 'Replaced' ] ] ) ;

        $this->assertCount( 1 , $docs ) ;
        $this->assertSame( 'a' , $docs[ 0 ]->getKey() ) ;
    }

    public function testRemoveAllSendsDeleteWithArrayBodyOnCollectionPath() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '[{"_key":"a","_id":"users/a","_rev":"r1"}]' ) ] ,
            $history ,
        ) ;

        $col->removeAll( [ 'a' , 'b' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                    , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/document/users'   , (string) $sent->getUri() ) ;
        $this->assertSame
        (
            [ 'a' , 'b' ] ,
            json_decode( (string) $sent->getBody() , associative : true ) ,
        ) ;
    }

    public function testRemoveAllAcceptsObjectSelectors() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 202 , [] , '[]' ) ] ,
            $history ,
        ) ;

        $col->removeAll
        ([
            [ '_key' => 'a' , '_rev' => 'r1' ] ,
            [ '_key' => 'b' , '_rev' => 'r2' ] ,
        ]) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            [ [ '_key' => 'a' , '_rev' => 'r1' ] , [ '_key' => 'b' , '_rev' => 'r2' ] ] ,
            json_decode( (string) $sent->getBody() , associative : true ) ,
        ) ;
    }

    public function testRemoveAllReturnsOneDocumentPerEntryAndSurfacesErrors() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    202 ,
                    [] ,
                    '[' .
                        '{"_key":"a","_id":"users/a","_rev":"r1"},' .
                        '{"error":true,"errorNum":1202,"errorMessage":"document not found"}' .
                    ']' ,
                ) ,
            ] ,
        ) ;

        $docs = $col->removeAll( [ 'a' , 'missing-key' ] ) ;

        $this->assertCount( 2 , $docs ) ;
        $this->assertSame( 'a'                            , $docs[ 0 ]->getKey() ) ;
        $this->assertTrue( $docs[ 1 ]->get( 'error' ) ) ;
        $this->assertSame( 1202                            , $docs[ 1 ]->get( 'errorNum' ) ) ;
    }

    // =========================================================================
    // import() — POST /_api/import?collection={name}&type=documents
    // =========================================================================

    public function testImportSendsPostWithJsonLinesBodyAndIdentityQueryParams() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":2,"errors":0,"empty":0,"updated":0,"ignored":0}' ) ] ,
            $history ,
        ) ;

        $col->import
        ([
            [ '_key' => 'alice' , 'name' => 'Alice' ] ,
            [ '_key' => 'bob'   , 'name' => 'Bob'   ] ,
        ]) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;

        $this->assertSame( HttpMethod::POST                                          , $sent->getMethod() ) ;

        $uri   = $sent->getUri() ;
        $this->assertSame( '/_db/mydb/_api/import' , $uri->getPath() ) ;

        parse_str( $uri->getQuery() , $query ) ;
        $this->assertSame( 'users'     , $query[ 'collection' ] ) ;
        $this->assertSame( 'documents' , $query[ 'type' ]       ) ;

        $body  = (string) $sent->getBody() ;
        $this->assertStringEndsWith( "\r\n" , $body ) ;

        $lines = explode( "\r\n" , rtrim( $body , "\r\n" ) ) ;
        $this->assertCount( 2 , $lines ) ;
        $this->assertSame
        (
            [ '_key' => 'alice' , 'name' => 'Alice' ] ,
            json_decode( $lines[ 0 ] , associative : true ) ,
        ) ;
        $this->assertSame
        (
            [ '_key' => 'bob' , 'name' => 'Bob' ] ,
            json_decode( $lines[ 1 ] , associative : true ) ,
        ) ;
    }

    public function testImportSendsLdjsonContentType() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":1,"errors":0,"empty":0,"updated":0,"ignored":0}' ) ] ,
            $history ,
        ) ;

        $col->import( [ [ 'name' => 'Alice' ] ] ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;

        $this->assertSame( 'application/x-ldjson' , $sent->getHeaderLine( 'Content-Type' ) ) ;
    }

    public function testImportReturnsImportResultParsedFromBody() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":5,"errors":2,"empty":1,"updated":3,"ignored":4,"details":["row 0 failed","row 7 failed"]}' ) ] ,
        ) ;

        $result = $col->import
        ([
            [ 'name' => 'Alice' ] ,
            [ 'name' => 'Bob'   ] ,
        ]) ;

        $this->assertSame( 5                                        , $result->created ) ;
        $this->assertSame( 2                                        , $result->errors  ) ;
        $this->assertSame( 1                                        , $result->empty   ) ;
        $this->assertSame( 3                                        , $result->updated ) ;
        $this->assertSame( 4                                        , $result->ignored ) ;
        $this->assertSame( [ 'row 0 failed' , 'row 7 failed' ]      , $result->details ) ;
        $this->assertTrue( $result->hasErrors() ) ;
    }

    public function testImportForwardsServerOptionsAndStringifiesBooleans() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":1,"errors":0,"empty":0,"updated":0,"ignored":0}' ) ] ,
            $history ,
        ) ;

        $col->import
        (
            [ [ '_key' => 'alice' , 'name' => 'Alice' ] ] ,
            [
                'overwrite'   => true ,
                'waitForSync' => true ,
                'complete'    => false ,
                'details'     => true ,
                'onDuplicate' => 'update' ,
                'fromPrefix'  => 'people/' ,
            ] ,
        ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;

        parse_str( $sent->getUri()->getQuery() , $query ) ;

        $this->assertSame( 'true'     , $query[ 'overwrite'   ] ) ;
        $this->assertSame( 'true'     , $query[ 'waitForSync' ] ) ;
        $this->assertSame( 'false'    , $query[ 'complete'    ] ) ;
        $this->assertSame( 'true'     , $query[ 'details'     ] ) ;
        $this->assertSame( 'update'   , $query[ 'onDuplicate' ] ) ;
        $this->assertSame( 'people/'  , $query[ 'fromPrefix'  ] ) ;
        $this->assertSame( 'users'    , $query[ 'collection'  ] ) ;
        $this->assertSame( 'documents', $query[ 'type'        ] ) ;
    }

    public function testImportCollectionAndTypeOptionsCannotBeOverridden() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":0,"errors":0,"empty":0,"updated":0,"ignored":0}' ) ] ,
            $history ,
        ) ;

        $col->import
        (
            [ [ 'name' => 'Alice' ] ] ,
            [
                'collection' => 'attacker' ,
                'type'       => 'array'    ,
            ] ,
        ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        parse_str( $sent->getUri()->getQuery() , $query ) ;

        $this->assertSame( 'users'     , $query[ 'collection' ] ) ;
        $this->assertSame( 'documents' , $query[ 'type'       ] ) ;
    }

    public function testImportEmptyArraySendsEmptyBody() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":0,"errors":0,"empty":0,"updated":0,"ignored":0}' ) ] ,
            $history ,
        ) ;

        $result = $col->import( [] ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( '' , (string) $sent->getBody() ) ;
        $this->assertFalse( $result->hasErrors() ) ;
    }

    public function testImportRejectsNonArrayEntries() :void
    {
        $col = $this->makeCollection( [] ) ;

        $this->expectException( \InvalidArgumentException::class ) ;
        $this->expectExceptionMessageMatches( '/entry #1 is string/' ) ;

        /** @phpstan-ignore-next-line — intentionally violating the type for the test */
        $col->import( [ [ 'name' => 'Alice' ] , 'not-an-array' ] ) ;
    }

    public function testImportPreservesUnicodeAndSlashesInJsonLines() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 201 , [] , '{"created":1,"errors":0,"empty":0,"updated":0,"ignored":0}' ) ] ,
            $history ,
        ) ;

        $col->import( [ [ 'name' => 'café' , 'url' => 'https://example.com/path' ] ] ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $body = (string) $sent->getBody() ;

        $this->assertStringContainsString( 'café'                       , $body ) ;
        $this->assertStringContainsString( 'https://example.com/path'   , $body ) ;
    }

    // =========================================================================
    // truncate()
    // =========================================================================

    public function testTruncateSendsPutTruncate() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->truncate() ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                                   , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection/users/truncate' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // create() — POST /_api/collection
    // =========================================================================

    public function testCreateDefaultsToDocumentType() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->create() ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection'  , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'users' , $payload[ 'name' ] ) ;
        $this->assertSame( 2       , $payload[ 'type' ] ) ; // CollectionType::DOCUMENT
    }

    public function testCreateForwardsExtraOptions() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->create
        (
            [
                'waitForSync'        => true ,
                'numberOfShards'     => 3 ,
                'replicationFactor'  => 2 ,
            ]
        ) ;

        $payload = json_decode( (string) $history[ 0 ][ 'request' ]->getBody() , associative : true ) ;
        $this->assertSame( 'users' , $payload[ 'name'              ] ) ;
        $this->assertSame( 2       , $payload[ 'type'              ] ) ;
        $this->assertTrue ( $payload[ 'waitForSync'       ] ) ;
        $this->assertSame( 3       , $payload[ 'numberOfShards'    ] ) ;
        $this->assertSame( 2       , $payload[ 'replicationFactor' ] ) ;
    }

    // =========================================================================
    // drop() — DELETE /_api/collection/{name}
    // =========================================================================

    public function testDropSendsDeleteOnCollection() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->drop() ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection/users' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // exists() — GET /_api/collection/{name} (404 → false)
    // =========================================================================

    public function testExistsTrueOn200() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"name":"users"}' ) ] ,
        ) ;

        $this->assertTrue( $col->exists() ) ;
    }

    public function testExistsSendsGetOnCollection() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"name":"users"}' ) ] ,
            $history ,
        ) ;

        $col->exists() ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                          , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection/users' , (string) $sent->getUri() ) ;
    }

    public function testExistsFalseOn404() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    404 ,
                    [] ,
                    '{"error":true,"code":404,"errorNum":1203,"errorMessage":"collection or view not found"}' ,
                ) ,
            ] ,
        ) ;

        $this->assertFalse( $col->exists() ) ;
    }

    public function testExistsRethrowsOnNon404Failure() :void
    {
        $col = $this->makeCollection
        (
            [
                new Response
                (
                    500 ,
                    [] ,
                    '{"error":true,"code":500,"errorMessage":"boom"}' ,
                ) ,
            ] ,
        ) ;

        $this->expectException( HttpException::class ) ;
        $col->exists() ;
    }

    // =========================================================================
    // properties() — GET /_api/collection/{name}/properties
    // =========================================================================

    public function testPropertiesReturnsRawBody() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [
                new Response
                (
                    200 ,
                    [] ,
                    '{"name":"users","type":2,"isSystem":false,"waitForSync":true,"globallyUniqueId":"h12/345"}' ,
                ) ,
            ] ,
            $history ,
        ) ;

        $properties = $col->properties() ;

        $this->assertSame( 'users'    , $properties[ 'name'             ] ) ;
        $this->assertSame( 2          , $properties[ 'type'             ] ) ;
        $this->assertFalse(  $properties[ 'isSystem'    ] ) ;
        $this->assertTrue (  $properties[ 'waitForSync' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                                     , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection/users/properties' , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // rename() — PUT /_api/collection/{name}/rename
    // =========================================================================

    public function testRenameReturnsNewInstanceWithNewName() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $renamed = $col->rename( 'employees' ) ;

        $this->assertInstanceOf( Collection::class , $renamed ) ;
        $this->assertSame( 'employees' , $renamed->getName() ) ;
        // The original instance keeps its old name.
        $this->assertSame( 'users'     , $col->getName() ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::PUT                                                 , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/collection/users/rename' , (string) $sent->getUri() ) ;
        $this->assertSame( '{"name":"employees"}'                                          , (string) $sent->getBody() ) ;
    }

    // =========================================================================
    // createIndex() / dropIndex() / indexes() — Lot 5.3
    // =========================================================================

    public function testCreateIndexPostsDefinitionPayload() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [
                new Response
                (
                    201 ,
                    [] ,
                    '{"id":"users/idx_email","type":"persistent","fields":["email"],"unique":true,"sparse":true}' ,
                ) ,
            ] ,
            $history ,
        ) ;

        $meta = $col->createIndex
        (
            new PersistentIndex
            (
                fields : [ 'email' ] ,
                unique : true ,
                sparse : true ,
                name   : 'idx_email' ,
            )
        ) ;

        $this->assertSame( 'users/idx_email' , $meta[ 'id'   ] ) ;
        $this->assertSame( 'persistent'      , $meta[ 'type' ] ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                            , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/index?collection=users' ,
            (string) $sent->getUri() ,
        ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'persistent' , $payload[ 'type'   ] ) ;
        $this->assertSame( [ 'email' ]  , $payload[ 'fields' ] ) ;
        $this->assertTrue ( $payload[ 'unique' ] ) ;
        $this->assertTrue ( $payload[ 'sparse' ] ) ;
        $this->assertSame( 'idx_email' , $payload[ 'name'   ] ) ;
    }

    public function testIndexAcceptsFullHandle() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [
                new Response
                (
                    200 ,
                    [] ,
                    '{"id":"users/idx_email","type":"persistent","fields":["email"],"unique":true}' ,
                ) ,
            ] ,
            $history ,
        ) ;

        $meta = $col->index( 'users/idx_email' ) ;

        $this->assertSame( 'users/idx_email' , $meta[ 'id'   ] ) ;
        $this->assertSame( 'persistent'      , $meta[ 'type' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                          , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/index/users/idx_email' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testIndexAcceptsBareKeyAndPrefixesCollection() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"id":"users/idx_email","type":"persistent"}' ) ] ,
            $history ,
        ) ;

        $col->index( 'idx_email' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/index/users/idx_email' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testDropIndexAcceptsFullHandle() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->dropIndex( 'users/12345' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                  , $sent->getMethod() ) ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/index/users/12345' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testDropIndexAcceptsBareKeyAndPrefixesCollection() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $col->dropIndex( 'idx_email' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/index/users/idx_email' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testIndexesReturnsServerEntriesArray() :void
    {
        $history = [] ;
        $col     = $this->makeCollection
        (
            [
                new Response
                (
                    200 ,
                    [] ,
                    '{"indexes":[' .
                        '{"id":"users/0","type":"primary","fields":["_key"]},' .
                        '{"id":"users/idx_email","type":"persistent","fields":["email"],"unique":true}' .
                    '],"identifiers":{},"error":false,"code":200}' ,
                ) ,
            ] ,
            $history ,
        ) ;

        $indexes = $col->indexes() ;

        $this->assertCount( 2 , $indexes ) ;
        $this->assertSame( 'primary'    , $indexes[ 0 ][ 'type' ] ) ;
        $this->assertSame( 'persistent' , $indexes[ 1 ][ 'type' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/index?collection=users' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testIndexesReturnsEmptyArrayWhenIndexesFieldMissing() :void
    {
        $col = $this->makeCollection
        (
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $col->indexes() ) ;
    }
}
