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
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Filter;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\enums\Boolean;

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
     * A DOTTED sub-path after the `[*]` (a nested object leaf, `offers[*].seller.id`
     * where `seller` is an object) builds the correct inline `CURRENT.seller.id`
     * condition — instead of the former `doc.offers[*].seller.id == @v` (an array
     * projection compared to a scalar) that never matched, a silent `0`.
     *
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
    public function testArrayExpansionFilterWithNestedObjectLeaf(): void
    {
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'products' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'offers' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'seller' => FilterType::STRING ,
                    ]
                ]
            ]
        ]);

        $init = [ 'key' => 'offers[*].seller.id' , 'val' => 'org-42' ] ;

        $result = $model->prepareFilter( $init , $this->binds ) ;

        // Correct inline form, not the always-false projection equality.
        $this->assertStringContainsString( 'LENGTH' , $result ) ;
        $this->assertStringContainsString( 'CURRENT.seller.id ==' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
        $this->assertStringNotContainsString( '.seller.id ==' , str_replace( 'CURRENT.seller.id ==' , '' , $result ) ) ;
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
     * A mistyped operator is refused the same way at every depth.
     *
     * The refusal is built for whoever has to fix the URL, and it used to reach them
     * only at the root: in depth the broad catch turned it into a dropped filter, so
     * the very same mistake answered `400` on `title` and the whole collection on
     * `address.city`. Both now refuse.
     *
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
    public function testNestedLeafRefusesAnUnknownOperatorLikeTheRootDoes(): void
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
                    AQL::FILTERS => [ 'city' => FilterType::STRING ] ,
                ]
            ]
        ]);

        $rootRefusal = null ;

        try
        {
            $binds = [] ;
            $model->prepareFilter( [ 'key' => 'name' , 'val' => 'x' , 'op' => 'zzz' ] , $binds ) ;
        }
        catch ( RequestValidationException $exception )
        {
            $rootRefusal = $exception ;
        }

        $this->assertInstanceOf( RequestValidationException::class , $rootRefusal ) ;

        $this->expectException( RequestValidationException::class ) ;

        $model->prepareFilter( [ 'key' => 'address.city' , 'val' => 'x' , 'op' => 'zzz' ] , $this->binds ) ;
    }

    /**
     * The refusal also covers an operator that exists but not for the field at hand:
     * `sw` asks for a number *starting with* 12 and used to answer numbers *equal to*
     * 12 — a plausible page nobody asked for.
     *
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
    public function testNestedLeafRefusesAFunctionFormOperatorOnANumber(): void
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
                    AQL::FILTERS => [ 'floor' => FilterType::NUMBER ] ,
                ]
            ]
        ]);

        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionCode( 400 ) ;

        $model->prepareFilter( [ 'key' => 'address.floor' , 'val' => 12 , 'op' => 'sw' ] , $this->binds ) ;
    }

    /**
     * An exception thrown by a custom leaf filter is caught and logged, and the
     * whole hierarchical filter resolves to null (rather than bubbling up).
     *
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
    // AN OBJECT NAMED LAST — PRESENCE
    // ========================================

    /**
     * The model used by the presence cases: `resolution` is a plain sub-document,
     * `audit` a sub-document one level deeper.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function tickets( array $fields = [] ): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'tickets' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'title'      => FilterType::STRING ,
                'resolution' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'closedAt' => FilterType::DATE ,
                        'audit'    =>
                        [
                            AQL::TYPE    => Filter::DOCUMENT ,
                            AQL::FILTERS => [ 'by' => FilterType::STRING ] ,
                        ] ,
                    ]
                ]
            ] ,
            ...( $fields === [] ? [] : [ AQL::FIELDS => $fields ] ) ,
        ]);
    }

    /**
     * An object named last has no terminal field, so the comparison bears on the
     * location itself — `doc.resolution`, byte for byte what the same key produces
     * when it holds a scalar. AQL reads a missing attribute as `null`, so this is
     * the "no resolution yet" question, which had no writable form at all.
     *
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
    public function testObjectNamedLastComparesTheLocationItself(): void
    {
        $result = $this->tickets()->prepareFilter( [ 'key' => 'resolution' , 'val' => null ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.resolution == @\w+$/' , $result ) ;
        $this->assertContains( null , $this->binds ) ;
    }

    /**
     * The mirror question — "which tickets DO have a resolution" — is the same
     * comparison negated.
     *
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
    public function testObjectNamedLastHonoursNotEquals(): void
    {
        $result = $this->tickets()->prepareFilter( [ 'key' => 'resolution' , 'val' => null , 'op' => 'ne' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.resolution != @\w+$/' , $result ) ;
    }

    /**
     * The same sentence one level down. This is a **second seat**, reached by another
     * road: a dotted key goes through the hierarchical walk, where the object is the
     * last segment, while `resolution` alone never enters that walk at all.
     *
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
    public function testNestedObjectNamedLastComparesTheLocationItself(): void
    {
        $result = $this->tickets()->prepareFilter( [ 'key' => 'resolution.audit' , 'val' => null ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.resolution\.audit == @\w+$/' , $result ) ;
    }

    /**
     * 🚨 A refused object is neutralised to `false`, never dropped to `null`.
     *
     * That distinction is the whole point of the fix: `false` says "you may not",
     * `null` said "I did not understand" and quietly widened the query to the entire
     * collection. Answering the presence question on a locked field would be an
     * oracle, so the gate keeps its say — and it keeps it by refusing, not by
     * forgetting.
     *
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
    public function testRefusedObjectNamedLastIsNeutralisedToFalse(): void
    {
        $model = $this->tickets( [ 'resolution' => [ Field::REQUIRES => 'ticket:resolve' ] ] ) ;

        $result = $model->prepareFilter
        (
            [ Arango::FILTER => [ 'key' => 'resolution' , 'val' => null ] , Arango::AUTHORIZER => fn() => false ] ,
            $this->binds
        ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotSame( null , $result ) ;
    }

    /**
     * The same refusal on the deeper seat, where the gate is the path-aware one.
     *
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
    public function testRefusedNestedObjectNamedLastIsNeutralisedToFalse(): void
    {
        $model = $this->tickets
        ([
            'resolution' =>
            [
                Field::FIELDS => [ 'audit' => [ Field::REQUIRES => 'ticket:audit' ] ] ,
            ]
        ]) ;

        $result = $model->prepareFilter
        (
            [ Arango::FILTER => [ 'key' => 'resolution.audit' , 'val' => null ] , Arango::AUTHORIZER => fn() => false ] ,
            $this->binds
        ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotSame( null , $result ) ;
    }

    /**
     * The fix touches the terminal case only: an object followed by other segments
     * keeps traversing exactly as before, and so does its nested array.
     *
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
    public function testObjectFollowedBySegmentsStillTraverses(): void
    {
        $model = $this->tickets() ;

        $this->assertStringContainsString
        (
            'doc.resolution.closedAt' ,
            $model->prepareFilter( [ 'key' => 'resolution.closedAt' , 'val' => '2026-01-01' ] , $this->binds )
        ) ;

        $binds = [] ;

        $this->assertStringContainsString
        (
            'doc.resolution.audit.by' ,
            $model->prepareFilter( [ 'key' => 'resolution.audit.by' , 'val' => 'someone' ] , $binds )
        ) ;
    }

    /**
     * Routing to the shared comparator brings its refusal along: a form this
     * comparison cannot honour is answered with a `400` rather than quietly
     * mistranslated into equality. Nothing to keep in step — no whitelist of
     * operators is maintained here.
     *
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
    public function testObjectNamedLastRefusesAnOperatorItCannotHonour(): void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionCode( 400 ) ;

        $this->tickets()->prepareFilter( [ 'key' => 'resolution' , 'val' => 'x' , 'op' => 'sw' ] , $this->binds ) ;
    }

    /**
     * `quant` quantifies elements, and an object has none — it is inert here, exactly
     * as it is on a scalar key, rather than fabricating a lopsided clause.
     *
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
    public function testQuantIsInertOnAnObjectNamedLast(): void
    {
        $result = $this->tickets()->prepareFilter
        (
            [ 'key' => 'resolution' , 'val' => null , 'quant' => 'none' ] ,
            $this->binds
        ) ;

        $this->assertMatchesRegularExpression( '/^doc\.resolution == @\w+$/' , $result ) ;
    }

    // ========================================
    // A LIST OF OBJECTS NAMED LAST — CARDINALITY
    // ========================================

    /**
     * The model used by the cardinality cases: `attachments` is a list of objects at
     * the root, `resolution.steps` one nested under an object.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function ticketsWithLists( array $fields = [] ): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'tickets' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'attachments' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ] ,
                'resolution' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'steps' =>
                        [
                            AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                            AQL::FILTERS => [ 'dueAt' => FilterType::STRING ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
            ...( $fields === [] ? [] : [ AQL::FIELDS => $fields ] ) ,
        ]);
    }

    /**
     * A list named last, with nothing after it, counts its elements. With no `quant`
     * the question is « at least one », the same default a relation carries.
     *
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
    public function testListNamedLastIsExistentialByDefault(): void
    {
        $result = $this->ticketsWithLists()->prepareFilter( [ 'key' => 'attachments[*]' ] , $this->binds ) ;

        $this->assertSame( 'LENGTH(doc.attachments[*]) > 0' , $result ) ;
    }

    /**
     * « Which tickets have no attachment at all? » — the question that had no writable
     * form, in the vocabulary the relations already use.
     *
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
    public function testListNamedLastAnswersNone(): void
    {
        $result = $this->ticketsWithLists()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertSame( 'LENGTH(doc.attachments[*]) == 0' , $result ) ;
    }

    /**
     * An integer quantifier means « at least n », as it does on a relation.
     *
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
    public function testListNamedLastAnswersAtLeastN(): void
    {
        $result = $this->ticketsWithLists()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 3 ] , $this->binds ) ;

        $this->assertSame( 'LENGTH(doc.attachments[*]) >= 3' , $result ) ;
    }

    /**
     * 🚨 The count is taken on the EXPANSION, never on the bare attribute.
     *
     * `LENGTH()` of a string is its character count, so a document storing `"oops"`
     * under a list-declared key answers 4 to `LENGTH(doc.attachments)` and would be
     * selected by « at least 3 elements ». Through `[*]` a non-array yields an empty
     * list and counts 0. This pins the shape that makes the difference.
     *
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
    public function testTheCountIsTakenOnTheExpansionNotTheBareAttribute(): void
    {
        $result = $this->ticketsWithLists()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 3 ] , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(doc.attachments[*])' , $result ) ;
        $this->assertStringNotContainsString( 'LENGTH(doc.attachments)' , $result ) ;
    }

    /**
     * The deeper seat, reached by the other road: a dotted key goes through the
     * hierarchical walk, where the list is the last segment.
     *
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
    public function testNestedListNamedLastCountsItsElements(): void
    {
        $model = $this->ticketsWithLists() ;

        $this->assertSame
        (
            'LENGTH(doc.resolution.steps[*]) > 0' ,
            $model->prepareFilter( [ 'key' => 'resolution.steps[*]' ] , $this->binds )
        ) ;

        $binds = [] ;

        $this->assertSame
        (
            'LENGTH(doc.resolution.steps[*]) == 0' ,
            $model->prepareFilter( [ 'key' => 'resolution.steps[*]' , 'quant' => 'none' ] , $binds )
        ) ;
    }

    /**
     * `all` means « every element satisfies the condition », and there is no condition
     * here to satisfy — refused, exactly as it is on a relation named last.
     *
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
    public function testListNamedLastRefusesAllWithoutACondition(): void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionCode( 400 ) ;

        $this->ticketsWithLists()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 'all' ] , $this->binds ) ;
    }

    /**
     * 🚨 A refused list is neutralised to `false`, never dropped to `null` — a count is
     * an answer about a locked field like any other.
     *
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
    public function testRefusedListNamedLastIsNeutralisedToFalse(): void
    {
        $model = $this->ticketsWithLists( [ 'attachments' => [ Field::REQUIRES => 'ticket:files' ] ] ) ;

        $result = $model->prepareFilter
        (
            [ Arango::FILTER => [ 'key' => 'attachments[*]' , 'quant' => 'none' ] , Arango::AUTHORIZER => fn() => false ] ,
            $this->binds
        ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotSame( null , $result ) ;
    }

    /**
     * The fix touches the terminal case only: filtering the elements of the list keeps
     * its existing existential form, at the root and in depth.
     *
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
    public function testFilteringTheElementsOfTheListIsUnchanged(): void
    {
        $model = $this->ticketsWithLists() ;

        $this->assertStringContainsString
        (
            'doc.attachments[* FILTER CURRENT.name' ,
            $model->prepareFilter( [ 'key' => 'attachments[*].name' , 'val' => 'a.pdf' ] , $this->binds )
        ) ;

        $binds = [] ;

        $this->assertStringContainsString
        (
            'doc.resolution.steps[* FILTER CURRENT.dueAt' ,
            $model->prepareFilter( [ 'key' => 'resolution.steps[*].dueAt' , 'val' => '2026-01-01' ] , $binds )
        ) ;
    }

    /**
     * ⚠ The strict notation rule is deliberately left standing: a list named WITHOUT
     * its `[*]` keeps being dropped.
     *
     * It is what catches the caller who means `attachments[*]` and forgets the marker.
     * Giving that spelling a meaning of its own would turn a caught typo into a
     * plausible page answering another question — the failure mode this whole batch
     * exists to close.
     *
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
    public function testAListNamedWithoutItsMarkerIsStillDropped(): void
    {
        $this->assertNull( $this->ticketsWithLists()->prepareFilter( [ 'key' => 'attachments' ] , $this->binds ) ) ;
    }

    /**
     * ⚠ A list carrying a `match` is NOT the cardinality question.
     *
     * `match` is a multi-field test on the ELEMENTS, owned by the array filter — which
     * also gates every sub-field it names. Routing it to the terminal branch would
     * answer a bare count instead: a plausible page for a different question, with the
     * sub-field permission gates dropped along the way. This pins the frontier, which
     * the routing has to step around explicitly.
     *
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
    public function testAListCarryingAMatchIsNotACountingQuestion(): void
    {
        $result = $this->ticketsWithLists()->prepareFilter
        (
            [ 'key' => 'attachments[*]' , 'match' => [ 'name' => 'a.pdf' ] ] ,
            $this->binds
        ) ;

        $this->assertStringContainsString( 'CURRENT.name' , $result ) ;
        $this->assertStringNotContainsString( 'LENGTH(doc.attachments[*])' , $result ) ;
    }

    // ========================================
    // JOIN TRAVERSAL
    // ========================================

    /**
     * A JOIN segment expands to a correlated sub-query (LENGTH(FOR … FILTER
     * <joinKey> == <sourceKey> && <leaf> LIMIT 1 RETURN 1) > 0).
     *
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
        $this->expectExceptionMessageIsOrContains( 'No model for join' ) ;
        $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , $this->binds ) ;
    }

    // ========================================
    // EDGE TRAVERSAL
    // ========================================

    /**
     * An OUTBOUND EDGES segment expands to a graph-traversal existence check.
     *
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
        $this->expectExceptionMessageIsOrContains( 'Invalid edge model' ) ;
        $model->prepareFilter( [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] , $this->binds ) ;
    }

    /**
     * When the inner condition behind the edge cannot be resolved, the edge
     * traversal resolves to null.
     *
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
     * @return void
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
        $this->expectExceptionMessageIsOrContains( 'Cannot resolve collection' ) ;
        $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , $this->binds ) ;
    }

    // ========================================
    // EDGE / JOIN QUANTIFIERS (quant)
    // ========================================

    /**
     * Builds a model whose `members` edge is filterable, used by the quantifier tests.
     *
     * @return Documents
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
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
     * @return Documents
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ValidationException
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
    public function testEdgeAtLeastZeroIsRejected(): void
    {
        $model = $this->edgeQuantifierModel() ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 0 ] , $this->binds ) ;
    }

    /**
     * An unknown `quant` keyword is rejected.
     *
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
    public function testEdgeUnknownQuantifierIsRejected(): void
    {
        $model = $this->edgeQuantifierModel() ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 'most' ] , $this->binds ) ;
    }

    /**
     * `quant:none` on a join → « no joined match » (`== 0`), key condition kept.
     *
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
    public function testJoinAtLeastN(): void
    {
        $model  = $this->joinQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' , 'quant' => 2 ] , $this->binds ) ;

        $this->assertStringContainsString( '._key == doc.company' , $result ) ;
        $this->assertStringContainsString( '>= 2' , $result ) ;
        $this->assertStringNotContainsString( 'LIMIT' , $result ) ;
    }

    /**
     * `quant:all` on an edge → « every linked vertex satisfies the leaf », i.e.
     * no vertex violates it: the leaf is negated and the count must be zero.
     *
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
    public function testEdgeAllNegatesTheLeaf(): void
    {
        $model  = $this->edgeQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'members[*].active' , 'val' => true , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertStringContainsString( '!(' , $result ) ;
        $this->assertStringContainsString( '.active == @' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '== 0' , $result ) ;
    }

    /**
     * `quant:all` without a leaf condition is rejected — there is nothing to satisfy.
     *
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
    public function testEdgeAllWithoutLeafIsRejected(): void
    {
        $model = $this->edgeQuantifierModel() ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareFilter( [ 'key' => 'members[*]' , 'quant' => 'all' ] , $this->binds ) ;
    }

    /**
     * `quant:all` on a join → negated leaf, structural key condition kept positive.
     *
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
    public function testJoinAllNegatesTheLeafKeepingTheKey(): void
    {
        $model  = $this->joinQuantifierModel() ;
        $result = $model->prepareFilter( [ 'key' => 'company.name' , 'val' => 'Acme' , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertStringContainsString( '._key == doc.company' , $result ) ;
        $this->assertStringContainsString( '!(' , $result ) ;
        $this->assertStringContainsString( '.name == @' , $result ) ;
        $this->assertStringContainsString( 'LIMIT 1' , $result ) ;
        $this->assertStringContainsString( '== 0' , $result ) ;
    }

    /**
     * `quant:all` without a leaf condition is rejected on a join too.
     *
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
    public function testJoinAllWithoutLeafIsRejected(): void
    {
        $model = $this->joinQuantifierModel() ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareFilter( [ 'key' => 'company' , 'quant' => 'all' ] , $this->binds ) ;
    }
}
