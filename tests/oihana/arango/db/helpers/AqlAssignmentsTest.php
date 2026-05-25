<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\Comparator;
use oihana\enums\Char;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\aqlArray;
use function oihana\arango\db\helpers\aqlAssignments;

class AqlAssignmentsTest extends TestCase
{
    public function testReturnsNullWhenAssignmentsIsNull(): void
    {
        $this->assertNull(aqlAssignments(null));
    }

    public function testReturnsNullWhenAssignmentsIsEmptyArray(): void
    {
        $this->assertNull(aqlAssignments([]));
    }

    public function testReturnsNullWhenAssignmentsContainsOnlyCleanableValues(): void
    {
        $this->assertNull(aqlAssignments(['key1' => null, 'key2' => '']));
    }

    public function testSingleAssignmentWithDefaultSeparatorAndComparator(): void
    {
        $assignments = ['age' => 42];
        $this->assertSame('age = 42', aqlAssignments($assignments));
    }

    public function testMultipleAssignmentsWithDefaultSeparatorAndComparator(): void
    {
        $assignments = [
            'name' => '"John"',
            'age' => 30,
            'active' => 'true'
        ];
        // Mock predicate produces "key = value" for each
        $expected = 'name = "John", age = 30, active = true';
        $this->assertSame($expected, aqlAssignments($assignments));
    }

    public function testMultipleAssignmentsWithCustomSeparator(): void
    {
        $assignments = [
            'a' => 1,
            'b' => 2
        ];
        $separator = ' AND ';
        // Mock predicate: "a = 1", "b = 2"
        $expected = 'a = 1 AND b = 2';
        $this->assertSame($expected, aqlAssignments($assignments, $separator));
    }

    public function testMultipleAssignmentsWithCustomComparator(): void
    {
        $assignments = [
            'status' => '"active"',
            'type' => '"user"'
        ];
        $comparator = Comparator::EQUAL; // Use '==' from mock
        $expected = 'status == "active", type == "user"';
        $this->assertSame($expected, aqlAssignments($assignments, Char::COMMA . Char::SPACE, $comparator));
    }

    public function testAssignmentsIgnoresCleanedValues(): void
    {
        $assignments = [
            'valid1' => 123,
            'empty' => '',
            'valid2' => '"abc"',
            'nullVal' => null
        ];
        $expected = 'valid1 = 123, valid2 = "abc"';
        $this->assertSame($expected, aqlAssignments($assignments));
    }

    public function testAssignmentsHandlesValuesNeedingCompile(): void
    {
        $assignments =
        [
            'list' => aqlArray( [1, 2] ) ,
            'num'  => 0
        ];
        // Mock predicate: "list = [1,2]", "num = 0"
        $expected = 'list = [1,2], num = 0';
        $this->assertSame($expected, aqlAssignments($assignments));
    }
}