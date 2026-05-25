<?php

namespace tests\oihana\arango\models\helpers\edges;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Edges;

use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\models\helpers\edges\resolveEdges;

final class ResolveEdgesTest extends TestCase
{
    private Container $container ;

    public function setUp(): void
    {
        $this->container = new Container();
        $this->container->set( 'service_1' , fn( Container $container ) => new Edges( $container ) ) ;
        $this->container->set( 'service_2' , fn( Container $container ) => new Edges( $container ) ) ;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     */
    public function testResolveIndexedEdgesDefinitions(): void
    {
        $edges =
        [
            'service_1',
            'service_2'
        ];

        resolveEdges( $edges , $this->container ) ;

        $this->assertTrue(true);
        $this->assertEmpty( $edges );
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     */
    public function testResolveAssociativeEdgesWithModel(): void
    {
        $edges =
        [
            'edge_1' =>
            [
                AQL::MODEL => 'service_1',
            ],
        ];

        resolveEdges($edges, $this->container );

        $this->assertInstanceOf(Edges::class , $edges['edge_1'][AQL::MODEL] ) ;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     */
    public function testResolveWithAqlResolveKey(): void
    {
        $edges =
        [
            AQL::RESOLVE =>
            [
                'service_1',
                'service_2',
            ],
        ];

        resolveEdges($edges , $this->container );

        // The key AQL::RESOLVE should be removed after resolution
        $this->assertArrayNotHasKey(AQL::RESOLVE , $edges ) ;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     */
    public function testEmptyEdgesArrayDoesNothing(): void
    {
        $edges = [];
        resolveEdges($edges , $this->container ) ;
        $this->assertEmpty($edges);
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     */
    public function testNullContainerDoesNothing(): void
    {
        $edges = [
            'service_edge_1',
        ];
        resolveEdges($edges ) ; // should not throw
        $this->assertIsArray( $edges ) ;
        $this->assertSame( [ 'service_edge_1' ] , $edges ) ;
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     */
    public function testShortcutReferenceInAssociativeArray(): void
    {
        $edges = [
            'ref'       => 'edge_real',
            'edge_real' =>
            [
                AQL::MODEL => 'service_1',
            ],
        ];

        resolveEdges($edges, $this->container);

        $this->assertInstanceOf(Edges::class , $edges['edge_real'][AQL::MODEL] ) ;
    }
}