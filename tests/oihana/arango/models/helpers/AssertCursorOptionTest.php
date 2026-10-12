<?php

namespace tests\oihana\arango\models\helpers;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\assertCursorOption;

/**
 * {@see assertCursorOption()} — the five cursor options a caller may name, and the
 * sentence each refusal speaks.
 *
 * The server says nothing of an option it does not know, nor of a value of the wrong
 * type (measured on 3.12: `batchSize: "x"`, `ttl: -5`, `stream: "yes"` all run as if
 * absent), so a typo must be refused here or it becomes a silent no-op.
 */
final class AssertCursorOptionTest extends TestCase
{
    public static function acceptedOptions() :array
    {
        return
        [
            'batchSize'          => [ CursorField::BATCH_SIZE   , 500 ] ,
            'ttl'                => [ CursorField::TTL          , 300 ] ,
            'memoryLimit'        => [ CursorField::MEMORY_LIMIT , 64000000 ] ,
            'maxRuntime float'   => [ CursorField::MAX_RUNTIME  , 2.5 ] ,
            'maxRuntime integer' => [ CursorField::MAX_RUNTIME  , 3 ] ,
            'stream true'        => [ CursorField::STREAM       , true ] ,
            'stream false'       => [ CursorField::STREAM       , false ] ,
        ] ;
    }

    #[DataProvider( 'acceptedOptions' )]
    public function testAnAcceptedOptionPasses( string $name , mixed $value ) :void
    {
        $this->expectNotToPerformAssertions() ;
        assertCursorOption( $name , $value ) ;
    }

    public static function refusedOptions() :array
    {
        return
        [
            'a list index'       => [ 0                         , 500                   , 'Unknown cursor option "0"' ] ,
            'unknown'            => [ 'bogus'                   , 1                     , 'Unknown cursor option "bogus" — expected one of: batchSize, maxRuntime, memoryLimit, stream, ttl.' ] ,
            'wrong case'         => [ 'batchsize'               , 500                   , 'Unknown cursor option "batchsize"' ] ,
            'query'              => [ CursorField::QUERY        , 'FOR d IN x RETURN d' , 'The cursor option "query" is refused: the query and its binds are the ones the model builds.' ] ,
            'bindVars'           => [ CursorField::BIND_VARS    , [ 'a' => 1 ]          , 'The cursor option "bindVars" is refused: the query and its binds are the ones the model builds.' ] ,
            'fullCount'          => [ CursorField::FULL_COUNT   , true                  , 'The cursor option "fullCount" is refused: the read decides it, from its LIMIT.' ] ,
            'profile'            => [ CursorField::PROFILE      , 2                     , 'The cursor option "profile" is refused: ask for a profile with Arango::PROFILE.' ] ,
            'count'              => [ CursorField::COUNT        , true                  , 'The cursor option "count" is refused: no read of the model hands the cursor count back.' ] ,
            'batchSize zero'     => [ CursorField::BATCH_SIZE   , 0                     , 'The cursor option "batchSize" must be a positive integer, int given.' ] ,
            'batchSize negative' => [ CursorField::BATCH_SIZE   , -1                    , 'The cursor option "batchSize" must be a positive integer, int given.' ] ,
            'batchSize string'   => [ CursorField::BATCH_SIZE   , '500'                 , 'The cursor option "batchSize" must be a positive integer, string given.' ] ,
            'batchSize float'    => [ CursorField::BATCH_SIZE   , 1.5                   , 'The cursor option "batchSize" must be a positive integer, float given.' ] ,
            'batchSize null'     => [ CursorField::BATCH_SIZE   , null                  , 'The cursor option "batchSize" must be a positive integer, null given.' ] ,
            'ttl zero'           => [ CursorField::TTL          , 0                     , 'The cursor option "ttl" must be a positive integer, int given.' ] ,
            'ttl negative'       => [ CursorField::TTL          , -5                    , 'The cursor option "ttl" must be a positive integer, int given.' ] ,
            'memoryLimit string' => [ CursorField::MEMORY_LIMIT , '1'                   , 'The cursor option "memoryLimit" must be a positive integer, string given.' ] ,
            'memoryLimit zero'   => [ CursorField::MEMORY_LIMIT , 0                     , 'The cursor option "memoryLimit" must be a positive integer, int given.' ] ,
            'maxRuntime zero'    => [ CursorField::MAX_RUNTIME  , 0                     , 'The cursor option "maxRuntime" must be a positive number, int given.' ] ,
            'maxRuntime string'  => [ CursorField::MAX_RUNTIME  , '1'                   , 'The cursor option "maxRuntime" must be a positive number, string given.' ] ,
            'maxRuntime bool'    => [ CursorField::MAX_RUNTIME  , true                  , 'The cursor option "maxRuntime" must be a positive number, bool given.' ] ,
            'stream string'      => [ CursorField::STREAM       , 'yes'                 , 'The cursor option "stream" must be a boolean, string given.' ] ,
            'stream int'         => [ CursorField::STREAM       , 1                     , 'The cursor option "stream" must be a boolean, int given.' ] ,
        ] ;
    }

    #[DataProvider( 'refusedOptions' )]
    public function testARefusedOptionIsSaid( int|string $name , mixed $value , string $message ) :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( $message ) ;

        assertCursorOption( $name , $value ) ;
    }
}
