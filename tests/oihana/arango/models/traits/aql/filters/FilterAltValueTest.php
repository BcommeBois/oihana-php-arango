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
 * Tests for the value-side (right) `alt` transformation: the object form
 * `alt:{ key:<chain>, val:<chain|true> }` and the `val:true` mirror, across the
 * string, number, array and date filter types.
 */
class FilterAltValueTest extends TestCase
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
                'email'    => FilterType::STRING ,
                'category' => FilterType::STRING ,
                'price'    => FilterType::NUMBER ,
                'created'  => FilterType::DATE ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // OBJECT FORM { key, val }
    // ========================================

    public function testObjectFormExplicitWrapsBothSides(): void
    {
        $init = [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'key' => 'lower' , 'val' => 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(doc\.email\) == LOWER\(@\S+\)$/' , $result ) ;
    }

    public function testObjectFormValTrueMirrorsKeySide(): void
    {
        $init = [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(doc\.email\) == LOWER\(@\S+\)$/' , $result ) ;
    }

    public function testObjectFormValTrueMirrorsAChain(): void
    {
        $init = [ 'key' => 'name' , 'val' => ' John ' , 'alt' => [ 'key' => [ 'trim' , 'lower' ] , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(TRIM\(doc\.name\)\) == LOWER\(TRIM\(@\S+\)\)$/' , $result ) ;
    }

    public function testObjectFormKeyOnlyLeavesValueRaw(): void
    {
        $init = [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'key' => 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(doc\.email\) == @\S+$/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER(@' , $result ) ;
    }

    public function testObjectFormValOnlyLeavesKeyRaw(): void
    {
        $init = [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => [ 'val' => 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.email == LOWER\(@\S+\)$/' , $result ) ;
    }

    // ========================================
    // LEGACY FORMS (regression: key side only)
    // ========================================

    public function testLegacyStringAltStaysKeyOnly(): void
    {
        $init = [ 'key' => 'email' , 'val' => 'JEAN@X.COM' , 'alt' => 'lower' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(doc\.email\) == @\S+$/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER(@' , $result ) ;
    }

    public function testLegacyListAltStaysKeyOnly(): void
    {
        $init = [ 'key' => 'name' , 'val' => 'john' , 'alt' => [ 'trim' , 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(TRIM\(doc\.name\)\) == @\S+$/' , $result ) ;
        $this->assertStringNotContainsString( 'LOWER(TRIM(@' , $result ) ;
    }

    // ========================================
    // ARRAY VALUE (Option A: map over each element)
    // ========================================

    public function testArrayValueMapsChainOverEachElement(): void
    {
        $init = [ 'key' => 'category' , 'op' => 'in' , 'val' => [ 'TECH' , 'NEWS' ] , 'alt' => [ 'key' => 'lower' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^LOWER\(doc\.category\) IN @\S+\[\* RETURN LOWER\(CURRENT\)\]$/' , $result ) ;
        $this->assertContains( [ 'TECH' , 'NEWS' ] , $this->binds ) ;
    }

    public function testArrayValueChainOnlyOnValueSide(): void
    {
        $init = [ 'key' => 'category' , 'op' => 'in' , 'val' => [ 'TECH' , 'NEWS' ] , 'alt' => [ 'val' => 'lower' ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.category IN @\S+\[\* RETURN LOWER\(CURRENT\)\]$/' , $result ) ;
    }

    // ========================================
    // NUMBER
    // ========================================

    public function testNumberAbsBothSides(): void
    {
        $init = [ 'key' => 'price' , 'op' => 'ge' , 'val' => 10 , 'alt' => [ 'key' => 'abs' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^ABS\(doc\.price\) >= ABS\(@\S+\)$/' , $result ) ;
    }

    // ========================================
    // DATE
    // ========================================

    public function testDateExtractorStringFormLeavesValueRaw(): void
    {
        $init = [ 'key' => 'created' , 'val' => 2024 , 'alt' => 'dateYear' , 'op' => 'eq' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^DATE_YEAR\(doc\.created\) == @\S+$/' , $result ) ;
        $this->assertStringNotContainsString( 'DATE_YEAR(@' , $result ) ;
    }

    public function testDateNormalizerObjectFormWrapsBothSides(): void
    {
        $init = [ 'key' => 'created' , 'op' => 'ge' , 'val' => '2024-06-01' , 'alt' => [ 'key' => 'dateDay' , 'val' => true ] ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^DATE_DAY\(doc\.created\) >= DATE_DAY\(@\S+\)$/' , $result ) ;
    }
}
