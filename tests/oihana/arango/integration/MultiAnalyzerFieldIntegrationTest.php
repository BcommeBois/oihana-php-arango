<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use Devium\Toml\TomlError;

use oihana\arango\clients\analyzer\enums\AnalyzerFeature;
use oihana\arango\clients\analyzer\NgramAnalyzer;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of multiple Analyzers per field (WS1): a single
 * `Search::FIELDS` entry indexed through a list of Analyzers is searchable
 * under each of them.
 *
 * - multilingual: a field indexed with `[text_fr, text_en]` matches both a
 *   French-stemmed and an English-stemmed query;
 * - autocomplete: a field indexed with `[text_en, <ngram>]` matches both a
 *   whole word (text branch) and a partial term (n-gram branch).
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class MultiAnalyzerFieldIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_multiaz_it' ;

    private const string COLLECTION = 'maz' ;

    private const string NGRAM = 'it_autocomplete' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $coll = $db->collection( self::COLLECTION ) ;
        $coll->create() ;

        $coll->insert( [ 'label' => 'A' , 'name' => 'atelier' , 'mixed' => 'chiens dogs' ] ) ;
        $coll->insert( [ 'label' => 'B' , 'name' => 'ferronnerie' , 'mixed' => 'chats cats' ] ) ;
    }

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
     * A Documents model wired to the disposable database with a given
     * `AQL::VIEW`. Lazy mode is ON so the construction provisions the View.
     *
     * @throws Throwable
     */
    private function model( array $view ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $this->arango() ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::VIEW        => $view ,
        ]) ;
    }

    /**
     * @throws ArangoException
     */
    private function waitForIndexing( int $expected , string $view ) :void
    {
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $rows = iterator_to_array
            (
                self::$db->query( 'FOR d IN ' . $view . ' COLLECT WITH COUNT INTO total RETURN total' ) ,
                false
            ) ;

            if ( ( $rows[0] ?? 0 ) === $expected )
            {
                return ;
            }

            usleep( 100_000 ) ;
        }

        throw new ArangoException( 'The view never reached ' . $expected . ' indexed documents.' ) ;
    }

    /**
     * @param array<int,array|object> $rows
     * @return array<int,string>
     */
    private function labels( array $rows ) :array
    {
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;
        sort( $labels ) ;
        return $labels ;
    }

    /**
     * A field indexed with `[text_fr, text_en]` is queried under both: a French
     * word and an English word in the same field both match.
     *
     * @throws Throwable
     */
    public function testMultilingualFieldMatchesUnderBothAnalyzers() :void
    {
        $model = $this->model(
        [
            Search::NAME     => 'mazI18nView' ,
            Search::ANALYZER => 'text_fr' ,
            Search::FIELDS   => [ 'mixed' => [ Search::ANALYZER => [ 'text_fr' , 'text_en' ] ] ] ,
        ]) ;

        $this->assertTrue( $model->viewExists( 'mazI18nView' ) ) ;
        $this->waitForIndexing( 2 , 'mazI18nView' ) ;

        // 'chien' is the French stem of 'chiens' (text_fr branch).
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'chien' ] ) ) ) ;

        // 'dog' is the English stem of 'dogs' (text_en branch) — same field.
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'dog' ] ) ) ) ;
    }

    /**
     * A field indexed with `[text_en, <ngram>]` answers both a whole-word query
     * (text branch) and a partial / "as-you-type" query (n-gram branch).
     *
     * @throws Throwable
     */
    public function testAutocompleteFieldCombinesTextAndNgram() :void
    {
        $arango = $this->arango() ;

        // The n-gram analyzer must exist before the View links it.
        $arango->analyzerSync( new AnalyzerDefinition
        (
            self::NGRAM ,
            new NgramAnalyzer( min: 2 , max: 5 , preserveOriginal: true , streamType: 'utf8' ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ) ;

        $model = $this->model(
        [
            Search::NAME     => 'mazAutoView' ,
            Search::ANALYZER => 'text_en' ,
            Search::FIELDS   => [ 'name' => [ Search::ANALYZER => [ 'text_en' , self::NGRAM ] ] ] ,
        ]) ;

        $this->assertTrue( $model->viewExists( 'mazAutoView' ) ) ;
        $this->waitForIndexing( 2 , 'mazAutoView' ) ;

        // Partial term — `text_en` alone would NOT match 'ate', but the n-gram
        // branch does (and only A: 'ferronnerie' shares none of 'at'/'ate'/'te').
        // This is the headline: the n-gram branch is active on the field.
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'ate' ] ) ) ) ;

        // The whole word still matches through the text branch. (n-gram is loose
        // by nature — a whole word may also bring loose n-gram matches such as B
        // via shared fragments, so only A's presence is asserted here.)
        $this->assertContains( 'A' , $this->labels( $model->list( [ Arango::SEARCH => 'atelier' ] ) ) ) ;

        // A term whose fragments appear in no document matches nothing.
        $this->assertSame( [] , $this->labels( $model->list( [ Arango::SEARCH => 'zzz' ] ) ) ) ;
    }

    /**
     * `Search::NGRAM` queries the ngram analyzer by **similarity threshold**
     * (`NGRAM_MATCH`) instead of the loose `IN TOKENS`. The whole word `atelier`
     * then matches **only** `atelier` (similarity 1.0) and excludes `ferronnerie`
     * (similarity below the threshold) — the precision the loose combo lacks —
     * while the partial `ate` still matches through the n-grams.
     *
     * @throws Throwable
     */
    public function testNgramThresholdExcludesLooseMatches() :void
    {
        $arango = $this->arango() ;

        // NGRAM_MATCH wants a single-size ngram analyzer (min == max), original off.
        $arango->analyzerSync( new AnalyzerDefinition
        (
            'it_trigram' ,
            new NgramAnalyzer( min: 3 , max: 3 , preserveOriginal: false , streamType: 'utf8' ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ) ;

        $model = $this->model(
        [
            Search::NAME     => 'mazThreshView' ,
            Search::ANALYZER => 'text_en' ,
            Search::FIELDS   =>
            [
                'name' =>
                [
                    Search::ANALYZER => 'text_en' ,                                            // whole-word branch
                    Search::NGRAM    => [ Search::ANALYZER => 'it_trigram' , Search::THRESHOLD => 0.5 ] , // precise branch
                ] ,
            ] ,
        ]) ;

        $this->assertTrue( $model->viewExists( 'mazThreshView' ) ) ;
        $this->waitForIndexing( 2 , 'mazThreshView' ) ;

        // Partial term still matches through NGRAM_MATCH (trigram 'ate' is in 'atelier').
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'ate' ] ) ) ) ;

        // The whole word matches ONLY 'atelier' — 'ferronnerie' is below the
        // threshold (the loose IN TOKENS combo would have brought it via shared
        // fragments). This is the precision win.
        $this->assertSame( [ 'A' ] , $this->labels( $model->list( [ Arango::SEARCH => 'atelier' ] ) ) ) ;
    }
}
