<?php

namespace tests\oihana\arango\clients\analyzer\enums ;

use oihana\arango\clients\analyzer\enums\CaseFolding ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see CaseFolding} — the `case` property vocabulary of the
 * `norm` and `text` analyzers.
 */
#[CoversClass( CaseFolding::class )]
class CaseFoldingTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'lower' , CaseFolding::LOWER ) ;
        $this->assertSame( 'none'  , CaseFolding::NONE  ) ;
        $this->assertSame( 'upper' , CaseFolding::UPPER ) ;
    }

    public function testEnumsContainsEveryStrategy() :void
    {
        $enums = CaseFolding::enums() ;

        $this->assertCount( 3 , $enums ) ;
        $this->assertContains( CaseFolding::LOWER , $enums ) ;
        $this->assertContains( CaseFolding::NONE  , $enums ) ;
        $this->assertContains( CaseFolding::UPPER , $enums ) ;
    }

    public function testIncludesRecognisesStrategiesAndRejectsOthers() :void
    {
        $this->assertTrue ( CaseFolding::includes( 'lower' ) ) ;
        $this->assertTrue ( CaseFolding::includes( 'none'  ) ) ;
        $this->assertTrue ( CaseFolding::includes( 'upper' ) ) ;

        $this->assertFalse( CaseFolding::includes( 'title' ) ) ;
    }
}
