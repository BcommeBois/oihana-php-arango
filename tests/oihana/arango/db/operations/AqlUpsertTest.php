<?php

namespace tests\oihana\arango\db\operations;

use InvalidArgumentException;
use ReflectionException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\UpsertType;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\operations\aqlRepsert;
use function oihana\arango\db\operations\aqlUpsert;

class AqlUpsertTest extends TestCase
{
    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpsertBuildsQueryWithUpdate()
    {
        $query = aqlUpsert
        ([
            AQL::SEARCH => [['foo','bar']],
            AQL::INSERT => [['foo','bar']],
            AQL::UPDATE => [['foo','baz']],
            AQL::COLLECTION => 'myCollection'
        ]);

        $this->assertStringContainsString('UPSERT {foo:\'bar\'}', $query);
        $this->assertStringContainsString('INSERT {foo:\'bar\'}', $query);
        $this->assertStringContainsString('UPDATE {foo:\'baz\'}', $query);
        $this->assertStringContainsString('IN myCollection', $query);
        $this->assertStringContainsString('RETURN NEW', $query);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRepsertBuildsQueryWithReplace()
    {
        $query = aqlRepsert
        ([
            AQL::SEARCH => [['foo','bar']],
            AQL::INSERT => [['foo','bar']],
            AQL::REPLACE => [['foo','baz']],
            AQL::COLLECTION => 'myCollection'
        ]);

        $this->assertStringContainsString('REPLACE {foo:\'baz\'}', $query);
        $this->assertStringContainsString('RETURN NEW', $query);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRepsertWithReturnWithStatus()
    {
        $query = aqlRepsert
        ([
            AQL::SEARCH     => [['foo','bar']],
            AQL::INSERT     => [['foo','bar']],
            AQL::REPLACE    => [['foo','baz']],
            AQL::COLLECTION => 'myCollection',
            AQL::RETURN     => Clause::WITH_STATUS
        ]);

        $this->assertStringContainsString
        (
            sprintf(
                "{ doc: NEW , type: OLD ? '%s' : '%s' }",
                UpsertType::REPLACE ,
                UpsertType::INSERT
            ),
            $query
        );
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpsertWithReturnWithStatus()
    {
        $query = aqlUpsert
        ([
            AQL::SEARCH => [['foo','bar']],
            AQL::INSERT => [['foo','bar']],
            AQL::UPDATE => [['foo','baz']],
            AQL::COLLECTION => 'myCollection',
            AQL::RETURN => Clause::WITH_STATUS
        ]);

        $this->assertStringContainsString(
            sprintf(
                "{ doc: NEW , type: OLD ? '%s' : '%s' }",
                UpsertType::UPDATE ,
                UpsertType::INSERT
            ),
            $query
        );
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpsertThrowsWithoutSearchOrFilter()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either FILTER or SEARCH option is required.');

        aqlUpsert
        ([
            AQL::INSERT => [['foo','bar']],
            AQL::UPDATE => [['foo','baz']]
        ]);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testUpsertThrowsWithoutInsert()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('INSERT option is required');

        aqlUpsert
        ([
            AQL::SEARCH => [['foo','bar']],
            AQL::UPDATE => [['foo','baz']]
        ]);
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testRepsertThrowsWithoutReplace()
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage('REPLACE option is required');

        aqlRepsert
        ([
            AQL::SEARCH => [['foo','bar']],
            AQL::INSERT => [['foo','bar']]
        ]);
    }
}

