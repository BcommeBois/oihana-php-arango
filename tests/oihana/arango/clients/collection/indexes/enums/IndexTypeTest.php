<?php

namespace tests\oihana\arango\clients\collection\indexes\enums ;

use oihana\arango\clients\collection\indexes\enums\IndexType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see IndexType} — canonical type values of the ArangoDB
 * index API.
 */
#[CoversClass( IndexType::class )]
class IndexTypeTest extends TestCase
{
    public function testCanonicalTypeValues() :void
    {
        $this->assertSame( 'edge'         , IndexType::EDGE         ) ;
        $this->assertSame( 'fulltext'     , IndexType::FULLTEXT     ) ;
        $this->assertSame( 'geo'          , IndexType::GEO          ) ;
        $this->assertSame( 'inverted'     , IndexType::INVERTED     ) ;
        $this->assertSame( 'mdi'          , IndexType::MDI          ) ;
        $this->assertSame( 'mdi-prefixed' , IndexType::MDI_PREFIXED ) ;
        $this->assertSame( 'persistent'   , IndexType::PERSISTENT   ) ;
        $this->assertSame( 'primary'      , IndexType::PRIMARY      ) ;
        $this->assertSame( 'ttl'          , IndexType::TTL          ) ;
        $this->assertSame( 'vector'       , IndexType::VECTOR       ) ;
    }

    public function testIncludesRecognisesKnownTypes() :void
    {
        $known =
        [
            'edge' , 'fulltext' , 'geo' , 'inverted' , 'mdi' , 'mdi-prefixed' ,
            'persistent' , 'primary' , 'ttl' , 'vector' ,
        ] ;

        foreach ( $known as $type )
        {
            $this->assertTrue( IndexType::includes( $type ) , "Expected '$type' to be recognised by IndexType" ) ;
        }
        $this->assertFalse( IndexType::includes( 'hash'     ) ) ;
        $this->assertFalse( IndexType::includes( 'skiplist' ) ) ;
        $this->assertFalse( IndexType::includes( 'unknown'  ) ) ;
    }
}
