<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operators\rangeOperator;

final class RangeOperatorTest extends TestCase
{
    public function testBasicRangeOperator()
    {
        $result = rangeOperator(2010, 2013);
        $this->assertSame('2010 .. 2013', $result);

        $result = rangeOperator('a', 'z');
        $this->assertSame('a .. z', $result);
    }

    public function testIntegerRange(): void
    {
        $result = rangeOperator(2010, 2013);
        $this->assertEquals('2010 .. 2013', $result);
    }

    public function testStringNumericRange(): void
    {
        $result = rangeOperator('1', '5');
        $this->assertEquals('1 .. 5', $result);
    }

    public function testFloatRange(): void
    {
        $result = rangeOperator(1.5, 4.5);
        $this->assertEquals('1.5 .. 4.5', $result);
    }

    public function testNegativeRange(): void
    {
        $result = rangeOperator(-3, 2);
        $this->assertEquals('-3 .. 2', $result);
    }

    public function testEqualMinMax(): void
    {
        $result = rangeOperator(7, 7);
        $this->assertEquals('7 .. 7', $result);
    }
}