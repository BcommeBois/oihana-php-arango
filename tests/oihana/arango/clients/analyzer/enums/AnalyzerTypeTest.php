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
        $this->assertSame( 'ngram'    , AnalyzerType::NGRAM    ) ;
        $this->assertSame( 'norm'     , AnalyzerType::NORM     ) ;
        $this->assertSame( 'stem'     , AnalyzerType::STEM     ) ;
        $this->assertSame( 'text'     , AnalyzerType::TEXT     ) ;
    }

    public function testEnumsContainsAllExposedTypes() :void
    {
        $enums = AnalyzerType::enums() ;

        $this->assertContains( AnalyzerType::IDENTITY , $enums ) ;
        $this->assertContains( AnalyzerType::NGRAM    , $enums ) ;
        $this->assertContains( AnalyzerType::NORM     , $enums ) ;
        $this->assertContains( AnalyzerType::STEM     , $enums ) ;
        $this->assertContains( AnalyzerType::TEXT     , $enums ) ;
    }

    public function testIncludesRecognisesExposedAndRejectsOthers() :void
    {
        $this->assertTrue ( AnalyzerType::includes( 'identity' ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'ngram'    ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'norm'     ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'stem'     ) ) ;
        $this->assertTrue ( AnalyzerType::includes( 'text'     ) ) ;

        // Types still deferred to a later follow-up.
        $this->assertFalse( AnalyzerType::includes( 'pipeline' ) ) ;
        $this->assertFalse( AnalyzerType::includes( 'aql'      ) ) ;
    }
}
