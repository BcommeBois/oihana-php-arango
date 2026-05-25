<?php

namespace tests\oihana\arango\clients\enums ;

use oihana\arango\clients\enums\ConnectionMode ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ConnectionMode} — the HTTP connection persistence catalogue.
 *
 * Values must match the canonical `Connection` HTTP header values so that
 * the enumeration can be used directly as a header builder by the transport
 * layer.
 */
#[CoversClass( ConnectionMode::class )]
class ConnectionModeTest extends TestCase
{
    public function testEnumsContainsAllModes() :void
    {
        $enums = ConnectionMode::enums() ;

        $this->assertContains( ConnectionMode::CLOSE      , $enums ) ;
        $this->assertContains( ConnectionMode::KEEP_ALIVE , $enums ) ;
    }

    public function testIncludesKnownValuesAndRejectsUnknown() :void
    {
        $this->assertTrue ( ConnectionMode::includes( 'Close'      ) ) ;
        $this->assertTrue ( ConnectionMode::includes( 'Keep-Alive' ) ) ;
        $this->assertFalse( ConnectionMode::includes( 'close'      ) ) ; // case-sensitive
        $this->assertFalse( ConnectionMode::includes( 'keep-alive' ) ) ;
    }

    public function testConstantValuesMatchHttpHeaderSpelling() :void
    {
        $this->assertSame( 'Close'      , ConnectionMode::CLOSE      ) ;
        $this->assertSame( 'Keep-Alive' , ConnectionMode::KEEP_ALIVE ) ;
    }
}
