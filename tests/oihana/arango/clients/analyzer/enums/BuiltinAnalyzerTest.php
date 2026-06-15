<?php

namespace tests\oihana\arango\clients\analyzer\enums ;

use oihana\arango\clients\analyzer\enums\BuiltinAnalyzer ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see BuiltinAnalyzer} — the catalogue of server-provided
 * ArangoSearch analyzer names (`identity`, `text_*`).
 */
#[CoversClass( BuiltinAnalyzer::class )]
class BuiltinAnalyzerTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'identity' , BuiltinAnalyzer::IDENTITY ) ;
        $this->assertSame( 'text_de'  , BuiltinAnalyzer::TEXT_DE  ) ;
        $this->assertSame( 'text_en'  , BuiltinAnalyzer::TEXT_EN  ) ;
        $this->assertSame( 'text_es'  , BuiltinAnalyzer::TEXT_ES  ) ;
        $this->assertSame( 'text_fi'  , BuiltinAnalyzer::TEXT_FI  ) ;
        $this->assertSame( 'text_fr'  , BuiltinAnalyzer::TEXT_FR  ) ;
        $this->assertSame( 'text_it'  , BuiltinAnalyzer::TEXT_IT  ) ;
        $this->assertSame( 'text_nl'  , BuiltinAnalyzer::TEXT_NL  ) ;
        $this->assertSame( 'text_no'  , BuiltinAnalyzer::TEXT_NO  ) ;
        $this->assertSame( 'text_pt'  , BuiltinAnalyzer::TEXT_PT  ) ;
        $this->assertSame( 'text_ru'  , BuiltinAnalyzer::TEXT_RU  ) ;
        $this->assertSame( 'text_sv'  , BuiltinAnalyzer::TEXT_SV  ) ;
        $this->assertSame( 'text_zh'  , BuiltinAnalyzer::TEXT_ZH  ) ;
    }

    public function testEnumsContainsEveryBuiltin() :void
    {
        $enums = BuiltinAnalyzer::enums() ;

        $this->assertCount( 13 , $enums ) ;

        $this->assertContains( BuiltinAnalyzer::IDENTITY , $enums ) ;
        $this->assertContains( BuiltinAnalyzer::TEXT_FR  , $enums ) ;
        $this->assertContains( BuiltinAnalyzer::TEXT_ZH  , $enums ) ;
    }

    public function testIncludesRecognisesBuiltinsAndRejectsCustomNames() :void
    {
        $this->assertTrue ( BuiltinAnalyzer::includes( 'identity' ) ) ;
        $this->assertTrue ( BuiltinAnalyzer::includes( 'text_fr'  ) ) ;
        $this->assertTrue ( BuiltinAnalyzer::includes( 'text_en'  ) ) ;

        // Custom / unknown analyzer names are not built-ins.
        $this->assertFalse( BuiltinAnalyzer::includes( 'text_en_custom' ) ) ;
        $this->assertFalse( BuiltinAnalyzer::includes( 'stem_en'        ) ) ;
        $this->assertFalse( BuiltinAnalyzer::includes( 'text_jp'        ) ) ;
    }
}
