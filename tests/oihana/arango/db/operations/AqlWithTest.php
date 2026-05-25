<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\Operator;
use oihana\enums\Char;

use function oihana\arango\db\operations\aqlWith;

final class AqlWithTest extends TestCase
{
    public function testWithNoCollectionsReturnsEmptyString(): void
    {
        $result = aqlWith();
        $this->assertSame(Char::EMPTY, $result);
    }

    public function testWithSingleCollection(): void
    {
        $result = aqlWith('users');
        $expected = Operator::WITH . Char::SPACE . 'users';
        $this->assertSame($expected, $result);
    }

    public function testWithMultipleCollections(): void
    {
        $result = aqlWith('users', 'orders', 'products');
        $expected = Operator::WITH . Char::SPACE . 'users' . Char::COMMA . Char::SPACE . 'orders' . Char::COMMA . Char::SPACE . 'products';
        $this->assertSame($expected, $result);
    }

    public function testWithCollectionsWithSpaces(): void
    {
        $result = aqlWith('users', 'user orders');
        $expected = Operator::WITH . Char::SPACE . 'users' . Char::COMMA . Char::SPACE . 'user orders';
        $this->assertSame($expected, $result);
    }
}