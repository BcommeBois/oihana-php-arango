<?php

namespace tests\oihana\arango\clients\collection\enums ;

use oihana\arango\clients\collection\enums\CollectionField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see CollectionField} — JSON field names exchanged with the
 * ArangoDB collection-management API.
 */
#[CoversClass( CollectionField::class )]
class CollectionFieldTest extends TestCase
{
    public function testCanonicalFieldNames() :void
    {
        $this->assertSame( 'count'    , CollectionField::COUNT     ) ;
        $this->assertSame( 'isSystem' , CollectionField::IS_SYSTEM ) ;
        $this->assertSame( 'name'     , CollectionField::NAME      ) ;
        $this->assertSame( 'result'   , CollectionField::RESULT    ) ;
        $this->assertSame( 'type'     , CollectionField::TYPE      ) ;
    }

    public function testIncludesRecognisesKnownFields() :void
    {
        foreach ( [ 'count' , 'isSystem' , 'name' , 'result' , 'type' ] as $field )
        {
            $this->assertTrue( CollectionField::includes( $field ) , "Expected '$field' to be recognised by CollectionField" ) ;
        }
        $this->assertFalse( CollectionField::includes( 'unknown' ) ) ;
    }
}
