<?php

namespace tests\oihana\arango\clients\collection\enums ;

use oihana\arango\clients\collection\enums\CollectionRoute ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see CollectionRoute} — URI suffixes appended to
 * `/_api/collection/{name}` for collection sub-resources.
 */
#[CoversClass( CollectionRoute::class )]
class CollectionRouteTest extends TestCase
{
    public function testCanonicalSuffixesStartWithSlash() :void
    {
        $this->assertSame( '/count'      , CollectionRoute::COUNT      ) ;
        $this->assertSame( '/properties' , CollectionRoute::PROPERTIES ) ;
        $this->assertSame( '/rename'     , CollectionRoute::RENAME     ) ;
        $this->assertSame( '/truncate'   , CollectionRoute::TRUNCATE   ) ;
    }

    public function testIncludesRecognisesKnownSuffixes() :void
    {
        foreach ( [ '/count' , '/properties' , '/rename' , '/truncate' ] as $suffix )
        {
            $this->assertTrue( CollectionRoute::includes( $suffix ) , "Expected '$suffix' to be recognised by CollectionRoute" ) ;
        }
        $this->assertFalse( CollectionRoute::includes( '/unknown' ) ) ;
    }
}
