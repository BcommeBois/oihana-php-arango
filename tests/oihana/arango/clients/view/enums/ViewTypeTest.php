<?php

namespace tests\oihana\arango\clients\view\enums ;

use oihana\arango\clients\view\enums\ViewType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ViewType} — V1 must-have view type discriminator.
 */
#[CoversClass( ViewType::class )]
class ViewTypeTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'arangosearch' , ViewType::ARANGOSEARCH ) ;
    }

    public function testEnumsContainsArangosearch() :void
    {
        $this->assertContains( ViewType::ARANGOSEARCH , ViewType::enums() ) ;
    }

    public function testIncludesRecognisesArangosearchAndRejectsV2Types() :void
    {
        $this->assertTrue ( ViewType::includes( 'arangosearch' ) ) ;
        // V2 type intentionally not exposed yet.
        $this->assertFalse( ViewType::includes( 'search-alias' ) ) ;
    }
}
