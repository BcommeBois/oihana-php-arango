<?php

namespace tests\oihana\arango\models\helpers;

use org\schema\constants\Schema;
use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\extractFromIDs;
use function oihana\arango\models\helpers\extractToIDs;
use function oihana\arango\models\helpers\extractVertexIDs;

final class ExtractVertexIDsTest extends TestCase
{
    public function testExtractToIdsFromObjects(): void
    {
        $edges = [
            (object) ['_from' => 'apis/1', '_to' => 'permissions/1'],
            (object) ['_from' => 'apis/1', '_to' => 'permissions/2'],
            (object) ['_from' => 'apis/2', '_to' => 'permissions/1'],
        ];

        $result = extractVertexIds($edges, Schema::_TO);
        $this->assertEqualsCanonicalizing(['permissions/1', 'permissions/2'], $result);

        $resultFrom = extractVertexIds($edges, Schema::_FROM);
        $this->assertEqualsCanonicalizing(['apis/1', 'apis/2'], $resultFrom);
    }

    public function testExtractToIdsFromArrays(): void
    {
        $edges = [
            ['_from' => 'apis/1', '_to' => 'permissions/1'],
            ['_from' => 'apis/1', '_to' => 'permissions/2'],
            ['_from' => 'apis/2', '_to' => 'permissions/1'],
        ];

        $result = extractVertexIds($edges, Schema::_TO);
        $this->assertEqualsCanonicalizing(['permissions/1', 'permissions/2'], $result);

        $resultFrom = extractVertexIds($edges, Schema::_FROM);
        $this->assertEqualsCanonicalizing(['apis/1', 'apis/2'], $resultFrom);
    }

    public function testExtractFromIdsHelper(): void
    {
        $edges = [
            ['_from' => 'apis/1', '_to' => 'permissions/1'],
            ['_from' => 'apis/1', '_to' => 'permissions/2'],
        ];

        $result = extractFromIDs($edges);
        $this->assertEqualsCanonicalizing(['apis/1'], $result);
    }

    public function testExtractToIdsHelper(): void
    {
        $edges = [
            ['_from' => 'apis/1', '_to' => 'permissions/1'],
            ['_from' => 'apis/1', '_to' => 'permissions/2'],
        ];

        $result = extractToIDs($edges);
        $this->assertEqualsCanonicalizing(['permissions/1', 'permissions/2'], $result);
    }

    public function testEmptyEdges(): void
    {
        $edges = [];
        $this->assertSame([], extractVertexIds($edges, Schema::_FROM));
        $this->assertSame([], extractVertexIds($edges, Schema::_TO));
    }

    public function testMissingKeys(): void
    {
        $edges = [
            (object) ['_from' => 'apis/1'], // no _to
            ['_to' => 'permissions/1'],     // no _from
        ];

        $this->assertEquals(['apis/1'], extractFromIDs($edges));
        $this->assertEquals(['permissions/1'], extractToIDs($edges));
    }
}