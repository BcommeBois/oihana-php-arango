<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ConditionOptimization;
use oihana\arango\db\enums\CountApproximate;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\functions\search\analyzer;
use function oihana\arango\db\functions\search\bm25;
use function oihana\arango\db\functions\search\boost;
use function oihana\arango\db\functions\search\exists;
use function oihana\arango\db\functions\search\inRange;
use function oihana\arango\db\functions\search\levenshteinMatch;
use function oihana\arango\db\functions\search\minMatch;
use function oihana\arango\db\functions\search\phrase;
use function oihana\arango\db\functions\search\tfidf;
use function oihana\arango\db\functions\strings\startsWith;
use function oihana\arango\db\operations\aqlFor;

/**
 * Live validation of the `db/functions/search/` ArangoSearch helpers.
 *
 * A small `articles` collection is indexed by an `arangosearch` View
 * (`text` → built-in `text_en` Analyzer, `value`/`tag` → `identity`,
 * `storeValues: "id"` so that `EXISTS()` can match). Every test runs a
 * **helper-generated** `SEARCH` expression against the View and asserts the
 * matched documents — proving the emitted AQL (quoting, argument order,
 * default filling, unicode escapes) is accepted and behaves as documented
 * on a real server.
 *
 * `NGRAM_MATCH()` and `MINHASH_MATCH()` are exercised by unit tests only:
 * they require custom `ngram`/`minhash` Analyzers (no built-in equivalent).
 *
 * Views are eventually consistent (`commitIntervalMsec`): {@see seed()}
 * waits until all seeded documents are searchable before the tests run.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class SearchIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_search_it' ;

    private const string COLLECTION = 'articles' ;

    private const string VIEW = 'articlesView' ;

    /**
     * Seeds the articles, creates the View, then waits for the initial indexing.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $articles = $db->collection( self::COLLECTION ) ;
        $articles->create() ;

        $articles->insert( [ 'label' => 'A' , 'text' => 'the quick brown fox jumps over the lazy dog' , 'value' => 4 , 'tag' => 'évre'  ] ) ;
        $articles->insert( [ 'label' => 'B' , 'text' => 'quick red fox'                               , 'value' => 7 , 'tag' => 'loire' ] ) ;
        $articles->insert( [ 'label' => 'C' , 'text' => 'the lazy dog sleeps all day'                 , 'value' => 2 , 'tag' => 'maine' ] ) ;

        $db->view( self::VIEW )->create
        (
            [
                self::COLLECTION => new ArangoSearchLink
                (
                    fields :
                    [
                        'text'  => new ArangoSearchLink( analyzers : [ 'text_en'  ] ) ,
                        'value' => new ArangoSearchLink( analyzers : [ 'identity' ] ) ,
                        'tag'   => new ArangoSearchLink( analyzers : [ 'identity' ] ) ,
                    ] ,
                    storeValues : 'id' ,
                ) ,
            ] ,
            [ 'commitIntervalMsec' => 100 ]
        ) ;

        self::waitForIndexing( $db , 3 ) ;
    }

    /**
     * Polls the View until it exposes the expected document count (eventual consistency).
     *
     * @throws ArangoException When the count is still wrong after ~15 seconds.
     */
    private static function waitForIndexing( Database $db , int $expected ) :void
    {
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $rows = iterator_to_array
            (
                $db->query( 'FOR d IN ' . self::VIEW . ' COLLECT WITH COUNT INTO total RETURN total' ) ,
                false
            ) ;

            if ( ( $rows[0] ?? 0 ) === $expected )
            {
                return ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        throw new ArangoException( 'The view never reached ' . $expected . ' indexed documents.' ) ;
    }

    /**
     * Runs `FOR doc IN <view> SEARCH <expression> ...` and returns the sorted `label` values.
     *
     * @return array<int,string>
     * @throws ArangoException
     */
    private function search( string $expression , string $tail = 'SORT doc.label RETURN doc.label' ) :array
    {
        $aql  = 'FOR doc IN ' . self::VIEW . ' SEARCH ' . $expression . ' ' . $tail ;
        return iterator_to_array( self::$db->query( $aql ) , false ) ;
    }

    /**
     * `PHRASE()` matches adjacent tokens in order — and only in order.
     */
    public function testPhraseMatchesTokensInOrder() :void
    {
        $expr = analyzer( phrase( 'doc.text' , 'red fox' ) , 'text_en' ) ;
        $this->assertSame( [ 'B' ] , $this->search( $expr ) ) ;

        $expr = analyzer( phrase( 'doc.text' , 'fox red' ) , 'text_en' ) ;
        $this->assertSame( [] , $this->search( $expr ) ) ;
    }

    /**
     * The array form with `skipTokens` wildcards: "quick … fox" with 1 token between.
     */
    public function testPhraseArrayFormWithSkipTokens() :void
    {
        $expr = analyzer( phrase( 'doc.text' , [ 'quick' , 1 , 'fox' ] ) , 'text_en' ) ;
        $this->assertSame( [ 'A' , 'B' ] , $this->search( $expr ) ) ;
    }

    /**
     * `LEVENSHTEIN_MATCH()` tolerates typos within the given edit distance.
     */
    public function testLevenshteinMatchToleratesTypo() :void
    {
        // "quikc" → "quick" = Damerau-Levenshtein distance 1 (one transposition).
        $expr = analyzer( levenshteinMatch( 'doc.text' , 'quikc' , 1 ) , 'text_en' ) ;
        $this->assertSame( [ 'A' , 'B' ] , $this->search( $expr ) ) ;

        // Pure Levenshtein distance (transpositions: false): "quikc" → "quick" needs 2 edits.
        $expr = analyzer( levenshteinMatch( 'doc.text' , 'quikc' , 1 , false ) , 'text_en' ) ;
        $this->assertSame( [] , $this->search( $expr ) ) ;
    }

    /**
     * `STARTS_WITH()` with an array of prefixes and a minimum match count.
     */
    public function testStartsWithArrayOfPrefixes() :void
    {
        $expr = analyzer( startsWith( 'doc.text' , [ 'laz' , 'qui' ] , 1 ) , 'text_en' ) ;
        $this->assertSame( [ 'A' , 'B' , 'C' ] , $this->search( $expr ) ) ;

        // Both prefixes must match somewhere in the tokens.
        $expr = analyzer( startsWith( 'doc.text' , [ 'laz' , 'qui' ] , 2 ) , 'text_en' ) ;
        $this->assertSame( [ 'A' ] , $this->search( $expr ) ) ;
    }

    /**
     * `MIN_MATCH()` keeps documents satisfying at least N of the sub-expressions.
     */
    public function testMinMatch() :void
    {
        $expr = analyzer
        (
            minMatch( [ 'doc.text == "quick"' , 'doc.text == "fox"' , 'doc.text == "sleeps"' ] , 2 ) ,
            'text_en'
        ) ;
        $this->assertSame( [ 'A' , 'B' ] , $this->search( $expr ) ) ;
    }

    /**
     * `EXISTS()` (requires `storeValues: "id"`) combined with `IN_RANGE()`.
     */
    public function testExistsAndInRange() :void
    {
        $expr = exists( 'doc.text' ) . ' AND ' . inRange( 'doc.value' , 3 , 5 , true , true ) ;
        $this->assertSame( [ 'A' ] , $this->search( $expr ) ) ;

        $expr = inRange( 'doc.value' , 2 , 7 , true , false ) ;
        $this->assertSame( [ 'A' , 'C' ] , $this->search( $expr ) ) ;
    }

    /**
     * The unicode escapes emitted by `json_encode` (`é` → `é`) are valid AQL.
     */
    public function testUnicodeEscapedLiteralMatches() :void
    {
        // identity Analyzer: the whole "évre" tag must equal the escaped literal.
        $expr = 'doc.tag == ' . json_encode( 'évre' ) ;
        $this->assertSame( [ 'A' ] , $this->search( $expr ) ) ;
    }

    /**
     * `BM25()` ranks the best match first and `BOOST()` shifts the ranking.
     */
    public function testBm25RankingAndBoost() :void
    {
        // Both A and C contain "lazy dog"; C is shorter so BM25 ranks it first.
        $expr = analyzer( phrase( 'doc.text' , 'lazy dog' ) , 'text_en' ) ;
        $rows = $this->search( $expr , 'SORT ' . bm25( 'doc' ) . ' DESC RETURN doc.label' ) ;
        $this->assertSame( [ 'C' , 'A' ] , $rows ) ;

        // Boosting a sub-expression that only A satisfies flips the ranking.
        $expr = analyzer
        (
            boost( phrase( 'doc.text' , 'brown fox' ) , 10 ) . ' OR ' . phrase( 'doc.text' , 'lazy dog' ) ,
            'text_en'
        ) ;
        $rows = $this->search( $expr , 'SORT ' . bm25( 'doc' ) . ' DESC RETURN doc.label' ) ;
        $this->assertSame( 'A' , $rows[0] ) ;
    }

    /**
     * The full `aqlFor()`/`aqlSearch()` form — `SEARCH` wrapped by `AQL::ANALYZER`
     * plus a real `OPTIONS` object from `AQL::SEARCH_OPTIONS` — runs on the server
     * and returns the same matches as the option-less query.
     */
    public function testAqlForWithSearchOptions() :void
    {
        $aql = aqlFor
        ([
            AQL::DOC_REF        => 'doc' ,
            AQL::IN             => self::VIEW ,
            AQL::SEARCH         => phrase( 'doc.text' , 'lazy dog' ) ,
            AQL::ANALYZER       => 'text_en' ,
            AQL::SEARCH_OPTIONS =>
            [
                'collections'           => [ self::COLLECTION ] ,
                'conditionOptimization' => ConditionOptimization::NONE ,
                'countApproximate'      => CountApproximate::COST ,
            ] ,
        ]) . ' SORT doc.label RETURN doc.label' ;

        $rows = iterator_to_array( self::$db->query( $aql ) , false ) ;
        $this->assertSame( [ 'A' , 'C' ] , $rows ) ;
    }

    /**
     * `TFIDF()` returns a strictly positive score for matched documents.
     */
    public function testTfidfScoresMatches() :void
    {
        $expr = analyzer( phrase( 'doc.text' , 'quick' ) , 'text_en' ) ;
        $rows = $this->search( $expr , 'SORT ' . tfidf( 'doc' ) . ' DESC RETURN ' . tfidf( 'doc' ) ) ;

        $this->assertCount( 2 , $rows ) ;
        foreach ( $rows as $score )
        {
            $this->assertGreaterThan( 0 , $score ) ;
        }
    }
}
