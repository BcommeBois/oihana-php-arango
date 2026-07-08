<?php

namespace tests\oihana\arango\db\options\analyzers;

use oihana\arango\clients\analyzer\NormAnalyzer;
use oihana\arango\clients\analyzer\enums\AnalyzerFeature;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see AnalyzerDefinition} — the declarative analyzer unit the
 * lifecycle tooling reasons about.
 *
 * @package tests\oihana\arango\db\options\analyzers
 * @author  Marc Alcaraz
 */
#[CoversClass( AnalyzerDefinition::class )]
final class AnalyzerDefinitionTest extends TestCase
{
    public function testConstructExposesNameOptionsAndFeatures() :void
    {
        $options    = new NormAnalyzer( locale: 'fr.utf-8' , case: 'lower' , accent: false ) ;
        $features   = [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION , AnalyzerFeature::NORM ] ;
        $definition = new AnalyzerDefinition( 'text_fr_custom' , $options , $features ) ;

        $this->assertSame( 'text_fr_custom' , $definition->name ) ;
        $this->assertSame( $options , $definition->options ) ;
        $this->assertSame( $features , $definition->features ) ;
    }

    public function testConstructDefaultsToNoFeatures() :void
    {
        $definition = new AnalyzerDefinition( 'norm_en' , new NormAnalyzer( locale: 'en' ) ) ;

        $this->assertSame( 'norm_en' , $definition->name ) ;
        $this->assertSame( [] , $definition->features ) ;
    }
}
