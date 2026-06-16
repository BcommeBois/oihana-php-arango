<?php

namespace tests\oihana\arango\integration;

use Throwable;

use Devium\Toml\TomlError;

use Psr\Log\NullLogger;

use oihana\arango\clients\analyzer\enums\AnalyzerFeature;
use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the analyzer diff & safe-sync façade (Lot A1a): a
 * declared custom analyzer is reported MISSING then created by
 * `analyzerSync()`, an unchanged declaration round-trips IN_SYNC, a changed
 * declaration is reported DRIFTED **but left untouched** (an analyzer is
 * immutable — repairing it is a deliberate operation), and the Views that
 * reference it are listed by `analyzerDependentViews()`.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class AnalyzerSyncIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_analyzersync_it' ;

    private const string ANALYZER = 'it_az' ;

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
     * The stemmed declaration of the fixtures.
     *
     * @param bool $stemming
     *
     * @return AnalyzerDefinition
     */
    private function definition( bool $stemming = true ) :AnalyzerDefinition
    {
        return new AnalyzerDefinition
        (
            self::ANALYZER ,
            new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: $stemming ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ;
    }

    /**
     * The full A1a lifecycle on a real server.
     *
     * @throws ArangoException
     * @throws TomlError
     * @throws Throwable
     */
    public function testAnalyzerDiffAndSafeSyncLifecycle() :void
    {
        $arango = $this->arango() ;

        // 1 — missing, then created by sync.

        $this->assertSame( DiffStatus::MISSING , $arango->analyzerDiff( $this->definition() )->status ) ;

        $report = $arango->analyzerSync( $this->definition() ) ;
        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertTrue( $report->applied ) ;
        $this->assertTrue( $arango->analyzerExists( self::ANALYZER ) ) ;

        // 2 — an unchanged declaration is in sync, and a second sync is a no-op.

        $this->assertSame( DiffStatus::IN_SYNC , $arango->analyzerDiff( $this->definition() )->status ) ;

        $report = $arango->analyzerSync( $this->definition() ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertFalse( $report->applied ) ;

        // 3 — a changed declaration drifts, and the safe sync leaves it untouched.

        $report = $arango->analyzerDiff( $this->definition( stemming: false ) ) ;
        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( self::ANALYZER . ' : drop + recreate required (an analyzer is immutable)' , $report->changes ) ;

        $report = $arango->analyzerSync( $this->definition( stemming: false ) ) ;
        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertFalse( $report->applied , 'A drifted analyzer must NOT be repaired by the safe sync.' ) ;

        // the server analyzer is still the original (stemmed) one.
        $server = $arango->database()->analyzer( self::ANALYZER )->get() ;
        $this->assertTrue( $server[ 'properties' ][ 'stemming' ] ?? null ) ;
    }

    /**
     * A View referencing the analyzer is listed as a dependent, and the drift
     * report names it (the cascade hint).
     *
     * @throws ArangoException
     * @throws TomlError
     * @throws Throwable
     */
    public function testDependentViewsAreListedInTheDrift() :void
    {
        $arango = $this->arango() ;
        $db     = $arango->database() ;

        $arango->analyzerSync( $this->definition() ) ; // ensure it exists

        $db->collection( 'things' )->create() ;
        $arango->viewCreate( 'azView' ,
        [
            'things' => new ArangoSearchLink( fields : [ 'title' => new ArangoSearchLink( analyzers : [ self::ANALYZER ] ) ] ) ,
        ]) ;

        $this->assertSame( [ 'azView' ] , $arango->analyzerDependentViews( self::ANALYZER ) ) ;

        $report = $arango->analyzerDiff( $this->definition( stemming: false ) ) ;
        $this->assertSame( DiffStatus::DRIFTED , $report->status ) ;
        $this->assertContains( self::ANALYZER . ' : referenced by view(s) azView — they must be rebuilt after the recreate' , $report->changes ) ;
    }
}
