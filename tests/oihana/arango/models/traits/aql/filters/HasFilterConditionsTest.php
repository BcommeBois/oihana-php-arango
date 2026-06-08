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
 * Tests for HasFilterConditions trait.
 */
class HasFilterConditionsTest extends TestCase
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
                'name'     => FilterType::STRING ,
                'age'      => FilterType::NUMBER ,
                'email'    => FilterType::STRING ,
                'active'   => FilterType::BOOL   ,
                'score'    => FilterType::NUMBER ,
                'status'   => FilterType::STRING ,
                'category' => FilterType::STRING ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // AND CONDITIONS (IMPLICIT)
    // ========================================

    public function testImplicitAndConditions(): void
    {
        $init =
        [
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'age'  , 'val' => 25 , 'op' => 'ge' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( 'doc.age' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertCount( 2 , $this->binds ) ;
    }

    public function testImplicitAndWithThreeConditions(): void
    {
        $init =
        [
            [ 'key' => 'name'   , 'val' => 'John' ] ,
            [ 'key' => 'age'    , 'val' => 18 , 'op' => 'ge' ] ,
            [ 'key' => 'active' , 'val' => true ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( 'doc.age' , $result ) ;
        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertCount( 3 , $this->binds ) ;
    }

    // ========================================
    // AND CONDITIONS (EXPLICIT)
    // ========================================

    public function testExplicitAndConditions(): void
    {
        $init =
        [
            'and' ,
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'age'  , 'val' => 25 , 'op' => 'ge' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '&&' , $result ) ;
    }

    // ========================================
    // OR CONDITIONS
    // ========================================

    public function testOrConditions(): void
    {
        $init =
        [
            'or' ,
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'name' , 'val' => 'Jane' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '||' , $result ) ;
        $this->assertContains( 'John' , $this->binds ) ;
        $this->assertContains( 'Jane' , $this->binds ) ;
    }

    public function testOrWithThreeConditions(): void
    {
        $init =
        [
            'or' ,
            [ 'key' => 'status' , 'val' => 'active' ] ,
            [ 'key' => 'status' , 'val' => 'pending' ] ,
            [ 'key' => 'status' , 'val' => 'review' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '||' , $result ) ;
        $this->assertCount( 3 , $this->binds ) ;
    }

    // ========================================
    // NOT CONDITIONS
    // ========================================

    public function testNotCondition(): void
    {
        $init =
        [
            'not' ,
            [ 'key' => 'active' , 'val' => true ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '!' , $result ) ;
        $this->assertStringContainsString( 'doc.active' , $result ) ;
    }

    public function testNotConditionWithComplexFilter(): void
    {
        $init =
        [
            'not' ,
            [ 'key' => 'status' , 'val' => 'deleted' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '!' , $result ) ;
    }

    // ========================================
    // NESTED CONDITIONS
    // ========================================

    public function testNestedAndInOr(): void
    {
        $init =
        [
            'or' ,
            [
                'and' ,
                [ 'key' => 'name' , 'val' => 'John' ] ,
                [ 'key' => 'age'  , 'val' => 18 , 'op' => 'ge' ] ,
            ] ,
            [
                'and' ,
                [ 'key' => 'name' , 'val' => 'Jane' ] ,
                [ 'key' => 'age'  , 'val' => 21 , 'op' => 'ge' ] ,
            ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '||' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
    }

    public function testNestedOrInAnd(): void
    {
        $init =
        [
            'and' ,
            [
                'or' ,
                [ 'key' => 'name' , 'val' => 'John' ] ,
                [ 'key' => 'name' , 'val' => 'Jane' ] ,
            ] ,
            [ 'key' => 'age' , 'val' => 18 , 'op' => 'ge' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '||' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
    }

    public function testDeeplyNestedConditions(): void
    {
        $init =
        [
            'and' ,
            [
                'or' ,
                [ 'key' => 'category' , 'val' => 'A' ] ,
                [ 'key' => 'category' , 'val' => 'B' ] ,
            ] ,
            [
                'or' ,
                [ 'key' => 'status' , 'val' => 'active' ] ,
                [ 'key' => 'status' , 'val' => 'pending' ] ,
            ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertStringContainsString( '||' , $result ) ;
        $this->assertCount( 4 , $this->binds ) ;
    }

    // ========================================
    // COMPLEX REAL-WORLD SCENARIOS
    // ========================================

    public function testComplexFilterScenario(): void
    {
        // Find active users named John or Jane who are at least 18
        $init =
        [
            'and' ,
            [ 'key' => 'active' , 'val' => true ] ,
            [
                'or' ,
                [ 'key' => 'name' , 'val' => 'John' ] ,
                [ 'key' => 'name' , 'val' => 'Jane' ] ,
            ] ,
            [ 'key' => 'age' , 'val' => 18 , 'op' => 'ge' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( 'doc.age' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertStringContainsString( '||' , $result ) ;
    }

    public function testNotWithNestedOr(): void
    {
        // Exclude users who are either admin or superuser
        $init =
        [
            'not' ,
            [
                'or' ,
                [ 'key' => 'status' , 'val' => 'admin' ] ,
                [ 'key' => 'status' , 'val' => 'superuser' ] ,
            ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        // The NOT should wrap the OR condition
        $this->assertStringContainsString( '!' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    public function testConditionsWithCustomDocRef(): void
    {
        $init =
        [
            'or' ,
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'name' , 'val' => 'Jane' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds , 'v' ) ;

        $this->assertStringContainsString( 'v.name' , $result ) ;
        $this->assertStringNotContainsString( 'doc.name' , $result ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function testSingleConditionInArray(): void
    {
        $init =
        [
            [ 'key' => 'name' , 'val' => 'John' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
    }

    public function testNotWithEmptyCondition(): void
    {
        $init =
        [
            'not' ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        // Should return null for invalid NOT condition
        $this->assertNull( $result ) ;
    }

    /**
     * A single NOT operand that is null short-circuits to null (the
     * isset($init[0]) guard fails), without attempting to negate anything.
     */
    public function testNotWithNullOperandReturnsNull(): void
    {
        $result = $this->model->prepareFilterConditions( [ 'not' , null ] , $this->binds ) ;
        $this->assertNull( $result ) ;
    }

    public function testConditionsWithMixedTypes(): void
    {
        $init =
        [
            'and' ,
            [ 'key' => 'name'   , 'val' => 'John' ] ,               // STRING
            [ 'key' => 'age'    , 'val' => 25 , 'op' => 'ge' ] ,    // NUMBER
            [ 'key' => 'active' , 'val' => true ] ,                 // BOOL
            [ 'key' => 'email'  , 'val' => '%@example.com' , 'op' => 'like' ] , // STRING with LIKE
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertStringContainsString( 'doc.age' , $result ) ;
        $this->assertStringContainsString( 'doc.active' , $result ) ;
        $this->assertStringContainsString( 'doc.email' , $result ) ;
        $this->assertStringContainsString( 'LIKE' , $result ) ;
        $this->assertCount( 4 , $this->binds ) ;
    }

    // ========================================
    // PARENTHESES GROUPING
    // ========================================

    public function testParenthesesInResult(): void
    {
        $init =
        [
            'or' ,
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'name' , 'val' => 'Jane' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        // Result should be wrapped in parentheses
        $this->assertStringStartsWith( '(' , $result ) ;
        $this->assertStringEndsWith( ')' , $result ) ;
    }
}
