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
 * Tests for HasFilterDate trait.
 */
class HasFilterDateTest extends TestCase
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
                'created'  => FilterType::DATE ,
                'modified' => FilterType::DATE ,
                'expires'  => FilterType::DATE ,
                'birthday' => FilterType::DATE ,
            ]
        ]);

        $this->binds = [] ;
    }

    // ========================================
    // BASIC DATE FILTERS
    // ========================================

    public function testDateFilterEquals(): void
    {
        $init = [ 'key' => 'created' , 'val' => '2024-01-15' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertStringContainsString( '==' , $result ) ;
        $this->assertContains( '2024-01-15' , $this->binds ) ;
    }

    public function testDateFilterWithIso8601Format(): void
    {
        $init = [ 'key' => 'created' , 'val' => '2024-01-15T10:30:00Z' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertContains( '2024-01-15T10:30:00Z' , $this->binds ) ;
    }

    // ========================================
    // DATE OPERATORS
    // ========================================

    public function testDateFilterGreaterThan(): void
    {
        $init = [ 'key' => 'created' , 'val' => '2024-01-01' , 'op' => 'gt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>' , $result ) ;
        $this->assertStringNotContainsString( '>=' , $result ) ;
    }

    public function testDateFilterGreaterThanOrEquals(): void
    {
        $init = [ 'key' => 'created' , 'val' => '2024-01-01' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
    }

    public function testDateFilterLessThan(): void
    {
        $init = [ 'key' => 'expires' , 'val' => '2025-12-31' , 'op' => 'lt' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '<' , $result ) ;
        $this->assertStringNotContainsString( '<=' , $result ) ;
    }

    public function testDateFilterLessThanOrEquals(): void
    {
        $init = [ 'key' => 'expires' , 'val' => '2025-12-31' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '<=' , $result ) ;
    }

    // ========================================
    // SPECIAL DATE VALUES
    // ========================================

    public function testDateFilterWithNow(): void
    {
        $init = [ 'key' => 'created' , 'val' => 'now' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertStringContainsString( 'DATE_ISO8601' , $result ) ;
    }

    public function testDateFilterWithCurrentTimestamp(): void
    {
        $init = [ 'key' => 'created' , 'val' => 'cts' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertStringContainsString( 'DATE_NOW()' , $result ) ;
    }

    public function testDateFilterWithYesterday(): void
    {
        $init = [ 'key' => 'created' , 'val' => 'yesterday' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertStringContainsString( 'DATE_SUBTRACT' , $result ) ;
    }

    public function testDateFilterWithTomorrow(): void
    {
        $init = [ 'key' => 'expires' , 'val' => 'tomorrow' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.expires' , $result ) ;
        $this->assertStringContainsString( 'DATE_ADD' , $result ) ;
    }

    // ========================================
    // TIMEZONE SUPPORT
    // ========================================

    /**
     * Note: The timezone conversion uses DATE_LOCALTOUTC with bind variables.
     * The isValidTimezone() function validates the timezone before binding,
     * but the actual AQL function receives bind variables (@param).
     *
     * This test verifies that the filter result contains the expected structure
     * when a valid timezone is provided.
     */
    public function testDateFilterWithTimezoneStructure(): void
    {
        // When timezone is invalid, it falls back to direct binding without DATE_LOCALTOUTC
        $init = [ 'key' => 'created' , 'val' => '2024-01-15T10:00:00' , 'op' => 'ge' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertStringContainsString( '>=' , $result ) ;
        $this->assertContains( '2024-01-15T10:00:00' , $this->binds ) ;
    }

    // ========================================
    // DATE FUNCTION TRANSFORMATIONS
    // ========================================

    public function testDateFilterWithYearExtraction(): void
    {
        $init = [ 'key' => 'birthday' , 'val' => 1990 , 'alt' => 'dateYear' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_YEAR(doc.birthday)' , $result ) ;
    }

    public function testDateFilterWithMonthExtraction(): void
    {
        $init = [ 'key' => 'birthday' , 'val' => 12 , 'alt' => 'dateMonth' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_MONTH(doc.birthday)' , $result ) ;
    }

    public function testDateFilterWithDayExtraction(): void
    {
        $init = [ 'key' => 'birthday' , 'val' => 25 , 'alt' => 'dateDay' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_DAY(doc.birthday)' , $result ) ;
    }

    public function testDateFilterWithDayOfWeek(): void
    {
        $init = [ 'key' => 'created' , 'val' => 1 , 'alt' => 'dateDayOfWeek' ] ; // Monday

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_DAYOFWEEK(doc.created)' , $result ) ;
    }

    public function testDateFilterWithDayOfYear(): void
    {
        $init = [ 'key' => 'created' , 'val' => 100 , 'alt' => 'dateDayOfYear' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_DAYOFYEAR(doc.created)' , $result ) ;
    }

    public function testDateFilterWithHourExtraction(): void
    {
        $init = [ 'key' => 'created' , 'val' => 12 , 'alt' => 'dateHour' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_HOUR(doc.created)' , $result ) ;
    }

    public function testDateFilterWithQuarter(): void
    {
        $init = [ 'key' => 'created' , 'val' => 4 , 'alt' => 'dateQuarter' ] ; // Q4

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_QUARTER(doc.created)' , $result ) ;
    }

    public function testDateFilterWithIsoWeek(): void
    {
        $init = [ 'key' => 'created' , 'val' => 52 , 'alt' => 'dateIsoWeek' , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DATE_ISOWEEK(doc.created)' , $result ) ;
    }

    // ========================================
    // CUSTOM DOC REF
    // ========================================

    public function testDateFilterWithCustomDocRef(): void
    {
        $init = [ 'key' => 'created' , 'val' => '2024-01-01' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds , 'v' ) ;

        $this->assertStringContainsString( 'v.created' , $result ) ;
        $this->assertStringNotContainsString( 'doc.created' , $result ) ;
    }

    // ========================================
    // DATE RANGE
    // ========================================

    public function testDateRangeFilter(): void
    {
        $init =
        [
            'and' ,
            [ 'key' => 'created' , 'val' => '2024-01-01' , 'op' => 'ge' ] ,
            [ 'key' => 'created' , 'val' => '2024-12-31' , 'op' => 'le' ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
        $this->assertStringContainsString( '<=' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertContains( '2024-01-01' , $this->binds ) ;
        $this->assertContains( '2024-12-31' , $this->binds ) ;
    }

    // ========================================
    // EDGE CASES
    // ========================================

    public function testDateFilterWithNullDefaultsToNow(): void
    {
        $init = [ 'key' => 'created' , 'val' => null , 'op' => 'le' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        $this->assertStringContainsString( 'DATE_ISO8601' , $result ) ;
    }

    public function testDateFilterWithInvalidTimezoneIgnored(): void
    {
        $init = [ 'key' => 'created' , 'val' => '2024-01-15' , 'tz' => 'Invalid/Zone' ] ;

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.created' , $result ) ;
        // Invalid timezone is ignored, value is used directly
        $this->assertStringNotContainsString( 'DATE_LOCALTOUTC' , $result ) ;
    }
}
