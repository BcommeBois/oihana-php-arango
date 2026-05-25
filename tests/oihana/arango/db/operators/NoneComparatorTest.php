<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operators\noneEqual;
use function oihana\arango\db\operators\noneGreaterThan;
use function oihana\arango\db\operators\noneGreaterThanOrEqual;
use function oihana\arango\db\operators\noneIn;
use function oihana\arango\db\operators\noneLessThan;
use function oihana\arango\db\operators\noneLessThanOrEqual;
use function oihana\arango\db\operators\noneNotEqual;
use function oihana\arango\db\operators\noneNotIn;

class NoneComparatorTest extends TestCase
{
    public function testNoneEqual(): void
    {
        $this->assertEquals('[1, 2, 3] NONE == 4', noneEqual('[1, 2, 3]', '4'));
    }

    public function testNoneGreaterThan(): void
    {
        $this->assertEquals('[1, 2, 3] NONE > 3', noneGreaterThan('[1, 2, 3]', '3'));
    }

    public function testNoneGreaterThanOrEqual(): void
    {
        $this->assertEquals('[1, 2, 3] NONE >= 4', noneGreaterThanOrEqual('[1, 2, 3]', '4'));
    }

    public function testNoneIn(): void
    {
        $this->assertEquals('[1, 2, 3] NONE IN [4, 5, 6]', noneIn('[1, 2, 3]', '[4, 5, 6]'));
    }

    public function testNoneLessThan(): void
    {
        $this->assertEquals('[1, 2, 3] NONE < 1', noneLessThan('[1, 2, 3]', '1'));
    }

    public function testNoneLessThanOrEqual(): void
    {
        $this->assertEquals('[1, 2, 3] NONE <= 0', noneLessThanOrEqual('[1, 2, 3]', '0'));
    }

    public function testNoneNotEqual(): void
    {
        $this->assertEquals('[1, 2, 3] NONE != 1', noneNotEqual('[1, 2, 3]', '1'));
    }

    public function testNoneNotIn(): void
    {
        $this->assertEquals('[1, 2, 3] NONE NOT IN [1, 2, 3]', noneNotIn('[1, 2, 3]', '[1, 2, 3]'));
    }
}
