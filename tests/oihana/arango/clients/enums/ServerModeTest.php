<?php

namespace tests\oihana\arango\clients\enums ;

use oihana\arango\clients\enums\ServerMode ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ServerMode} — server modes reported by the
 * ArangoDB availability endpoint.
 */
#[CoversClass( ServerMode::class )]
class ServerModeTest extends TestCase
{
    public function testCanonicalValues() :void
    {
        $this->assertSame( 'default'  , ServerMode::DEFAULT  ) ;
        $this->assertSame( 'readonly' , ServerMode::READONLY ) ;
    }

    public function testIncludesRecognisesKnownModes() :void
    {
        $this->assertTrue ( ServerMode::includes( 'default'  ) ) ;
        $this->assertTrue ( ServerMode::includes( 'readonly' ) ) ;
        $this->assertFalse( ServerMode::includes( 'unknown'  ) ) ;
    }
}
