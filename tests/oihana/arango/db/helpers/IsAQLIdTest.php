<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use stdClass;

use function oihana\arango\db\helpers\isAQLId;

/**
 * Test suite for the isAQLId() helper function.
 * Uses PHP 8 attributes for tests and data providers.
 */
#[CoversFunction('oihana\arango\db\helpers\isAQLId')]
class IsAQLIdTest extends TestCase
{
    /**
     * Teste que la fonction retourne "true" pour les IDs valides.
     */
    #[Test]
    #[DataProvider('provideValidIds')]
    public function isAqlIdReturnsTrueForValidIds(string $id): void
    {
        $this->assertTrue(isAQLId($id));
    }

    /**
     * Teste que la fonction retourne "false" pour les chaînes de caractères invalides.
     */
    #[Test]
    #[DataProvider('provideInvalidStrings')]
    public function isAqlIdReturnsFalseForInvalidStringFormats(string $value): void
    {
        $this->assertFalse(isAQLId($value));
    }

    /**
     * Teste que la fonction retourne "false" pour les types de données non-string.
     */
    #[Test]
    #[DataProvider('provideInvalidDataTypes')]
    public function isAqlIdReturnsFalseForNonStringTypes(mixed $value): void
    {
        $this->assertFalse(isAQLId($value));
    }

    // --- Data Providers ---

    /**
     * Fournit un jeu de données d'IDs ArangoDB valides.
     * @return array<array{string}>
     */
    public static function provideValidIds(): array
    {
        return [
            'standard'        => ['users/12345'],
            'with underscore' => ['my_collection/my_key'],
            'with hyphen'     => ['my-collection/key-with-hyphen'],
            'with numbers'    => ['collection123/key456'],
            'complex key'     => ['documents/key:with.special-chars_123'],
        ];
    }

    /**
     * Fournit un jeu de données de chaînes qui NE SONT PAS des IDs valides.
     * @return array<array{string}>
     */
    public static function provideInvalidStrings(): array
    {
        return [
            'collection only'  => ['users'],
            'key only'         => ['/12345'],
            'trailing slash'   => ['users/'],
            'multiple slashes' => ['users/123/abc'],
            'double slash'     => ['users//123'],
            'bind variable'    => ['@startVertex'],
            'empty string'     => [''],
            'just a slash'     => ['/'],
        ];
    }

    /**
     * Fournit un jeu de données de types invalides (non-string).
     * @return array<array{mixed}>
     */
    public static function provideInvalidDataTypes(): array
    {
        return [
            'null'          => [null],
            'integer'       => [12345],
            'float'         => [123.45],
            'boolean true'  => [true],
            'boolean false' => [false],
            'empty array'   => [[]],
            'filled array'  => [['users/123']],
            'object'        => [new stdClass()],
        ];
    }
}