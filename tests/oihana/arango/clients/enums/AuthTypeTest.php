<?php

namespace tests\oihana\arango\clients\enums ;

use oihana\arango\clients\enums\AuthType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see AuthType} — the authentication scheme catalogue.
 */
#[CoversClass( AuthType::class )]
class AuthTypeTest extends TestCase
{
    public function testEnumsContainsAllSchemes() :void
    {
        $enums = AuthType::enums() ;

        $this->assertContains( AuthType::BASIC , $enums ) ;
        $this->assertContains( AuthType::JWT   , $enums ) ;
    }

    public function testIncludesKnownValuesAndRejectsUnknown() :void
    {
        $this->assertTrue ( AuthType::includes( 'Basic' ) ) ;
        $this->assertTrue ( AuthType::includes( 'JWT'   ) ) ;
        $this->assertFalse( AuthType::includes( 'OAuth' ) ) ;
    }

    public function testConstantValuesMatchCanonicalSpelling() :void
    {
        $this->assertSame( 'Basic' , AuthType::BASIC ) ;
        $this->assertSame( 'JWT'   , AuthType::JWT   ) ;
    }
}
