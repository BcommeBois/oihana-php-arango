<?php

namespace tests\oihana\arango\integration;

use Throwable;

use Devium\Toml\TomlError;

use Psr\Log\NullLogger;

use oihana\arango\clients\analyzer\NgramAnalyzer;
use oihana\arango\clients\analyzer\NormAnalyzer;
use oihana\arango\clients\analyzer\PipelineAnalyzer;
use oihana\arango\clients\analyzer\RawAnalyzer;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the typed {@see PipelineAnalyzer} round-trip — the proof,
 * on a real server, that a `norm` → `ngram` pipeline declared **without** its
 * sub-analyzer defaults does not show up as a permanent false drift.
 *
 * When the server reads a pipeline back it fills every sub-analyzer default
 * (`norm` → `case` / `accent`, `ngram` → `startMarker` / `endMarker` /
 * `streamType`); the declaration-oriented `comparePipeline()` comparison must
 * ignore those, so a freshly created pipeline immediately reports IN_SYNC.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class PipelineAnalyzerSyncIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_pipeline_it' ;

    private const string ANALYZER = 'it_autocomplete' ;

    /**
     * An {@see ArangoDB} façade wired to the disposable database.
     *
     * @throws TomlError
     * @throws Throwable
     */
    private function arango() :ArangoDB
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        return new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;
    }

    /**
     * The declared `norm` → `ngram` autocomplete pipeline — each sub-analyzer
     * carries only the properties we set, none of the server defaults.
     *
     * @return AnalyzerDefinition
     */
    private function definition() :AnalyzerDefinition
    {
        return new AnalyzerDefinition
        (
            self::ANALYZER ,
            new PipelineAnalyzer
            ([
                new NormAnalyzer ( locale: 'fr' , case: 'lower' , accent: false ) ,
                new NgramAnalyzer( min: 3 , max: 5 , preserveOriginal: true ) ,
            ]) ,
        ) ;
    }

    /**
     * Missing → created by sync → IN_SYNC on the next diff (no false drift on
     * the sub-analyzer defaults the server fills in).
     *
     * @throws ArangoException
     * @throws TomlError
     * @throws Throwable
     */
    public function testPipelineRoundTripsWithoutFalseDrift() :void
    {
        $arango = $this->arango() ;

        // 1 — missing, then created.
        $this->assertSame( DiffStatus::MISSING , $arango->analyzerDiff( $this->definition() )->status ) ;

        $report = $arango->analyzerSync( $this->definition() ) ;
        $this->assertTrue( $report->applied ) ;
        $this->assertTrue( $arango->analyzerExists( self::ANALYZER ) ) ;

        // 2 — the same declaration, re-diffed against the server (which now
        //     reports every sub-analyzer default), is IN_SYNC : no false drift.
        $report = $arango->analyzerDiff( $this->definition() ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $report->status , 'The created pipeline drifted on its own server-filled defaults.' ) ;
        $this->assertSame( [] , $report->changes ) ;

        // 3 — sanity : the server really did fill the ngram defaults the
        //     declaration never mentioned.
        $server = $arango->database()->analyzer( self::ANALYZER )->get() ;
        $ngram  = $server[ 'properties' ][ 'pipeline' ][ 1 ][ 'properties' ] ?? [] ;
        $this->assertArrayHasKey( 'streamType' , $ngram , 'The server is expected to fill the ngram defaults.' ) ;
    }

    /**
     * A {@see RawAnalyzer} rebuilt from the server dump (all defaults present)
     * is also a valid round-trip : re-diffing the dumped pipeline is IN_SYNC.
     *
     * @throws ArangoException
     * @throws TomlError
     * @throws Throwable
     */
    public function testRawAnalyzerDumpOfThePipelineRoundTrips() :void
    {
        $arango = $this->arango() ;
        $arango->analyzerSync( $this->definition() ) ; // ensure it exists

        $server     = $arango->database()->analyzer( self::ANALYZER )->get() ;
        $properties = is_array( $server[ 'properties' ] ?? null ) ? $server[ 'properties' ] : [] ;

        $dumped = new AnalyzerDefinition( self::ANALYZER , new RawAnalyzer( 'pipeline' , $properties ) ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $arango->analyzerDiff( $dumped )->status ) ;
    }
}
