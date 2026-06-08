<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\functions\geo\distance;
use function oihana\arango\db\functions\geo\geoArea;
use function oihana\arango\db\functions\geo\geoContains;
use function oihana\arango\db\functions\geo\geoDistance;
use function oihana\arango\db\functions\geo\geoEquals;
use function oihana\arango\db\functions\geo\geoInRange;
use function oihana\arango\db\functions\geo\geoIntersects;
use function oihana\arango\db\functions\geo\geoLineString;
use function oihana\arango\db\functions\geo\geoMultiLineString;
use function oihana\arango\db\functions\geo\geoMultiPoint;
use function oihana\arango\db\functions\geo\geoMultiPolygon;
use function oihana\arango\db\functions\geo\geoPoint;
use function oihana\arango\db\functions\geo\geoPolygon;
use function oihana\arango\db\functions\geo\isInPolygon;
use function oihana\arango\db\functions\geo\near;
use function oihana\arango\db\functions\geo\within;
use function oihana\arango\db\functions\geo\withinRectangle;

class GeoFunctionsTest extends TestCase
{
    // ---- Geometry constructors

    public function testGeoPointReordersToLongitudeFirst(): void
    {
        $this->assertSame( 'GEO_POINT(2.3522,48.8566)' , geoPoint( 48.8566 , 2.3522 ) );
    }

    public function testGeoPointWithExpressions(): void
    {
        $this->assertSame
        (
            'GEO_POINT(doc.geo.longitude,doc.geo.latitude)' ,
            geoPoint( 'doc.geo.latitude' , 'doc.geo.longitude' )
        );
    }

    public function testGeoMultiPointFromArray(): void
    {
        $this->assertSame
        (
            'GEO_MULTIPOINT([[2.35,48.85],[4.83,45.76]])' ,
            geoMultiPoint( [ [ 2.35 , 48.85 ] , [ 4.83 , 45.76 ] ] )
        );
    }

    public function testGeoMultiPointFromExpression(): void
    {
        $this->assertSame( 'GEO_MULTIPOINT(doc.points)' , geoMultiPoint( 'doc.points' ) );
    }

    public function testGeoPolygonFromArray(): void
    {
        $this->assertSame
        (
            'GEO_POLYGON([[[0,0],[1,0],[1,1],[0,0]]])' ,
            geoPolygon( [ [ [ 0 , 0 ] , [ 1 , 0 ] , [ 1 , 1 ] , [ 0 , 0 ] ] ] )
        );
    }

    public function testGeoPolygonFromExpression(): void
    {
        $this->assertSame( 'GEO_POLYGON(doc.area)' , geoPolygon( 'doc.area' ) );
    }

    public function testGeoMultiPolygonFromExpression(): void
    {
        $this->assertSame( 'GEO_MULTIPOLYGON(doc.areas)' , geoMultiPolygon( 'doc.areas' ) );
    }

    public function testGeoLineStringFromArray(): void
    {
        $this->assertSame
        (
            'GEO_LINESTRING([[2.35,48.85],[4.83,45.76]])' ,
            geoLineString( [ [ 2.35 , 48.85 ] , [ 4.83 , 45.76 ] ] )
        );
    }

    public function testGeoMultiLineStringFromExpression(): void
    {
        $this->assertSame( 'GEO_MULTILINESTRING(doc.routes)' , geoMultiLineString( 'doc.routes' ) );
    }

    // ---- Distances

    public function testDistanceIsLatitudeFirst(): void
    {
        $this->assertSame
        (
            'DISTANCE(doc.geo.latitude,doc.geo.longitude,48.8566,2.3522)' ,
            distance( 'doc.geo.latitude' , 'doc.geo.longitude' , 48.8566 , 2.3522 )
        );
    }

    public function testGeoDistanceWithGeoPoint(): void
    {
        $this->assertSame
        (
            'GEO_DISTANCE(doc.geo,GEO_POINT(2.3522,48.8566))' ,
            geoDistance( 'doc.geo' , geoPoint( 48.8566 , 2.3522 ) )
        );
    }

    public function testGeoDistanceWithEllipsoid(): void
    {
        $this->assertSame
        (
            'GEO_DISTANCE(doc.geo,@target,"wgs84")' ,
            geoDistance( 'doc.geo' , '@target' , 'wgs84' )
        );
    }

    public function testGeoAreaWithoutEllipsoid(): void
    {
        $this->assertSame( 'GEO_AREA(doc.area)' , geoArea( 'doc.area' ) );
    }

    public function testGeoAreaWithEllipsoid(): void
    {
        $this->assertSame( 'GEO_AREA(doc.area,"wgs84")' , geoArea( 'doc.area' , 'wgs84' ) );
    }

    // ---- Predicates

    public function testGeoContains(): void
    {
        $this->assertSame
        (
            'GEO_CONTAINS(doc.area,GEO_POINT(2.3522,48.8566))' ,
            geoContains( 'doc.area' , geoPoint( 48.8566 , 2.3522 ) )
        );
    }

    public function testGeoEquals(): void
    {
        $this->assertSame( 'GEO_EQUALS(doc.geo,@target)' , geoEquals( 'doc.geo' , '@target' ) );
    }

    public function testGeoIntersects(): void
    {
        $this->assertSame( 'GEO_INTERSECTS(doc.area,@zone)' , geoIntersects( 'doc.area' , '@zone' ) );
    }

    public function testGeoInRangeWithoutFlags(): void
    {
        $this->assertSame
        (
            'GEO_IN_RANGE(doc.geo,@center,1000,5000)' ,
            geoInRange( 'doc.geo' , '@center' , 1000 , 5000 )
        );
    }

    public function testGeoInRangeWithFlags(): void
    {
        $this->assertSame
        (
            'GEO_IN_RANGE(doc.geo,@center,1000,5000,false,true)' ,
            geoInRange( 'doc.geo' , '@center' , 1000 , 5000 , false , true )
        );
    }

    public function testIsInPolygon(): void
    {
        $this->assertSame
        (
            'IS_IN_POLYGON(@area,doc.geo.latitude,doc.geo.longitude)' ,
            isInPolygon( '@area' , 'doc.geo.latitude' , 'doc.geo.longitude' )
        );
    }

    // ---- Legacy collection functions

    public function testNearWithLimitAndDistanceName(): void
    {
        $this->assertSame
        (
            'NEAR(places,48.8566,2.3522,10,"distance")' ,
            near( 'places' , 48.8566 , 2.3522 , 10 , 'distance' )
        );
    }

    public function testNearMinimal(): void
    {
        $this->assertSame( 'NEAR(places,48.8566,2.3522)' , near( 'places' , 48.8566 , 2.3522 ) );
    }

    public function testWithinWithDistanceName(): void
    {
        $this->assertSame
        (
            'WITHIN(places,48.8566,2.3522,5000,"distance")' ,
            within( 'places' , 48.8566 , 2.3522 , 5000 , 'distance' )
        );
    }

    public function testWithinMinimal(): void
    {
        $this->assertSame( 'WITHIN(places,48.8566,2.3522,5000)' , within( 'places' , 48.8566 , 2.3522 , 5000 ) );
    }

    public function testWithinRectangle(): void
    {
        $this->assertSame
        (
            'WITHIN_RECTANGLE(places,48.8,2.25,48.9,2.4)' ,
            withinRectangle( 'places' , 48.80 , 2.25 , 48.90 , 2.40 )
        );
    }
}
