<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\ValidationException;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use stdClass;

use function oihana\arango\db\helpers\assertVariableName;
use function oihana\arango\db\helpers\isAttributeName;
use function oihana\arango\db\helpers\isVariableName;

/**
 * Test suite for the isVariableName() / assertVariableName() helpers, which
 * guard a declared identifier before it is interpolated into a `LET`.
 */
#[CoversFunction('oihana\arango\db\helpers\isVariableName')]
#[CoversFunction('oihana\arango\db\helpers\assertVariableName')]
class VariableNameTest extends TestCase
{
    #[Test]
    #[DataProvider('provideValidNames')]
    public function isVariableNameReturnsTrueForWellFormedNames(string $name): void
    {
        $this->assertTrue(isVariableName($name));
    }

    #[Test]
    #[DataProvider('provideInvalidNames')]
    public function isVariableNameReturnsFalseForIllFormedNames(string $name): void
    {
        $this->assertFalse(isVariableName($name));
    }

    #[Test]
    #[DataProvider('provideInvalidDataTypes')]
    public function isVariableNameReturnsFalseForNonStrings(mixed $value): void
    {
        $this->assertFalse(isVariableName($value));
    }

    #[Test]
    public function assertVariableNamePassesSilentlyForAWellFormedName(): void
    {
        $this->expectNotToPerformAssertions();
        assertVariableName('authorRef');
    }

    #[Test]
    #[DataProvider('provideInvalidNames')]
    public function assertVariableNameThrowsForIllFormedNames(string $name): void
    {
        $this->expectException(ValidationException::class);
        assertVariableName($name);
    }

    #[Test]
    public function theRefusalNamesWhatWasWrittenAndWhatIsExpected(): void
    {
        try
        {
            assertVariableName('address.city');
            $this->fail('A dotted path must be refused.');
        }
        catch (ValidationException $exception)
        {
            $this->assertStringContainsString('address.city', $exception->getMessage());
            $this->assertStringContainsString('identifier', $exception->getMessage());
        }
    }

    /**
     * The reason this helper exists rather than reusing the attribute guard: a
     * path is a valid attribute name and an invalid variable name. Measured
     * against a real server, `LET address.city = …` is a syntax error — so a
     * guard that accepts it would let the failure reach the database.
     */
    #[Test]
    public function aPathIsAValidAttributeNameAndNotAValidVariableName(): void
    {
        $this->assertTrue(isAttributeName('address.city'), 'The attribute guard accepts a path…');
        $this->assertFalse(isVariableName('address.city'), '…which is exactly what this one must not.');
    }

    /**
     * Two failures this guard deliberately does not chase, because they are not
     * about shape: an AQL keyword has the shape of an identifier, and a name
     * already bound in the query (`doc`) is well-formed too. ArangoDB refuses
     * both loudly at the first query, so they surface at once instead of
     * corrupting a result — enumerating the keyword list here would only add a
     * copy to keep in sync with the server.
     */
    #[Test]
    #[DataProvider('provideNamesRefusedByTheServerNotByTheShape')]
    public function namesTheServerRefusesAreWellFormedHere(string $name): void
    {
        $this->assertTrue(isVariableName($name));
    }

    public static function provideValidNames(): array
    {
        return
        [
            'lower'            => ['ref'],
            'camel case'       => ['authorRef'],
            'leading under'    => ['_ref'],
            'digits inside'    => ['ref2'],
            'underscore mix'   => ['author_ref_2'],
            'single letter'    => ['x'],
            'upper'            => ['REF'],
        ];
    }

    public static function provideInvalidNames(): array
    {
        return
        [
            'dotted path'      => ['address.city'],
            'leading digit'    => ['1ref'],
            'hyphen'           => ['my-ref'],
            'space'            => ['a b'],
            'empty'            => [''],
            'injection'        => ['x = 1) RETURN 1 //'],
            'accented'         => ['été'],
            'bracket'          => ['ref[0]'],
            'at sign'          => ['@ref'],
        ];
    }

    public static function provideInvalidDataTypes(): array
    {
        return
        [
            'null'   => [null],
            'int'    => [42],
            'float'  => [1.5],
            'bool'   => [true],
            'array'  => [['ref']],
            'object' => [new stdClass()],
        ];
    }

    public static function provideNamesRefusedByTheServerNotByTheShape(): array
    {
        return
        [
            'AQL keyword LET'    => ['LET'],
            'AQL keyword RETURN' => ['RETURN'],
            'already bound doc'  => ['doc'],
        ];
    }
}
