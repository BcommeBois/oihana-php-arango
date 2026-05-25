<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operators\equal;
use function oihana\arango\db\operators\greaterThan;
use function oihana\arango\db\operators\greaterThanOrEqual;
use function oihana\arango\db\operators\in;
use function oihana\arango\db\operators\isLike;
use function oihana\arango\db\operators\isMatch;
use function oihana\arango\db\operators\lessThan;
use function oihana\arango\db\operators\lessThanOrEqual;
use function oihana\arango\db\operators\notEqual;
use function oihana\arango\db\operators\notIn;
use function oihana\arango\db\operators\notLike;
use function oihana\arango\db\operators\notMatch;

class ComparisonOperatorsTest extends TestCase
{
    public function testEqual(): void
    {
        $this->assertEquals('a == 12', equal('a', '12'));
    }

    public function testGreaterThan(): void
    {
        $this->assertEquals('a > 12', greaterThan('a', '12'));
    }

    public function testGreaterThanOrEqual(): void
    {
        $this->assertEquals('a >= 12', greaterThanOrEqual('a', '12'));
    }

    public function testIn(): void
    {
        $this->assertEquals('1.5 IN [ 2, 3, 1.5 ]', in('1.5', '[ 2, 3, 1.5 ]'));
    }

    public function testIsLike(): void
    {
        $this->assertEquals('"foo" LIKE "f%"', isLike('"foo"', '"f%"'));
    }

    public function testIsMatch(): void
    {
        $this->assertEquals('"foo" =~ "^f[o].$"', isMatch('"foo"', '"^f[o].$"'));
    }

    public function testLessThan(): void
    {
        $this->assertEquals('a < 12', lessThan('a', '12'));
    }

    public function testLessThanOrEqual(): void
    {
        $this->assertEquals('a <= 12', lessThanOrEqual('a', '12'));
    }

    public function testNotEqual(): void
    {
        $this->assertEquals('a != 12', notEqual('a', '12'));
    }

    public function testNotIn(): void
    {
        $this->assertEquals('42 NOT IN [ 2, 3, 1.5 ]', notIn('42', '[ 2, 3, 1.5 ]'));
    }

    public function testNotLike(): void
    {
        $this->assertEquals('"foo" NOT LIKE "f%"', notLike('"foo"', '"f%"'));
    }

    public function testNotMatch(): void
    {
        $this->assertEquals('"foo" !~ "^f[o].$"', notMatch('"foo"', '"^f[o].$"'));
    }
}
