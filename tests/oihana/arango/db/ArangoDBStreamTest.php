<?php

namespace tests\oihana\arango\db;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

use oihana\arango\clients\ArangoClient;
use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\http\HostRing;
use oihana\arango\clients\http\HttpTransport;
use oihana\arango\clients\http\RetryPolicy;
use oihana\arango\clients\options\ClientOptions;
use oihana\arango\db\ArangoDB;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * {@see ArangoDB::streamDocuments()} and the one cursor slot the façade shares with every
 * model.
 *
 * The stream used to empty that slot when it ended — whoever owned it by then. The total
 * and the profile of the stream were lost after the loop, an abandoned stream released
 * after another query wiped that query's total, and an abandoned cursor stayed open on
 * the server until its `ttl`. Real {@see Cursor} objects run here, over a database whose
 * HTTP is canned, so the `DELETE` a close sends can be seen.
 */
#[AllowMockObjectsWithoutExpectations] // the harness builds a bare ArangoClient mock
final class ArangoDBStreamTest extends ArangoDBTestCase
{
    /**
     * A database whose HTTP answers are canned — the cursors' own connection.
     *
     * @param array<int, Response>             $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function http( array $responses , array &$history = [] ) :Database
    {
        $stack = HandlerStack::create( new MockHandler( $responses ) ) ;
        $stack->push( Middleware::history( $history ) ) ;

        $options = new ClientOptions( database : 'mydb' , endpoints : [ 'http://127.0.0.1:8529' ] ) ;

        $transport = new HttpTransport
        (
            options     : $options ,
            httpClient  : new Client( [ 'handler' => $stack ] ) ,
            retryPolicy : new RetryPolicy( maxAttempts : 1 , baseDelayMs : 0 , maxDelayMs : 0 ) ,
            hostRing    : new HostRing( $options->endpoints ) ,
        ) ;

        return new ArangoClient( options : $options , transport : $transport )->database( 'mydb' ) ;
    }

    /**
     * A façade whose successive executions open the given cursors, in order.
     *
     * @param array<int, Cursor> $cursors
     */
    private function facade( array $cursors , ?LoggerInterface $logger = null ) :ArangoDB
    {
        $database = $this->createStub( Database::class ) ;
        $database->method( 'query' )->willReturnCallback( function() use ( &$cursors ) :Cursor
        {
            return array_shift( $cursors ) ;
        } ) ;

        return $this->newArangoDB( $database , null , null , $logger ) ;
    }

    private function execute( ArangoDB $db , string $query ) :void
    {
        $db->prepare( [ CursorField::QUERY => $query ] )->execute() ;
    }

    private static function extra( int $fullCount ) :array
    {
        return [ 'stats' => [ 'fullCount' => $fullCount ] , 'profile' => [ 'executing' => 0.1 ] ] ;
    }

    public function testTheTotalAndTheProfileOutliveACompleteStream() :void
    {
        $history = [] ;
        $cursor  = new Cursor( $this->http( [] , $history ) , [ 'result' => [ 1 , 2 , 3 ] , 'hasMore' => false , 'extra' => self::extra( 42 ) ] ) ;
        $db      = $this->facade( [ $cursor ] ) ;

        $this->execute( $db , 'stream' ) ;
        $this->assertCount( 3 , iterator_to_array( $db->streamDocuments() ) ) ;

        $this->assertSame( 42 , $db->getFoundRows() ) ;
        $this->assertSame( [ 'executing' => 0.1 ] , $db->getExtra()[ 'profile' ] ) ;

        // The rows are let go of — the memory the old reset freed is still freed.
        $this->assertSame( [] , ( fn() => $this->batch )->call( $db->getCursor() ) ) ;

        // A drained cursor is closed by the server already : nothing is sent.
        $this->assertCount( 0 , $history ) ;
    }

    /**
     * 🚨 The generator's `finally` runs when the generator is released — after whatever
     * query ran in between. It used to empty the slot that query then owned.
     */
    public function testAnAbandonedStreamReleasedLaterLeavesTheNextQueryAlone() :void
    {
        $stream = new Cursor( $this->http( [ new Response( 202 , [] , '{}' ) ] ) , [ 'result' => [ 1 , 2 ] , 'hasMore' => true , 'id' => 'c-1' , 'extra' => self::extra( 42 ) ] ) ;
        $list   = new Cursor( $this->http( [] ) , [ 'result' => [ 'a' ] , 'hasMore' => false , 'extra' => self::extra( 99 ) ] ) ;
        $db     = $this->facade( [ $stream , $list ] ) ;

        $this->execute( $db , 'stream' ) ;
        $generator = $db->streamDocuments() ;
        foreach ( $generator as $row )
        {
            break ;
        }

        $this->execute( $db , 'list' ) ;
        $db->getDocuments() ;

        unset( $generator ) ;

        $this->assertSame( 99 , $db->getFoundRows() ) ;
        $this->assertSame( $list , $db->getCursor() ) ;
    }

