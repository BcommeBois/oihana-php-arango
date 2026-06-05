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
use RuntimeException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * Tests for HasHierarchicalFilter trait.
 *
 * This trait enables filtering on nested document structures, array expansions,
 * and relationships (edges/joins).
 */
class HasHierarchicalFilterTest extends TestCase
{
    private Container $container;
    private array $binds;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
        $this->binds = [] ;
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
    public function testNestedDocumentFilterSimple(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'    => FilterType::STRING ,
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'email'      => FilterType::STRING ,
                        'street'     => FilterType::STRING ,
                        'city'       => FilterType::STRING ,
                        'postalCode' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'address.email' , 'val' => 'john@doe.com' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.address.email' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
        $this->assertContains( 'john@doe.com' , $this->binds ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testNestedDocumentFilterWithPostalCode(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'postalCode' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'address.postalCode' , 'val' => '75001' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.address.postalCode' , $result ) ;
        $this->assertContains( '75001' , $this->binds ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testNestedDocumentFilterWithOperator(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
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

        $init = [ 'key' => 'address.city' , 'val' => 'Paris%' , 'op' => 'like' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.address.city' , $result ) ;
        $this->assertStringContainsString( 'LIKE' , $result ) ;
    }

    // ========================================
    // DEEPLY NESTED DOCUMENT FILTERS
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testDeeplyNestedDocumentFilter(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'headquarters' =>
                        [
                            AQL::TYPE    => Filter::DOCUMENT ,
                            AQL::FILTERS =>
                            [
                                'address' =>
                                [
                                    AQL::TYPE    => Filter::DOCUMENT ,
                                    AQL::FILTERS =>
                                    [
                                        'country' => FilterType::STRING ,
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'company.headquarters.address.country' , 'val' => 'France' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.company.headquarters.address.country' , $result ) ;
        $this->assertContains( 'France' , $this->binds ) ;
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
    public function testArrayExpansionFilterSimple(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
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

        $init = [ 'key' => 'contactPoint[*].email' , 'val' => 'admin@acme.com' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH' , $result ) ;
        $this->assertStringContainsString( 'contactPoint' , $result ) ;
        $this->assertStringContainsString( 'FILTER' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testArrayExpansionFilterWithLikeOperator(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'telephone' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'contactPoint[*].telephone' , 'val' => '06%' , 'op' => 'like' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH' , $result ) ;
        $this->assertStringContainsString( 'LIKE' , $result ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testArrayExpansionFilterWithNotEquals(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'email' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'contactPoint[*].email' , 'op' => 'ne' , 'val' => null ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH' , $result ) ;
        $this->assertStringContainsString( '!=' , $result ) ;
    }

    // ========================================
    // ARRAY EXPANSION WITH MATCH (COMBINED CONDITIONS)
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testArrayExpansionWithSimpleMatch(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'additionalProperty' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'propertyID' => FilterType::STRING ,
                        'value'      => FilterType::BOOL ,
                    ]
                ]
            ]
        ]);

        // Simple match syntax: all fields use "eq" and combined with AND
        $init =
        [
            'key'   => 'additionalProperty[*]' ,
            'match' =>
            [
                'propertyID' => 'generateReceipt' ,
                'value'      => true ,
            ]
        ];

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH' , $result ) ;
        $this->assertStringContainsString( 'FILTER' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testHierarchicalFilterWithCustomDocRef(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
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

        $init = [ 'key' => 'address.city' , 'val' => 'Paris' ] ;

        $result = $model->prepareFilter( $init , $this->binds , 'vertex' ) ;

        $this->assertStringContainsString( 'vertex.address.city' , $result ) ;
        $this->assertStringNotContainsString( 'doc.address.city' , $result ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testHierarchicalFilterWithInvalidPath(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
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

        // Invalid path - 'country' is not defined
        $init = [ 'key' => 'address.country' , 'val' => 'France' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        // Should return null for invalid paths
        $this->assertNull( $result ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testHierarchicalFilterWithMissingKey(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
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

        // Missing key
        $init = [ 'val' => 'Paris' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertNull( $result ) ;
    }

    // ========================================
    // COMBINED CONDITIONS WITH HIERARCHICAL
    // ========================================

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testCombinedConditionsWithHierarchicalFilters(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'    => FilterType::STRING ,
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'city'       => FilterType::STRING ,
                        'postalCode' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $init =
        [
            'and' ,
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'address.city' , 'val' => 'Paris' ] ,
        ];

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( 'doc.address.city' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
    }

    /**
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundExceptionInterface
     */
    public function testOrConditionsWithHierarchicalFilters(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
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

        $init =
        [
            'or' ,
            [ 'key' => 'address.city' , 'val' => 'Paris' ] ,
            [ 'key' => 'address.city' , 'val' => 'Lyon' ] ,
        ];

        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.address.city' , $result ) ;
        $this->assertStringContainsString( '||' , $result ) ;
        $this->assertContains( 'Paris' , $this->binds ) ;
        $this->assertContains( 'Lyon' , $this->binds ) ;
    }

    // ========================================
    // MIXED FILTER TYPES
    // ========================================

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ConstantException
     */
    public function testMixedFilterTypesInHierarchy(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'profile' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'age'      => FilterType::NUMBER ,
                        'verified' => FilterType::BOOL ,
                        'created'  => FilterType::DATE ,
                    ]
                ]
            ]
        ]);

        // Test NUMBER type in hierarchy
        $init = [ 'key' => 'profile.age' , 'val' => 18 , 'op' => 'ge' ] ;
        $binds = [] ;
        $result = $model->prepareFilter( $init , $binds ) ;

        $this->assertStringContainsString( 'doc.profile.age' , $result ) ;
        $this->assertStringContainsString( '>=' , $result ) ;

        // Test BOOL type in hierarchy
        $init = [ 'key' => 'profile.verified' , 'val' => true ] ;
        $binds = [] ;
        $result = $model->prepareFilter( $init , $binds ) ;

        $this->assertStringContainsString( 'doc.profile.verified' , $result ) ;

        // Test DATE type in hierarchy
        $init = [ 'key' => 'profile.created' , 'val' => '2024-01-01' , 'op' => 'ge' ] ;
        $binds = [] ;
        $result = $model->prepareFilter( $init , $binds ) ;

        $this->assertStringContainsString( 'doc.profile.created' , $result ) ;
    }

    // ========================================
    // CUSTOM CALLABLE LEAF (requires FilterPath mixed $type)
    // ========================================

    /**
     * A closure declared as a nested leaf filter is resolved and invoked with
     * ( $init , &$binds , $docRef ) — previously this crashed in FilterPath
     * because its constructor typed $type as string.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testNestedCustomCallableLeafIsInvoked(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'score' => fn( array $init , array &$binds , string $docRef ) : string
                            => "LOWER($docRef.score) == 'hi'" ,
                    ]
                ]
            ]
        ]);

        $init   = [ 'key' => 'address.score' , 'val' => 'hi' ] ;
        $result = $model->prepareFilter( $init , $this->binds ) ;

        $this->assertSame( "LOWER(doc.address.score) == 'hi'" , $result ) ;
    }

    /**
     * An exception thrown by a custom leaf filter is caught and logged, and the
     * whole hierarchical filter resolves to null (rather than bubbling up).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testNestedLeafExceptionIsCaughtAndReturnsNull(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'boom' => function( array $init , array &$binds , string $docRef ) : string
                        {
                            throw new RuntimeException( 'boom' ) ;
                        } ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'address.boom' , 'val' => 'x' ] ;

        $this->assertNull( $model->prepareFilter( $init , $this->binds ) ) ;
    }
}
