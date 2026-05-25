<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlLet;

final class AqlLetTest extends TestCase
{
    public function testSimpleLet()
    {
        $query = aqlLet('total', 'SUM(doc.amount)');
        $expected = 'LET total = SUM(doc.amount)';
        $this->assertSame($expected, $query);
    }

    public function testLetWithConcatenation()
    {
        $query = aqlLet('fullName', "CONCAT(user.firstName, ' ', user.lastName)");
        $expected = "LET fullName = CONCAT(user.firstName, ' ', user.lastName)";
        $this->assertSame($expected, $query);
    }

    public function testLetWithVariableContainingUnderscore()
    {
        $query = aqlLet('user_count', 'LENGTH(users)');
        $expected = 'LET user_count = LENGTH(users)';
        $this->assertSame($expected, $query);
    }

    public function testLetWithNumericExpression()
    {
        $query = aqlLet('max', '10 + 5');
        $expected = 'LET max = 10 + 5';
        $this->assertSame($expected, $query);
    }

    public function testLetWithParentheses()
    {
        $query = aqlLet('surface', 'doc.width * doc.height', true);
        $expected = 'LET surface = (doc.width * doc.height)';
        $this->assertSame($expected, $query);
    }

    public function testLetWithComplexExpression()
    {
        $query = aqlLet('ratio', '(doc.valueA + doc.valueB) / doc.total', true);
        $expected = 'LET ratio = ((doc.valueA + doc.valueB) / doc.total)';
        $this->assertSame($expected, $query);
    }

    public function testLetWithEmptyExpression()
    {
        $query = aqlLet('emptyVar', '');
        $expected = 'LET emptyVar =';
        $this->assertSame($expected, $query);
    }

    public function testLetWithWhitespaceExpression()
    {
        $query = aqlLet('foo', '   ', false, true);
        $expected = 'LET foo =';
        $this->assertSame($expected, $query);
    }

    public function testLetWithBooleanLikeVariable()
    {
        $query = aqlLet('isActive', 'user.status == "active"');
        $expected = 'LET isActive = user.status == "active"';
        $this->assertSame($expected, $query);
    }

    public function testLetWithNestedParenthesesAndTrim()
    {
        $query = aqlLet('calc', '(a + b) * (c - d)', true, true);
        $expected = 'LET calc = (a + b) * (c - d)';
        $this->assertSame($expected, $query);
    }
}
