<?php

namespace tests\oihana\arango\models\traits\aql\filters;

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
use ReflectionException;
use RuntimeException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

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

    /**
     * A nested leaf whose configured type is neither a known FilterType nor a
     * resolvable callable produces no handler: a warning is logged and the
     * filter resolves to null.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testNestedUnknownLeafTypeReturnsNull(): void
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
                        'weird' => 'totallyUnknownType' ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'address.weird' , 'val' => 'x' ] ;

        $this->assertNull( $model->prepareFilter( $init , $this->binds ) ) ;
    }

    // ========================================
    // JOIN TRAVERSAL
    // ========================================

    /**
     * A JOIN segment expands to a correlated sub-query (LENGTH(FOR … FILTER
     * <joinKey> == <sourceKey> && <leaf> LIMIT 1 RETURN 1) > 0).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testJoinTraversalSingleLevel(): void
    {
        $company = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'name' => FilterType::STRING ] ,
        ]);
        $this->container->set( 'CompanyModel' , $company ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::JOIN ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::JOINS =>
            [
                'company' => [ AQL::MODEL => 'CompanyModel' , AQL::KEY => '_key' ] ,
            ],
        ]);

        $result = $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertStringContainsString( '._key == doc.company' , $result ) ;
        $this->assertStringContainsString( '.name == @' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( 'RETURN 1' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
        $this->assertContains( 'companies' , $this->binds ) ;
        $this->assertContains( 'Acme' , $this->binds ) ;
    }

    /**
     * When the remaining path cannot resolve a leaf condition inside the join,
     * the whole join traversal resolves to null.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testJoinTraversalReturnsNullWhenInnerConditionUnresolved(): void
    {
        $company = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'name' => FilterType::STRING ] ,
        ]);
        $this->container->set( 'CompanyModel' , $company ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::JOIN ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::JOINS =>
            [
                'company' => [ AQL::MODEL => 'CompanyModel' , AQL::KEY => '_key' ] ,
            ],
        ]);

        // 'unknownField' is not declared in the join's nested filters
        $result = $model->prepareFilter( [ 'key' => 'company.unknownField' , 'val' => 'x' ] , $this->binds ) ;

        $this->assertNull( $result ) ;
    }

    /**
     * A JOIN whose configuration carries no model throws a RuntimeException.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testJoinTraversalThrowsWhenNoModelConfigured(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::JOIN ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::JOINS =>
            [
                'company' => [ AQL::KEY => '_key' ] , // no AQL::MODEL
            ],
        ]);

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'No model for join' ) ;
        $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , $this->binds ) ;
    }

    // ========================================
    // EDGE TRAVERSAL
    // ========================================

    /**
     * An OUTBOUND EDGES segment expands to a graph-traversal existence check.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeTraversalOutbound(): void
    {
        $this->container->set( 'EmployeeEdge' , new MockEdges( 'employee_edges' ) ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'employee' =>
                [
                    AQL::TYPE    => Filter::EDGES ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::EDGES =>
            [
                'employee' => [ AQL::MODEL => 'EmployeeEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ,
            ],
        ]);

        $result = $model->prepareFilter( [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( '.name == @' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
        $this->assertContains( 'employee_edges' , $this->binds ) ;
        $this->assertContains( 'Bob' , $this->binds ) ;
    }

    /**
     * The traversal direction follows the edge configuration (INBOUND).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeTraversalInbound(): void
    {
        $this->container->set( 'EmployeeEdge' , new MockEdges( 'employee_edges' ) ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'employee' =>
                [
                    AQL::TYPE    => Filter::EDGES ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::EDGES =>
            [
                'employee' => [ AQL::MODEL => 'EmployeeEdge' , AQL::DIRECTION => Traversal::INBOUND ] ,
            ],
        ]);

        $result = $model->prepareFilter( [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] , $this->binds ) ;

        $this->assertStringContainsString( 'INBOUND doc' , $result ) ;
    }

    /**
     * An edge model reference that does not resolve to an Edges instance throws
     * a RuntimeException.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeTraversalThrowsOnInvalidEdgeModel(): void
    {
        // Wire a plain Documents under the edge's model id (not an Edges)
        $this->container->set( 'NotAnEdge' , new Documents( $this->container ,
        [
            AQL::COLLECTION => 'whatever' ,
            AQL::LAZY       => false ,
        ]) );

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'employee' =>
                [
                    AQL::TYPE    => Filter::EDGES ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::EDGES =>
            [
                'employee' => [ AQL::MODEL => 'NotAnEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ,
            ],
        ]);

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'Invalid edge model' ) ;
        $model->prepareFilter( [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] , $this->binds ) ;
    }

    /**
     * When the inner condition behind the edge cannot be resolved, the edge
     * traversal resolves to null.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeTraversalReturnsNullWhenInnerConditionUnresolved(): void
    {
        $this->container->set( 'EmployeeEdge' , new MockEdges( 'employee_edges' ) ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'employee' =>
                [
                    AQL::TYPE    => Filter::EDGES ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::EDGES =>
            [
                'employee' => [ AQL::MODEL => 'EmployeeEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ,
            ],
        ]);

        // 'unknownField' is not declared in the edge's nested filters
        $result = $model->prepareFilter( [ 'key' => 'employee[*].unknownField' , 'val' => 'x' ] , $this->binds ) ;

        $this->assertNull( $result ) ;
    }

    /**
     * prepareHierarchicalFilter() returns null when the init carries no key.
     * The public prepareFilter() short-circuits a missing key before dispatch,
     * so this guard is reached only by calling the method directly — done here
     * through a thin subclass that re-exposes it.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testPrepareHierarchicalFilterReturnsNullWhenKeyMissing(): void
    {
        $model = new class( $this->container , [ AQL::COLLECTION => 'customers' , AQL::LAZY => false ] ) extends Documents
        {
            public function callPrepareHierarchical( array $init , ?array &$binds = null ) :?string
            {
                return $this->prepareHierarchicalFilter( $init , $binds ) ;
            }
        };

        $binds = [] ;
        $this->assertNull( $model->callPrepareHierarchical( [] , $binds ) ) ;
    }

    /**
     * A join whose model resolves to a Documents without a collection cannot be
     * traversed: the join traversal throws "Cannot resolve collection".
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testJoinTraversalThrowsWhenModelHasNoCollection(): void
    {
        // A model whose collection is empty (no AQL::COLLECTION supplied).
        $company = new Documents( $this->container ,
        [
            AQL::LAZY    => false ,
            AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
        ]);
        $this->container->set( 'CollectionlessModel' , $company ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::JOIN ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::JOINS =>
            [
                'company' => [ AQL::MODEL => 'CollectionlessModel' , AQL::KEY => '_key' ] ,
            ],
        ]);

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'Cannot resolve collection' ) ;
        $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , $this->binds ) ;
    }

    // ========================================
    // EDGE / JOIN QUANTIFIERS (quant)
    // ========================================

    /**
     * Builds a model whose `members` edge is filterable, used by the quantifier tests.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function edgeQuantifierModel(): Documents
    {
        $this->container->set( 'MemberEdge' , new MockEdges( 'member_edges' ) ) ;

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'organizations' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'members' =>
                [
                    AQL::TYPE    => Filter::EDGES ,
                    AQL::FILTERS => [ 'active' => FilterType::BOOL ] ,
                ]
            ],
            AQL::EDGES =>
            [
                'members' => [ AQL::MODEL => 'MemberEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ,
            ],
        ]);
    }

    /**
     * Builds a model whose `company` join is filterable, used by the quantifier tests.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function joinQuantifierModel(): Documents
    {
        $company = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'name' => FilterType::STRING ] ,
        ]);
        $this->container->set( 'CompanyModel' , $company ) ;

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' =>
                [
                    AQL::TYPE    => Filter::JOIN ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ]
            ],
            AQL::JOINS =>
            [
                'company' => [ AQL::MODEL => 'CompanyModel' , AQL::KEY => '_key' ] ,
            ],
        ]);
    }

    /**
     * Backward-compatibility: no `quant` keeps the historical existence form
     * (`LENGTH(...) > 0` with a `LIMIT 1` short-circuit).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeDefaultQuantifierIsUnchanged(): void
    {
        $model  = $this->edgeQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true ] , $this->binds ) ;

        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
        $this->assertStringNotContainsString( '== 0' , $result ) ;
    }

    /**
     * `quant:none` with a leaf condition → « no linked match » (`== 0`, LIMIT 1).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeNoneWithLeaf(): void
    {
        $model  = $this->edgeQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( '.active == @' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '== 0' , $result ) ;
        $this->assertStringNotContainsString( '> 0' , $result ) ;
    }

    /**
     * `quant:none` without a leaf → pure absence (no FILTER on the vertex).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeNonePureAbsence(): void
    {
        $model  = $this->edgeQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'members[*]' , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '== 0' , $result ) ;
        $this->assertStringNotContainsString( 'FILTER' , $result ) ;
    }

    /**
     * `members[*]` without `quant` → pure existence (`> 0`), previously dropped.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgePureExistence(): void
    {
        $model  = $this->edgeQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'members[*]' ] , $this->binds ) ;

        $this->assertNotNull( $result ) ;
        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
        $this->assertStringNotContainsString( 'FILTER' , $result ) ;
    }

    /**
     * Integer `quant` → « at least n » (`>= n` inlined, no LIMIT).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeAtLeastN(): void
    {
        $model  = $this->edgeQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 3 ] , $this->binds ) ;

        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( '.active == @' , $result ) ;
        $this->assertStringContainsString( '>= 3' , $result ) ;
        $this->assertStringNotContainsString( 'LIMIT' , $result ) ;
    }

    /**
     * `quant:0` (or negative) is rejected — « at least 0 » is meaningless.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeAtLeastZeroIsRejected(): void
    {
        $model = $this->edgeQuantifierModel() ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 0 ] , $this->binds ) ;
    }

    /**
     * An unknown `quant` keyword is rejected.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testEdgeUnknownQuantifierIsRejected(): void
    {
        $model = $this->edgeQuantifierModel() ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 'most' ] , $this->binds ) ;
    }

    /**
     * `quant:none` on a join → « no joined match » (`== 0`), key condition kept.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testJoinNoneWithLeaf(): void
    {
        $model  = $this->joinQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertStringContainsString( '._key == doc.company' , $result ) ;
        $this->assertStringContainsString( '.name == @' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '== 0' , $result ) ;
    }

    /**
     * Integer `quant` on a join → « at least n » (`>= n`, no LIMIT).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function testJoinAtLeastN(): void
    {
        $model  = $this->joinQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' , 'quant' => 2 ] , $this->binds ) ;

        $this->assertStringContainsString( '._key == doc.company' , $result ) ;
        $this->assertStringContainsString( '>= 2' , $result ) ;
        $this->assertStringNotContainsString( 'LIMIT' , $result ) ;
    }
}
