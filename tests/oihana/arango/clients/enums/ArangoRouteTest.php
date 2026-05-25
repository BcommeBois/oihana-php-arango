<?php

namespace tests\oihana\arango\clients\enums ;

use oihana\arango\clients\enums\ArangoRoute ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ArangoRoute} — single source of truth for the
 * ArangoDB HTTP route prefixes consumed by the client.
 */
#[CoversClass( ArangoRoute::class )]
class ArangoRouteTest extends TestCase
{
    public function testCanonicalRouteValues() :void
    {
        $this->assertSame( '/_admin/server/availability' , ArangoRoute::ADMIN_AVAILABILITY ) ;
        $this->assertSame( '/_admin/time'                , ArangoRoute::ADMIN_TIME         ) ;
        $this->assertSame( '/_api/analyzer'              , ArangoRoute::ANALYZER           ) ;
        $this->assertSame( '/_api/collection'            , ArangoRoute::COLLECTION         ) ;
        $this->assertSame( '/_api/cursor'                , ArangoRoute::CURSOR             ) ;
        $this->assertSame( '/_api/database'              , ArangoRoute::DATABASE           ) ;
        $this->assertSame( '/_api/document'              , ArangoRoute::DOCUMENT           ) ;
        $this->assertSame( '/_api/explain'               , ArangoRoute::EXPLAIN            ) ;
        $this->assertSame( '/_api/gharial'               , ArangoRoute::GHARIAL            ) ;
        $this->assertSame( '/_api/import'                , ArangoRoute::IMPORT             ) ;
        $this->assertSame( '/_api/index'                 , ArangoRoute::INDEX              ) ;
        $this->assertSame( '/_open/auth'                 , ArangoRoute::OPEN_AUTH          ) ;
        $this->assertSame( '/_api/query'                 , ArangoRoute::QUERY              ) ;
        $this->assertSame( '/_api/transaction'           , ArangoRoute::TRANSACTION        ) ;
        $this->assertSame( '/_api/version'               , ArangoRoute::VERSION            ) ;
        $this->assertSame( '/_api/view'                  , ArangoRoute::VIEW               ) ;
    }

    public function testIncludesRecognisesKnownRoutes() :void
    {
        foreach
        ( [
            '/_admin/server/availability' ,
            '/_admin/time' ,
            '/_api/analyzer' ,
            '/_api/collection' ,
            '/_api/cursor' ,
            '/_api/database' ,
            '/_api/document' ,
            '/_api/explain' ,
            '/_api/gharial' ,
            '/_api/import' ,
            '/_api/index' ,
            '/_open/auth' ,
            '/_api/query' ,
            '/_api/transaction' ,
            '/_api/version' ,
            '/_api/view' ,
        ] as $route )
        {
            $this->assertTrue
            (
                ArangoRoute::includes( $route ) ,
                "Expected '$route' to be recognised by ArangoRoute" ,
            ) ;
        }

        $this->assertFalse( ArangoRoute::includes( '/_api/unknown' ) ) ;
        $this->assertFalse( ArangoRoute::includes( '/_db/'          ) ) ;
    }

    public function testEveryRouteStartsWithLeadingSlash() :void
    {
        $reflection = new \ReflectionClass( ArangoRoute::class ) ;

        foreach ( $reflection->getReflectionConstants() as $constant )
        {
            if ( !$constant->isPublic() )
            {
                continue ;
            }
            $value = $constant->getValue() ;
            $this->assertIsString( $value ) ;
            $this->assertStringStartsWith
            (
                '/' ,
                $value ,
                sprintf( 'ArangoRoute::%s must start with a leading slash.' , $constant->getName() ) ,
            ) ;
        }
    }

    public function testRoutesAreUnique() :void
    {
        $values     = array_values( ArangoRoute::getAll() ) ;
        $duplicates = array_diff_assoc( $values , array_unique( $values ) ) ;

        $this->assertSame
        (
            [] ,
            $duplicates ,
            'ArangoRoute must not contain duplicate route values: ' . implode( ', ' , $duplicates ) ,
        ) ;
    }
}
