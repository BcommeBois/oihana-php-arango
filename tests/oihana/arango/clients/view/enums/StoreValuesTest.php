<?php

namespace tests\oihana\arango\clients\view\enums ;

use oihana\arango\clients\view\enums\StoreValues ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see StoreValues} — per-link storeValues strategy
 * catalogue.
 */
#[CoversClass( StoreValues::class )]
class StoreValuesTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'id'   , StoreValues::ID   ) ;
        $this->assertSame( 'none' , StoreValues::NONE ) ;
    }

    public function testEnumsContainsBothStrategies() :void
    {
        $enums = StoreValues::enums() ;

        $this->assertContains( StoreValues::ID   , $enums ) ;
        $this->assertContains( StoreValues::NONE , $enums ) ;
    }

    public function testIncludesRecognisesKnownAndRejectsUnknown() :void
    {
        $this->assertTrue ( StoreValues::includes( 'id'   ) ) ;
        $this->assertTrue ( StoreValues::includes( 'none' ) ) ;
        $this->assertFalse( StoreValues::includes( 'full' ) ) ;
    }
}
