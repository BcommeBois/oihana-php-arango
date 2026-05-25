<?php


namespace tests\oihana\arango\db\operators;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operators\ternary;

final class TernaryTest extends TestCase
{
    /**
     * Test basic ternary with all values provided.
     */
    public function testTernaryBasic(): void
    {
        $expr = ternary('IS_ARRAY(doc.tags)', 'FIRST(doc.tags)', 'null');
        $this->assertEquals('IS_ARRAY(doc.tags) ? FIRST(doc.tags) : null', $expr);
    }

    /**
     * Test ternary with null true value.
     */
    public function testTernaryNullTrueValue(): void
    {
        $expr = ternary('doc.count > 0', null, '0');
        $this->assertEquals('doc.count > 0 ?  : 0', $expr);
    }

    /**
     * Test ternary with null false value.
     */
    public function testTernaryNullFalseValue(): void
    {
        $expr = ternary('doc.active', '1', null);
        $this->assertEquals('doc.active ? 1 : ', $expr);
    }

    /**
     * Test ternary with both true and false values null.
     */
    public function testTernaryBothNull(): void
    {
        $expr = ternary('doc.active');
        $this->assertEquals('doc.active ?  : ', $expr);
    }

    /**
     * Test ternary with complex expressions as values.
     */
    public function testTernaryWithExpressions(): void
    {
        $trueExpr  = 'LENGTH(doc.items) > 0 ? doc.items[0] : null';
        $falseExpr = '[]';
        $expr      = ternary('IS_ARRAY(doc.items)', $trueExpr, $falseExpr);

        $expected = 'IS_ARRAY(doc.items) ? LENGTH(doc.items) > 0 ? doc.items[0] : null : []';
        $this->assertEquals($expected, $expr);
    }

    public function testTernary()
    {
        $result = ternary('x > 0');
        $this->assertSame('x > 0 ?  : ', $result);

        $result = ternary('x > 0', 'positive', 'non-positive');
        $this->assertSame('x > 0 ? positive : non-positive', $result);

        $result = ternary('flag', 1, 0);
        $this->assertSame('flag ? 1 : 0', $result);
    }
}