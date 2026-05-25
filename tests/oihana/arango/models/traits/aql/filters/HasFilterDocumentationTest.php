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
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Tests for HasFilterDocumentation trait.
 */
class HasFilterDocumentationTest extends TestCase
{
    private Container $container;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
    }

    // ========================================
    // BASIC FILTER PATHS
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithSimpleFilters(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'   => FilterType::STRING ,
                'age'    => FilterType::NUMBER ,
                'active' => FilterType::BOOL ,
            ]
        ]);

        $paths = $model->documentFilterPaths() ;

        $this->assertIsArray( $paths ) ;
        $this->assertCount( 3 , $paths ) ;

        // Check that paths are present
        $pathNames = array_column( $paths , 'path' ) ;
        $this->assertContains( 'name'   , $pathNames ) ;
        $this->assertContains( 'age'    , $pathNames ) ;
        $this->assertContains( 'active' , $pathNames ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsIncludesTypes(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'    => FilterType::STRING ,
                'age'     => FilterType::NUMBER ,
                'created' => FilterType::DATE ,
            ]
        ]);

        $paths = $model->documentFilterPaths( includeTypes: true ) ;

        foreach( $paths as $path )
        {
            $this->assertArrayHasKey( 'path' , $path ) ;
            $this->assertArrayHasKey( 'type' , $path ) ;
            $this->assertArrayHasKey( 'leaf' , $path ) ;
        }

        // Verify types match
        $pathByName = array_column( $paths , null , 'path' ) ;

        $this->assertSame( FilterType::STRING , $pathByName['name']['type']    ) ;
        $this->assertSame( FilterType::NUMBER , $pathByName['age']['type']     ) ;
        $this->assertSame( FilterType::DATE   , $pathByName['created']['type'] ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithoutTypes(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name' => FilterType::STRING ,
                'age'  => FilterType::NUMBER ,
            ]
        ]);

        $paths = $model->documentFilterPaths( includeTypes: false ) ;

        foreach( $paths as $path )
        {
            $this->assertArrayHasKey( 'path' , $path ) ;
            $this->assertArrayNotHasKey( 'type' , $path ) ;
            $this->assertArrayNotHasKey( 'leaf' , $path ) ;
        }
    }

    // ========================================
    // NESTED DOCUMENT FILTERS
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithNestedFilters(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'    => FilterType::STRING ,
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'street'     => FilterType::STRING ,
                        'city'       => FilterType::STRING ,
                        'postalCode' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $paths = $model->documentFilterPaths() ;

        $pathNames = array_column( $paths , 'path' ) ;

        $this->assertContains( 'name'               , $pathNames ) ;
        $this->assertContains( 'address'            , $pathNames ) ;
        $this->assertContains( 'address.street'     , $pathNames ) ;
        $this->assertContains( 'address.city'       , $pathNames ) ;
        $this->assertContains( 'address.postalCode' , $pathNames ) ;
    }

    // ========================================
    // ARRAY EXPANSION FILTERS
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithArrayExpansion(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'         => FilterType::STRING ,
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'email'     => FilterType::STRING ,
                        'telephone' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $paths = $model->documentFilterPaths() ;

        $pathNames = array_column( $paths , 'path' ) ;

        $this->assertContains( 'name'                      , $pathNames ) ;
        $this->assertContains( 'contactPoint[*]'           , $pathNames ) ;
        $this->assertContains( 'contactPoint.email'        , $pathNames ) ;
        $this->assertContains( 'contactPoint.telephone'    , $pathNames ) ;
    }

    // ========================================
    // DEEPLY NESTED FILTERS
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithDeeplyNestedFilters(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'employee' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'givenName'  => FilterType::STRING ,
                        'familyName' => FilterType::STRING ,
                        'workLocation' =>
                        [
                            AQL::TYPE    => Filter::DOCUMENT ,
                            AQL::FILTERS =>
                            [
                                'name' => FilterType::STRING ,
                                'address' =>
                                [
                                    AQL::TYPE    => Filter::DOCUMENT ,
                                    AQL::FILTERS =>
                                    [
                                        'city' => FilterType::STRING ,
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $paths = $model->documentFilterPaths() ;

        $pathNames = array_column( $paths , 'path' ) ;

        $this->assertContains( 'employee[*]'                          , $pathNames ) ;
        $this->assertContains( 'employee.givenName'                   , $pathNames ) ;
        $this->assertContains( 'employee.familyName'                  , $pathNames ) ;
        $this->assertContains( 'employee.workLocation'                , $pathNames ) ;
        $this->assertContains( 'employee.workLocation.name'           , $pathNames ) ;
        $this->assertContains( 'employee.workLocation.address'        , $pathNames ) ;
        $this->assertContains( 'employee.workLocation.address.city'   , $pathNames ) ;
    }

    // ========================================
    // LEAF FLAG
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsLeafFlag(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'    => FilterType::STRING ,
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'city' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $paths = $model->documentFilterPaths( includeTypes: true ) ;

        $pathByName = array_column( $paths , null , 'path' ) ;

        // Leaf nodes (simple types) should have leaf = true
        $this->assertTrue( $pathByName['name']['leaf'] ) ;
        $this->assertTrue( $pathByName['address.city']['leaf'] ) ;

        // Branch nodes (with nested filters) should have leaf = false
        $this->assertFalse( $pathByName['address']['leaf'] ) ;
    }

    // ========================================
    // EMPTY FILTERS
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithNoFilters(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => []
        ]);

        $paths = $model->documentFilterPaths() ;

        $this->assertIsArray( $paths ) ;
        $this->assertEmpty( $paths ) ;
    }

    // ========================================
    // ALL FILTER TYPES
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDocumentFilterPathsWithAllFilterTypes(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'stringField' => FilterType::STRING ,
                'numberField' => FilterType::NUMBER ,
                'boolField'   => FilterType::BOOL   ,
                'dateField'   => FilterType::DATE   ,
                'arrayField'  => FilterType::ARRAY  ,
            ]
        ]);

        $paths = $model->documentFilterPaths( includeTypes: true ) ;

        $pathByName = array_column( $paths , null , 'path' ) ;

        $this->assertSame( FilterType::STRING , $pathByName['stringField']['type'] ) ;
        $this->assertSame( FilterType::NUMBER , $pathByName['numberField']['type'] ) ;
        $this->assertSame( FilterType::BOOL   , $pathByName['boolField']['type']   ) ;
        $this->assertSame( FilterType::DATE   , $pathByName['dateField']['type']   ) ;
        $this->assertSame( FilterType::ARRAY  , $pathByName['arrayField']['type']  ) ;
    }
}
