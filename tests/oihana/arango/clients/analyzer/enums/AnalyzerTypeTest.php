<?php

namespace tests\oihana\arango\clients\analyzer\enums ;

use oihana\arango\clients\analyzer\enums\AnalyzerType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see AnalyzerType} — V1 must-have ArangoSearch
 * analyzer type discriminator catalogue.
 */
#[CoversClass( AnalyzerType::class )]
class AnalyzerTypeTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'identity' , AnalyzerType::IDENTITY ) ;
        $this->assertSame( 'norm'     , AnalyzerType::NORM     ) ;
        $this->assertSame( 'stem'     , AnalyzerType::STEM     ) ;
        $this->assertSame( 'text'     , AnalyzerType::TEXT     ) ;
    }

    public function testEnumsContainsAllMustHaveTypes() :void
    {
        $enums = AnalyzerType::enums() ;

        $this->assertContains( AnalyzerType::IDENTITY , $enums ) ;
        $this->assertContains( AnalyzerType::NORM     , $enums ) ;
        $this->assertContains( AnalyzerType::STEM     , $enums ) ;
        $this->assertContains( AnalyzerType::TEXT     , $enums ) ;
    }

    public function testIncludesRecognisesMustHaveAndRejectsOthers() :void
    {
        $this->assertTrue ( AnalyzerType::includes( 'identity' ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'norm'     ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'stem'     ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'text'     ) ) ;

        // V2 types intentionally not exposed yet.
        $this->assertFalse( AnalyzerType::includes( 'ngram'    ) ) ;
        $this->assertFalse( AnalyzerType::includes( 'pipeline' ) ) ;
        $this->assertFalse( AnalyzerType::includes( 'aql'      ) ) ;
    }
}
