<?php

namespace tests\oihana\arango\models\traits;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * The cursor options a caller names through {@see Arango::CURSOR_OPTIONS}, as the four
 * reads — `list()`, `get()`, `count()`, `stream()` — hand them to the fetch seams.
 *
 * The server says nothing of an option it does not know, nor of a value of the wrong
 * type (measured on 3.12: `batchSize: "x"`, `ttl: -5`, `stream: "yes"` all run as if
 * absent), so the refusals are ours: a typo must not become a silent no-op.
 */
#[CoversTrait( ArangoTrait::class )]
final class ArangoTraitCursorOptionsTest extends TestCase
{
    /**
     * A double whose count() has a number to hand back.
     */
    private static function model() :MockDocuments
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 0 ;
        return $model ;
    }

    // ---- defaults ---------------------------------------------------------------

    /**
     * 🚨 Without CURSOR_OPTIONS nor PROFILE, every read sends exactly what it sent
     * before the key existed.
     */
    public function testNoReadChangesItsDefaultOptions() :void
    {
        $model = self::model() ;

        $model->list( [] ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => false ] , $model->lastOptions ) ;

        $model->list( [ Arango::LIMIT => 5 ] ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => true ] , $model->lastOptions ) ;

        $model->lastOptions = [ 'stale' => true ] ;
        $model->get( [ Arango::VALUE => 'k1' ] ) ;
        $this->assertSame( [] , $model->lastOptions ) ;

        $model->lastOptions = [ 'stale' => true ] ;
        $model->count( [] ) ;
        $this->assertSame( [] , $model->lastOptions ) ;

        iterator_to_array( $model->stream( [] ) ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => false ] , $model->lastOptions ) ;

        iterator_to_array( $model->stream( [ Arango::LIMIT => 5 ] ) ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => true ] , $model->lastOptions ) ;
    }

    // ---- forwarding -------------------------------------------------------------

    public static function reads() :array
    {
        return
        [
            'list'   => [ 'list'   , [ CursorField::FULL_COUNT => false ] ] ,
            'get'    => [ 'get'    , [] ] ,
            'count'  => [ 'count'  , [] ] ,
            'stream' => [ 'stream' , [ CursorField::FULL_COUNT => false ] ] ,
        ] ;
    }

    public static function readNames() :array
    {
        return array_map( static fn( array $read ) :array => [ $read[ 0 ] ] , self::reads() ) ;
    }

    /**
     * Runs one read the way its caller would — a stream is a generator, so it is only
     * executed once iterated.
     */
    private function read( MockDocuments $model , string $read , array $init ) :void
    {
        $init = $read === 'get' ? $init + [ Arango::VALUE => 'k1' ] : $init ;
        $result = $model->{ $read }( $init ) ;
        if ( $read === 'stream' )
        {
            iterator_to_array( $result ) ;
        }
    }

    #[DataProvider( 'reads' )]
    public function testEveryReadForwardsTheFiveCursorOptions( string $read , array $defaults ) :void
    {
        $model  = self::model() ;
        $caller =
        [
            CursorField::BATCH_SIZE   => 500 ,
            CursorField::TTL          => 300 ,
            CursorField::MEMORY_LIMIT => 64000000 ,
            CursorField::MAX_RUNTIME  => 2.5 ,
            CursorField::STREAM       => true ,
        ] ;

        $this->read( $model , $read , [ Arango::CURSOR_OPTIONS => $caller ] ) ;

        $this->assertSame( $defaults + $caller , $model->lastOptions ) ;
    }

    #[DataProvider( 'reads' )]
    public function testEveryReadHonoursTheProfile( string $read , array $defaults ) :void
    {
        $model = self::model() ;

        $this->read( $model , $read , [ Arango::PROFILE => true ] ) ;

        $this->assertSame( $defaults + [ CursorField::PROFILE => 2 ] , $model->lastOptions ) ;
    }

    #[DataProvider( 'readNames' )]
    public function testEveryReadRefusesAnUnknownCursorOption( string $read ) :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'Unknown cursor option "batchsize"' ) ;

        $this->read( self::model() , $read , [ Arango::CURSOR_OPTIONS => [ 'batchsize' => 500 ] ] ) ;
    }

    public function testAMaxRuntimeMayBeAnInteger() :void
    {
        $model = self::model() ;
        $model->count( [ Arango::CURSOR_OPTIONS => [ CursorField::MAX_RUNTIME => 3 ] ] ) ;
        $this->assertSame( [ CursorField::MAX_RUNTIME => 3 ] , $model->lastOptions ) ;
    }

    public function testAnEmptyOrNullCursorOptionsChangesNothing() :void
    {
        $model = self::model() ;

        $model->list( [ Arango::CURSOR_OPTIONS => [] ] ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => false ] , $model->lastOptions ) ;

        $model->list( [ Arango::CURSOR_OPTIONS => null ] ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => false ] , $model->lastOptions ) ;
    }

    // ---- refusals ---------------------------------------------------------------

    /**
     * The options themselves are judged one by one by {@see \oihana\arango\models\helpers\assertCursorOption()} — see
     * `AssertCursorOptionTest`. What belongs to the read is the shape of the whole.
     */
    public function testCursorOptionsThatAreNotAnArrayAreRefused() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The cursor options must be an array, string given.' ) ;

        self::model()->list( [ Arango::CURSOR_OPTIONS => 'batchSize=500' ] ) ;
    }

    // ---- stream + LIMIT ---------------------------------------------------------

    /**
     * 🚨 Measured on 3.12: a streaming cursor answers `fullCount: null`, even once
     * drained. A limited list read in stream mode would report a total of 0.
     */
    public function testAStreamingCursorIsRefusedOnALimitedList() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The cursor option "stream" is refused on a limited read' ) ;

        self::model()->list( [ Arango::LIMIT => 10 , Arango::CURSOR_OPTIONS => [ CursorField::STREAM => true ] ] ) ;
    }

    /**
     * stream() is a generator: the refusal surfaces at the first iteration, as every
     * other refusal of its query does.
     */
    public function testAStreamingCursorIsRefusedOnALimitedStream() :void
    {
        $model     = self::model() ;
        $generator = $model->stream( [ Arango::LIMIT => 10 , Arango::CURSOR_OPTIONS => [ CursorField::STREAM => true ] ] ) ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'The cursor option "stream" is refused on a limited read' ) ;

        iterator_to_array( $generator ) ;
    }

    public function testAStreamingCursorFalseStaysAllowedOnALimitedRead() :void
    {
        $model = self::model() ;
        $model->list( [ Arango::LIMIT => 10 , Arango::CURSOR_OPTIONS => [ CursorField::STREAM => false ] ] ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => true , CursorField::STREAM => false ] , $model->lastOptions ) ;
    }

    public function testAStreamingCursorIsAllowedWhereNothingIsCounted() :void
    {
        $model  = self::model() ;
        $stream = [ Arango::CURSOR_OPTIONS => [ CursorField::STREAM => true ] ] ;

        iterator_to_array( $model->stream( $stream ) ) ;
        $this->assertSame( [ CursorField::FULL_COUNT => false , CursorField::STREAM => true ] , $model->lastOptions ) ;

        $model->count( $stream + [ Arango::LIMIT => 10 ] ) ;
        $this->assertSame( [ CursorField::STREAM => true ] , $model->lastOptions ) ;
    }
}
