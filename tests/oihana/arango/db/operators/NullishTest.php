<?php

namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operators\nullish;

final class NullishTest extends TestCase
{
    /**
     * Test simple string fallback.
     */
    public function testNullishBasic(): void
    {
        $expr = nullish('u.value', 'default');
        $this->assertEquals('u.value ? : default', $expr);

        $result = nullish('u.value');
        $this->assertSame('u.value ? : ', $result);

        $result = nullish('u.value', 42);
        $this->assertSame('u.value ? : 42', $result);

        $result = nullish('x.foo', 'default');
        $this->assertSame('x.foo ? : default', $result);
    }

    /**
     * Test numeric default value.
     */
    public function testNullishNumeric(): void
    {
        $expr = nullish('item.count', 0);
        $this->assertEquals('item.count ? : 0', $expr);
    }

    /**
     * Test null default value.
     */
    public function testNullishWithNullDefault(): void
    {
        $expr = nullish('user.name');
        $this->assertEquals('user.name ? : ', $expr);
    }

    /**
     * Test complex expression as condition.
     */
    public function testNullishComplexExpression(): void
    {
        $expr = nullish('doc.tags[0]', '"no tags"');
        $this->assertEquals('doc.tags[0] ? : "no tags"', $expr);
    }

    /**
     * Test condition with underscore or dot in field name.
     */
    public function testNullishSpecialFieldNames(): void
    {
        $expr = nullish('doc._id', 'unknown');
        $this->assertEquals('doc._id ? : unknown', $expr);

        $expr2 = nullish('doc.user.name', 'guest');
        $this->assertEquals('doc.user.name ? : guest', $expr2);
    }
}