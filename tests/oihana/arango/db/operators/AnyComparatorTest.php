<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operators\anyEqual;
use function oihana\arango\db\operators\anyGreaterThan;
use function oihana\arango\db\operators\anyGreaterThanOrEqual;
use function oihana\arango\db\operators\anyIn;
use function oihana\arango\db\operators\anyLessThan;
use function oihana\arango\db\operators\anyLessThanOrEqual;
use function oihana\arango\db\operators\anyNotEqual;
use function oihana\arango\db\operators\anyNotIn;

class AnyComparatorTest extends TestCase
{
    public function testAnyEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ANY == 2', anyEqual('[1, 2, 3]', '2'));
    }

    public function testAnyGreaterThan(): void
    {
        $this->assertEquals('[1, 2, 3] ANY > 0', anyGreaterThan('[1, 2, 3]', '0'));
    }

    public function testAnyGreaterThanOrEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ANY >= 0', anyGreaterThanOrEqual('[1, 2, 3]', '0'));
    }

    public function testAnyIn(): void
    {
        $this->assertEquals('[1, 2, 3] ANY IN [1, 2, 3]', anyIn('[1, 2, 3]', '[1, 2, 3]'));
    }

    public function testAnyLessThan(): void
    {
        $this->assertEquals('[1, 2, 3] ANY < 5', anyLessThan('[1, 2, 3]', '5'));
    }

    public function testAnyLessThanOrEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ANY <= 5', anyLessThanOrEqual('[1, 2, 3]', '5'));
    }

    public function testAnyNotEqual(): void
    {
        $this->assertEquals('[1, 2, 3] ANY != 2', anyNotEqual('[1, 2, 3]', '2'));
    }

    public function testAnyNotIn(): void
    {
        $this->assertEquals('[1, 2, 3] ANY NOT IN [4, 5, 6]', anyNotIn('[1, 2, 3]', '[4, 5, 6]'));
    }
}
