<?php

namespace tests\oihana\arango\clients ;

use InvalidArgumentException ;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Response ;

use Psr\Http\Message\RequestInterface ;

use oihana\enums\http\HttpMethod ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\aql\AqlQuery ;
use oihana\arango\clients\collection\Collection ;
use oihana\arango\clients\collection\EdgeCollection ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;
use oihana\arango\clients\graph\EdgeDefinition ;
use oihana\arango\clients\graph\Graph ;
use oihana\arango\clients\transaction\Transaction ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Database} — operations scoped to a single ArangoDB database.
 *
 * Each test wires an `HttpTransport` around a mocked Guzzle client so the
 * URL routing and `/_db/{name}` prefixing are exercised end-to-end without
 * touching the network.
 */
#[CoversClass( Database::class )]
class DatabaseTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<int, Response>             $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function makeClient
    (
        ClientOptions $options ,
        array         $responses ,
        array         &$history = [] ,
    )
    : ArangoClient
    {
        $mock  = new MockHandler( $responses ) ;
        $stack = HandlerStack::create( $mock ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        return new ArangoClient( options : $options , transport : $transport ) ;
    }

    private function defaultOptions( ?string $database = null ) : ClientOptions
    {
        return new ClientOptions
        (
            database  : $database ,
            endpoints : [ 'http://127.0.0.1:8529' ] ,
        ) ;
    }

    // =========================================================================
    // Construction + name
    // =========================================================================

    public function testConstructWithClientAndName() :void
    {
        $client = new ArangoClient( $this->defaultOptions( 'foo' ) ) ;
        $db     = new Database( $client , 'mydb' ) ;

        $this->assertSame( $client , $db->client ) ;
        $this->assertSame( 'mydb'  , $db->name   ) ;
    }

    public function testGetNameReturnsName() :void
    {
        $client = new ArangoClient( $this->defaultOptions() ) ;
        $db     = new Database( $client , 'mydb' ) ;

        $this->assertSame( 'mydb' , $db->getName() ) ;
    }

    // =========================================================================
    // exists()
    // =========================================================================

    public function testExistsTrueWhenDatabaseIsInList() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":["_system","mydb"],"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertTrue( $client->database( 'mydb' )->exists() ) ;
    }

    public function testExistsFalseWhenDatabaseIsNotInList() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":["_system"],"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertFalse( $client->database( 'missing' )->exists() ) ;
    }

    // =========================================================================
    // create() / drop() — delegate to parent client
    // =========================================================================

    public function testCreateDelegatesToClient() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":true,"error":false,"code":201}' ) ] ,
            $history ,
        ) ;

        $client->database( 'fresh' )->create() ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                 , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/database'  , (string) $sent->getUri() ) ;
        $this->assertSame( '{"name":"fresh"}'                     , (string) $sent->getBody() ) ;
    }

    public function testDropDelegatesToClient() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":true,"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $client->database( 'gone' )->drop() ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::DELETE                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_api/database/gone'     , (string) $sent->getUri() ) ;
    }

    // =========================================================================
    // request() — scoped URL with /_db/{name} prefix
    // =========================================================================

    public function testRequestApplyDbPrefixToScopedRoutes() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":[],"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $client->database( 'mydb' )->request( HttpMethod::GET , '/_api/collection' ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/collection' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testRequestUsesDatabaseNameRegardlessOfOptions() :void
    {
        // Options point at 'configured', but the Database is bound to 'other'
        // → the request URL must use 'other', not 'configured'.
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'configured' ) ,
            [ new Response( 200 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $client->database( 'other' )->request( HttpMethod::GET , '/_api/collection' ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/other/_api/collection' ,
            (string) $sent->getUri() ,
        ) ;
    }

    // =========================================================================
    // collection() factory
    // =========================================================================

    public function testCollectionFactoryReturnsCollectionBoundToThisDatabase() :void
    {
        $client = new ArangoClient( $this->defaultOptions( 'mydb' ) ) ;
        $db     = $client->database() ;

        $col = $db->collection( 'users' ) ;

        $this->assertInstanceOf( Collection::class , $col ) ;
        $this->assertSame( 'users' , $col->getName() ) ;
        $this->assertSame( $db     , $col->database ) ;
    }

    public function testEdgeCollectionFactoryReturnsEdgeCollectionBoundToThisDatabase() :void
    {
        $client = new ArangoClient( $this->defaultOptions( 'mydb' ) ) ;
        $db     = $client->database() ;

        $col = $db->edgeCollection( 'follows' ) ;

        $this->assertInstanceOf( EdgeCollection::class , $col ) ;
        $this->assertInstanceOf( Collection::class     , $col ) ;
        $this->assertSame( 'follows' , $col->getName() ) ;
    }

    // =========================================================================
    // collections() — GET /_api/collection
    // =========================================================================

    public function testCollectionsExcludesSystemByDefault() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [
                new Response
                (
                    200 ,
                    [] ,
                    '{"result":[' .
                        '{"name":"users","isSystem":false,"type":2},' .
                        '{"name":"follows","isSystem":false,"type":3}' .
                    '],"error":false,"code":200}' ,
                ) ,
            ] ,
            $history ,
        ) ;

        $collections = $client->database()->collections() ;

        $this->assertCount( 2 , $collections ) ;
        $this->assertSame( 'users'   , $collections[ 0 ]->getName() ) ;
        $this->assertSame( 'follows' , $collections[ 1 ]->getName() ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/collection?excludeSystem=true' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testCollectionsIncludesSystemWhenAsked() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [ new Response( 200 , [] , '{"result":[],"error":false,"code":200}' ) ] ,
            $history ,
        ) ;

        $client->database()->collections( includeSystem : true ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/collection' ,
            (string) $sent->getUri() ,
        ) ;
    }

    public function testCollectionsSkipsEntriesWithoutName() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [
                new Response
                (
                    200 ,
                    [] ,
                    '{"result":[{"name":"users","type":2},{"type":2},"corrupt"],"error":false,"code":200}' ,
                ) ,
            ] ,
        ) ;

        $collections = $client->database()->collections() ;

        $this->assertCount( 1 , $collections ) ;
        $this->assertSame( 'users' , $collections[ 0 ]->getName() ) ;
    }

    public function testCollectionsReturnsEmptyArrayWhenResultMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions( 'mydb' ) ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $client->database()->collections() ) ;
    }

    // =========================================================================
    // query() — POST /_api/cursor + Cursor construction
    // =========================================================================

    public function testQueryStringFormBuildsAqlQueryInternally() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":[1,2],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $cursor = $client->database( 'mydb' )->query
        (
            'FOR u IN users FILTER u.role == @role RETURN u' ,
            [ 'role' => 'admin' ] ,
        ) ;

        $this->assertInstanceOf( Cursor::class , $cursor ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/cursor'       , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR u IN users FILTER u.role == @role RETURN u' , $payload[ 'query'    ] ) ;
        $this->assertSame( [ 'role' => 'admin' ]                            , $payload[ 'bindVars' ] ) ;
    }

    public function testQueryAqlQueryFormForwardsQueryAndBindVars() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":[],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $aql = new AqlQuery
        (
            'FOR u IN users FILTER u.age > @minAge RETURN u' ,
            [ 'minAge' => 21 ] ,
        ) ;

        $client->database( 'mydb' )->query( $aql ) ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( $aql->query                  , $payload[ 'query'    ] ) ;
        $this->assertSame( [ 'minAge' => 21 ]           , $payload[ 'bindVars' ] ) ;
    }

    public function testQueryThrowsWhenAqlQueryReceivesSeparateBindVars() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{}' ) ] ,
        ) ;

        $this->expectException( InvalidArgumentException::class ) ;

        $client->database( 'mydb' )->query
        (
            new AqlQuery( 'RETURN @a' , [ 'a' => 1 ] ) ,
            [ 'duplicate' => 2 ] ,
        ) ;
    }

    public function testQueryMergesOptionsIntoBody() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":[],"hasMore":false,"count":42}' ) ] ,
            $history ,
        ) ;

        $cursor = $client->database( 'mydb' )->query
        (
            'RETURN 1' ,
            options : [ 'count' => true , 'batchSize' => 100 ] ,
        ) ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'RETURN 1' , $payload[ 'query'     ] ) ;
        $this->assertTrue ( $payload[ 'count'     ] ) ;
        $this->assertSame ( 100 , $payload[ 'batchSize' ] ) ;
        $this->assertSame ( 42  , count( $cursor ) ) ;
    }

    public function testQueryReturnsCursorBoundToThisDatabase() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":[1],"hasMore":false}' ) ] ,
        ) ;

        $db     = $client->database( 'mydb' ) ;
        $cursor = $db->query( 'RETURN 1' ) ;

        $this->assertSame( $db , $cursor->database ) ;
    }

    // =========================================================================
    // explain() — POST /_api/explain
    // =========================================================================

    public function testExplainStringFormPostsQueryToExplainEndpoint() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"plan":{"nodes":[]},"warnings":[],"cacheable":true}' ) ] ,
            $history ,
        ) ;

        $plan = $client->database( 'mydb' )->explain( 'FOR u IN users RETURN u' ) ;

        $this->assertIsArray( $plan ) ;
        $this->assertArrayHasKey( 'plan' , $plan ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                       , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/explain'           , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( 'FOR u IN users RETURN u' , $payload[ 'query' ] ) ;
        $this->assertArrayNotHasKey( 'bindVars' , $payload ) ;
        $this->assertArrayNotHasKey( 'options'  , $payload ) ;
    }

    public function testExplainAqlQueryFormForwardsQueryAndBindVars() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"plan":{"nodes":[]},"warnings":[],"cacheable":true}' ) ] ,
            $history ,
        ) ;

        $aql = new AqlQuery
        (
            'FOR u IN users FILTER u.age > @minAge RETURN u' ,
            [ 'minAge' => 21 ] ,
        ) ;

        $client->database( 'mydb' )->explain( $aql ) ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( $aql->query        , $payload[ 'query'    ] ) ;
        $this->assertSame( [ 'minAge' => 21 ] , $payload[ 'bindVars' ] ) ;
    }

    public function testExplainStringFormCarriesBindVarsAndOptions() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"plans":[],"warnings":[],"cacheable":true}' ) ] ,
            $history ,
        ) ;

        $client->database( 'mydb' )->explain
        (
            'FOR u IN users FILTER u.role == @role RETURN u' ,
            [ 'role' => 'admin' ] ,
            [ 'allPlans' => true , 'maxNumberOfPlans' => 5 ] ,
        ) ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( [ 'role' => 'admin' ]                          , $payload[ 'bindVars' ] ) ;
        $this->assertSame( [ 'allPlans' => true , 'maxNumberOfPlans' => 5 ] , $payload[ 'options' ] ) ;
    }

    public function testExplainThrowsWhenAqlQueryReceivesSeparateBindVars() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{}' ) ] ,
        ) ;

        $this->expectException( InvalidArgumentException::class ) ;

        $client->database( 'mydb' )->explain
        (
            new AqlQuery( 'RETURN @a' , [ 'a' => 1 ] ) ,
            [ 'duplicate' => 2 ] ,
        ) ;
    }

    // =========================================================================
    // parse() — POST /_api/query
    // =========================================================================

    public function testParseStringFormPostsQueryToParserEndpoint() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"parsed":true,"collections":["users"],"bindVars":[],"ast":[]}' ) ] ,
            $history ,
        ) ;

        $result = $client->database( 'mydb' )->parse( 'FOR u IN users RETURN u' ) ;

        $this->assertIsArray( $result ) ;
        $this->assertTrue( $result[ 'parsed' ] ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                    , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/query'          , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( [ 'query' => 'FOR u IN users RETURN u' ] , $payload ) ;
    }

    public function testParseAqlQueryFormOnlyForwardsQueryString() :void
    {
        // Bind vars are intentionally ignored by /_api/query — only the
        // query string is parsed. The AqlQuery's ->bindVars must NOT
        // leak into the request body.
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"parsed":true}' ) ] ,
            $history ,
        ) ;

        $aql = new AqlQuery
        (
            'RETURN @x' ,
            [ 'x' => 42 ] ,
        ) ;

        $client->database( 'mydb' )->parse( $aql ) ;

        $sent    = $history[ 0 ][ 'request' ] ;
        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame( [ 'query' => 'RETURN @x' ] , $payload ) ;
    }

    // =========================================================================
    // request() — scoped URL with /_db/{name} prefix
    // =========================================================================

    public function testRequestPassesBodyQueryAndHeaders() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $client->database( 'mydb' )->request
        (
            method  : HttpMethod::POST ,
            path    : '/_api/document/users' ,
            body    : [ 'name' => 'Marc' ] ,
            query   : [ 'returnNew' => 'true' ] ,
            headers : [ 'X-Custom' => 'yes' ] ,
        ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame
        (
            'http://127.0.0.1:8529/_db/mydb/_api/document/users?returnNew=true' ,
            (string) $sent->getUri() ,
        ) ;
        $this->assertSame( '{"name":"Marc"}' , (string) $sent->getBody() ) ;
        $this->assertSame( 'yes'             , $sent->getHeaderLine( 'X-Custom' ) ) ;
    }

    // =========================================================================
    // beginTransaction() — POST /_api/transaction/begin
    // =========================================================================

    public function testBeginTransactionPostsCollectionsScopeAndReturnsHandle() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"code":201,"error":false,"result":{"id":"trx-007","status":"running"}}' ) ] ,
            $history ,
        ) ;

        $trx = $client->database( 'mydb' )->beginTransaction
        (
            write : [ 'users' , 'audits' ] ,
            read  : [ 'audits' ] ,
        ) ;

        $this->assertInstanceOf( Transaction::class , $trx ) ;
        $this->assertSame( 'trx-007' , $trx->id ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST                                            , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/transaction/begin'    , (string) $sent->getUri() ) ;

        $payload = json_decode( (string) $sent->getBody() , associative : true ) ;
        $this->assertSame
        (
            [
                'collections' => [
                    'write' => [ 'users' , 'audits' ] ,
                    'read'  => [ 'audits' ] ,
                ] ,
            ] ,
            $payload ,
        ) ;
    }

    public function testBeginTransactionForwardsExclusiveCollectionsAndOptions() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":{"id":"trx-99","status":"running"}}' ) ] ,
            $history ,
        ) ;

        $client->database( 'mydb' )->beginTransaction
        (
            write     : [ 'docs' ] ,
            exclusive : [ 'locks' ] ,
            options   : [ 'lockTimeout' => 30 , 'waitForSync' => true , 'skipFastLockRound' => true ] ,
        ) ;

        $payload = json_decode( (string) $history[ 0 ][ 'request' ]->getBody() , associative : true ) ;
        $this->assertSame
        (
            [
                'lockTimeout'       => 30 ,
                'waitForSync'       => true ,
                'skipFastLockRound' => true ,
                'collections'       => [
                    'write'     => [ 'docs' ] ,
                    'exclusive' => [ 'locks' ] ,
                ] ,
            ] ,
            $payload ,
        ) ;
    }

    public function testBeginTransactionOmitsEmptyCollectionBuckets() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"result":{"id":"trx-1","status":"running"}}' ) ] ,
            $history ,
        ) ;

        $client->database( 'mydb' )->beginTransaction( write : [ 'docs' ] ) ;

        $payload = json_decode( (string) $history[ 0 ][ 'request' ]->getBody() , associative : true ) ;
        $this->assertSame
        (
            [ 'collections' => [ 'write' => [ 'docs' ] ] ] ,
            $payload ,
        ) ;
        $this->assertArrayNotHasKey( 'read'      , $payload[ 'collections' ] ) ;
        $this->assertArrayNotHasKey( 'exclusive' , $payload[ 'collections' ] ) ;
    }

    public function testBeginTransactionParsesIdFromBareBodyWhenResultEnvelopeAbsent() :void
    {
        // Defensive parsing — some proxies / older servers may flatten the response.
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"id":"trx-bare","status":"running"}' ) ] ,
        ) ;

        $trx = $client->database( 'mydb' )->beginTransaction( write : [ 'docs' ] ) ;

        $this->assertSame( 'trx-bare' , $trx->id ) ;
    }

    // =========================================================================
    // transaction() factory — wraps an existing trx id, no HTTP call
    // =========================================================================

    public function testTransactionFactoryWrapsAnIdWithoutHttpCall() :void
    {
        $history = [] ;
        $client  = $this->makeClient( $this->defaultOptions() , [] , $history ) ;

        $trx = $client->database( 'mydb' )->transaction( 'trx-pre-existing' ) ;

        $this->assertInstanceOf( Transaction::class , $trx ) ;
        $this->assertSame( 'trx-pre-existing' , $trx->id ) ;
        $this->assertCount( 0 , $history , 'transaction() must not hit the network' ) ;
    }

    // =========================================================================
    // listTransactions() — GET /_api/transaction
    // =========================================================================

    public function testListTransactionsReturnsServerListing() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"transactions":[{"id":"trx-1","state":"running"},{"id":"trx-2","state":"running"}]}' ) ] ,
            $history ,
        ) ;

        $list = $client->database( 'mydb' )->listTransactions() ;

        $this->assertCount( 2 , $list ) ;
        $this->assertSame( 'trx-1' , $list[ 0 ][ 'id' ] ) ;
        $this->assertSame( 'trx-2' , $list[ 1 ][ 'id' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/transaction', (string) $sent->getUri() ) ;
    }

    public function testListTransactionsReturnsEmptyArrayWhenFieldMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $client->database( 'mydb' )->listTransactions() ) ;
    }

    // =========================================================================
    // withTransaction() — high-level commit/abort wrapper
    // =========================================================================

    public function testWithTransactionCommitsOnSuccessAndReturnsCallbackResult() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [
                new Response( 201 , [] , '{"result":{"id":"trx-1","status":"running"}}'   ) , // begin
                new Response( 200 , [] , '{"result":{"id":"trx-1","status":"committed"}}' ) , // commit
            ] ,
            $history ,
        ) ;

        $result = $client->database( 'mydb' )->withTransaction
        (
            callback : static fn( Transaction $trx ) : string => 'result-from-' . $trx->id ,
            write    : [ 'users' ] ,
        ) ;

        $this->assertSame( 'result-from-trx-1' , $result ) ;
        $this->assertSame( HttpMethod::POST , $history[ 0 ][ 'request' ]->getMethod() ) ; // begin
        $this->assertStringEndsWith( '/_api/transaction/begin' , $history[ 0 ][ 'request' ]->getUri()->getPath() ) ;
        $this->assertSame( HttpMethod::PUT , $history[ 1 ][ 'request' ]->getMethod() ) ; // commit
        $this->assertStringEndsWith( '/_api/transaction/trx-1' , $history[ 1 ][ 'request' ]->getUri()->getPath() ) ;
    }

    public function testWithTransactionAbortsAndRethrowsOnCallbackException() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [
                new Response( 201 , [] , '{"result":{"id":"trx-2","status":"running"}}' ) , // begin
                new Response( 200 , [] , '{"result":{"id":"trx-2","status":"aborted"}}' ) , // abort
            ] ,
            $history ,
        ) ;

        try
        {
            $client->database( 'mydb' )->withTransaction
            (
                callback : static fn() => throw new \RuntimeException( 'callback boom' ) ,
                write    : [ 'users' ] ,
            ) ;
            $this->fail( 'withTransaction must rethrow the callback exception' ) ;
        }
        catch ( \RuntimeException $e )
        {
            $this->assertSame( 'callback boom' , $e->getMessage() ) ;
        }

        $this->assertSame( HttpMethod::POST  , $history[ 0 ][ 'request' ]->getMethod() ) ;
        $this->assertSame( HttpMethod::DELETE, $history[ 1 ][ 'request' ]->getMethod() ) ;
        $this->assertStringEndsWith( '/_api/transaction/trx-2' , $history[ 1 ][ 'request' ]->getUri()->getPath() ) ;
    }

    public function testWithTransactionSwallowsAbortFailureAndStillRethrowsOriginal() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [
                new Response( 201 , [] , '{"result":{"id":"trx-3","status":"running"}}' ) , // begin
                new Response( 500 , [] , '{"error":true,"errorNum":1234,"errorMessage":"abort failed"}' ) , // abort
            ] ,
        ) ;

        try
        {
            $client->database( 'mydb' )->withTransaction
            (
                callback : static fn() => throw new \DomainException( 'business error' ) ,
                write    : [ 'users' ] ,
            ) ;
            $this->fail( 'withTransaction must rethrow the original exception even when abort fails' ) ;
        }
        catch ( \DomainException $e )
        {
            // The original exception wins. The abort failure is silently
            // swallowed because the caller cares about the cause, not the
            // cleanup hiccup.
            $this->assertSame( 'business error' , $e->getMessage() ) ;
        }
    }

    public function testWithTransactionPropagatesTransactionIdToInnerRequests() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [
                new Response( 201 , [] , '{"result":{"id":"trx-X","status":"running"}}'   ) , // begin
                new Response( 200 , [] , '{}'                                              ) , // inner request inside the callback
                new Response( 200 , [] , '{"result":{"id":"trx-X","status":"committed"}}' ) , // commit
            ] ,
            $history ,
        ) ;

        $db = $client->database( 'mydb' ) ;

        $db->withTransaction
        (
            callback : static function () use ( $db ) : void
            {
                // Any plain Database::request() inside the callback must
                // automatically carry the x-arango-trx-id header — the
                // helper installs the trx scope before calling us.
                $db->request( HttpMethod::GET , '/_api/version' ) ;
            } ,
            write    : [ 'users' ] ,
        ) ;

        // History: [0] begin, [1] inner GET, [2] commit.
        $this->assertCount( 3 , $history ) ;
        $this->assertSame( 'trx-X' , $history[ 1 ][ 'request' ]->getHeaderLine( 'x-arango-trx-id' ) ) ;
    }

    // =========================================================================
    // Graph factories — graph() / graphs() / listGraphs() / createGraph()
    // =========================================================================

    public function testGraphFactoryWrapsAName_NoHttp() :void
    {
        $history = [] ;
        $client  = $this->makeClient( $this->defaultOptions() , [] , $history ) ;

        $graph = $client->database( 'mydb' )->graph( 'workplaces' ) ;

        $this->assertInstanceOf( Graph::class , $graph ) ;
        $this->assertSame( 'workplaces' , $graph->name ) ;
        $this->assertCount( 0 , $history ) ;
    }

    public function testListGraphsReturnsServerListing() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"graphs":[{"_key":"g1","name":"workplaces"},{"_key":"g2","name":"friends"}]}' ) ] ,
            $history ,
        ) ;

        $list = $client->database( 'mydb' )->listGraphs() ;

        $this->assertCount( 2 , $list ) ;
        $this->assertSame( 'workplaces' , $list[ 0 ][ 'name' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                  , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/gharial'   , (string) $sent->getUri() ) ;
    }

    public function testListGraphsReturnsEmptyArrayWhenFieldMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $client->database( 'mydb' )->listGraphs() ) ;
    }

    public function testGraphsReturnsTypedHandlesFromListing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"graphs":[{"name":"workplaces"},{"name":"friends"},{"missingName":true}]}' ) ] ,
        ) ;

        $graphs = $client->database( 'mydb' )->graphs() ;

        $this->assertCount( 2 , $graphs , 'entries without a name string must be skipped' ) ;
        $this->assertSame( 'workplaces' , $graphs[ 0 ]->name ) ;
        $this->assertSame( 'friends'    , $graphs[ 1 ]->name ) ;
    }

    public function testCreateGraphHitsServerAndReturnsHandle() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"graph":{"name":"workplaces","edgeDefinitions":[]}}' ) ] ,
            $history ,
        ) ;

        $employs = new EdgeDefinition( 'employs' , [ 'companies' ] , [ 'people' ] ) ;
        $graph   = $client->database( 'mydb' )->createGraph( 'workplaces' , [ $employs ] ) ;

        $this->assertInstanceOf( Graph::class , $graph ) ;
        $this->assertSame( 'workplaces' , $graph->name ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST , $sent->getMethod() ) ;
        $this->assertStringEndsWith( '/_api/gharial' , $sent->getUri()->getPath() ) ;
    }

    // =========================================================================
    // Analyzer factories — analyzer() / analyzers() / listAnalyzers() / createAnalyzer()
    // =========================================================================

    public function testAnalyzerFactoryWrapsAName_NoHttp() :void
    {
        $history = [] ;
        $client  = $this->makeClient( $this->defaultOptions() , [] , $history ) ;

        $analyzer = $client->database( 'mydb' )->analyzer( 'text_fr' ) ;

        $this->assertInstanceOf( \oihana\arango\clients\analyzer\Analyzer::class , $analyzer ) ;
        $this->assertSame( 'text_fr' , $analyzer->name ) ;
        $this->assertCount( 0 , $history ) ;
    }

    public function testListAnalyzersReturnsServerListing() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":[{"name":"identity","type":"identity"},{"name":"mydb::text_fr","type":"text"}]}' ) ] ,
            $history ,
        ) ;

        $list = $client->database( 'mydb' )->listAnalyzers() ;

        $this->assertCount( 2 , $list ) ;
        $this->assertSame( 'identity'      , $list[ 0 ][ 'name' ] ) ;
        $this->assertSame( 'mydb::text_fr' , $list[ 1 ][ 'name' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                                 , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/analyzer' , (string) $sent->getUri() ) ;
    }

    public function testListAnalyzersReturnsEmptyArrayWhenFieldMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $client->database( 'mydb' )->listAnalyzers() ) ;
    }

    public function testAnalyzersReturnsTypedHandlesFromListing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":[{"name":"identity"},{"name":"mydb::text_fr"},{"missingName":true}]}' ) ] ,
        ) ;

        $analyzers = $client->database( 'mydb' )->analyzers() ;

        $this->assertCount( 2 , $analyzers , 'entries without a name string must be skipped' ) ;
        $this->assertSame( 'identity'      , $analyzers[ 0 ]->name ) ;
        $this->assertSame( 'mydb::text_fr' , $analyzers[ 1 ]->name ) ;
    }

    public function testCreateAnalyzerHitsServerAndReturnsHandle() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"name":"text_en","type":"text"}' ) ] ,
            $history ,
        ) ;

        $analyzer = $client->database( 'mydb' )->createAnalyzer
        (
            'text_en' ,
            new \oihana\arango\clients\analyzer\TextAnalyzer( locale : 'en' ) ,
            [ \oihana\arango\clients\analyzer\enums\AnalyzerFeature::FREQUENCY ] ,
        ) ;

        $this->assertInstanceOf( \oihana\arango\clients\analyzer\Analyzer::class , $analyzer ) ;
        $this->assertSame( 'text_en' , $analyzer->name ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST , $sent->getMethod() ) ;
        $this->assertStringEndsWith( '/_api/analyzer' , $sent->getUri()->getPath() ) ;

        $body = json_decode( (string) $sent->getBody() , true ) ;
        $this->assertSame( 'text_en'     , $body[ 'name' ] ) ;
        $this->assertSame( 'text'        , $body[ 'type' ] ) ;
        $this->assertSame( [ 'frequency' ] , $body[ 'features' ] ) ;
        $this->assertSame( [ 'locale' => 'en' ] , $body[ 'properties' ] ) ;
    }

    // =========================================================================
    // View factories — view() / views() / listViews() / createView()
    // =========================================================================

    public function testViewFactoryWrapsAName_NoHttp() :void
    {
        $history = [] ;
        $client  = $this->makeClient( $this->defaultOptions() , [] , $history ) ;

        $view = $client->database( 'mydb' )->view( 'articles_view' ) ;

        $this->assertInstanceOf( \oihana\arango\clients\view\View::class , $view ) ;
        $this->assertSame( 'articles_view' , $view->name ) ;
        $this->assertCount( 0 , $history ) ;
    }

    public function testListViewsReturnsServerListing() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":[{"name":"v1","type":"arangosearch","id":"1","globallyUniqueId":"abc"},{"name":"v2","type":"arangosearch","id":"2","globallyUniqueId":"def"}]}' ) ] ,
            $history ,
        ) ;

        $list = $client->database( 'mydb' )->listViews() ;

        $this->assertCount( 2 , $list ) ;
        $this->assertSame( 'v1' , $list[ 0 ][ 'name' ] ) ;
        $this->assertSame( 'v2' , $list[ 1 ][ 'name' ] ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::GET                              , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/view' , (string) $sent->getUri() ) ;
    }

    public function testListViewsReturnsEmptyArrayWhenFieldMissing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"error":false,"code":200}' ) ] ,
        ) ;

        $this->assertSame( [] , $client->database( 'mydb' )->listViews() ) ;
    }

    public function testViewsReturnsTypedHandlesFromListing() :void
    {
        $client = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 200 , [] , '{"result":[{"name":"v1"},{"name":"v2"},{"missingName":true}]}' ) ] ,
        ) ;

        $views = $client->database( 'mydb' )->views() ;

        $this->assertCount( 2 , $views , 'entries without a name string must be skipped' ) ;
        $this->assertSame( 'v1' , $views[ 0 ]->name ) ;
        $this->assertSame( 'v2' , $views[ 1 ]->name ) ;
    }

    public function testCreateViewHitsServerAndReturnsHandle() :void
    {
        $history = [] ;
        $client  = $this->makeClient
        (
            $this->defaultOptions() ,
            [ new Response( 201 , [] , '{"name":"articles_view","type":"arangosearch","id":"42"}' ) ] ,
            $history ,
        ) ;

        $view = $client->database( 'mydb' )->createView
        (
            'articles_view' ,
            links :
            [
                'articles' => new \oihana\arango\clients\view\ArangoSearchLink
                (
                    analyzers : [ 'text_en' ] ,
                ) ,
            ] ,
        ) ;

        $this->assertInstanceOf( \oihana\arango\clients\view\View::class , $view ) ;
        $this->assertSame( 'articles_view' , $view->name ) ;

        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( HttpMethod::POST , $sent->getMethod() ) ;
        $this->assertStringEndsWith( '/_api/view' , $sent->getUri()->getPath() ) ;

        $body = json_decode( (string) $sent->getBody() , true ) ;
        $this->assertSame( 'articles_view' , $body[ 'name' ] ) ;
        $this->assertSame( 'arangosearch'  , $body[ 'type' ] ) ;
        $this->assertSame( [ 'analyzers' => [ 'text_en' ] ] , $body[ 'links' ][ 'articles' ] ) ;
    }
}
