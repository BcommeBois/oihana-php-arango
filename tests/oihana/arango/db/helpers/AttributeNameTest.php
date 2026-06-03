<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use stdClass;

use function oihana\arango\db\helpers\assertAttributeName;
use function oihana\arango\db\helpers\isAttributeName;

/**
 * Test suite for the isAttributeName() / assertAttributeName() helpers, which
 * guard untrusted identifiers concatenated into AQL dot-notation accessors.
 */
#[CoversFunction('oihana\arango\db\helpers\isAttributeName')]
#[CoversFunction('oihana\arango\db\helpers\assertAttributeName')]
class AttributeNameTest extends TestCase
{
    #[Test]
    #[DataProvider('provideValidNames')]
    public function isAttributeNameReturnsTrueForSafeNames(string $name): void
    {
        $this->assertTrue(isAttributeName($name));
    }

    #[Test]
    #[DataProvider('provideInvalidNames')]
    public function isAttributeNameReturnsFalseForUnsafeNames(string $name): void
    {
        $this->assertFalse(isAttributeName($name));
    }

    #[Test]
    #[DataProvider('provideInvalidDataTypes')]
    public function isAttributeNameReturnsFalseForNonStringTypes(mixed $value): void
    {
        $this->assertFalse(isAttributeName($value));
    }

    #[Test]
    public function assertAttributeNamePassesForValidName(): void
    {
        $this->expectNotToPerformAssertions();
        assertAttributeName('breeding.alternateName');
    }

    #[Test]
    #[DataProvider('provideInvalidNames')]
    public function assertAttributeNameThrowsForUnsafeNames(string $name): void
    {
        $this->expectException(ValidationException::class);
        assertAttributeName($name);
    }

    #[Test]
    public function assertAttributeNameThrowsForNonString(): void
    {
        $this->expectException(ValidationException::class);
        assertAttributeName(42);
    }

    // --- Data Providers ---

    /**
     * @return array<string,array{string}>
     */
    public static function provideValidNames(): array
    {
        return [
            'simple'          => ['value'],
            'underscore lead' => ['_key'],
            'with digits'     => ['field2'],
            'nested path'     => ['breeding.alternateName'],
            'deep nested'     => ['a1.b2.c3'],
            'all underscores' => ['_a_b_'],
        ];
    }

    /**
     * Names that could break out of a `doc.<name>` accessor and must be rejected.
     *
     * @return array<string,array{string}>
     */
    public static function provideInvalidNames(): array
    {
        return [
            'space'             => ['with space'],
            'boolean injection' => ['a == 1 || 1'],
            'subquery injection'=> ['a)||LENGTH(FOR s IN secrets RETURN 1)>0||(b'],
            'double quote'      => ['a" OR "1'],
            'hyphen'            => ['my-key'],
            'leading dot'       => ['.value'],
            'trailing dot'      => ['value.'],
            'double dot'        => ['a..b'],
            'leading digit'     => ['1value'],
            'digit segment'     => ['a.1b'],
            'semicolon'         => ['a;b'],
            'bracket'           => ['a[0]'],
            'empty string'      => [''],
        ];
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function provideInvalidDataTypes(): array
    {
        return [
            'null'    => [null],
            'integer' => [42],
            'float'   => [1.5],
            'boolean' => [true],
            'array'   => [['value']],
            'object'  => [new stdClass()],
        ];
    }
}
