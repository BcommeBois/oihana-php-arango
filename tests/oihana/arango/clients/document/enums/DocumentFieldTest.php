<?php

namespace tests\oihana\arango\clients\document\enums ;

use oihana\arango\clients\document\enums\DocumentField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see DocumentField} — optional payload fields returned by
 * the ArangoDB document API when `returnNew` / `returnOld` is requested.
 */
#[CoversClass( DocumentField::class )]
class DocumentFieldTest extends TestCase
{
    public function testCanonicalFieldNames() :void
    {
        $this->assertSame( 'new' , DocumentField::NEW ) ;
        $this->assertSame( 'old' , DocumentField::OLD ) ;
    }

    public function testIncludesRecognisesKnownFields() :void
    {
        $this->assertTrue ( DocumentField::includes( 'new'     ) ) ;
        $this->assertTrue ( DocumentField::includes( 'old'     ) ) ;
        $this->assertFalse( DocumentField::includes( 'unknown' ) ) ;
    }
}
