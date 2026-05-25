<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\aqlValue;

class AqlValueTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testStringEscaping()
    {
        $this->assertEquals("'hello'", aqlValue('hello'));
        $this->assertEquals("'it\\'s fine'", aqlValue("it's fine"));
        $this->assertEquals("'a\\'b\\'c'", aqlValue("a'b'c"));
        $this->assertEquals("'back\\slash'", aqlValue("back\\slash"));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testRawValuesAreReturnedAsIs()
    {
        $raws = ['RAW_VAR', 'anotherOne'];
        $this->assertEquals('RAW_VAR', aqlValue('RAW_VAR', $raws));
        $this->assertEquals('anotherOne', aqlValue('anotherOne', $raws));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testBooleans()
    {
        $this->assertEquals('true', aqlValue(true));
        $this->assertEquals('false', aqlValue(false));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testNull()
    {
        $this->assertEquals('null', aqlValue(null));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testNumeric()
    {
        $this->assertEquals('42'   , aqlValue(42));
        $this->assertEquals('3.14' , aqlValue(3.14));
        $this->assertEquals('-7'   , aqlValue(-7));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testIndexedArray()
    {
        $arr = [1, 'two', true];
        $this->assertEquals("[1,'two',true]", aqlValue($arr));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testAssociativeArray()
    {
        $arr = [
            'name' => "O'Reilly",
            'age' => 42,
            'active' => true
        ];

        $expected = "{name:'O\\'Reilly',age:42,active:true}";
        $this->assertEquals($expected, aqlValue($arr));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testNestedArrayAndObject()
    {
        $obj = (object)
        [
            'user' => ['name' => "Alice", 'score' => 10],
            'active' => false
        ];

        $expected = "{user:{name:'Alice',score:10},active:false}";
        $this->assertEquals($expected, aqlValue($obj));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testAQLExpressionIsReturnedAsIs()
    {
        $expr = 'CONCAT("user_", doc.id)';
        $this->assertEquals($expr, aqlValue($expr));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testUnsupportedTypeThrowsException()
    {
        $this->expectException(UnsupportedOperationException::class);
        aqlValue(tmpfile()); // resource
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testComplexRawValuesInsideDocument()
    {
        $data = [
            '_key' => 'CONCAT("test")', // auto-detect
            'custom' => 'MY_VAR'         // requires rawValues
        ];

        $raws = ['MY_VAR'];
        $expected = "{_key:CONCAT(\"test\"),custom:MY_VAR}";
        $this->assertEquals($expected, aqlValue($data, $raws));
    }
}