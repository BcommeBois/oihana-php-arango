<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\GeoIndex ;
use oihana\arango\clients\collection\indexes\IndexDefinition ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see GeoIndex} — value object for the geospatial index.
 */
#[CoversClass( GeoIndex::class )]
class GeoIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf( IndexDefinition::class , new GeoIndex( fields : [ 'location' ] ) ) ;
    }

    public function testMinimalIndexCarriesOnlyTypeAndFields() :void
    {
        $payload = ( new GeoIndex( fields : [ 'location' ] ) )->toArray() ;

        $this->assertSame
        (
            [ 'type' => 'geo' , 'fields' => [ 'location' ] ] ,
            $payload ,
        ) ;
    }

    public function testGeoJsonIsEmittedWhenSet() :void
    {
        $payload = ( new GeoIndex( fields : [ 'location' ] , geoJson : true ) )->toArray() ;

        $this->assertTrue( $payload[ 'geoJson' ] ) ;
    }

    public function testTwoFieldsLatLngForm() :void
    {
        $payload = ( new GeoIndex( fields : [ 'lat' , 'lng' ] ) )->toArray() ;

        $this->assertSame( [ 'lat' , 'lng' ] , $payload[ 'fields' ] ) ;
        $this->assertArrayNotHasKey( 'geoJson' , $payload ) ;
    }

    public function testNameAndInBackgroundAreEmittedWhenSet() :void
    {
        $payload = ( new GeoIndex
        (
            fields       : [ 'location' ] ,
            name         : 'idx_geo' ,
            inBackground : true ,
        ) )->toArray() ;

        $this->assertSame( 'idx_geo' , $payload[ 'name' ] ) ;
        $this->assertTrue( $payload[ 'inBackground' ] ) ;
    }
}
