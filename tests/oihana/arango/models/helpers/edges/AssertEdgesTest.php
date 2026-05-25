<?php

namespace tests\oihana\arango\models\helpers\edges;

use DI\Container;
use oihana\arango\models\Edges;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use UnexpectedValueException;
use function oihana\arango\models\helpers\edges\assertEdges;

final class AssertEdgesTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testValidInstanceDoesNotThrow(): void
    {
        $docs = new Edges( new Container());
        $this->expectNotToPerformAssertions();
        assertEdges($docs); // ne doit rien lancer
    }

    public function testInvalidInstanceThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Edges.');

        assertEdges('not a Edges instance'); // string invalide
    }

    public function testInvalidObjectThrowsException(): void
    {
        $invalidObject = new class {};
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Edges.');

        assertEdges($invalidObject);
    }

    public function testNullThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Edges.');

        assertEdges(null);
    }

    public function testIntegerThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The value property must be an instance of Edges.');

        assertEdges(123);
    }
}