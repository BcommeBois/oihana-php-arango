<?php

namespace tests\oihana\arango\models\helpers\edges;

use DI\Container;
use oihana\arango\enums\Arango;
use oihana\arango\models\Edges;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use function oihana\arango\models\helpers\edges\getEdges;

final class GetEdgesTest extends TestCase
{
    private Edges     $edges     ;
    private Container $container ;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function setUp(): void
    {
        $this->container = new Container();
        $this->edges     = new Edges( $this->container ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDirectInstance(): void
    {
        $result = getEdges( $this->edges );
        $this->assertSame( $this->edges , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsFromArrayDefinition(): void
    {
        $definition = [ Arango::EDGES => $this->edges ] ;
        $result = getEdges( $definition ) ;
        $this->assertSame( $this->edges , $result );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsFromContainer(): void
    {
        $id = 'edges.service';

        $this->container->set( $id , $this->edges ) ;

        $result = getEdges( $id , $this->container );

        $this->assertSame( $this->edges , $result);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDefaultWhenNull(): void
    {
        $result = getEdges( default: $this->edges ) ;
        $this->assertSame( $this->edges , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDefaultWhenArrayMissingKey(): void
    {
        $default = $this->edges ;
        $definition = ['other_key' => new Edges( $this->container ) ]; // key Arango::EDGES absent
        $result = getEdges( $definition ,  default: $default ) ;
        $this->assertSame($default, $result);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDefaultWhenContainerDoesNotHaveService(): void
    {
        $result = getEdges( 'missing.service', $this->container , default: $this->edges);
        $this->assertSame( $this->edges , $result );
    }
}