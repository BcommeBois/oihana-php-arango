<?php

namespace tests\oihana\arango\db\operations;

use JsonSerializable;
use oihana\arango\db\options\QueryOptions;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\enums\Char;
use ReflectionException;
use function oihana\arango\db\operations\aqlOptions;

final class AqlOptionsTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testReturnsEmptyWhenNoOptionsKey(): void
    {
        $this->assertSame(Char::EMPTY, aqlOptions());
        $this->assertSame(Char::EMPTY, aqlOptions([AQL::FILTER => 'foo']));
    }

    /**
     * @throws ReflectionException
     */
    public function testWithAssociativeArray(): void
    {
        $opts   = [AQL::OPTIONS => [ 'fullCount' => true, 'batchSize' => 100 ]];
        $result = aqlOptions($opts);
        $this->assertSame(Clause::OPTIONS . Char::SPACE . '{"fullCount":true,"batchSize":100}', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testWithNonAssociativeArrayReturnsEmpty(): void
    {
        $opts   = [AQL::OPTIONS => ['a', 'b', 'c']];
        $result = aqlOptions($opts);
        $this->assertSame(Char::EMPTY, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testWithJsonStringIsUsedDirectly(): void
    {
        $json   = '{"fullCount":true}';
        $opts   = [AQL::OPTIONS => $json];
        $result = aqlOptions($opts);
        $this->assertSame(Clause::OPTIONS . Char::SPACE . $json, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testWithGenericObjectCastToArray(): void
    {
        $obj = new class
        {
            public bool $fullCount = true ;
            public int $batchSize = 500;
        };
        $opts   = [ AQL::OPTIONS => $obj ];
        $result = aqlOptions($opts);
        $this->assertSame(Clause::OPTIONS . Char::SPACE . '{"fullCount":true,"batchSize":500}', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testWithSchemaHydration(): void
    {
        $opts = [AQL::OPTIONS => [ 'fullCount' => true, 'batchSize' => 200, 'profile' => null ]];
        $result = aqlOptions($opts, SchemaOptions::class);
        // profile is null and should be removed by clean()
        $this->assertSame( 'OPTIONS {"batchSize":200,"fullCount":true}', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testWithJsonSerializableString(): void
    {
        $this->assertSame
        (
            expected : 'OPTIONS {"trace":false}',
            actual   : aqlOptions([ AQL::OPTIONS => new JsonSerializableClass() ] )
        );
    }
}

class JsonSerializableClass implements JsonSerializable
{
    public function jsonSerialize(): array
    {
        return [ 'trace' => false ] ;
    }
}

/**
 * Simple schema used to verify ReflectionTrait::hydrate() integration in OptionsTrait.
 * Public properties allow object cast to associative array after hydration.
 */
class SchemaOptions extends QueryOptions
{
    public ?bool $fullCount = null;
    public ?int  $batchSize = null;
    public ?string $profile = null;
}
