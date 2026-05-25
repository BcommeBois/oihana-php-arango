<?php

namespace tests\oihana\arango\db\operations;

use oihana\arango\db\enums\Operation;
use oihana\enums\Char;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operations\aqlAsc;
use function oihana\arango\db\operations\aqlDesc;
use function oihana\arango\db\operations\aqlSort;

final class AqlSortTest extends TestCase
{
    public function testAsc(): void
    {
        $this->assertSame('foo ASC', aqlAsc('foo'));
        $this->assertSame('p.foo ASC', aqlAsc('foo', 'p'));
    }

    public function testDesc(): void
    {
        $this->assertSame('foo DESC', aqlDesc('foo'));
        $this->assertSame('p.foo DESC', aqlDesc('foo', 'p'));
    }

    public function testSortWithString(): void
    {
        $sort = 'foo ASC, bar DESC';
        $result = aqlSort($sort);
        $this->assertSame(Operation::SORT . Char::SPACE . $sort, $result);
    }

    public function testSortWithArray(): void
    {
        $sort = ['foo ASC', 'bar DESC'];
        $result = aqlSort($sort);
        $this->assertSame(Operation::SORT . Char::SPACE . 'foo ASC, bar DESC', $result);
    }

    public function testSortWithNull(): void
    {
        $this->assertSame(Char::EMPTY, aqlSort(null));
    }

    public function testSortWithEmptyString(): void
    {
        $this->assertSame(Char::EMPTY, aqlSort(Char::EMPTY));
    }

    public function testSortWithEmptyArray(): void
    {
        $this->assertSame(Char::EMPTY, aqlSort([]));
    }

    public function testSortWithAscDescHelpers(): void
    {
        $sortExpressions = [
            aqlAsc('score', 'player'),
            aqlDesc('createdAt', 'doc')
        ];

        $expected = Operation::SORT . Char::SPACE . 'player.score ASC, doc.createdAt DESC';
        $this->assertSame($expected, aqlSort($sortExpressions));
    }
}
