<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\functions\toArray;
use function oihana\arango\db\functions\toBool;
use function oihana\arango\db\functions\toNumber;
use function oihana\arango\db\functions\toString;

class CastingFunctionsTest extends TestCase
{
    public function testToArray(): void
    {
        $this->assertEquals("TO_ARRAY('value')", toArray("'value'"));
    }

    public function testToBool(): void
    {
        $this->assertEquals("TO_BOOL('value')", toBool("'value'"));
    }

    public function testToNumber(): void
    {
        $this->assertEquals("TO_NUMBER('value')", toNumber("'value'"));
    }

    public function testToString(): void
    {
        $this->assertEquals("TO_STRING('value')", toString("'value'"));
    }
}
