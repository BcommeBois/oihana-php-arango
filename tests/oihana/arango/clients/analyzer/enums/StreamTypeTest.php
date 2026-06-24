<?php

namespace tests\oihana\arango\clients\analyzer\enums ;

use oihana\arango\clients\analyzer\enums\StreamType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see StreamType} — the `streamType` property vocabulary of the
 * `ngram` analyzer.
 */
#[CoversClass( StreamType::class )]
class StreamTypeTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'binary' , StreamType::BINARY ) ;
        $this->assertSame( 'utf8'   , StreamType::UTF8   ) ;
    }

    public function testEnumsContainsEveryStreamType() :void
    {
        $enums = StreamType::enums() ;

        $this->assertCount( 2 , $enums ) ;
        $this->assertContains( StreamType::BINARY , $enums ) ;
        $this->assertContains( StreamType::UTF8   , $enums ) ;
    }

    public function testIncludesRecognisesStreamTypesAndRejectsOthers() :void
    {
        $this->assertTrue ( StreamType::includes( 'binary' ) ) ;
        $this->assertTrue ( StreamType::includes( 'utf8'   ) ) ;

        $this->assertFalse( StreamType::includes( 'ascii' ) ) ;
    }
}
