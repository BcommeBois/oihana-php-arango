<?php

namespace tests\oihana\arango\clients\collection\enums ;

use oihana\arango\clients\collection\enums\CollectionType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see CollectionType} — collection type values defined by
 * the ArangoDB server protocol. Values must match the canonical server
 * constants exactly.
 */
#[CoversClass( CollectionType::class )]
class CollectionTypeTest extends TestCase
{
    public function testCanonicalNumericValues() :void
    {
        $this->assertSame( 2 , CollectionType::DOCUMENT ) ;
        $this->assertSame( 3 , CollectionType::EDGE     ) ;
    }

    public function testIncludesRecognisesKnownValues() :void
    {
        $this->assertTrue ( CollectionType::includes( 2 ) ) ;
        $this->assertTrue ( CollectionType::includes( 3 ) ) ;
        $this->assertFalse( CollectionType::includes( 0 ) ) ;
        $this->assertFalse( CollectionType::includes( 1 ) ) ;
    }
}
