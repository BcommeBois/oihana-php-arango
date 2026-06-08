<?php

namespace tests\oihana\arango\db\operations;

use oihana\exceptions\UnsupportedOperationException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;

use function oihana\arango\db\operations\aqlCollectReturn;

class AqlCollectReturnTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testEmptySpecReturnsEmpty(): void
    {
        $this->assertSame('', aqlCollectReturn([]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testExplicitReturnWins(): void
    {
        // Explicit expression overrides any derivation.
        $this->assertSame(
            'RETURN { year: y }',
            aqlCollectReturn([AQL::ASSIGN => ['y' => 'doc.created']], '{ year: y }')
        );
        // ...even with an otherwise empty spec.
        $this->assertSame('RETURN x', aqlCollectReturn([], 'x'));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testEmptyExplicitFallsBackToDerivation(): void
    {
        $this->assertSame(
            'RETURN {status}',
            aqlCollectReturn([AQL::ASSIGN => ['status' => 'doc.status']], '')
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testGroupingOnly(): void
    {
        $this->assertSame(
            'RETURN {status}',
            aqlCollectReturn([AQL::ASSIGN => ['status' => 'doc.status']])
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testMultipleGroupingKeys(): void
    {
        $this->assertSame(
            'RETURN {a, b}',
            aqlCollectReturn([AQL::ASSIGN => ['a' => 'doc.a', 'b' => 'doc.b']])
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testGroupingWithCount(): void
    {
        $this->assertSame(
            'RETURN {category, count}',
            aqlCollectReturn([AQL::ASSIGN => ['category' => 'doc.category'], AQL::WITH_COUNT => 'count'])
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testCountOnlyIsScalar(): void
    {
        $this->assertSame('RETURN length', aqlCollectReturn([AQL::WITH_COUNT => 'length']));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testGroupingWithAggregate(): void
    {
        $this->assertSame(
            'RETURN {y, total}',
            aqlCollectReturn([
                AQL::ASSIGN    => ['y' => 'DATE_YEAR(doc.created)'],
                AQL::AGGREGATE => ['total' => 'SUM(doc.amount)'],
            ])
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testAggregateDropsCount(): void
    {
        // AGGREGATE and WITH_COUNT are mutually exclusive (mirrors aqlCollect()).
        $this->assertSame(
            'RETURN {total}',
            aqlCollectReturn([AQL::AGGREGATE => ['total' => 'SUM(1)'], AQL::WITH_COUNT => 'n'])
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testIntoIsNotAutoProjected(): void
    {
        // INTO collected documents are not auto-projected; only the group key is.
        $this->assertSame(
            'RETURN {author}',
            aqlCollectReturn([AQL::ASSIGN => ['author' => 'doc.authorId'], AQL::INTO => 'docs'])
        );
    }
}
