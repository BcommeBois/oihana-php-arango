<?php

namespace tests\oihana\arango\clients\analyzer\enums ;

use oihana\arango\clients\analyzer\enums\AnalyzerFeature ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see AnalyzerFeature} — analyzer feature catalogue used
 * by both the analyzer API and the per-field `features` array on
 * inverted indexes / arangosearch views.
 */
#[CoversClass( AnalyzerFeature::class )]
class AnalyzerFeatureTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'frequency' , AnalyzerFeature::FREQUENCY ) ;
        $this->assertSame( 'norm'      , AnalyzerFeature::NORM      ) ;
        $this->assertSame( 'offset'    , AnalyzerFeature::OFFSET    ) ;
        $this->assertSame( 'position'  , AnalyzerFeature::POSITION  ) ;
    }

    public function testEnumsContainsAllFeatures() :void
    {
        $enums = AnalyzerFeature::enums() ;

        $this->assertContains( AnalyzerFeature::FREQUENCY , $enums ) ;
        $this->assertContains( AnalyzerFeature::NORM      , $enums ) ;
        $this->assertContains( AnalyzerFeature::OFFSET    , $enums ) ;
        $this->assertContains( AnalyzerFeature::POSITION  , $enums ) ;
    }

    public function testIncludesRecognisesKnownAndRejectsUnknown() :void
    {
        $this->assertTrue ( AnalyzerFeature::includes( 'frequency' ) ) ;
        $this->assertTrue ( AnalyzerFeature::includes( 'norm'      ) ) ;
        $this->assertTrue ( AnalyzerFeature::includes( 'offset'    ) ) ;
        $this->assertTrue ( AnalyzerFeature::includes( 'position'  ) ) ;
        $this->assertFalse( AnalyzerFeature::includes( 'unknown'   ) ) ;
    }
}