    /**
     * The stream closes and releases its OWN cursor : a query executed but not read yet
     * when the abandoned stream is released keeps its rows, and its server cursor.
     */
    public function testAnAbandonedStreamReleasedLaterNeitherClosesNorEmptiesTheNextQuery() :void
    {
        $nextHistory = [] ;
        $stream = new Cursor( $this->http( [ new Response( 202 , [] , '{}' ) ] ) , [ 'result' => [ 1 , 2 ] , 'hasMore' => true , 'id' => 'c-1' ] ) ;
        $next   = new Cursor
        (
            $this->http( [ new Response( 200 , [] , '{"result":["c","d"],"hasMore":false}' ) ] , $nextHistory ) ,
            [ 'result' => [ 'a' , 'b' ] , 'hasMore' => true , 'id' => 'c-2' ] ,
        ) ;
        $db = $this->facade( [ $stream , $next ] ) ;

        $this->execute( $db , 'stream' ) ;
        $generator = $db->streamDocuments() ;
        foreach ( $generator as $row )
        {
            break ;
        }

        $this->execute( $db , 'list' ) ;
        unset( $generator ) ;

        $this->assertSame( [ 'a' , 'b' , 'c' , 'd' ] , $db->getResult() ) ;

        /** @var RequestInterface $sent */
        $sent = $nextHistory[ 0 ][ 'request' ] ;
        $this->assertCount( 1 , $nextHistory ) ;
        $this->assertSame( 'POST' , $sent->getMethod() ) ; // its next batch — never a DELETE
    }

    /**
     * A read inside the loop takes the slot, as any query does ; the end of the loop no
     * longer empties it after.
     */
    public function testAReadInsideTheLoopStillOwnsTheSlotAfterTheLoop() :void
    {
        $stream = new Cursor( $this->http( [] ) , [ 'result' => [ 1 , 2 ] , 'hasMore' => false , 'extra' => self::extra( 42 ) ] ) ;
        $first  = new Cursor( $this->http( [] ) , [ 'result' => [ 'x' ] , 'hasMore' => false , 'extra' => self::extra( 7 ) ] ) ;
        $second = new Cursor( $this->http( [] ) , [ 'result' => [ 'y' ] , 'hasMore' => false , 'extra' => self::extra( 8 ) ] ) ;
        $db     = $this->facade( [ $stream , $first , $second ] ) ;

        $this->execute( $db , 'stream' ) ;
        $rows = [] ;
        foreach ( $db->streamDocuments() as $row )
        {
            $this->execute( $db , 'get' ) ;
            $db->getFirstResult() ;
            $rows[] = $row ;
        }

        $this->assertSame( [ 1 , 2 ] , $rows ) ;
        $this->assertSame( $second , $db->getCursor() ) ;
        $this->assertSame( 8 , $db->getFoundRows() ) ;
    }

    /**
     * 🚨 An abandoned cursor stayed open on the server until its `ttl` — with
     * `stream: true`, a query still running there.
     */
    public function testAnAbandonedStreamClosesItsServerCursor() :void
    {
        $history = [] ;
        $cursor  = new Cursor( $this->http( [ new Response( 202 , [] , '{}' ) ] , $history ) , [ 'result' => [ 1 , 2 ] , 'hasMore' => true , 'id' => 'c-9' ] ) ;
        $db      = $this->facade( [ $cursor ] ) ;

        $this->execute( $db , 'stream' ) ;
        foreach ( $db->streamDocuments() as $row )
        {
            break ;
        }

        $this->assertCount( 1 , $history ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'DELETE' , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/cursor/c-9' , (string) $sent->getUri() ) ;
        $this->assertSame( [] , ( fn() => $this->batch )->call( $cursor ) ) ;
    }

    /**
     * A close that fails — a cursor the server already dropped — is said in the log and
     * never thrown : it would surface wherever the generator happens to be released.
     */
    public function testAFailedCloseIsLoggedAndNeverThrown() :void
    {
        $gone   = new Response( 404 , [] , '{"error":true,"code":404,"errorNum":1600,"errorMessage":"cursor not found"}' ) ;
        $cursor = new Cursor( $this->http( [ $gone ] ) , [ 'result' => [ 1 , 2 ] , 'hasMore' => true , 'id' => 'c-3' ] ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )->method( 'warning' )->with( $this->stringContains( 'cursor not found' ) ) ;

        $db = $this->facade( [ $cursor ] , $logger ) ;

        $this->execute( $db , 'stream' ) ;
        foreach ( $db->streamDocuments() as $row )
        {
            break ;
        }

        $this->assertSame( [] , ( fn() => $this->batch )->call( $cursor ) ) ;
    }

    /**
     * The error that stopped the loop — the next batch refused, a `ttl` run out — is the
     * one the caller receives, not the close that follows it.
     */
    public function testTheErrorThatStoppedTheLoopSurvivesAFailedClose() :void
    {
        $cursor = new Cursor
        (
            $this->http
            ([
                new Response( 404 , [] , '{"error":true,"code":404,"errorNum":1600,"errorMessage":"next batch refused"}' ) ,
                new Response( 404 , [] , '{"error":true,"code":404,"errorNum":1600,"errorMessage":"close refused"}' ) ,
            ]) ,
            [ 'result' => [ 1 ] , 'hasMore' => true , 'id' => 'c-5' ] ,
        ) ;

        $db = $this->facade( [ $cursor ] , $this->createStub( LoggerInterface::class ) ) ;
        $this->execute( $db , 'stream' ) ;

        $this->expectException( ArangoException::class ) ;
        $this->expectExceptionMessage( 'next batch refused' ) ;

        iterator_to_array( $db->streamDocuments() ) ;
    }
}
