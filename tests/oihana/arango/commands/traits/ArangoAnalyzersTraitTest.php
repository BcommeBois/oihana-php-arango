<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\clients\analyzer\IdentityAnalyzer;
use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\commands\traits\ArangoAnalyzersTrait;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Bare host for {@see ArangoAnalyzersTrait}.
 */
class ArangoAnalyzersTraitHost
{
    use ArangoAnalyzersTrait ;
}

/**
 * Unit coverage for {@see ArangoAnalyzersTrait} — the custom-analyzer registry
 * holder and its `getAnalyzerDefinitions()` normalizer (Lot A2).
 *
 * @package tests\oihana\arango\commands\traits
 * @author  Marc Alcaraz
 */
#[CoversTrait( ArangoAnalyzersTrait::class )]
class ArangoAnalyzersTraitTest extends TestCase
{
    private function definition( string $name ) :AnalyzerDefinition
    {
        return new AnalyzerDefinition( $name , new IdentityAnalyzer() ) ;
    }

    public function testDefaultsToAnEmptyList() :void
    {
        $this->assertSame( [] , new ArangoAnalyzersTraitHost()->getAnalyzerDefinitions() ) ;
    }

    public function testKeepsADeclaredList() :void
    {
        $host = new ArangoAnalyzersTraitHost() ;
        $host->analyzers = [ $a = $this->definition( 'a' ) , $b = $this->definition( 'b' ) ] ;

        $this->assertSame( [ $a , $b ] , $host->getAnalyzerDefinitions() ) ;
    }

    public function testNormalizesASingleDefinitionToAList() :void
    {
        $host = new ArangoAnalyzersTraitHost() ;
        $host->analyzers = $single = new AnalyzerDefinition( 'solo' , new TextAnalyzer( locale: 'en' ) ) ;

        $this->assertSame( [ $single ] , $host->getAnalyzerDefinitions() ) ;
    }

    public function testDropsNonDefinitionEntriesAndReindexes() :void
    {
        $host = new ArangoAnalyzersTraitHost() ;
        $host->analyzers = [ 'bogus' , $a = $this->definition( 'a' ) , null ] ;

        $this->assertSame( [ $a ] , $host->getAnalyzerDefinitions() ) ;
    }
}
