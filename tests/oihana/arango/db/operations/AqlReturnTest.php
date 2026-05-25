<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use stdClass;
use function oihana\arango\db\operations\aqlReturn;

final class AqlReturnTest extends TestCase
{
    public function testReturnWithString()
    {
        $this->assertEquals("RETURN my_string", aqlReturn("my_string"));
    }

    public function testReturnWithStringDistinct()
    {
        $this->assertEquals("RETURN DISTINCT my_string", aqlReturn("my_string", true));
    }

    public function testReturnWithArray()
    {
        $this->assertEquals('RETURN DISTINCT i', aqlReturn([ "DISTINCT" , "i" ]));
    }

    public function testReturnWithArrayDistinct()
    {
        $this->assertEquals('RETURN DISTINCT foo', aqlReturn(["foo" ], true));
    }

    public function testReturnWithObject()
    {
        $obj = new stdClass();
        $obj->foo = "bar";
        $this->assertEquals('RETURN {"foo":"bar"}', aqlReturn($obj));
    }

    public function testReturnWithInteger()
    {
        $this->assertEquals("RETURN 123", aqlReturn(123));
    }

    public function testReturnWithBoolean()
    {
        $this->assertEquals("RETURN true", aqlReturn(true));
    }

    public function testReturnWithEmptyString()
    {
        $this->assertEquals("", aqlReturn(""));
    }

    public function testReturnWithEmptyArray()
    {
        $this->assertEquals("", aqlReturn([]));
    }
}
