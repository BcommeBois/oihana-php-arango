<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\functions\isArray;
use function oihana\arango\db\functions\isBool;
use function oihana\arango\db\functions\isDateString;
use function oihana\arango\db\functions\isKey;
use function oihana\arango\db\functions\isNull;
use function oihana\arango\db\functions\isNumber;
use function oihana\arango\db\functions\isObject;
use function oihana\arango\db\functions\isString;
use function oihana\arango\db\functions\typeName;

class CheckFunctionsTest extends TestCase
{
    public function testIsArray(): void
    {
        $this->assertEquals("IS_ARRAY('value')", isArray("'value'"));
    }

    public function testIsBool(): void
    {
        $this->assertEquals("IS_BOOL('value')", isBool("'value'"));
    }

    public function testIsDateString(): void
    {
        $this->assertEquals("IS_DATESTRING('value')", isDateString("'value'"));
    }

    public function testIsKey(): void
    {
        $this->assertEquals("IS_KEY('value')", isKey("'value'"));
    }

    public function testIsNull(): void
    {
        $this->assertEquals("IS_NULL('value')", isNull("'value'"));
    }

    public function testIsNumber(): void
    {
        $this->assertEquals("IS_NUMBER('value')", isNumber("'value'"));
    }

    public function testIsObject(): void
    {
        $this->assertEquals("IS_OBJECT('value')", isObject("'value'"));
    }

    public function testIsString(): void
    {
        $this->assertEquals("IS_STRING('value')", isString("'value'"));
    }

    public function testTypeName(): void
    {
        $this->assertEquals("TYPENAME('value')", typeName("'value'"));
    }
}
