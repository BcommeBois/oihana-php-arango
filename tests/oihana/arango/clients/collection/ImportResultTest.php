<?php

namespace tests\oihana\arango\clients\collection ;

use oihana\arango\clients\collection\ImportResult ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ImportResult} — value object summarising the outcome
 * of a {@see \oihana\arango\clients\collection\Collection::import()} call.
 */
#[CoversClass( ImportResult::class )]
class ImportResultTest extends TestCase
{
    public function testConstructorDefaultsAreZeroesAndEmptyDetails() :void
    {
        $result = new ImportResult() ;

        $this->assertSame( 0    , $result->created ) ;
        $this->assertSame( 0    , $result->errors  ) ;
        $this->assertSame( 0    , $result->empty   ) ;
        $this->assertSame( 0    , $result->updated ) ;
        $this->assertSame( 0    , $result->ignored ) ;
        $this->assertSame( []   , $result->details ) ;
    }

    public function testConstructorAssignsAllCounters() :void
    {
        $result = new ImportResult
        (
            created : 10 ,
            errors  : 2  ,
            empty   : 1  ,
            updated : 3  ,
            ignored : 4  ,
            details : [ 'row 1 failed' , 'row 2 failed' ] ,
        ) ;

        $this->assertSame( 10                                       , $result->created ) ;
        $this->assertSame( 2                                        , $result->errors  ) ;
        $this->assertSame( 1                                        , $result->empty   ) ;
        $this->assertSame( 3                                        , $result->updated ) ;
        $this->assertSame( 4                                        , $result->ignored ) ;
        $this->assertSame( [ 'row 1 failed' , 'row 2 failed' ]      , $result->details ) ;
    }

    public function testFromBodyParsesFullResponse() :void
    {
        $result = ImportResult::fromBody
        ([
            'created' => 5  ,
            'errors'  => 2  ,
            'empty'   => 1  ,
            'updated' => 3  ,
            'ignored' => 4  ,
            'details' => [ 'row 0 failed' , 'row 7 failed' ] ,
        ]) ;

        $this->assertSame( 5                                        , $result->created ) ;
        $this->assertSame( 2                                        , $result->errors  ) ;
        $this->assertSame( 1                                        , $result->empty   ) ;
        $this->assertSame( 3                                        , $result->updated ) ;
        $this->assertSame( 4                                        , $result->ignored ) ;
        $this->assertSame( [ 'row 0 failed' , 'row 7 failed' ]      , $result->details ) ;
    }

    public function testFromBodyMissingFieldsFallBackToZero() :void
    {
        $result = ImportResult::fromBody( [ 'created' => 3 ] ) ;

        $this->assertSame( 3    , $result->created ) ;
        $this->assertSame( 0    , $result->errors  ) ;
        $this->assertSame( 0    , $result->empty   ) ;
        $this->assertSame( 0    , $result->updated ) ;
        $this->assertSame( 0    , $result->ignored ) ;
        $this->assertSame( []   , $result->details ) ;
    }

    public function testFromBodyCoercesStringCountersToInt() :void
    {
        // Defensive parsing — Arango always emits integers, but the
        // value object should not break if a stub or proxy returns
        // string counters.
        $result = ImportResult::fromBody
        ([
            'created' => '12' ,
            'errors'  => '0'  ,
        ]) ;

        $this->assertSame( 12 , $result->created ) ;
        $this->assertSame( 0  , $result->errors  ) ;
    }

    public function testFromBodyIgnoresNonStringEntriesInDetails() :void
    {
        $result = ImportResult::fromBody
        ([
            'errors'  => 3 ,
            'details' => [ 'ok message' , 42 , null , 'second message' , [ 'nested' ] ] ,
        ]) ;

        $this->assertSame( [ 'ok message' , 'second message' ] , $result->details ) ;
    }

    public function testFromBodyAcceptsNonArrayDetailsAsEmptyList() :void
    {
        $result = ImportResult::fromBody
        ([
            'created' => 1 ,
            'details' => 'not an array' ,
        ]) ;

        $this->assertSame( [] , $result->details ) ;
    }

    public function testHasErrorsReflectsErrorsCounter() :void
    {
        $this->assertFalse( ( new ImportResult( errors : 0 ) )->hasErrors() ) ;
        $this->assertTrue ( ( new ImportResult( errors : 1 ) )->hasErrors() ) ;
        $this->assertTrue ( ( new ImportResult( errors : 7 ) )->hasErrors() ) ;
    }

    public function testToArrayRoundTripsAllFields() :void
    {
        $result = new ImportResult
        (
            created : 5 ,
            errors  : 1 ,
            empty   : 0 ,
            updated : 2 ,
            ignored : 3 ,
            details : [ 'failed' ] ,
        ) ;

        $this->assertSame
        (
            [
                'created' => 5 ,
                'errors'  => 1 ,
                'empty'   => 0 ,
                'updated' => 2 ,
                'ignored' => 3 ,
                'details' => [ 'failed' ] ,
            ] ,
            $result->toArray() ,
        ) ;
    }
}
