<?php

namespace tests\oihana\arango\clients\cursor ;

use RuntimeException ;

use GuzzleHttp\Client ;
use GuzzleHttp\Handler\MockHandler ;
use GuzzleHttp\HandlerStack ;
use GuzzleHttp\Middleware ;
use GuzzleHttp\Psr7\Response ;

use Psr\Http\Message\RequestInterface ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\Database ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\clients\http\HostRing ;
use oihana\arango\clients\http\HttpTransport ;
use oihana\arango\clients\http\RetryPolicy ;
use oihana\arango\clients\options\ClientOptions ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Cursor} — lazy iterator over AQL query results, with
 * transparent batch fetching against `POST /_api/cursor/{id}`.
 */
#[CoversClass( Cursor::class )]
class CursorTest extends TestCase
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
    // Single batch (hasMore: false)
    // =========================================================================

    public function testIteratesOverInitialBatchOnly() :void
    {
        $db = $this->makeDatabase( [] ) ; // no further HTTP call expected

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 , 3 ] ,
                'hasMore' => false ,
            ] ,
        ) ;

        $rows = [] ;
        foreach ( $cursor as $row )
        {
            $rows[] = $row ;
        }

        $this->assertSame( [ 1 , 2 , 3 ] , $rows ) ;
        $this->assertFalse( $cursor->hasMore() ) ;
        $this->assertNull( $cursor->getId() ) ;
    }

    public function testAllReturnsEveryRow() :void
    {
        $db = $this->makeDatabase( [] ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 'a' , 'b' ] ,
                'hasMore' => false ,
            ] ,
        ) ;

        $this->assertSame( [ 'a' , 'b' ] , $cursor->all() ) ;
    }

    // =========================================================================
    // Batch fetching
    // =========================================================================

    public function testLazyBatchFetchingPullsSubsequentPages() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [
                // Server response for the next-batch fetch
                new Response( 200 , [] , '{"result":[3,4],"hasMore":true,"id":"c-7"}' ) ,
                new Response( 200 , [] , '{"result":[5],"hasMore":false}' ) ,
            ] ,
            $history ,
        ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 ] ,
                'hasMore' => true ,
                'id'      => 'c-7' ,
            ] ,
        ) ;

        $rows = [] ;
        foreach ( $cursor as $row )
        {
            $rows[] = $row ;
        }

        $this->assertSame( [ 1 , 2 , 3 , 4 , 5 ] , $rows ) ;

        // Two next-batch fetches must have been sent.
        $this->assertCount( 2 , $history ) ;

        /** @var RequestInterface $first */
        $first = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'POST'                                          , $first->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/cursor/c-7' , (string) $first->getUri() ) ;
    }

    public function testHasMoreBecomesFalseAfterLastBatch() :void
    {
        $db = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":[3],"hasMore":false}' ) ] ,
        ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 ] ,
                'hasMore' => true ,
                'id'      => 'c-7' ,
            ] ,
        ) ;

        // Drain the cursor.
        foreach ( $cursor as $row ) {}

        $this->assertFalse( $cursor->hasMore() ) ;
        $this->assertNull ( $cursor->getId()   ) ;
    }

    // =========================================================================
    // count() — only when count: true was requested
    // =========================================================================

    public function testCountReturnsServerTotalWhenAvailable() :void
    {
        $db = $this->makeDatabase( [] ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 , 3 ] ,
                'hasMore' => false ,
                'count'   => 42 ,
            ] ,
        ) ;

        $this->assertSame( 42 , count( $cursor ) ) ;
    }

    public function testCountThrowsWhenServerDidNotReturnCount() :void
    {
        $db = $this->makeDatabase( [] ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 , 3 ] ,
                'hasMore' => false ,
            ] ,
        ) ;

        $this->expectException( RuntimeException::class ) ;
        count( $cursor ) ;
    }

    // =========================================================================
    // close()
    // =========================================================================

    public function testCloseIsNoopWhenAlreadyExhausted() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase( [] , $history ) ; // no HTTP call expected

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 ] ,
                'hasMore' => false ,
            ] ,
        ) ;

        $cursor->close() ;

        $this->assertCount( 0 , $history ) ;
    }

    public function testCloseSendsDeleteWhenCursorStillOpen() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 202 , [] , '{}' ) ] ,
            $history ,
        ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 ] ,
                'hasMore' => true ,
                'id'      => 'c-9' ,
            ] ,
        ) ;

        $cursor->close() ;

        $this->assertCount( 1 , $history ) ;

        /** @var RequestInterface $sent */
        $sent = $history[ 0 ][ 'request' ] ;
        $this->assertSame( 'DELETE'                                         , $sent->getMethod() ) ;
        $this->assertSame( 'http://127.0.0.1:8529/_db/mydb/_api/cursor/c-9' , (string) $sent->getUri() ) ;
        $this->assertFalse( $cursor->hasMore() ) ;
        $this->assertNull ( $cursor->getId()   ) ;
    }

    // =========================================================================
    // Metadata
    // =========================================================================

    public function testGetExtraExposesServerMetadata() :void
    {
        $db = $this->makeDatabase( [] ) ;

        $extra = [ 'warnings' => [] , 'stats' => [ 'writesExecuted' => 0 ] ] ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [] ,
                'hasMore' => false ,
                'extra'   => $extra ,
            ] ,
        ) ;

        $this->assertSame( $extra , $cursor->getExtra() ) ;
    }

    // =========================================================================
    // getFullCount() — read from extra.stats.fullCount
    // =========================================================================

    public function testGetFullCountReturnsValueFromStats() :void
    {
        $db = $this->makeDatabase( [] ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 , 3 ] ,
                'hasMore' => false ,
                'extra'   => [ 'stats' => [ 'fullCount' => 42 ] ] ,
            ] ,
        ) ;

        $this->assertSame( 42 , $cursor->getFullCount() ) ;
    }

    public function testGetFullCountReturnsZeroWhenAbsent() :void
    {
        $db = $this->makeDatabase( [] ) ;

        $cursor = new Cursor
        (
            $db ,
            [
                'result'  => [ 1 , 2 , 3 ] ,
                'hasMore' => false ,
            ] ,
        ) ;

        $this->assertSame( 0 , $cursor->getFullCount() ) ;
    }

    // =========================================================================
    // Pipeline — map() / forEach() / reduce() / flatMap()
    // =========================================================================

    public function testMapLazilyYieldsTransformedRows() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 ] , 'hasMore' => false ] ,
        ) ;

        $mapped = $cursor->map( static fn( int $row ) : int => $row * 10 ) ;

        $this->assertInstanceOf( \Generator::class , $mapped ) ;
        $this->assertSame( [ 10 , 20 , 30 ] , iterator_to_array( $mapped , false ) ) ;
    }

    public function testMapCallbackReceivesIndexAndCursor() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 'a' , 'b' , 'c' ] , 'hasMore' => false ] ,
        ) ;

        $received = [] ;
        $mapped   = $cursor->map
        (
            function ( string $row , int $index , Cursor $self ) use ( &$received , $cursor ) : string
            {
                $received[] = [ 'row' => $row , 'index' => $index , 'sameCursor' => $self === $cursor ] ;
                return strtoupper( $row ) ;
            }
        ) ;

        $rows = iterator_to_array( $mapped , false ) ;

        $this->assertSame( [ 'A' , 'B' , 'C' ] , $rows ) ;
        $this->assertSame
        (
            [
                [ 'row' => 'a' , 'index' => 0 , 'sameCursor' => true ] ,
                [ 'row' => 'b' , 'index' => 1 , 'sameCursor' => true ] ,
                [ 'row' => 'c' , 'index' => 2 , 'sameCursor' => true ] ,
            ] ,
            $received ,
        ) ;
    }

    public function testForEachCallsCallbackForEveryRowAndReportsCompletion() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 ] , 'hasMore' => false ] ,
        ) ;

        $visited  = [] ;
        $finished = $cursor->forEach( function ( int $row ) use ( &$visited ) : void { $visited[] = $row ; } ) ;

        $this->assertSame( [ 1 , 2 , 3 ] , $visited ) ;
        $this->assertTrue( $finished ) ;
    }

    public function testForEachAbortsWhenCallbackReturnsFalse() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 , 4 , 5 ] , 'hasMore' => false ] ,
        ) ;

        $visited  = [] ;
        $finished = $cursor->forEach
        (
            function ( int $row ) use ( &$visited ) : false|null
            {
                $visited[] = $row ;
                return $row === 3 ? false : null ;
            }
        ) ;

        $this->assertSame( [ 1 , 2 , 3 ]  , $visited ) ;
        $this->assertFalse( $finished ) ;
    }

    public function testReduceFoldsRowsWithInitialAccumulator() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 , 4 ] , 'hasMore' => false ] ,
        ) ;

        $sum = $cursor->reduce
        (
            static fn( int $acc , int $row ) : int => $acc + $row ,
            0 ,
        ) ;

        $this->assertSame( 10 , $sum ) ;
    }

    public function testReduceReturnsInitialWhenCursorIsEmpty() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [] , 'hasMore' => false ] ,
        ) ;

        $this->assertSame( 'fallback' , $cursor->reduce( static fn() : string => 'whatever' , 'fallback' ) ) ;
    }

    public function testReduceDefaultInitialIsNull() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [] , 'hasMore' => false ] ,
        ) ;

        $this->assertNull( $cursor->reduce( static fn() : mixed => 'unused' ) ) ;
    }

    public function testFlatMapFlattensArrayReturnsOneLevel() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 ] , 'hasMore' => false ] ,
        ) ;

        $result = $cursor->flatMap( static fn( int $row ) : array => [ $row , $row * 10 ] ) ;

        $this->assertSame( [ 1 , 10 , 2 , 20 , 3 , 30 ] , $result ) ;
    }

    public function testFlatMapAcceptsScalarReturnsAlongsideArrays() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 ] , 'hasMore' => false ] ,
        ) ;

        $result = $cursor->flatMap
        (
            static fn( int $row ) : int|array => $row === 2 ? [ 'a' , 'b' ] : $row ,
        ) ;

        $this->assertSame( [ 1 , 'a' , 'b' , 3 ] , $result ) ;
    }

    public function testFlatMapEmptyArrayDropsRows() :void
    {
        $db     = $this->makeDatabase( [] ) ;
        $cursor = new Cursor
        (
            $db ,
            [ 'result' => [ 1 , 2 , 3 , 4 , 5 ] , 'hasMore' => false ] ,
        ) ;

        // Keep only odd numbers — empty array flattens to nothing.
        $odds = $cursor->flatMap( static fn( int $row ) : array => $row % 2 === 1 ? [ $row ] : [] ) ;

        $this->assertSame( [ 1 , 3 , 5 ] , $odds ) ;
    }

    public function testMapStreamsAcrossMultipleBatches() :void
    {
        $history = [] ;
        $db      = $this->makeDatabase
        (
            [ new Response( 200 , [] , '{"result":[4,5,6],"hasMore":false}' ) ] ,
            $history ,
        ) ;

        $cursor = new Cursor
        (
            $db ,
            [ 'id' => 'c-1' , 'result' => [ 1 , 2 , 3 ] , 'hasMore' => true ] ,
        ) ;

        $squares = iterator_to_array
        (
            $cursor->map( static fn( int $row ) : int => $row * $row ) ,
            false ,
        ) ;

        $this->assertSame( [ 1 , 4 , 9 , 16 , 25 , 36 ] , $squares ) ;
        $this->assertCount( 1 , $history ) ; // exactly one batch was pulled
    }
}
