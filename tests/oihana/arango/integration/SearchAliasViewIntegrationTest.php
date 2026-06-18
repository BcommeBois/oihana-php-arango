<?php

namespace tests\oihana\arango\integration;

use DI\Container;

use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\indexes\InvertedIndex;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\views\SearchAliasView;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Search;
use oihana\arango\search\FederatedSearch;
use oihana\arango\search\enums\FederatedSearchParam;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the `search-alias` substrate (Lot G1a): an `inverted`
 * index declared per collection, aggregated by a `search-alias` view, queried
 * with a single federated `SEARCH` spanning both collections.
 *
 * Proves end-to-end that `View::createSearchAlias()` + `SearchAliasView` produce
 * a view that actually parses, indexes and returns cross-collection results on a
 * real server — the foundation of the federated search engine (Chantier C).
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class SearchAliasViewIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_search_alias_it' ;

    private const string CUSTOMERS = 'it_sa_customers' ;
    private const string PRODUCTS  = 'it_sa_products' ;
    private const string VIEW      = 'it_sa_global' ;
    private const string INDEX     = 'inv_search' ;

    /**
     * Seeds two collections, each with an `inverted` index on `tag` (identity
     * analyzer → exact match), then a `search-alias` view aggregating both.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::CUSTOMERS )->create() ;
        $db->collection( self::PRODUCTS )->create() ;

        $index = new InvertedIndex( fields: [ 'tag' ] , name: self::INDEX , analyzer: 'identity' ) ;
        $db->collection( self::CUSTOMERS )->createIndex( $index ) ;
        $db->collection( self::PRODUCTS )->createIndex( $index ) ;

        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'c1' , 'tag' => 'shared' ] ) ;
        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'c2' , 'tag' => 'other'  ] ) ;
        $db->collection( self::PRODUCTS  )->insert( [ '_key' => 'p1' , 'tag' => 'shared' ] ) ;

        $view = new SearchAliasView( self::VIEW , [ self::CUSTOMERS => self::INDEX , self::PRODUCTS => self::INDEX ] ) ;
        $db->view( self::VIEW )->createSearchAlias( $view->getIndexes() ) ;
    }

    public function testFederatedSearchSpansBothCollections() :void
    {
        // A single SEARCH over the search-alias view returns the matching
        // documents from BOTH collections, ordered by _id.
        $ids = $this->waitForSearch( 'shared' , [ self::CUSTOMERS . '/c1' , self::PRODUCTS . '/p1' ] ) ;

        $this->assertSame( [ self::CUSTOMERS . '/c1' , self::PRODUCTS . '/p1' ] , $ids ) ;
    }

    /**
     * The façade lifecycle (Lot G1b): sync creates a missing search-alias view,
     * a second diff reports it in sync, on a real server.
     *
     * @throws ArangoException
     */
    public function testFacadeSyncCreatesThenDiffsInSync() :void
    {
        $facade = $this->facade() ;
        $view   = new SearchAliasView( 'it_sa_facade' , [ self::CUSTOMERS => self::INDEX , self::PRODUCTS => self::INDEX ] ) ;

        $created = $facade->searchAliasViewSync( $view ) ;
        $this->assertSame( DiffStatus::MISSING , $created->status ) ;
        $this->assertTrue( $created->applied ) ;

        $diff = $facade->searchAliasViewDiff( $view ) ;
        $this->assertSame( DiffStatus::IN_SYNC , $diff->status ) ;

        $facade->viewDrop( 'it_sa_facade' ) ;
    }

    /**
     * G1c — the inverted index seeded per collection is reported IN_SYNC by
     * `indexesDiff()` on a real server: proves the canonicaliser lines up the
     * declared string field with the server's `{ name }` object (no false
     * drift) on ArangoDB itself.
     *
     * @throws ArangoException
     */
    public function testIndexesDiffOnSeededInvertedIndexIsInSync() :void
    {
        $facade   = $this->facade() ;
        $declared = new InvertedIndex( fields: [ 'tag' ] , name: self::INDEX , analyzer: 'identity' ) ;

        $report = $facade->indexesDiff( self::CUSTOMERS , [ $declared ] ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status , implode( ' | ' , $report->changes ) ) ;
    }

    /**
     * G1c — the special inverted options reconcile against the live server:
     * the declared `{ direction:"asc" }` vs the stored `{ asc:true }`, and the
     * declared `storedValues` without the server's default `compression`, all
     * read IN_SYNC.
     *
     * @throws ArangoException
     */
    public function testIndexesDiffReconcilesInvertedSpecialOptions() :void
    {
        $collection = 'it_sa_nested' ;
        self::$db->collection( $collection )->create() ;

        $declared = new InvertedIndex
        (
            fields       : [ 'title' ] ,
            name         : 'inv_nested' ,
            analyzer     : 'identity' ,
            primarySort  : [ 'fields' => [ [ 'field' => 'title' , 'direction' => 'asc' ] ] ] ,
            storedValues : [ [ 'fields' => [ 'title' ] ] ] ,
        ) ;
        self::$db->collection( $collection )->createIndex( $declared ) ;

        $report = $this->facade()->indexesDiff( $collection , [ $declared ] ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status , implode( ' | ' , $report->changes ) ) ;
    }

    /**
     * C2 — the *find* stage on a real server: the federated engine runs one
     * scored SEARCH over the search-alias view and returns the ranked
     * provenance (collection + key + score) of the matches across both
     * collections.
     *
     * @throws ArangoException
     */
    public function testFederatedFindReturnsRankedProvenance() :void
    {
        $engine = new FederatedSearch( new Container() ,
        [
            FederatedSearchParam::VIEW       => self::VIEW ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'tag' ] , Search::ANALYZER => 'identity' ] ,
            FederatedSearchParam::MODELS     => [ self::CUSTOMERS => 'model.customers' , self::PRODUCTS => 'model.products' ] ,
            Arango::DATABASE                 => $this->facade() ,
        ]) ;

        [ $pairs , $rows ] = $this->waitForFind( $engine , 'shared' , 2 ) ;

        $this->assertSame( [ [ self::CUSTOMERS , 'c1' ] , [ self::PRODUCTS , 'p1' ] ] , $pairs ) ;
        $this->assertArrayHasKey( FederatedSearch::SCORE , $rows[ 0 ] ) ;
    }

    /**
     * Polls the federated `find()` until it returns the expected number of
     * matches (inverted-index eventual consistency), then returns the sorted
     * `[ collection , key ]` pairs and the raw rows.
     *
     * @param FederatedSearch $engine
     * @param string          $term
     * @param int             $expected
     *
     * @return array{0: array<int, array{0: string, 1: string}>, 1: array<int, array<string, mixed>>}
     *
     * @throws ArangoException
     */
    private function waitForFind( FederatedSearch $engine , string $term , int $expected ) :array
    {
        $rows = [] ;

        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $rows = $engine->find( [ Arango::SEARCH => $term ] ) ;

            if ( count( $rows ) === $expected )
            {
                break ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        $pairs = array_map( static fn( array $row ) => [ $row[ Arango::COLLECTION ] , $row[ Arango::KEY ] ] , $rows ) ;
        sort( $pairs ) ;

        return [ $pairs , $rows ] ;
    }

    /**
     * Builds a live {@see ArangoDB} façade bound to the disposable database.
     */
    private function facade() :ArangoDB
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        return new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;
    }

    /**
     * Polls the federated SEARCH until it returns the expected handles
     * (inverted-index eventual consistency).
     *
     * @param string             $tag
     * @param array<int, string> $expected
     *
     * @return array<int, string>
     *
     * @throws ArangoException
     */
    private function waitForSearch( string $tag , array $expected ) :array
    {
        $aql = 'FOR d IN ' . self::VIEW . ' SEARCH d.tag == @tag SORT d._id RETURN d._id' ;

        $ids = [] ;
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $ids = array_values( iterator_to_array( self::$db->query( $aql , [ 'tag' => $tag ] ) ) ) ;

            if ( $ids === $expected )
            {
                return $ids ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        return $ids ;
    }
}
