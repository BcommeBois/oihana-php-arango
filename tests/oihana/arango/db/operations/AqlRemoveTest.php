<?php

namespace tests\oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\operations\aqlRemove;

final class AqlRemoveTest extends TestCase
{
    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRemoveWithDefaultKey(): void
    {
        $query = aqlRemove
        ([
            'collection' => 'users'
        ]);

        $expected = 'REMOVE {_key:doc._key} IN users';
        $this->assertEquals($expected, $query);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRemoveWithCustomKey(): void
    {
        $query = aqlRemove
        ([
            'collection' => 'users',
            'key'        => 'username'
        ]);

        $expected = 'REMOVE {username:doc.username} IN users';
        $this->assertEquals($expected, $query);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRemoveWithCustomExpression(): void
    {
        $query = aqlRemove
        ([
            'collection' => 'products',
            'expression' => 'item._key'
        ]);

        $expected = 'REMOVE item._key IN products';
        $this->assertEquals($expected, $query);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRemoveWithOptions(): void
    {
        $query = aqlRemove
        ([
            'collection' => 'logs',
            'options'    =>
            [
                'ignoreErrors'      => true,
                'waitForSync'       => false,
                'refillIndexCaches' => true
            ]
        ]);

        $expected = 'REMOVE {_key:doc._key} IN logs OPTIONS {"ignoreErrors":true,"refillIndexCaches":true,"waitForSync":false}';
        $this->assertEquals($expected, $query);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRemoveThrowsExceptionWhenCollectionMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection name is required for REMOVE');

        aqlRemove();
    }
}