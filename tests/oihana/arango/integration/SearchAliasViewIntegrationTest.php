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
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Search;
use oihana\arango\models\enums\filters\FilterParam;
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
    private const string ORGS      = 'it_sa_orgs' ;
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
        $db->collection( self::ORGS )->create() ;

        $index = new InvertedIndex( fields: [ 'tag' ] , name: self::INDEX , analyzer: 'identity' ) ;
        $db->collection( self::CUSTOMERS )->createIndex( $index ) ;
        $db->collection( self::PRODUCTS )->createIndex( $index ) ;
        $db->collection( self::ORGS )->createIndex( $index ) ;

        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'c1' , 'tag' => 'shared' ] ) ;
        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'c2' , 'tag' => 'other'  ] ) ;
        $db->collection( self::PRODUCTS  )->insert( [ '_key' => 'p1' , 'tag' => 'shared' ] ) ;

        // a polymorphic collection : three additionalType values + one multi-typed
        // document, all tagged `org-shared` (a distinct term, so it never collides
        // with the `shared` tag of the other collections' tests).
        $db->collection( self::ORGS )->insert( [ '_key' => 'o1' , 'tag' => 'org-shared' , 'additionalType' => 'Customer' ] ) ;
        $db->collection( self::ORGS )->insert( [ '_key' => 'o2' , 'tag' => 'org-shared' , 'additionalType' => 'Provider' ] ) ;
        $db->collection( self::ORGS )->insert( [ '_key' => 'o3' , 'tag' => 'org-shared' , 'additionalType' => 'Subsidiary' ] ) ;
        $db->collection( self::ORGS )->insert( [ '_key' => 'o4' , 'tag' => 'org-shared' , 'additionalType' => [ 'Provider' , 'Customer' ] ] ) ;

        $view = new SearchAliasView( self::VIEW , [ self::CUSTOMERS => self::INDEX , self::PRODUCTS => self::INDEX , self::ORGS => self::INDEX ] ) ;
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
     * C3 — the *rebuild* stage end to end on a real server: a federated
     * `search()` finds the matches, then re-hydrates them through real
     * {@see Documents} models (one per collection), returning the ranked
     * `{ collection, score, document }` rows; `foundRows()` reports the total.
     *
     * @throws ArangoException
     */
    public function testFederatedSearchRebuildsRankedDocuments() :void
    {
        $container = new Container() ;
        $facade    = $this->facade() ;

        $customers = new Documents( $container , [ Arango::DATABASE => $facade , 'collection' => self::CUSTOMERS ] ) ;
        $products  = new Documents( $container , [ Arango::DATABASE => $facade , 'collection' => self::PRODUCTS  ] ) ;
        $container->set( 'model.customers' , $customers ) ;
        $container->set( 'model.products'  , $products  ) ;

        $engine = new FederatedSearch( $container ,
        [
            FederatedSearchParam::VIEW       => self::VIEW ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'tag' ] , Search::ANALYZER => 'identity' ] ,
            FederatedSearchParam::MODELS     => [ self::CUSTOMERS => 'model.customers' , self::PRODUCTS => 'model.products' ] ,
            Arango::DATABASE                 => $facade ,
        ]) ;

        $results = $this->waitForFederatedSearch( $engine , 'shared' , 2 ) ;

        $pairs = array_map( static function( array $row )
        {
            $document = $row[ FederatedSearch::DOCUMENT ] ; // the model hydrates to an object
            return [ $row[ Arango::COLLECTION ] , is_array( $document ) ? $document[ '_key' ] : $document->_key ] ;
        } , $results ) ;
        sort( $pairs ) ;

        $this->assertSame( [ [ self::CUSTOMERS , 'c1' ] , [ self::PRODUCTS , 'p1' ] ] , $pairs ) ;
        $this->assertSame( 2 , $engine->foundRows() ) ;
    }

    /**
     * C4 — the per-collection permission gate on a real server: the **same**
     * `search('shared')`, run with two different authorizers, returns two
     * different result sets — each user only sees the collections their
     * permissions grant, with a `foundRows()` that honours the restriction.
     *
     * @throws ArangoException
     */
    public function testFederatedSearchHonoursPerCollectionPermissions() :void
    {
        $container = new Container() ;
        $facade    = $this->facade() ;

        $customers = new Documents( $container , [ Arango::DATABASE => $facade , 'collection' => self::CUSTOMERS ] ) ;
        $products  = new Documents( $container , [ Arango::DATABASE => $facade , 'collection' => self::PRODUCTS  ] ) ;
        $container->set( 'model.customers' , $customers ) ;
        $container->set( 'model.products'  , $products  ) ;

        $engine = new FederatedSearch( $container ,
        [
            FederatedSearchParam::VIEW       => self::VIEW ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'tag' ] , Search::ANALYZER => 'identity' ] ,
            FederatedSearchParam::MODELS     => [ self::CUSTOMERS => 'model.customers' , self::PRODUCTS => 'model.products' ] ,
            FederatedSearchParam::REQUIRES   => [ self::CUSTOMERS => 'customers:list' , self::PRODUCTS => 'products:list' ] ,
            Arango::DATABASE                 => $facade ,
        ]) ;

        // wait until both are searchable (no authorizer → fail-open → both)
        $this->waitForFederatedSearch( $engine , 'shared' , 2 ) ;

        // authorizer A : only customers
        $a = $engine->search( [ Arango::SEARCH => 'shared' , Arango::AUTHORIZER => static fn( string $s ) => $s === 'customers:list' ] ) ;
        $this->assertSame( [ self::CUSTOMERS ] , array_map( static fn( array $r ) => $r[ Arango::COLLECTION ] , $a ) ) ;
        $this->assertSame( 1 , $engine->foundRows() ) ;

        // authorizer B : only products → a different result set, same query
        $b = $engine->search( [ Arango::SEARCH => 'shared' , Arango::AUTHORIZER => static fn( string $s ) => $s === 'products:list' ] ) ;
        $this->assertSame( [ self::PRODUCTS ] , array_map( static fn( array $r ) => $r[ Arango::COLLECTION ] , $b ) ) ;
        $this->assertSame( 1 , $engine->foundRows() ) ;
    }

    /**
     * Lot 7b — type-aware rebuild on a real server: a single polymorphic
     * collection (`organizations`, three `additionalType` values + one multi-typed
     * document) is searched once, then each match is routed to the model resolved
     * from its `additionalType` — read live from the collection. Each model records
     * which keys it was asked to rebuild, so the routing is observable; the
     * multi-typed document follows the map order.
     *
     * @throws ArangoException
     */
    public function testFederatedSearchRoutesAPolymorphicCollectionByType() :void
    {
        $container = new Container() ;
        $facade    = $this->facade() ;

        $customers    = new KeyCapturingModel( $container , [ Arango::DATABASE => $facade , 'collection' => self::ORGS ] ) ;
        $providers    = new KeyCapturingModel( $container , [ Arango::DATABASE => $facade , 'collection' => self::ORGS ] ) ;
        $subsidiaries = new KeyCapturingModel( $container , [ Arango::DATABASE => $facade , 'collection' => self::ORGS ] ) ;
        $container->set( 'model.customers'    , $customers    ) ;
        $container->set( 'model.providers'    , $providers    ) ;
        $container->set( 'model.subsidiaries' , $subsidiaries ) ;

        $engine = new FederatedSearch( $container ,
        [
            FederatedSearchParam::VIEW       => self::VIEW ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'tag' ] , Search::ANALYZER => 'identity' ] ,
            FederatedSearchParam::MODELS     =>
            [
                self::ORGS =>
                [
                    FederatedSearchParam::MAP =>
                    [
                        'Customer'   => 'model.customers' ,
                        'Provider'   => 'model.providers' ,
                        'Subsidiary' => 'model.subsidiaries' ,
                    ] ,
                ] ,
            ] ,
            Arango::DATABASE => $facade ,
        ]) ;

        $this->waitForFederatedSearch( $engine , 'org-shared' , 4 ) ;

        // routing is observable through the keys each model was asked to rebuild :
        // o1=Customer, o2=Provider, o3=Subsidiary, and o4=[Provider,Customer] which
        // follows the map order (Customer first) → the customers model.
        sort( $customers->requestedKeys ) ;
        sort( $providers->requestedKeys ) ;
        sort( $subsidiaries->requestedKeys ) ;

        $this->assertSame( [ 'o1' , 'o4' ] , $customers->requestedKeys ) ;
        $this->assertSame( [ 'o2' ] , $providers->requestedKeys ) ;
        $this->assertSame( [ 'o3' ] , $subsidiaries->requestedKeys ) ;
        $this->assertSame( 4 , $engine->foundRows() ) ;
    }

    /**
     * Polls the federated `search()` until it returns the expected number of
     * rebuilt documents (inverted-index eventual consistency).
     *
     * @param FederatedSearch $engine
     * @param string          $term
     * @param int             $expected
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException
     */
    private function waitForFederatedSearch( FederatedSearch $engine , string $term , int $expected ) :array
    {
        $results = [] ;

        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $results = $engine->search( [ Arango::SEARCH => $term ] ) ;

            if ( count( $results ) === $expected )
            {
                break ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        return $results ;
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

/**
 * A real {@see Documents} model (it still queries the live database through its
 * parent) that records the `_key` set it was asked to rebuild, so a federated
 * search can assert which keys were routed to which model — the observable signal
 * of type routing, independent of how the model projects the documents.
 */
final class KeyCapturingModel extends Documents
{
    /** @var array<int, string> The keys of the last `list()` filter. */
    public array $requestedKeys = [] ;

    /**
     * @param array<string, mixed> $init
     * @return array<int, mixed>
     */
    public function list( array $init = [] ) : array
    {
        $value = ( $init[ Arango::FILTER ] ?? [] )[ FilterParam::VAL ] ?? [] ;

        $this->requestedKeys = is_array( $value ) ? $value : [] ;

        return parent::list( $init ) ;
    }
}
