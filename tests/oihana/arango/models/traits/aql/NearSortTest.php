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
use oihana\arango\enums\Field;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterParam;

/**
 * Tests for the `?near=` distance sorting in SortTrait.
 */
class NearSortTest extends TestCase
{
    private Container $container;
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

        $this->container = $container ;

        $this->model = $this->modelWithSortable( [ 'name' , 'geo' ] ) ;

        $this->binds = [] ;
    }

    /**
     * Build a Documents model with a given `?sort=`/`?near=` whitelist.
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function modelWithSortable( array $sortable ): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::SORTABLE   => $sortable ,
        ]);
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

    public function testNearWithoutKeyYieldsNoSort(): void
    {
        $init = [ Arango::NEAR => [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( '' , $result ) ;
    }

    public function testSortIgnoresEmptySegments(): void
    {
        // A trailing comma yields an empty criterion that must be skipped.
        $init = [ Arango::SORT => 'name,' ] ;

        $result = $this->model->prepareSort( $init ) ;

        $this->assertSame( 'doc.name ASC' , $result ) ;
    }

    public function testNearWithCustomDocRef(): void
    {
        $init = [ Arango::NEAR => $this->near() ] ;

        $result = $this->model->prepareSort( $init , docRef: 'vertex', binds: $this->binds ) ;

        $this->assertStringContainsString( 'vertex.geo.latitude' , $result ) ;
        $this->assertStringNotContainsString( 'doc.geo.latitude' , $result ) ;
    }

    public function testNearWithUnsafeKeyIsDropped(): void
    {
        // Fail-closed: an unsafe key is not whitelisted, so it is dropped — no
        // exception, no injection, no distance sort.
        $init = [ Arango::NEAR => [ FilterParam::KEY => 'geo || 1==1' , 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertStringNotContainsString( 'DISTANCE' , $result ) ;
    }

    public function testNearKeyNotWhitelistedYieldsNoDistance(): void
    {
        // The model whitelists 'geo' only; a different geo key is refused.
        $init = [ Arango::NEAR => [ FilterParam::KEY => 'location' , 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ;

        $result = $this->model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertNotContains( 48.85 , $this->binds ) ;
    }

    public function testNearKeyDeniedByPermissionYieldsNoDistance(): void
    {
        // The geo dimension is permission-gated; a denying authorizer drops the distance sort.
        $model = $this->modelWithSortable
        (
            [ 'geo' => [ Field::PATH => 'geo' , Field::REQUIRES => 'geo:read' ] ]
        ) ;

        $init = [ Arango::NEAR => $this->near() , Arango::AUTHORIZER => fn() => false ] ;

        $result = $model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertNotContains( 48.8566 , $this->binds ) ;
    }

    public function testNearKeyAllowedByPermissionSorts(): void
    {
        $model = $this->modelWithSortable
        (
            [ 'geo' => [ Field::PATH => 'geo' , Field::REQUIRES => 'geo:read' ] ]
        ) ;

        $init = [ Arango::NEAR => $this->near() , Arango::AUTHORIZER => fn( string $s ) => $s === 'geo:read' ] ;

        $result = $model->prepareSort( $init , binds: $this->binds ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , $result ) ;
    }

    public function testNearAliasedDeepPathInheritsProjectionRequires(): void
    {
        // The geo key is aliased to a deep path (`geo` → `location.point`) masked by
        // the projection's Field::REQUIRES; the near sort inherits that gate at the
        // resolved path (T3), even without an explicit Field::REQUIRES on the entry.
        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'testCollection' ,
            AQL::LAZY       => false ,
            AQL::SORTABLE   => [ 'geo' => 'location.point' ] ,
            AQL::FIELDS     => [ 'location' => [ Field::FIELDS => [ 'point' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ,
        ]);

        // Denied → no DISTANCE emitted (no location oracle).
        $denied = [ Arango::NEAR => $this->near() , Arango::AUTHORIZER => fn() => false ] ;
        $this->assertSame( '' , $model->prepareSort( $denied , binds: $this->binds ) ) ;

        // Granted → DISTANCE on the resolved deep path.
        $this->binds = [] ;
        $granted = [ Arango::NEAR => $this->near() , Arango::AUTHORIZER => fn( string $s ) => $s === 'geo:read' ] ;
        $this->assertStringContainsString
        (
            'DISTANCE(doc.location.point.latitude,doc.location.point.longitude,' ,
            $model->prepareSort( $granted , binds: $this->binds )
        ) ;
    }
}
