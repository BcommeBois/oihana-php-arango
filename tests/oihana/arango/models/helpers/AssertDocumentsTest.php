<?php

namespace tests\oihana\arango\models\helpers;

use DI\Container;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use UnexpectedValueException;

use PHPUnit\Framework\TestCase;

use oihana\arango\models\Documents;

use function oihana\arango\models\helpers\assertDocuments;

final class AssertDocumentsTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testValidInstanceDoesNotThrow(): void
    {
        $docs = new Documents( new Container());
        $this->expectNotToPerformAssertions();
        assertDocuments($docs); // ne doit rien lancer
    }

    public function testInvalidInstanceThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Documents (arango).');

        assertDocuments('not a documents instance'); // string invalide
    }

    public function testInvalidObjectThrowsException(): void
    {
        $invalidObject = new class {};
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Documents (arango).');

        assertDocuments($invalidObject);
    }

    public function testNullThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Documents (arango).');

        assertDocuments(null);
    }

    public function testIntegerThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Documents (arango).');

        assertDocuments(123);
    }
}