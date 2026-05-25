<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operators\logicalAnd;
use function oihana\arango\db\operators\logicalNot;
use function oihana\arango\db\operators\logicalOr;

final class LogicalOperatorsTest extends TestCase
{
    public function testLogicalAnd(): void
    {
        $this->assertEquals('a == 2 && b == 3', logicalAnd('a == 2', 'b == 3'));
    }

    public function testLogicalNot(): void
    {
        $this->assertEquals('!a == 2', logicalNot('a == 2'));
        $this->assertEquals('!(a == 2)', logicalNot('a == 2', true) );
    }

    public function testLogicalOr(): void
    {
        $this->assertEquals('a == 2 || b == 3', logicalOr('a == 2', 'b == 3'));
    }
}