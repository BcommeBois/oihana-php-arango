<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\isAQLExpression;

class IsAQLExpressionTest extends TestCase
{
    public function testNullReturnsFalse()
    {
        $this->assertFalse(isAQLExpression(null));
    }

    public function testBooleanReturnsFalse()
    {
        $this->assertFalse(isAQLExpression(true));
        $this->assertFalse(isAQLExpression(false));
    }

    public function testNumbersReturnFalse()
    {
        $this->assertFalse(isAQLExpression(0));
        $this->assertFalse(isAQLExpression(42));
        $this->assertFalse(isAQLExpression(-3.14));
    }

    public function testArraysAndObjectsReturnFalse()
    {
        $this->assertFalse(isAQLExpression([1,2,3]));
        $this->assertFalse(isAQLExpression(['key' => 'value']));
        $this->assertFalse(isAQLExpression((object)['name' => 'Alice']));
    }

    public function testEmptyStringReturnsFalse()
    {
        $this->assertFalse(isAQLExpression(''));
        $this->assertFalse(isAQLExpression('   '));
    }

    public function testAqlFunctionReturnsTrue()
    {
        // On suppose que StringFunction::CONCAT("a","b") est reconnu
        $this->assertTrue(isAQLExpression('CONCAT("a","b")'));
        $this->assertFalse(isAQLExpression('concat("a","b")'));
        $this->assertFalse(isAQLExpression('TEST("a","b")'));
    }

    public function testDocumentReferences()
    {
        $this->assertTrue(isAQLExpression('doc.field'));
        $this->assertTrue(isAQLExpression('user.profile.name'));
        $this->assertFalse(isAQLExpression('doc..field')); // double point invalide
        $this->assertFalse(isAQLExpression('.doc.field')); // début invalide
    }

    public function testBindParameters()
    {
        $this->assertTrue(isAQLExpression('@param'));
        $this->assertTrue(isAQLExpression('@user.name'));
        $this->assertFalse(isAQLExpression('@'));
        $this->assertFalse(isAQLExpression('@.field'));
    }

    public function testCollectionDocumentPaths()
    {
        $this->assertTrue(isAQLExpression('collection/key'));
        $this->assertTrue(isAQLExpression('users/1234'));
        $this->assertFalse(isAQLExpression('collection/'));
        $this->assertFalse(isAQLExpression('/key'));
    }

    public function testRandomQuotedStringReturnsTrue()
    {
        $this->assertTrue(isAQLExpression("'hello world'"));
    }

    public function testRandomStringReturnsFalse()
    {
        $this->assertFalse(isAQLExpression('hello world'));
        $this->assertFalse(isAQLExpression('"hello world"'));
        $this->assertFalse(isAQLExpression('not_a_function()'));
    }
}