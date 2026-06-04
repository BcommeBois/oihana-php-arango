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
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterType;

use tests\oihana\arango\models\traits\aql\FacetTraitStub;

use function oihana\arango\db\helpers\buildBetweenClauses;

/**
 * Coverage for the `between` (range) operator: the shared free helper
 * {@see buildBetweenClauses()}, the `?filter=` number/string/date builders, and
 * the `?facets=` FIELD facet. An omitted bound is one-sided for number/string,
 * but resolves to "now" for dates.
 */
class FilterBetweenTest extends TestCase
{
    private array $binds;

    protected function setUp(): void
    {
        $this->binds = [] ;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function model(): Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        return new Documents( $container ,
        [
            AQL::COLLECTION => 'c' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'price'   => FilterType::NUMBER ,
                'name'    => FilterType::STRING ,
                'created' => FilterType::DATE ,
            ]
        ]);
    }

    // ========================================
    // buildBetweenClauses() — direct, host-free
    // ========================================

    public function testHelperBothBoundsParenthesized(): void
    {
        $this->assertSame( '(doc.price >= @min && doc.price <= @max)' , buildBetweenClauses( 'doc.price' , '@min' , '@max' ) ) ;
    }

    public function testHelperLowerOnly(): void
    {
        $this->assertSame( 'doc.price >= @min' , buildBetweenClauses( 'doc.price' , '@min' , null ) ) ;
    }

    public function testHelperUpperOnly(): void
    {
        $this->assertSame( 'doc.price <= @max' , buildBetweenClauses( 'doc.price' , null , '@max' ) ) ;
    }

    public function testHelperNoBoundsIsEmpty(): void
    {
        $this->assertSame( '' , buildBetweenClauses( 'doc.price' , null , null ) ) ;
    }

    // ========================================
    // ?filter= number / string
    // ========================================

    public function testNumberBetweenFull(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => 10 , 'max' => 50 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^\(doc\.price >= @\S+ && doc\.price <= @\S+\)$/' , $result ) ;
        $this->assertContains( 10 , $this->binds ) ;
        $this->assertContains( 50 , $this->binds ) ;
    }

    public function testNumberBetweenMinOmittedIsOneSided(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'max' => 50 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.price <= @\S+$/' , $result ) ;
    }

    public function testNumberBetweenMaxOmittedIsOneSided(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => 10 ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^doc\.price >= @\S+$/' , $result ) ;
    }

    public function testStringBetweenFull(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'name' , 'op' => 'between' , 'min' => 'a' , 'max' => 'm' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^\(doc\.name >= @\S+ && doc\.name <= @\S+\)$/' , $result ) ;
    }

    public function testNumberBetweenWrapsTheKeyWithAlt(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => 10 , 'max' => 50 , 'alt' => 'abs' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^\(ABS\(doc\.price\) >= @\S+ && ABS\(doc\.price\) <= @\S+\)$/' , $result ) ;
    }

    // ========================================
    // ?filter= date (omitted bound → now ; tz on both bounds)
    // ========================================

    public function testDateBetweenFull(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'created' , 'op' => 'between' , 'min' => '2024-01-01' , 'max' => '2024-12-31' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^\(doc\.created >= @\S+ && doc\.created <= @\S+\)$/' , $result ) ;
    }

    public function testDateBetweenOmittedMaxDefaultsToNow(): void
    {
        $result = $this->model()->prepareFilter( [ 'key' => 'created' , 'op' => 'between' , 'min' => '2024-01-01' ] , $this->binds ) ;

        $this->assertMatchesRegularExpression( '/^\(doc\.created >= @\S+ && doc\.created <= DATE_ISO8601\(DATE_NOW\(\)\)\)$/' , $result ) ;
    }

    public function testDateBetweenWithTimezoneWrapsBothBounds(): void
    {
        $result = $this->model()->prepareFilter
        (
            [ 'key' => 'created' , 'op' => 'between' , 'min' => '2024-01-01' , 'max' => '2024-12-31' , 'tz' => 'Europe/Paris' ] ,
            $this->binds
        ) ;

        $this->assertMatchesRegularExpression( '/^\(doc\.created >= DATE_LOCALTOUTC\(@\S+,@\S+\) && doc\.created <= DATE_LOCALTOUTC\(@\S+,@\S+\)\)$/' , $result ) ;
    }

    // ========================================
    // ?facets= FIELD between
    // ========================================

    public function testFacetFieldBetweenFull(): void
    {
        $stub   = new FacetTraitStub() ;
        $result = $stub->callField( 'price' , [ 'op' => 'between' , 'min' => 10 , 'max' => 50 ] , $this->binds , [] , AQL::DOC ) ;

        $this->assertSame( '(doc.price >= @price_min && doc.price <= @price_max)' , $result ) ;
    }

    public function testFacetFieldBetweenWithPropertyAndAlt(): void
    {
        $stub   = new FacetTraitStub() ;
        $facet  = [ Facet::PROPERTY => 'name' , Facet::ALT => [ 'key' => 'lower' ] ] ;
        $result = $stub->callField( 'p' , [ 'op' => 'between' , 'min' => 'a' , 'max' => 'm' ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame( '(LOWER(doc.name) >= @p_min && LOWER(doc.name) <= @p_max)' , $result ) ;
    }
}
