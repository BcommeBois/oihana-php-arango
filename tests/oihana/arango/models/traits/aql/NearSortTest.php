<?php

namespace tests\oihana\arango\models\traits\aql;

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
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\ValidationException;

/**
 * Tests for the `?near=` distance sorting in SortTrait.
 */
class NearSortTest extends TestCase
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
            AQL::SORTABLE   => [ 'name' => 'name' ] ,
        ]);

        $this->binds = [] ;
    }

    private function near(): array
    {
        return [ FilterParam::KEY => 'geo' , 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ;
    }

    public function testNearAloneDefaultsToDistanceAsc(): void
    {
        $init = [ Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
        $this->assertStringContainsString( 'ASC' , $result ) ;
        $this->assertStringNotContainsString( 'DESC' , $result ) ;
        $this->assertContains( 48.8566 , $this->binds ) ;
        $this->assertContains( 2.3522 , $this->binds ) ;
    }

    public function testNearWithExplicitDistance(): void
    {
        $init = [ Arango::SORT => 'distance' , Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
        $this->assertStringContainsString( 'ASC' , $result ) ;
    }

    public function testNearWithDescDistance(): void
    {
        $init = [ Arango::SORT => '-distance' , Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertStringContainsString( 'DESC' , $result ) ;
    }

    public function testNearDistanceThenName(): void
    {
        $init = [ Arango::SORT => 'distance,name' , Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertStringContainsString( 'doc.name ASC' , $result ) ;
        $this->assertLessThan
        (
            strpos( $result , 'doc.name' ) ,
            strpos( $result , 'DISTANCE' ) ,
            'distance must be ordered before name'
        );
    }

    public function testExplicitSortWithoutDistanceIgnoresNear(): void
    {
        $init = [ Arango::SORT => 'name' , Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( 'doc.name ASC' , $result ) ;
        $this->assertStringNotContainsString( 'DISTANCE' , $result ) ;
        $this->assertNotContains( 48.8566 , $this->binds ) ;
    }

    public function testDistanceWithoutNearIsDropped(): void
    {
        $init = [ Arango::SORT => 'distance' ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertStringNotContainsString( 'DISTANCE' , $result ) ;
    }

    public function testNearWithoutBindsIsInert(): void
    {
        // No $binds passed → near sorting cannot bind, so it is disabled.
        $init = [ Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init ) ;

        $this->assertStringNotContainsString( 'DISTANCE' , $result ) ;
    }

    public function testNearWithShortAliases(): void
    {
        $init = [ Arango::NEAR => [ FilterParam::KEY => 'geo' , 'lat' => 48.8566 , 'lng' => 2.3522 ] ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
        $this->assertContains( 48.8566 , $this->binds ) ;
    }

    public function testNearMissingCoordinatesYieldsNoSort(): void
    {
        $init = [ Arango::NEAR => [ FilterParam::KEY => 'geo' ] ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( '' , $result ) ;
    }

    public function testNearWithCustomDocRef(): void
    {
        $init = [ Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , docRef: 'vertex', binds: $this->binds ) ;

        $this->assertStringContainsString( 'vertex.geo.latitude' , $result ) ;
        $this->assertStringNotContainsString( 'doc.geo.latitude' , $result ) ;
    }

    public function testNearWithUnsafeKeyThrows(): void
    {
        $init = [ Arango::NEAR => [ FilterParam::KEY => 'geo || 1==1' , 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ;

        $this->expectException( ValidationException::class ) ;

        $this->model->prepareSort( $init , binds: $this->binds ) ;
    }
}
