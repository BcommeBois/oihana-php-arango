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
 * Tests for HasFilterNumber trait.
 */
class HasFilterNumberTest extends TestCase
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
                'age'      => FilterType::NUMBER ,
                'price'    => FilterType::NUMBER ,
                'quantity' => FilterType::NUMBER ,
                'score'    => FilterType::NUMBER ,
                'rating'   => FilterType::NUMBER ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // BASIC NUMBER FILTERS
    // ========================================

    public function testNumberFilterEquals(): void
    {
        $init = [ 'key' => 'age' , 'val' => 25 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.age' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
        $this->assertContains( 25 , $this->binds ) ;
    }

    public function testNumberFilterNotEquals(): void
    {
        $init = [ 'key' => 'age' , 'val' => 25 , 'op' => 'ne' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '!=' , $result ) ;
    }

    public function testNumberFilterGreaterThan(): void
    {
        $init = [ 'key' => 'age' , 'val' => 18 , 'op' => 'gt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>' , $result ) ;
        $this->assertStringNotContainsString( '>=' , $result ) ;
    }

    public function testNumberFilterGreaterThanOrEquals(): void
    {
        $init = [ 'key' => 'age' , 'val' => 18 , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
    }

    public function testNumberFilterLessThan(): void
    {
        $init = [ 'key' => 'age' , 'val' => 65 , 'op' => 'lt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '<' , $result ) ;
        $this->assertStringNotContainsString( '<=' , $result ) ;
    }

    public function testNumberFilterLessThanOrEquals(): void
    {
        $init = [ 'key' => 'age' , 'val' => 65 , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '<=' , $result ) ;
    }

    // ========================================
    // IN / NOT IN OPERATORS
    // ========================================

    public function testNumberFilterIn(): void
    {
        $init = [ 'key' => 'age' , 'val' => [ 18 , 21 , 25 , 30 ] , 'op' => 'in' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'IN' , $result ) ;
    }

    public function testNumberFilterNotIn(): void
    {
        $init = [ 'key' => 'age' , 'val' => [ 0 , -1 ] , 'op' => 'nin' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'NOT IN' , $result ) ;
    }

    // ========================================
    // FUNCTION TRANSFORMATIONS - MATH
    // ========================================

    public function testNumberFilterWithAbs(): void
    {
        $init = [ 'key' => 'price' , 'val' => 100 , 'alt' => 'abs' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ABS(doc.price)' , $result ) ;
        $this->assertStringContainsString( '>=' , $result ) ;
    }

    public function testNumberFilterWithCeil(): void
    {
        $init = [ 'key' => 'price' , 'val' => 10 , 'alt' => 'ceil' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'CEIL(doc.price)' , $result ) ;
    }

    public function testNumberFilterWithFloor(): void
    {
        $init = [ 'key' => 'price' , 'val' => 10 , 'alt' => 'floor' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'FLOOR(doc.price)' , $result ) ;
    }

    public function testNumberFilterWithRound(): void
    {
        $init = [ 'key' => 'price' , 'val' => 10 , 'alt' => 'rnd' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'ROUND(doc.price)' , $result ) ;
    }

    public function testNumberFilterWithSqrt(): void
    {
        $init = [ 'key' => 'score' , 'val' => 10 , 'alt' => 'sqrt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'SQRT(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithLog(): void
    {
        $init = [ 'key' => 'score' , 'val' => 2 , 'alt' => 'log' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LOG(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithLog10(): void
    {
        $init = [ 'key' => 'score' , 'val' => 2 , 'alt' => 'log10' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LOG10(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithExp(): void
    {
        $init = [ 'key' => 'score' , 'val' => 10 , 'alt' => 'exp' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'EXP(doc.score)' , $result ) ;
    }

    // ========================================
    // FUNCTION TRANSFORMATIONS - TRIG
    // ========================================

    public function testNumberFilterWithSin(): void
    {
        $init = [ 'key' => 'score' , 'val' => 0 , 'alt' => 'sin' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'SIN(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithCos(): void
    {
        $init = [ 'key' => 'score' , 'val' => 1 , 'alt' => 'cos' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'COS(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithTan(): void
    {
        $init = [ 'key' => 'score' , 'val' => 0 , 'alt' => 'tan' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'TAN(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithDegrees(): void
    {
        $init = [ 'key' => 'score' , 'val' => 180 , 'alt' => 'deg' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DEGREES(doc.score)' , $result ) ;
    }

    public function testNumberFilterWithRadians(): void
    {
        $init = [ 'key' => 'score' , 'val' => 3.14 , 'alt' => 'rad' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'RADIANS(doc.score)' , $result ) ;
    }

    // ========================================
    // FUNCTION TRANSFORMATIONS - WITH PARAMS
    // ========================================

    public function testNumberFilterWithPow(): void
    {
        $init = [ 'key' => 'score' , 'val' => 100 , 'alt' => [ 'pow' , 2 ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/POW\(doc\.score,\s*2\)/' , $result ) ;
    }

    public function testNumberFilterWithPowDefaultExponent(): void
    {
        $init = [ 'key' => 'score' , 'val' => 100 , 'alt' => 'pow' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/POW\(doc\.score,\s*2\)/' , $result ) ;
    }

    // ========================================
    // FUNCTION CHAINING
    // ========================================

    public function testNumberFilterWithFunctionChain(): void
    {
        $init = [ 'key' => 'price' , 'val' => 10 , 'alt' => [ 'abs' , 'ceil' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'CEIL(ABS(doc.price))' , $result ) ;
    }

    public function testNumberFilterWithMixedChain(): void
    {
        $init = [ 'key' => 'score' , 'val' => 10 , 'alt' => [ 'abs' , [ 'pow' , 2 ] ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/POW\(ABS\(doc\.score\),\s*2\)/' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    public function testNumberFilterWithCustomDocRef(): void
    {
        $init = [ 'key' => 'age' , 'val' => 25 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds , 'vertex' ) ;

        $this->assertStringContainsString( 'vertex.age' , $result ) ;
        $this->assertStringNotContainsString( 'doc.age' , $result ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function testNumberFilterWithZero(): void
    {
        $init = [ 'key' => 'quantity' , 'val' => 0 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.quantity' , $result ) ;
        $this->assertContains( 0 , $this->binds ) ;
    }

    public function testNumberFilterWithNegativeNumber(): void
    {
        $init = [ 'key' => 'price' , 'val' => -50 , 'op' => 'lt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.price' , $result ) ;
        $this->assertContains( -50 , $this->binds ) ;
    }

    public function testNumberFilterWithFloat(): void
    {
        $init = [ 'key' => 'price' , 'val' => 19.99 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.price' , $result ) ;
        $this->assertContains( 19.99 , $this->binds ) ;
    }

    public function testNumberFilterWithLargeNumber(): void
    {
        $init = [ 'key' => 'price' , 'val' => 9999999999 ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.price' , $result ) ;
        $this->assertContains( 9999999999 , $this->binds ) ;
    }

    public function testNumberFilterWithNullValue(): void
    {
        $init = [ 'key' => 'quantity' , 'val' => null ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.quantity' , $result ) ;
    }

    // ========================================
    // RANGE SIMULATION
    // ========================================

    public function testNumberFilterRangeSimulation(): void
    {
        $init =
        [
            'and' ,
            [ 'key' => 'age' , 'val' => 18 , 'op' => 'ge' ] ,
            [ 'key' => 'age' , 'val' => 65 , 'op' => 'lt' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
        $this->assertStringContainsString( '<' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertContains( 18 , $this->binds ) ;
        $this->assertContains( 65 , $this->binds ) ;
    }
}
