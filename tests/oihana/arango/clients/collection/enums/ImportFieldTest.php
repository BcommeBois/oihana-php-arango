<?php

namespace tests\oihana\arango\clients\collection\enums ;

use oihana\arango\clients\collection\enums\ImportField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ImportField} — response field names emitted by the
 * ArangoDB bulk import endpoint.
 */
#[CoversClass( ImportField::class )]
class ImportFieldTest extends TestCase
{
    public function testCanonicalFieldNames() :void
    {
        $this->assertSame( 'created' , ImportField::CREATED ) ;
        $this->assertSame( 'details' , ImportField::DETAILS ) ;
        $this->assertSame( 'empty'   , ImportField::EMPTY   ) ;
        $this->assertSame( 'errors'  , ImportField::ERRORS  ) ;
        $this->assertSame( 'ignored' , ImportField::IGNORED ) ;
        $this->assertSame( 'updated' , ImportField::UPDATED ) ;
    }

    public function testIncludesRecognisesKnownFields() :void
    {
        foreach ( [ 'created' , 'details' , 'empty' , 'errors' , 'ignored' , 'updated' ] as $field )
        {
            $this->assertTrue( ImportField::includes( $field ) , "Expected '$field' to be recognised by ImportField" ) ;
        }
        $this->assertFalse( ImportField::includes( 'unknown' ) ) ;
    }
}
