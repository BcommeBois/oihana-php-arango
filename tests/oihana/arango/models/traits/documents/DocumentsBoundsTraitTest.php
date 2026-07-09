<?php

namespace tests\oihana\arango\models\traits\documents;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\documents\DocumentsBoundsTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use Closure;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use org\schema\helpers\SchemaResolver;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

/**
 * Host for {@see DocumentsBoundsTrait::bounds()}. It composes
 * {@see ListQueryTrait} for the filter/bind machinery and stubs the DB access
 * ({@see getFirstResult()}) so the result-shaping logic is tested in isolation.
 */
class DocumentsBoundsHost
{
    use ListQueryTrait , DocumentsBoundsTrait ;

    public mixed $canned = null ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'products' ;
        $this->bounds     = [ 'width' => true ] ;
    }

    public function getFirstResult
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    : mixed
    {
        return $this->canned ;
    }
}

/**
 * Unit coverage for {@see DocumentsBoundsTrait::bounds()} — the result shaping
 * on top of {@see BoundsQueryTrait::buildBoundsQuery()}.
 */
class DocumentsBoundsTraitTest extends TestCase
{
    private function host() :DocumentsBoundsHost
    {
        return new DocumentsBoundsHost() ;
    }

    /**
     * @return void
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
     */
    public function testObjectResultIsFlattenedToAnArray() :void
    {
        $host = $this->host() ;
        $host->canned = (object) [ 'width' => (object) [ 'min' => 1 , 'max' => 9 ] ] ;

        $result = $host->bounds( [ Arango::BOUNDS => 'width' ] ) ;

        $this->assertArrayHasKey( 'width' , $result ) ;
        $this->assertEquals( (object) [ 'min' => 1 , 'max' => 9 ] , $result[ 'width' ] ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testArrayResultIsReturnedAsIs() :void
    {
        $host = $this->host() ;
        $host->canned = [ 'width' => [ 'min' => 1 , 'max' => 9 ] ] ;

        $this->assertSame( [ 'width' => [ 'min' => 1 , 'max' => 9 ] ] , $host->bounds( [ Arango::BOUNDS => 'width' ] ) ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullResultYieldsEmptyArray() :void
    {
        $host = $this->host() ;
        $host->canned = null ; // query non-empty (width requested), but DB returns nothing

        $this->assertSame( [] , $host->bounds( [ Arango::BOUNDS => 'width' ] ) ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testEmptyWhenNothingBoundable() :void
    {
        $host = $this->host() ;
        $host->canned = (object) [ 'depth' => (object) [ 'min' => 0 , 'max' => 0 ] ] ;

        // 'depth' is not whitelisted → the query is empty and the DB is never hit.
        $this->assertSame( [] , $host->bounds( [ Arango::BOUNDS => 'depth' ] ) ) ;
    }
}
