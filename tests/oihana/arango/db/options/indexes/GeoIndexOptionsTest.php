<?php

namespace tests\oihana\arango\db\options\indexes;

use ReflectionException;

use oihana\arango\db\options\indexes\GeoIndexOptions;
use oihana\arango\db\options\indexes\IndexOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a geo-spatial index declaration sends to the server.
 */
#[CoversClass( GeoIndexOptions::class )]
final class GeoIndexOptionsTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testTheDefaults() :void
    {
        $options = new GeoIndexOptions( [ IndexOptions::NAME => 'where' , IndexOptions::FIELDS => [ 'location' ] ] ) ;

        $this->assertSame
        (
            [ 'fields' => [ 'location' ] , 'geoJson' => false , 'inBackground' => false , 'name' => 'where' , 'type' => 'geo' ] ,
            $options->jsonSerialize()
        ) ;
    }

    /**
     * 🚨 `legacyPolygons` used to be a constant with no property behind it : declared,
     * it was dropped in silence. It is sent when declared, and only then.
     *
     * @throws ReflectionException
     */
    public function testTheLegacyPolygonsAreSentWhenDeclared() :void
    {
        $options = new GeoIndexOptions
        ([
            IndexOptions::FIELDS          => [ 'area' ] ,
            IndexOptions::GEO_JSON        => true ,
            IndexOptions::LEGACY_POLYGONS => true ,
        ]) ;

        $body = $options->jsonSerialize() ;

        $this->assertTrue( $body[ 'geoJson'        ] ) ;
        $this->assertTrue( $body[ 'legacyPolygons' ] ) ;
    }
}
