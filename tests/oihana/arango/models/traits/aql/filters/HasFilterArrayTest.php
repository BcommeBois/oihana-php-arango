<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Tests for HasFilterArray trait.
 */
class HasFilterArrayTest extends TestCase
{
    private Documents $model;
    private array $binds;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function setUp(): void
    {
        $container = new Container() ;

        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->model = new Documents( $container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'tags'    => FilterType::ARRAY ,
                'values'  => FilterType::ARRAY ,
                'numbers' => FilterType::ARRAY ,
                'items'   => FilterType::ARRAY ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // BASIC ARRAY FILTERS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterEquals(): void
    {
        $init = [ 'key' => 'tags' , 'val' => [ 'php' , 'mysql' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.tags' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
    }

    // ========================================
    // ARRAY INDEX ACCESS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithAtIndex(): void
    {
        $init = [ 'key' => 'tags' , 'at' => 0 , 'val' => 'first' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.tags[0]' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithAtIndexAndFunction(): void
    {
        $init = [ 'key' => 'tags' , 'at' => 0 , 'val' => 'FIRST' , 'alt' => 'upper' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'UPPER(doc.tags[0])' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithMultipleIndices(): void
    {
        $init = [ 'key' => 'tags' , 'at' => 2 , 'val' => 'third' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.tags[2]' , $result ) ;
    }

    // ========================================
    // AGGREGATE FUNCTIONS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithCount(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 5 , 'alt' => 'count' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'COUNT(doc.tags)' , $result ) ;
        $this->assertStringContainsString( '>=' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithLength(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 3 , 'alt' => 'length' , 'op' => 'gt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(doc.tags)' , $result ) ;
        $this->assertStringContainsString( '>' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithAverage(): void
    {
        $init = [ 'key' => 'values' , 'val' => 50 , 'alt' => 'avg' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'AVERAGE(doc.values)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithSum(): void
    {
        $init = [ 'key' => 'values' , 'val' => 100 , 'alt' => 'sum' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'SUM(doc.values)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithMin(): void
    {
        $init = [ 'key' => 'values' , 'val' => 0 , 'alt' => 'min' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'MIN(doc.values)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithMax(): void
    {
        $init = [ 'key' => 'values' , 'val' => 100 , 'alt' => 'max' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'MAX(doc.values)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithMedian(): void
    {
        $init = [ 'key' => 'values' , 'val' => 50 , 'alt' => 'median' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'MEDIAN(doc.values)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithProduct(): void
    {
        $init = [ 'key' => 'values' , 'val' => 24 , 'alt' => 'product' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'PRODUCT(doc.values)' , $result ) ;
    }

    // ========================================
    // ARRAY ACCESS FUNCTIONS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithFirst(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 'php' , 'alt' => 'first' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'FIRST(doc.tags)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithLast(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 'mysql' , 'alt' => 'last' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LAST(doc.tags)' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithNth(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 'middle' , 'alt' => [ 'nth' , 2 ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/NTH\(doc\.tags,\s*2\)/' , $result ) ;
    }

    // ========================================
    // ALL COMPARATOR
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAllEquals(): void
    {
        $init = [ 'key' => 'values' , 'op' => 'all.eq' , 'val' => 5 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.values' , $result ) ;
        $this->assertStringContainsString( 'ALL' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAllNotEquals(): void
    {
        $init = [ 'key' => 'values' , 'op' => 'all.ne' , 'val' => 0 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ALL' , $result ) ;
        $this->assertStringContainsString( '!=' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAllGreaterThan(): void
    {
        $init = [ 'key' => 'values' , 'op' => 'all.gt' , 'val' => 0 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ALL' , $result ) ;
        $this->assertStringContainsString( '>' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAllIn(): void
    {
        $init = [ 'key' => 'tags' , 'op' => 'all.in' , 'val' => [ 'php' , 'mysql' , 'redis' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ALL' , $result ) ;
        $this->assertStringContainsString( 'IN' , $result ) ;
    }

    // ========================================
    // ANY COMPARATOR
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAnyEquals(): void
    {
        $init = [ 'key' => 'tags' , 'op' => 'any.eq' , 'val' => 'php' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ANY' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAnyGreaterThan(): void
    {
        $init = [ 'key' => 'values' , 'op' => 'any.gt' , 'val' => 100 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ANY' , $result ) ;
        $this->assertStringContainsString( '>' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterAnyIn(): void
    {
        $init = [ 'key' => 'tags' , 'op' => 'any.in' , 'val' => [ 'php' , 'python' , 'go' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ANY' , $result ) ;
        $this->assertStringContainsString( 'IN' , $result ) ;
    }

    // ========================================
    // NONE COMPARATOR
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterNoneEquals(): void
    {
        $init = [ 'key' => 'tags' , 'op' => 'none.eq' , 'val' => 'deprecated' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'NONE' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterNoneIn(): void
    {
        $init = [ 'key' => 'tags' , 'op' => 'none.in' , 'val' => [ 'banned' , 'deprecated' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'NONE' , $result ) ;
        $this->assertStringContainsString( 'IN' , $result ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterNoneLessThan(): void
    {
        $init = [ 'key' => 'values' , 'op' => 'none.lt' , 'val' => 0 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'NONE' , $result ) ;
        $this->assertStringContainsString( '<' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithCustomDocRef(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 3 , 'alt' => 'length' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds , 'vertex' ) ;

        $this->assertStringContainsString( 'LENGTH(vertex.tags)' , $result ) ;
        $this->assertStringNotContainsString( 'doc.tags' , $result ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithEmptyArray(): void
    {
        $init = [ 'key' => 'tags' , 'val' => 0 , 'alt' => 'length' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(doc.tags)' , $result ) ;
        $this->assertContains( 0 , $this->binds ) ;
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterWithNullValue(): void
    {
        $init = [ 'key' => 'tags' , 'val' => null ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.tags' , $result ) ;
    }

    // ========================================
    // COMBINED FILTERS
    // ========================================

    /**
     * @throws UnsupportedOperationException
     * @throws ConstantException
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     */
    public function testArrayFilterCombinedConditions(): void
    {
        $init =
        [
            'and' ,
            [ 'key' => 'tags'   , 'val' => 1 , 'alt' => 'length' , 'op' => 'ge' ] ,
            [ 'key' => 'values' , 'val' => 0 , 'alt' => 'min'    , 'op' => 'ge' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(doc.tags)' , $result ) ;
        $this->assertStringContainsString( 'MIN(doc.values)' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
    }
}
