<?php

namespace tests\oihana\arango\models\helpers;

use DI\Container;
use oihana\arango\models\Documents;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function oihana\arango\models\helpers\vertexID;

final class VertexIDTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsNullWhenVertexKeyIsNull(): void
    {
        $this->assertNull(vertexID(null, null));
        $this->assertNull(vertexID(null, 'users'));
        $this->assertNull(vertexID(null, new Documents( new Container())));
    }

    public function testReturnsRawKeyWhenCollectionIsNull(): void
    {
        $vertexKey = '123';
        $this->assertSame('123', vertexID( $vertexKey ));
    }

    public function testPrefixesVertexKeyWithStringCollection(): void
    {
        $vertexKey = '456';
        $collection = 'accounts';
        $this->assertSame('accounts/456', vertexID($vertexKey, $collection));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testPrefixesVertexKeyWithDocumentsCollection(): void
    {
        $vertexKey = '789';
        $doc = new Documents( new Container() );
        $doc->collection = 'users';
        $this->assertSame('users/789', vertexID($vertexKey, $doc));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsRawKeyWhenDocumentsHasNoCollection(): void
    {
        $vertexKey = '999';
        $doc = new Documents( new Container() ); // no collection set
        $this->assertSame('999', vertexID($vertexKey, $doc));
    }

    /**
     * This tests the critical use case where the key is already a full _id.
     * The function should NOT prefix it again.
     *
     * NOTE: This test will FAIL with the current implementation of vertexID,
     * as it produces 'users/users/123'. It will PASS with the corrected version.
     */
    public function testReturnsFullIdAsIsEvenWhenStringCollectionIsProvided(): void
    {
        $vertexId = 'users/123';
        $collection = 'users';
        // The function must be smart enough to see 'users/123' is a full ID
        // and ignore the $collection parameter.
        $this->assertSame('users/123', vertexID($vertexId, $collection));

        $vertexId2 = 'posts/456';
        $collection2 = 'another_collection';
        // It should ignore the context collection if the key is already a full ID
        $this->assertSame('posts/456', vertexID($vertexId2, $collection2));
    }

    /**
     * This tests the same critical use case but with a Documents object.
     *
     * NOTE: This test will FAIL with the current implementation of vertexID,
     * as it produces 'users/users/123'. It will PASS with the corrected version.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsFullIdAsIsEvenWhenDocumentsCollectionIsProvided(): void
    {
        $vertexId = 'users/123';
        $doc = new Documents( new Container() );
        $doc->collection = 'users';
        // The function must be smart enough to see 'users/123' is a full ID
        // and ignore the $doc->collection parameter.
        $this->assertSame('users/123', vertexID($vertexId, $doc));
    }

    /**
     * This tests that a full _id is returned correctly when no collection
     * context is provided. This test should already pass.
     */
    public function testReturnsFullIdWhenCollectionIsNull(): void
    {
        $vertexId = 'users/123';
        $this->assertSame('users/123', vertexID($vertexId));
    }
}