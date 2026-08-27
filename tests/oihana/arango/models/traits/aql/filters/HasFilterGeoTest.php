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
 * Tests for HasFilterGeo trait.
 */
class HasFilterGeoTest extends TestCase
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
                'geo' => FilterType::GEO ,
            ]
        ]);

        $this->binds = [] ;
    }

    public function testDistanceWithinRadius(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
        $this->assertStringContainsString( '<=' , $result ) ;
        $this->assertStringNotContainsString( '>=' , $result ) ;
        $this->assertContains( 48.8566 , $this->binds ) ;
        $this->assertContains( 2.3522 , $this->binds ) ;
        $this->assertContains( 5000 , $this->binds ) ;
    }

    public function testDistanceRing(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
            'min' => 1000 ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
        $this->assertStringContainsString( '<=' , $result ) ;
        $this->assertStringContainsString( '&&' , $result ) ;
        $this->assertContains( 1000 , $this->binds ) ;
        $this->assertContains( 5000 , $this->binds ) ;
    }

    public function testDistanceMinOnly(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
            'min' => 1000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( '>=' , $result ) ;
        $this->assertStringNotContainsString( '<=' , $result ) ;
    }

    public function testDistanceDefaultsToDistanceOperator(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
    }

    public function testDistanceAcceptsShortAliases(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'val' => [ 'lat' => 48.8566 , 'lng' => 2.3522 ] ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
        $this->assertContains( 48.8566 , $this->binds ) ;
        $this->assertContains( 2.3522 , $this->binds ) ;
    }

    public function testDistanceWithCustomDocRef(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds , 'vertex' ) ;

        $this->assertStringContainsString( 'vertex.geo.latitude' , $result ) ;
        $this->assertStringNotContainsString( 'doc.geo.latitude' , $result ) ;
    }

    /**
     * "No clause" is `null`, which the composition layer drops — not an empty string,
     * which travels on as though it were a condition and reaches the server as
     * `FILTER  RETURN`.
     *
     * ⚠ These two cases asserted `''` from the day they were written, and their names
     * said `YieldsNoClause` all along: the intent was always `null`. This filter was
     * the only one in the library returning the empty string.
     */
    public function testUnsupportedOperatorYieldsNoClause(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'contains' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertNull( $result ) ;
        $this->assertNotSame( '' , $result ) ;
    }

    public function testMissingValueYieldsNoClause(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'max' => 5000 ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertNull( $result ) ;
        $this->assertNotSame( '' , $result ) ;
    }

    /**
     * A valid point with **no radius** yields no clause either.
     *
     * ⚠ This one arrives from `buildBetweenClauses()`, shared with the `between`
     * operator, and it was deliberately left as an empty string when the two local
     * returns above became `null` — one arbitration for two callers, decided once with
     * both in view. It has since been decided the same way: an unfilled range widget
     * expresses no constraint, and no constraint is `null`.
     */
    public function testMissingRadiusYieldsNoClause(): void
    {
        $init =
        [
            'key' => 'geo' ,
            'op'  => 'distance' ,
            'val' => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ,
        ];

        $result = $this->model->prepareFilter( $init , $this->binds ) ;

        $this->assertNull( $result ) ;
    }
}
