<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

/**
 * Coverage for the unified `quant` element-axis quantifier on array filters.
 *
 * The comparator stays in `op`; `quant` selects how many elements must match:
 * `any` (default) / `all` / `none` / an integer `n` (= at least `n`). It drives
 * both array surfaces:
 * - scalar arrays via the array comparison operator (`doc.scores ALL >= @v`);
 * - object arrays via the question-mark operator (`doc.reviews[? ALL FILTER …]`).
 *
 * The legacy notations (`op:"all.ge"`, `op:["atLeast.ge", n]`, and the
 * quant-less existential `LENGTH(...) > 0`) stay valid and are asserted unchanged.
 */
class FilterQuantTest extends TestCase
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
            AQL::COLLECTION => 'c' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'scores'  => FilterType::ARRAY ,
                'reviews' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'rating' => FilterType::NUMBER ],
                ],
                'contactPoint' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'verified' => FilterType::BOOL , 'type' => FilterType::STRING ],
                ],
                'employee' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'contactPoint' =>
                        [
                            AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                            AQL::FILTERS => [ 'verified' => FilterType::BOOL ],
                        ],
                    ],
                ],
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // SCALAR ARRAYS — array comparison operator
    // ========================================

    public function testScalarAll(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores ALL >= @\S+$/' , $result ) ;
        $this->assertContains( 80 , $this->binds ) ;
    }

    public function testScalarAny(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 'any' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores ANY >= @\S+$/' , $result ) ;
    }

    public function testScalarNone(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores NONE >= @\S+$/' , $result ) ;
    }

    public function testScalarAtLeastInteger(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 2 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(2\) >= @\S+$/' , $result ) ;
    }

    public function testScalarAtLeastNumericStringIsCoercedToInt(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => '2' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(2\) >= @\S+$/' , $result ) ;
    }

    public function testScalarDefaultComparatorIsEquals(): void
    {
        // No `op` → defaults to equality, the quantifier still applies.
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'val' => 80 , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores ALL == @\S+$/' , $result ) ;
    }

    // ========================================
    // OBJECT ARRAYS (sub-field) — question-mark operator
    // ========================================

    public function testObjectSubFieldAtLeast(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 3 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.reviews\[\? AT LEAST \(3\) FILTER CURRENT\.rating >= @\S+]$/' , $result ) ;
        $this->assertContains( 4 , $this->binds ) ;
    }

    public function testObjectSubFieldAll(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.reviews\[\? ALL FILTER CURRENT\.rating >= @\S+]$/' , $result ) ;
    }

    public function testObjectSubFieldNone(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.reviews\[\? NONE FILTER CURRENT\.rating >= @\S+]$/' , $result ) ;
    }

    // ========================================
    // OBJECT ARRAYS (match) — question-mark operator
    // ========================================

    public function testObjectMatchAll(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'contactPoint[*]' , 'match' => [ 'verified' => true ] , 'quant' => 'all' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.contactPoint\[\? ALL FILTER CURRENT\.verified == @\S+]$/' , $result ) ;
    }

    public function testObjectMatchNone(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'contactPoint[*]' , 'match' => [ 'verified' => true ] , 'quant' => 'none' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.contactPoint\[\? NONE FILTER CURRENT\.verified == @\S+]$/' , $result ) ;
    }

    // ========================================
    // BACKWARD COMPATIBILITY — no `quant` ⇒ legacy AQL unchanged
    // ========================================

    public function testScalarLegacyArrayComparatorUnchanged(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'all.ge' , 'val' => 80 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores ALL >= @\S+$/' , $result ) ;
    }

    public function testScalarLegacyAtLeastOperatorUnchanged(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => [ 'atLeast.ge' , 2 ] , 'val' => 80 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.scores AT LEAST \(2\) >= @\S+$/' , $result ) ;
    }

    public function testObjectSubFieldWithoutQuantStaysExistential(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LENGTH\(doc\.reviews\[\* FILTER CURRENT\.rating >= @\S+]\) > 0$/' , $result ) ;
    }

    public function testObjectMatchWithoutQuantStaysExistential(): void
    {
        $result = $this->model->prepareFilter( [ 'key' => 'contactPoint[*]' , 'match' => [ 'verified' => true ] ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LENGTH\(doc\.contactPoint\[\* FILTER CURRENT\.verified == @\S+]\) > 0$/' , $result ) ;
    }

    // ========================================
    // SCOPE GUARD — multi-level arrays keep legacy ANY
    // ========================================

    public function testMultiLevelArrayIgnoresQuant(): void
    {
        // Two `[*]` levels → the binding level is ambiguous, so `quant` is dropped
        // and the legacy existential (ANY) is emitted.
        $result = $this->model->prepareFilter
        (
            [ 'key' => 'employee[*].contactPoint[*].verified' , 'op' => 'eq' , 'val' => true , 'quant' => 'all' ] ,
            $this->binds
        ) ;

        $this->assertStringContainsString( 'LENGTH(' , $result ) ;
        $this->assertStringContainsString( '> 0' , $result ) ;
        $this->assertStringNotContainsString( 'ALL' , $result ) ;
        $this->assertStringNotContainsString( '[?' , $result ) ;
    }

    // ========================================
    // VALIDATION — unknown quantifier rejected
    // ========================================

    public function testUnknownQuantifierOnScalarIsRejected(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->model->prepareFilter( [ 'key' => 'scores' , 'op' => 'ge' , 'val' => 80 , 'quant' => 'bogus' ] , $this->binds ) ;
    }

    public function testUnknownQuantifierOnObjectIsRejected(): void
    {
        $this->expectException( ValidationException::class ) ;
        $this->model->prepareFilter( [ 'key' => 'reviews[*].rating' , 'op' => 'ge' , 'val' => 4 , 'quant' => 'bogus' ] , $this->binds ) ;
    }
}
