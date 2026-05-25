<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
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
 * Tests for HasFilterBoolean trait.
 */
class HasFilterBooleanTest extends TestCase
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
                'active'    => FilterType::BOOL ,
                'verified'  => FilterType::BOOL ,
                'published' => FilterType::BOOL ,
                'archived'  => FilterType::BOOL ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // BASIC BOOLEAN FILTERS
    // ========================================

    public function testBooleanFilterTrue(): void
    {
        $init = [ 'key' => 'active' , 'val' => true ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
        $this->assertContains( true , $this->binds , '' , false , false ) ;
    }

    public function testBooleanFilterFalse(): void
    {
        $init = [ 'key' => 'active' , 'val' => false ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertContains( false , $this->binds , '' ) ;
    }

    // ========================================
    // OPERATORS
    // ========================================

    public function testBooleanFilterNotEquals(): void
    {
        $init = [ 'key' => 'active' , 'val' => true , 'op' => 'ne' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '!=' , $result ) ;
    }

    public function testBooleanFilterEqualsExplicit(): void
    {
        $init = [ 'key' => 'active' , 'val' => true , 'op' => 'eq' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '==' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    public function testBooleanFilterWithCustomDocRef(): void
    {
        $init = [ 'key' => 'active' , 'val' => true ] ;

        $result = $this->model->prepareFilter( $init , $this->binds , 'v1' ) ;

        $this->assertStringContainsString( 'v1.active' , $result ) ;
        $this->assertStringNotContainsString( 'doc.active' , $result ) ;
    }

    // ========================================
    // MULTIPLE BOOLEAN FILTERS
    // ========================================

    public function testMultipleBooleanFiltersAnd(): void
    {
        $init =
        [
            'and' ,
            [ 'key' => 'active'   , 'val' => true  ] ,
            [ 'key' => 'verified' , 'val' => true  ] ,
            [ 'key' => 'archived' , 'val' => false ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertStringContainsString( 'doc.verified' , $result ) ;
        $this->assertStringContainsString( 'doc.archived' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertCount( 3 , $this->binds ) ;
    }

    public function testMultipleBooleanFiltersOr(): void
    {
        $init =
        [
            'or' ,
            [ 'key' => 'active'    , 'val' => true ] ,
            [ 'key' => 'published' , 'val' => true ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '||' , $result ) ;
    }

    // ========================================
    // NOT OPERATOR
    // ========================================

    public function testBooleanFilterNot(): void
    {
        $init =
        [
            'not' ,
            [ 'key' => 'archived' , 'val' => true ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '!' , $result ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function testBooleanFilterWithNullValueTreatedAsFalse(): void
    {
        $init = [ 'key' => 'active' , 'val' => null ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        // null is converted to false
        $this->assertContains( false , $this->binds , '' ) ;
    }

    public function testBooleanFilterWithIntegerOne(): void
    {
        // Integer 1 is not strictly true in PHP
        $init = [ 'key' => 'active' , 'val' => 1 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
    }

    public function testBooleanFilterWithIntegerZero(): void
    {
        // Integer 0 is not strictly false in PHP
        $init = [ 'key' => 'active' , 'val' => 0 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
    }

    public function testBooleanFilterWithStringTrue(): void
    {
        // String "true" is not strictly true
        $init = [ 'key' => 'active' , 'val' => "true" ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        // String "true" is not === true, so it becomes false
        $this->assertContains( false , $this->binds , '' ) ;
    }

    // ========================================
    // COMBINED WITH OTHER TYPES
    // ========================================

    public function testBooleanFilterCombinedWithStringFilter(): void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $model = new Documents( $container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'active' => FilterType::BOOL ,
                'name'   => FilterType::STRING ,
            ]
        ]);

        $init =
        [
            'and' ,
            [ 'key' => 'active' , 'val' => true  ] ,
            [ 'key' => 'name'   , 'val' => 'John' ] ,
        ];

        $binds = [] ;
        $result = $model->prepareFilter( $init , $binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
    }
}
