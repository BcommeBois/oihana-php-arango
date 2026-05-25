<?php

namespace tests\oihana\arango\db\operations;

use oihana\exceptions\BindException;
use PHPUnit\Framework\TestCase;
use function oihana\arango\db\operations\aqlLimit;

final class AqlLimitTest extends TestCase
{
    /**
     * @throws BindException
     */
    public function testAqlLimitOnly(): void
    {
        $result = aqlLimit(10);
        $this->assertSame('LIMIT 10', $result);
    }

    /**
     * @throws BindException
     */
    public function testAqlLimitWithOffset(): void
    {
        $result = aqlLimit(10, 5);
        $this->assertSame('LIMIT 5, 10', $result);
    }

    /**
     * @throws BindException
     */
    public function testAqlLimitZeroReturnsEmpty(): void
    {
        $result = aqlLimit(0);
        $this->assertSame('', $result);
    }

    /**
     * @throws BindException
     */
    public function testAqlLimitNegativeReturnsEmpty(): void
    {
        $result = aqlLimit(-5);
        $this->assertSame('', $result);
    }

    /**
     * @throws BindException
     */
    public function testAqlLimitWithBinds(): void
    {
        $binds = [];
        $result = aqlLimit(10, 5, $binds);
        $this->assertSame('LIMIT @offset, @limit', $result);
        $this->assertSame(['limit' => 10 , 'offset' => 5], $binds);
    }

    /**
     * @throws BindException
     */
    public function testAqlLimitWithBindsAndZeroOffset(): void
    {
        $binds = [];
        $result = aqlLimit(3, 0, $binds);

        $this->assertSame('LIMIT @limit', $result);
        $this->assertSame(['limit' => 3 ], $binds);
    }
}