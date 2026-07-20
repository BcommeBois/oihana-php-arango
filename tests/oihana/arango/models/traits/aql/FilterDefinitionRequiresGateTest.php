<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\enums\Boolean;
use ReflectionException;

/**
 * Permission gate on `?filter=` (Gate B): a `Field::REQUIRES` declared on the
 * FILTER definition itself gates the filter independently of the projection —
 * symmetric with `FacetTrait::isAuthorized( $facet )`. A refused definition
 * neutralises the predicate to `false` (never dropped, so it composes safely in
 * and/or/not). A definition without `Field::REQUIRES`, or a plain string /
 * callable definition, is left untouched.
 */
class FilterDefinitionRequiresGateTest extends TestCase
{
    private Container $container;
    private array $binds;

    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
        $this->binds = [] ;
    }

    private function filter( array $node , ?callable $authorizer = null ): array
    {
        $init = [ AQL::FILTER => $node ] ;
        if ( $authorizer !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
        return $init ;
    }

    /**
     * The filter definition of `items` carries a `Field::REQUIRES`, while the
     * projection does NOT gate it — so only Gate B can lock this filter.
     *
     * @return Documents
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ValidationException
     */
    private function model(): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'organizations' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'items' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , Field::REQUIRES => 'items:filter' , AQL::FILTERS => [ 'ref' => FilterType::STRING ] ] ,
                'name'  => FilterType::STRING ,
            ],
        ]);
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ValidationException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ConstantException
     */
    public function testRefusedDefinitionFilterIsNeutralisedToFalse(): void
    {
        // The authorizer refuses `items:filter` → the clause compiles to `false`,
        // no membership leak, and no value bound (the predicate never built).
        $init   = $this->filter( [ 'key' => 'items[*].ref' , 'op' => 'eq' , 'val' => 'X' ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 'X' , $this->binds ) ;
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ValidationException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ConstantException
     */
    public function testGrantedDefinitionFilterExpands(): void
    {
        $init   = $this->filter( [ 'key' => 'items[*].ref' , 'op' => 'eq' , 'val' => 'X' ] , fn( string $s ) => $s === 'items:filter' ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'CURRENT.ref' , $result ) ;
        $this->assertContains( 'X' , $this->binds ) ;
    }

    /**
     * @return void
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
    public function testDefinitionFilterFailsOpenWithoutAuthorizer(): void
    {
        // No authorizer injected → fail-open (field-level semantics), Gate B is inert.
        $result = $this->model()->prepareFilter( $this->filter( [ 'key' => 'items[*].ref' , 'op' => 'eq' , 'val' => 'X' ] ) , $this->binds ) ;

        $this->assertStringContainsString( 'CURRENT.ref' , $result ) ;
        $this->assertContains( 'X' , $this->binds ) ;
    }

    /**
     * @return void
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
    public function testStringDefinitionIsIgnoredByGateB(): void
    {
        // `name` is a plain FilterType::STRING (no array, no Field::REQUIRES) — Gate B
        // does nothing, the filter applies even under a denying authorizer.
        $init   = $this->filter( [ 'key' => 'name' , 'val' => 'Bob' ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertContains( 'Bob' , $this->binds ) ;
    }

    /**
     * @return void
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
    public function testDefinitionWithoutRequiresIsUnaffected(): void
    {
        // Same array shape, but no Field::REQUIRES on the definition → Gate B is a
        // no-op even under a denying authorizer.
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'organizations' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'items' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => [ 'ref' => FilterType::STRING ] ] ] ,
        ]);

        $init   = $this->filter( [ 'key' => 'items[*].ref' , 'op' => 'eq' , 'val' => 'X' ] , fn() => false ) ;
        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'CURRENT.ref' , $result ) ;
        $this->assertContains( 'X' , $this->binds ) ;
    }

    // ---------------------------------------------------------------- Composition in and/or/not

    /**
     * @return void
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
    public function testRefusedDefinitionFilterEmptiesAnAndBranch(): void
    {
        $init   = $this->filter( [ 'and' , [ 'key' => 'items[*].ref' , 'op' => 'eq' , 'val' => 'X' ] , [ 'key' => 'name' , 'val' => 'Bob' ] ] , fn( string $s ) => ! ( $s === 'items:filter' ) ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( Boolean::FALSE , $result ) ;
        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertNotContains( 'X' , $this->binds ) ;
    }
}
