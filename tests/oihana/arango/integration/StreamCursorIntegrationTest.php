<?php

namespace tests\oihana\arango\integration;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\clients\enums\ArangoRoute;
use oihana\arango\clients\exceptions\HttpException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\enums\http\HttpMethod;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that a `stream()` lets go of its rows, not of the cursor slot the façade
 * shares with every model.
 *
 * The stream used to empty that slot when it ended : its total and its profile were lost
 * after the loop, an abandoned stream released after another query wiped that query's
 * total, and an abandoned cursor stayed open on the server until its `ttl`.
 */
#[Group( 'integration' )]
final class StreamCursorIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_stream_cursor_it' ;

    private const string COLLECTION = 'items' ;

    protected static function seed( Database $db ) :void
    {
        $items = $db->collection( self::COLLECTION ) ;
        $items->create() ;
        for ( $i = 0 ; $i < 10 ; $i++ )
        {
            $items->insert( [ '_key' => "i$i" , 'n' => $i ] ) ;
        }
    }

    private function model() :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::LAZY        => false ,
        ]);
    }

    /**
     * Asks the server for the next batch of a cursor, by its identifier.
     */
    private function nextBatch( string $id ) :mixed
    {
        return self::$db->request( method: HttpMethod::POST , path: ArangoRoute::CURSOR . '/' . rawurlencode( $id ) )->body ;
    }

    public function testTheTotalAndTheProfileOutliveTheLoop() :void
    {
        $model = $this->model() ;

        $rows = iterator_to_array( $model->stream( [ Arango::LIMIT => 3 , Arango::PROFILE => true ] ) , false ) ;

        $this->assertCount( 3 , $rows ) ;
        $this->assertSame( 10 , $model->foundRows() ) ;
        $this->assertNotEmpty( $model->getExtra()[ 'profile' ] ?? null ) ;
    }

    /**
     * 🚨 With `stream: true`, an abandoned cursor holds a query still running on the
     * server — for as long as its `ttl`.
     */
    public function testAnAbandonedStreamClosesItsServerCursor() :void
    {
        $model = $this->model() ;
        $id    = null ;

        foreach ( $model->stream( [ Arango::CURSOR_OPTIONS => [ CursorField::BATCH_SIZE => 2 , CursorField::TTL => 600 ] ] ) as $row )
        {
            $id = $model->getDatabase()->getCursor()->getId() ;
            break ;
        }

        $this->assertNotNull( $id ) ;

        $this->expectException( HttpException::class ) ;
        $this->expectExceptionMessage( 'cursor not found' ) ;

        $this->nextBatch( $id ) ;
    }

    /**
     * The counter-proof of the one above : a cursor nobody closes is still there to serve
     * its next batch — so the 404 above is the close, not the server's own clean-up.
     */
    public function testACursorNobodyClosesStaysOpenOnTheServer() :void
    {
        $cursor = self::$db->query( 'FOR i IN items RETURN i' , [] , [ CursorField::BATCH_SIZE => 2 , CursorField::TTL => 600 ] ) ;

        $this->assertTrue( $cursor->hasMore() ) ;
        $this->assertCount( 2 , $this->nextBatch( $cursor->getId() )[ CursorField::RESULT ] ) ;

        $cursor->close() ;
    }

    /**
     * 🚨 The generator's `finally` runs when it is released — after whatever ran in
     * between. It used to empty the slot that query then owned.
     */
    public function testAnAbandonedStreamReleasedLaterLeavesTheNextTotalAlone() :void
    {
        $model     = $this->model() ;
        $generator = $model->stream( [ Arango::CURSOR_OPTIONS => [ CursorField::BATCH_SIZE => 2 ] ] ) ;

        foreach ( $generator as $row )
        {
            break ;
        }

        $this->assertCount( 3 , $model->list( [ Arango::LIMIT => 3 ] ) ) ;

        unset( $generator ) ;

        $this->assertSame( 10 , $model->foundRows() ) ;
    }
}
