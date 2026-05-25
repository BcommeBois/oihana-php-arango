<?php

namespace tests\oihana\arango\models\helpers;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use PHPUnit\Framework\TestCase;

use oihana\arango\models\Edges;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;
use function oihana\arango\models\helpers\getDocuments;

final class GetDocumentsTest extends TestCase
{
    private Documents  $documents ;
    private Container  $container ;

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function setUp(): void
    {
        $this->container = new Container();
        $this->documents = new Documents( $this->container ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDirectInstance(): void
    {
        $result = getDocuments( $this->documents );
        $this->assertSame( $this->documents , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsFromArrayDefinition(): void
    {
        $definition = [ Arango::DOCUMENTS => $this->documents ] ;
        $result = getDocuments( $definition ) ;
        $this->assertSame( $this->documents , $result );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsFromContainer(): void
    {
        $id = 'documents.service';

        $this->container->set( $id , $this->documents ) ;

        $result = getDocuments( $id , $this->container );

        $this->assertSame( $this->documents , $result);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDefaultWhenNull(): void
    {
        $result = getDocuments( default: $this->documents ) ;
        $this->assertSame( $this->documents , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testReturnsDefaultWhenArrayMissingKey(): void
    {
        $default = $this->documents ;
        $definition = ['other_key' => new Edges( $this->container ) ]; // key Arango::EDGES absent
        $result = getDocuments( $definition ,  default: $default ) ;
        $this->assertSame($default, $result);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testReturnsDefaultWhenContainerDoesNotHaveService(): void
    {
        $result = getDocuments( 'missing.service', $this->container , default: $this->documents);
        $this->assertSame( $this->documents , $result );
    }
}