<?php

namespace tests\oihana\arango\clients\view\enums ;

use oihana\arango\clients\view\enums\Compression ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Compression} — the compression vocabulary of an
 * ArangoSearch view (`primarySortCompression`, `storedValues`).
 */
#[CoversClass( Compression::class )]
class CompressionTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'lz4'  , Compression::LZ4  ) ;
        $this->assertSame( 'none' , Compression::NONE ) ;
    }

    public function testEnumsContainsEveryStrategy() :void
    {
        $enums = Compression::enums() ;

        $this->assertCount( 2 , $enums ) ;
        $this->assertContains( Compression::LZ4  , $enums ) ;
        $this->assertContains( Compression::NONE , $enums ) ;
    }

    public function testIncludesRecognisesStrategiesAndRejectsOthers() :void
    {
        $this->assertTrue ( Compression::includes( 'lz4'  ) ) ;
        $this->assertTrue ( Compression::includes( 'none' ) ) ;

        $this->assertFalse( Compression::includes( 'zstd' ) ) ;
    }
}
