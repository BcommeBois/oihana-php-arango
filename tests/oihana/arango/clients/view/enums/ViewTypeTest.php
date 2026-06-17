<?php

namespace tests\oihana\arango\clients\view\enums ;

use oihana\arango\clients\view\enums\ViewType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ViewType} — view type discriminator (arangosearch + search-alias).
 */
#[CoversClass( ViewType::class )]
class ViewTypeTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'arangosearch' , ViewType::ARANGOSEARCH ) ;
        $this->assertSame( 'search-alias' , ViewType::SEARCH_ALIAS ) ;
    }

    public function testEnumsContainsBothTypes() :void
    {
        $this->assertContains( ViewType::ARANGOSEARCH , ViewType::enums() ) ;
        $this->assertContains( ViewType::SEARCH_ALIAS , ViewType::enums() ) ;
    }

    public function testIncludesRecognisesBothTypes() :void
    {
        $this->assertTrue ( ViewType::includes( 'arangosearch' ) ) ;
        $this->assertTrue ( ViewType::includes( 'search-alias' ) ) ;
        $this->assertFalse( ViewType::includes( 'bogus' ) ) ;
    }
}
