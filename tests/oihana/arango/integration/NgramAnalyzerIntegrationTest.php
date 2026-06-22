<?php

namespace tests\oihana\arango\integration;

use Throwable;

use Devium\Toml\TomlError;

use Psr\Log\NullLogger;

use oihana\arango\clients\analyzer\enums\AnalyzerFeature;
use oihana\arango\clients\analyzer\NgramAnalyzer;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the `ngram` analyzer (WS2): a declared n-gram analyzer
 * is created by `analyzerSync()` and round-trips `IN_SYNC` (proving the diff
 * façade handles the n-gram `properties` with no false drift), and a View
 * indexing a field through it answers a substring / "as-you-type" query — a
 * partial term (`ate`) matches a longer value (`atelier…`).
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class NgramAnalyzerIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_ngram_it' ;

    private const string ANALYZER = 'it_ngram' ;

    private const string VIEW = 'ngramView' ;

    private const string COLLECTION = 'ngdocs' ;

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
     * The n-gram analyzer declaration (2–5 chars, original kept).
     *
     * @return AnalyzerDefinition
     */
    private function definition() :AnalyzerDefinition
    {
        return new AnalyzerDefinition
        (
            self::ANALYZER ,
            new NgramAnalyzer( min: 2 , max: 5 , preserveOriginal: true , streamType: 'utf8' ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ;
    }

    /**
     * The n-gram analyzer is created, round-trips IN_SYNC (no false drift on
     * its properties), and powers a substring search end to end.
     *
     * @throws ArangoException
     * @throws TomlError
     * @throws Throwable
     */
    public function testNgramLifecycleAndAutocompleteSearch() :void
    {
        $arango = $this->arango() ;
        $db     = $arango->database() ;

        // 1 — missing, then created by sync.
        $this->assertSame( DiffStatus::MISSING , $arango->analyzerDiff( $this->definition() )->status ) ;

        $report = $arango->analyzerSync( $this->definition() ) ;
        $this->assertTrue( $report->applied ) ;
        $this->assertTrue( $arango->analyzerExists( self::ANALYZER ) ) ;

        // 2 — the n-gram properties round-trip with no false drift.
        $this->assertSame
        (
            DiffStatus::IN_SYNC ,
            $arango->analyzerDiff( $this->definition() )->status ,
            'A re-declared n-gram analyzer must compare IN_SYNC (no false drift on its properties).'
        ) ;

        // 3 — a field indexed through the n-gram analyzer answers a substring query.
        $db->collection( self::COLLECTION )->create() ;
        $arango->viewCreate( self::VIEW ,
        [
            self::COLLECTION => new ArangoSearchLink( fields : [ 'title' => new ArangoSearchLink( analyzers : [ self::ANALYZER ] ) ] ) ,
        ]) ;
        $db->collection( self::COLLECTION )->insert( [ 'title' => 'atelier du bois' ] ) ;
        $db->collection( self::COLLECTION )->insert( [ 'title' => 'ferronnerie' ] ) ;

        // 'ate' is a substring of 'atelier' → matches through the n-grams.
        $this->waitForSearch( $arango , 'ate' , [ 'atelier du bois' ] ) ;

        // a substring present in no document matches nothing.
        $this->waitForSearch( $arango , 'zzz' , [] ) ;
    }

    /**
     * Polls a View `SEARCH` until it returns the expected titles (eventual
     * consistency of the inverted index).
     *
     * @param ArangoDB           $arango
     * @param string             $term
     * @param array<int, string> $expected
     *
     * @throws ArangoException
     */
    private function waitForSearch( ArangoDB $arango , string $term , array $expected ) :void
    {
        $aql = 'FOR d IN ' . self::VIEW
             . ' SEARCH ANALYZER(d.title IN TOKENS(@t, "' . self::ANALYZER . '"), "' . self::ANALYZER . '")'
             . ' SORT d.title RETURN d.title' ;

        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $rows = array_values( iterator_to_array( $arango->database()->query( $aql , [ 't' => $term ] ) ) ) ;

            if ( $rows === $expected )
            {
                return ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        $this->fail( sprintf( "The substring search '%s' never reached the expected result." , $term ) ) ;
    }
}
