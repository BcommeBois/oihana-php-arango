<?php

namespace tests\oihana\arango\integration;

use Generator;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\clients\exceptions\HttpException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that the cursor options a caller names through {@see Arango::CURSOR_OPTIONS}
 * reach the server, through the real reads.
 *
 * ⚠ A unit double only shows the array a read hands to its fetch seam ; the server
 * never says a word about an option it ignores. Each proof here is therefore built
 * so that the option **changes what happens** — a read that fails with it and
 * succeeds without it, side by side.
 */
#[Group( 'integration' )]
final class CursorOptionsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_cursor_options_it' ;

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
            AQL::SORTABLE    => [ 'n' ] ,
        ]);
    }

    /**
     * Runs one read the way its caller would, and returns what it read.
     */
    private function read( string $read , array $init ) :mixed
    {
        $model  = $this->model() ;
        $result = $read === 'get' ? $model->get( $init + [ Arango::VALUE => 'i3' ] ) : $model->{ $read }( $init ) ;
        return $result instanceof Generator ? iterator_to_array( $result , false ) : $result ;
    }

    public static function sortedReads() :array
    {
        return [ 'list' => [ 'list' ] , 'stream' => [ 'stream' ] ] ;
    }

    /**
     * A memory ceiling of one byte kills a sorted read — so a read that still succeeds
     * has lost the option on its way.
     *
     * ⚠ Sorted on purpose. Measured on 3.12: a plain `FOR … RETURN`, a `LIMIT 1` or a
     * `COUNT()` is accounted so little memory that it **passes** a one-byte ceiling,
     * most of the time — the first run of this proof failed on one read and not on the
     * next. The `SORT` buffers the rows, and fails every time.
     */
    #[DataProvider( 'sortedReads' )]
    public function testASortedReadHandsItsMemoryLimitToTheServer( string $read ) :void
    {
        $this->assertCount( 10 , $this->read( $read , [ Arango::SORT => 'n' ] ) ) ; // the counter-proof : the same read, without it

        $this->expectException( HttpException::class ) ;
        $this->expectExceptionMessage( 'more memory than allowed' ) ;

        $this->read( $read , [ Arango::SORT => 'n' , Arango::CURSOR_OPTIONS => [ CursorField::MEMORY_LIMIT => 1 ] ] ) ;
    }

    /**
     * 🚨 The trap the documentation names: a `ttl` shorter than the work done between
     * two batches kills the cursor. Both options must arrive for the read to fail —
     * without the small batch the whole seed comes in one, and no second batch is ever
     * fetched ; without the short ttl the cursor outlives the pause.
     */
    public function testAStreamWhoseTtlRunsOutBetweenTwoBatchesFails() :void
    {
        $this->expectException( HttpException::class ) ;
        $this->expectExceptionMessage( 'cursor not found' ) ;

        $this->walkSlowly( [ CursorField::BATCH_SIZE => 2 , CursorField::TTL => 1 ] ) ;
    }

    /**
     * The counter-proof of the one above: the same small batches and the same pause,
     * with the server's own ttl, read the whole seed.
     */
    public function testAStreamInSmallBatchesSurvivesThePauseWithTheDefaultTtl() :void
    {
        $this->assertSame( 10 , $this->walkSlowly( [ CursorField::BATCH_SIZE => 2 ] ) ) ;
    }

    /**
     * Pauses after the first row — the second batch is fetched after it.
     */
    private function walkSlowly( array $options ) :int
    {
        $count = 0 ;
        foreach ( $this->model()->stream( [ Arango::CURSOR_OPTIONS => $options ] ) as $row )
        {
            if ( $count++ === 0 )
            {
                sleep( 3 ) ;
            }
        }
        return $count ;
    }

    /**
     * A streaming cursor is accepted by the server, and a read without LIMIT still
     * hands back the whole seed.
     */
    public function testAStreamingCursorReadsTheWholeCollection() :void
    {
        $options = [ Arango::CURSOR_OPTIONS => [ CursorField::STREAM => true , CursorField::BATCH_SIZE => 3 ] ] ;

        $this->assertCount( 10 , $this->read( 'stream' , $options ) ) ;
        $this->assertCount( 10 , $this->read( 'list'   , $options ) ) ;
    }

    public static function wholeReads() :array
    {
        return [ 'list' => [ 'list' ] , 'get' => [ 'get' ] , 'count' => [ 'count' ] ] ;
    }

    /**
     * `count()` used to drop `Arango::PROFILE` without a word.
     */
    #[DataProvider( 'wholeReads' )]
    public function testAWholeReadIsProfiledWhenAsked( string $read ) :void
    {
        $model = $this->model() ;
        $init  = [ Arango::PROFILE => true ] + ( $read === 'get' ? [ Arango::VALUE => 'i3' ] : [] ) ;

        $model->{ $read }( $init ) ;

        $this->assertNotEmpty( $model->getExtra()[ 'profile' ] ?? null ) ;
    }

    /**
     * `stream()` used to drop `Arango::PROFILE` without a word too. Its profile is read
     * after the loop, like any other read's — see `StreamCursorIntegrationTest`.
     */
    public function testAStreamIsProfiledWhenAsked() :void
    {
        $model = $this->model() ;

        iterator_to_array( $model->stream( [ Arango::PROFILE => true ] ) , false ) ;
        $this->assertNotEmpty( $model->getExtra()[ 'profile' ] ?? null ) ;

        // The counter-proof: without the key, nothing is profiled.
        iterator_to_array( $model->stream( [] ) , false ) ;
        $this->assertArrayNotHasKey( 'profile' , $model->getExtra() ) ;
    }
}
