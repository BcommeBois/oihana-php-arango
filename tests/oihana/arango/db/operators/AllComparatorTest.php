<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operators\allEqual;
use function oihana\arango\db\operators\allGreaterThan;
use function oihana\arango\db\operators\allGreaterThanOrEqual;
use function oihana\arango\db\operators\allIn;
use function oihana\arango\db\operators\allLessThan;
use function oihana\arango\db\operators\allLessThanOrEqual;
use function oihana\arango\db\operators\allNotEqual;
use function oihana\arango\db\operators\allNotIn;

class AllComparatorTest extends TestCase
{
    public function testAllEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ALL == 2', allEqual('[1, 2, 3]', '2'));
    }

    public function testAllGreaterThan(): void
    {
        $this->assertEquals('[1, 2, 3] ALL > 0', allGreaterThan('[1, 2, 3]', '0'));
    }

    public function testAllGreaterThanOrEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ALL >= 0', allGreaterThanOrEqual('[1, 2, 3]', '0'));
    }

    public function testAllIn(): void
    {
        $this->assertEquals('[1, 2, 3] ALL IN [1, 2, 3]', allIn('[1, 2, 3]', '[1, 2, 3]'));
    }

    public function testAllLessThan(): void
    {
        $this->assertEquals('[1, 2, 3] ALL < 5', allLessThan('[1, 2, 3]', '5'));
    }

    public function testAllLessThanOrEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ALL <= 5', allLessThanOrEqual('[1, 2, 3]', '5'));
    }

    public function testAllNotEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ALL != 2', allNotEqual('[1, 2, 3]', '2'));
    }

    public function testAllNotIn(): void
    {
        $this->assertEquals('[1, 2, 3] ALL NOT IN [4, 5, 6]', allNotIn('[1, 2, 3]', '[4, 5, 6]'));
    }
}
