<?php

namespace tests\oihana\arango\db\operations;

use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;

use function oihana\arango\db\operations\aqlCollect;

class AqlCollectTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testReturnsEmptyWhenInvalid(): void
    {
        $this->assertSame('', aqlCollect([]));
        // Still invalid AQL if only INTO/KEEP/PROJECTION are present
        $this->assertSame('', aqlCollect([AQL::INTO => 'items']));
        $this->assertSame('', aqlCollect([AQL::KEEP => ['a']]));
        $this->assertSame('', aqlCollect([AQL::INTO => 'i', AQL::PROJECTION => 'p']));
    }

    /**
     * @throws ReflectionException
     */
    public function testWithCountOnly(): void
    {
        $init = [AQL::WITH_COUNT => 'myCount'];
        // Mock: COLLECT, WITH COUNT, INTO, myCount
        $expected = 'COLLECT WITH COUNT INTO myCount';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAssignOnly(): void
    {
        $init = [
            AQL::ASSIGN => ['group' => 'doc.type', 'age' => 'doc.age']
        ];
        // Mock aqlAssignments: "group = doc.type, age = doc.age"
        // Mock compile: COLLECT, "group = doc.type, age = doc.age"
        $expected = 'COLLECT group = doc.type, age = doc.age';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAggregateOnly(): void
    {
        // This case should now work because the validation allows it
        $init = [
            AQL::AGGREGATE => ['minAge' => 'MIN(u.age)', 'maxAge' => 'MAX(u.age)']
        ];
        // Mock aqlAssignments: "minAge = MIN(u.age), maxAge = MAX(u.age)"
        // Mock compile: COLLECT, AGGREGATE, "minAge = MIN(u.age), maxAge = MAX(u.age)"
        $expected = 'COLLECT AGGREGATE minAge = MIN(u.age), maxAge = MAX(u.age)';
        $this->assertSame($expected, aqlCollect($init));
    }


    /**
     * @throws ReflectionException
     */
    public function testAssignAndAggregate(): void
    {
        $init = [
            AQL::ASSIGN => ['type' => 'doc.type'],
            AQL::AGGREGATE => ['total' => 'SUM(doc.value)']
        ];
        // Mock aqlAssignments (assign): "type = doc.type"
        // Mock aqlAssignments (agg): "total = SUM(doc.value)"
        // Mock compile: COLLECT, "type = doc.type", AGGREGATE, "total = SUM(doc.value)"
        $expected = 'COLLECT type = doc.type AGGREGATE total = SUM(doc.value)';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAssignWithInto(): void
    {
        $init = [
            AQL::ASSIGN => ['type' => 'doc.type'],
            AQL::INTO => 'docs'
        ];
        // Mock aqlAssignments: "type = doc.type"
        // Mock compile: COLLECT, "type = doc.type", INTO, docs
        $expected = 'COLLECT type = doc.type INTO docs';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAssignWithIntoAndProjection(): void
    {
        $init = [
            AQL::ASSIGN => ['type' => 'doc.type'],
            AQL::INTO => 'docs',
            AQL::PROJECTION => 'doc.name'
        ];
        // Mock aqlAssignments: "type = doc.type"
        // Mock compile: COLLECT, "type = doc.type", INTO, docs, " = ", doc.name
        $expected = 'COLLECT type = doc.type INTO docs = doc.name';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAssignWithKeep(): void
    {
        $init = [
            AQL::ASSIGN => ['type' => 'doc.type'],
            AQL::KEEP => ['name', 'age']
        ];
        // Mock aqlAssignments: "type = doc.type"
        // Mock compile (keep): "name, age"
        // Mock compile (main): COLLECT, "type = doc.type", KEEP, "name, age"
        $expected = 'COLLECT type = doc.type KEEP name, age';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAssignWithCount(): void
    {
        $init = [
            AQL::ASSIGN => ['type' => 'doc.type'],
            AQL::WITH_COUNT => 'num'
        ];
        // Mock aqlAssignments: "type = doc.type"
        // Mock compile: COLLECT, "type = doc.type", WITH COUNT, INTO, num
        $expected = 'COLLECT type = doc.type WITH COUNT INTO num';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testAggregateWithCount(): void
    {
        $init =
        [
            AQL::AGGREGATE => ['total' => 'SUM(1)'],
            AQL::WITH_COUNT => 'len'
        ];
        // Mock aqlAssignments: "total = SUM(1)"
        // Mock compile: COLLECT, AGGREGATE, "total = SUM(1)", WITH COUNT, INTO, len
        $expected = 'COLLECT AGGREGATE total = SUM(1) WITH COUNT INTO len';
        $this->assertSame($expected, aqlCollect($init));
    }

    /**
     * @throws ReflectionException
     */
    public function testFullClauseWithOptions(): void
    {
        $init =
        [
            AQL::ASSIGN     => ['group' => 'd.group'   ] ,
            AQL::AGGREGATE  => ['count' => 'LENGTH(1)' ] ,
            AQL::INTO       => 'items' ,
            AQL::PROJECTION => 'd.name',
            AQL::KEEP       => [ 'sharedVar' ] ,
            AQL::WITH_COUNT => 'groupLength',
            AQL::OPTIONS    => [ 'method' => 'hash' ] // This triggers the aqlOptions mock
        ];

        // Mock aqlAssignments (assign): "group = d.group"
        // Mock aqlAssignments (agg): "count = LENGTH(1)"
        // Mock compile (keep): "sharedVar"
        // Mock aqlOptions: "OPTIONS { ... }"
        // Mock compile (main): Joins all parts
        $expected = 'COLLECT group = d.group AGGREGATE count = LENGTH(1) INTO items = d.name KEEP sharedVar WITH COUNT INTO groupLength OPTIONS {"method":"hash"}';
        $this->assertSame($expected, aqlCollect($init));
    }
}