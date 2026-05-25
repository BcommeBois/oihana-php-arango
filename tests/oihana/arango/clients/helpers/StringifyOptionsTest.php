<?php

namespace tests\oihana\arango\clients\helpers ;

use PHPUnit\Framework\TestCase ;

use function oihana\arango\clients\helpers\stringifyOptions ;

/**
 * Tests for {@see \oihana\arango\clients\helpers\stringifyOptions} —
 * coerce boolean entries to the lowercase string spelling ArangoDB
 * expects on query strings.
 */
class StringifyOptionsTest extends TestCase
{
    public function testTrueBecomesLowercaseString() :void
    {
        $this->assertSame( [ 'waitForSync' => 'true' ] , stringifyOptions( [ 'waitForSync' => true ] ) ) ;
    }

    public function testFalseBecomesLowercaseString() :void
    {
        $this->assertSame( [ 'returnNew' => 'false' ] , stringifyOptions( [ 'returnNew' => false ] ) ) ;
    }

    public function testIntegerValuesPassThroughUnchanged() :void
    {
        $this->assertSame( [ 'batchSize' => 100 ] , stringifyOptions( [ 'batchSize' => 100 ] ) ) ;
    }

    public function testStringValuesPassThroughUnchanged() :void
    {
        $this->assertSame( [ 'onDuplicate' => 'update' ] , stringifyOptions( [ 'onDuplicate' => 'update' ] ) ) ;
    }

    public function testEmptyInputReturnsEmptyArray() :void
    {
        $this->assertSame( [] , stringifyOptions( [] ) ) ;
    }

    public function testMixedTypesNormalisesBooleansOnly() :void
    {
        $result = stringifyOptions
        ([
            'waitForSync' => true ,
            'complete'    => false ,
            'batchSize'   => 100 ,
            'onDuplicate' => 'ignore' ,
            'fromPrefix'  => 'people/' ,
        ]) ;

        $this->assertSame
        (
            [
                'waitForSync' => 'true'  ,
                'complete'    => 'false' ,
                'batchSize'   => 100 ,
                'onDuplicate' => 'ignore' ,
                'fromPrefix'  => 'people/' ,
            ] ,
            $result ,
        ) ;
    }

    public function testNullValuesPassThroughUnchanged() :void
    {
        $this->assertSame( [ 'rev' => null ] , stringifyOptions( [ 'rev' => null ] ) ) ;
    }
}
