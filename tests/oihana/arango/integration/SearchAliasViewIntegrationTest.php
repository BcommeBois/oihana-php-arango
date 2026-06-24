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
use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
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

    private const string CUSTOMERS  = 'it_sa_customers' ;
    private const string PRODUCTS   = 'it_sa_products' ;
    private const string ORGS       = 'it_sa_orgs' ;
    private const string UNITS      = 'it_sa_units' ;
    private const string VIEW       = 'it_sa_global' ;
    private const string INDEX      = 'inv_search' ;
    private const string ORG_INDEX  = 'inv_orgs' ;
    private const string UNIT_INDEX = 'inv_units' ;

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
        $db->collection( self::UNITS )->create() ;

        $index = new InvertedIndex( fields: [ 'tag' ] , name: self::INDEX , analyzer: 'identity' ) ;
        $db->collection( self::CUSTOMERS )->createIndex( $index ) ;
        $db->collection( self::PRODUCTS )->createIndex( $index ) ;

        // The polymorphic collection ALSO indexes its discriminator so the per-type
        // SEARCH gate can filter on it. `organizations` stores `additionalType` as an
        // ARRAY → the index field carries the `[*]` array-expansion (`additionalType[*]`).
        // The other collections do not index it — the field absence that scopes the
        // gate to this collection alone.
        $orgIndex = new InvertedIndex( fields: [ 'tag' , 'additionalType[*]' ] , name: self::ORG_INDEX , analyzer: 'identity' ) ;
        $db->collection( self::ORGS )->createIndex( $orgIndex ) ;

        // A second polymorphic collection whose `additionalType` is a plain STRING →
        // the index field is `additionalType` (no `[*]`). Proves the same library gate
        // works on both shapes, the only difference being the index declaration.
        $unitIndex = new InvertedIndex( fields: [ 'tag' , 'additionalType' ] , name: self::UNIT_INDEX , analyzer: 'identity' ) ;
        $db->collection( self::UNITS )->createIndex( $unitIndex ) ;

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

        // per-type permission fixtures (ARRAY shape), under a distinct `org-perm` tag :
        // a Customer, a Provider, a multi-typed [Provider, Customer], and a typeless
        // document (hidden in strict mode — fail-closed — on the [*] index).
        $db->collection( self::ORGS )->insert( [ '_key' => 'op1' , 'tag' => 'org-perm' , 'additionalType' => [ 'Customer' ] ] ) ;
        $db->collection( self::ORGS )->insert( [ '_key' => 'op2' , 'tag' => 'org-perm' , 'additionalType' => [ 'Provider' ] ] ) ;
        $db->collection( self::ORGS )->insert( [ '_key' => 'op3' , 'tag' => 'org-perm' , 'additionalType' => [ 'Provider' , 'Customer' ] ] ) ;
        $db->collection( self::ORGS )->insert( [ '_key' => 'op4' , 'tag' => 'org-perm' ] ) ; // typeless

        // per-type permission fixtures (STRING shape) on the units collection.
        $db->collection( self::UNITS )->insert( [ '_key' => 'us1' , 'tag' => 'unit-perm' , 'additionalType' => 'Customer' ] ) ;
        $db->collection( self::UNITS )->insert( [ '_key' => 'us2' , 'tag' => 'unit-perm' , 'additionalType' => 'Provider' ] ) ;

        $view = new SearchAliasView( self::VIEW ,
        [
            self::CUSTOMERS => self::INDEX ,
            self::PRODUCTS  => self::INDEX ,
            self::ORGS      => self::ORG_INDEX ,
            self::UNITS     => self::UNIT_INDEX ,
        ]) ;
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

        sort( $customers->requestedKeys )    ; sort( $customers->returnedKeys )    ;
        sort( $providers->requestedKeys )    ; sort( $providers->returnedKeys )    ;
        sort( $subsidiaries->requestedKeys ) ; sort( $subsidiaries->returnedKeys ) ;

        // routing : each model was asked only for its type's keys — o1=Customer,
        // o2=Provider, o3=Subsidiary, and o4=[Provider,Customer] which follows the
        // map order (Customer first) → the customers model.
        $this->assertSame( [ 'o1' , 'o4' ] , $customers->requestedKeys ) ;
        $this->assertSame( [ 'o2' ] , $providers->requestedKeys ) ;
        $this->assertSame( [ 'o3' ] , $subsidiaries->requestedKeys ) ;

        // restriction : list() returned ONLY the requested keys on a real server —
        // the trusted internal condition applies even though `_key` is not a
        // whitelisted public filter (before the fix, each model returned all 4).
        $this->assertSame( $customers->requestedKeys , $customers->returnedKeys ) ;
        $this->assertSame( $providers->requestedKeys , $providers->returnedKeys ) ;
        $this->assertSame( $subsidiaries->requestedKeys , $subsidiaries->returnedKeys ) ;

        $this->assertSame( 4 , $engine->foundRows() ) ;
    }

    /**
     * Lot 2 (per-type permission) — **permissive** mode on a real server. With
     * `FALLBACK => true` the unlisted types stay visible and only the denied type
     * (Provider) is hidden, the predicate filtering inside the SEARCH so the total
     * stays exact. A multi-typed document carrying a denied type is excluded (array
     * semantics: a document matches `additionalType IN @denied` when **any** of its
     * types is denied); a typeless document passes (field absence).
     *
     * @throws ArangoException
     */
    public function testFederatedSearchPermissiveTypeGateHidesOnlyDeniedTypes() :void
    {
        $engine = $this->permissionEngine( self::ORGS ,
        [
            self::ORGS =>
            [
                FederatedSearchParam::MAP      => [ 'Customer' => 'cust:list' , 'Provider' => 'prov:list' ] ,
                FederatedSearchParam::FALLBACK => true , // unlisted types visible
            ] ,
        ]) ;

        // a user granted cust:list but not prov:list
        $init = [ Arango::SEARCH => 'org-perm' , Arango::AUTHORIZER => static fn( string $s ) => $s === 'cust:list' ] ;

        // op1 ([Customer]) kept ; op2 ([Provider]) hidden ; op3 ([Provider,Customer]) hidden
        // (carries a denied type) ; op4 (typeless) kept → { op1, op4 }
        $results = $this->waitForSearchResults( $engine , $init , 2 ) ;

        $this->assertSame( [ 'op1' , 'op4' ] , $this->resultKeys( $results ) ) ;
        $this->assertSame( 2 , $engine->foundRows() ) ; // total exact (filtered before the LIMIT)
    }

    /**
     * Lot 2 (per-type permission) — **strict** mode on a real server (array shape).
     * With no `FALLBACK` only the allowed types are visible; a multi-typed document
     * carrying an allowed type **is** kept (array semantics: it matches
     * `additionalType IN @allowed` when any of its types is allowed); a typeless
     * document is **hidden** (fail-closed — on the `additionalType[*]` index `EXISTS`
     * sees it as belonging to the collection).
     *
     * @throws ArangoException
     */
    public function testFederatedSearchStrictTypeGateKeepsAllowedTypesAndHidesTypeless() :void
    {
        $engine = $this->permissionEngine( self::ORGS ,
        [
            self::ORGS => [ FederatedSearchParam::MAP => [ 'Customer' => 'cust:list' , 'Provider' => 'prov:list' ] ] , // no FALLBACK → strict
        ]) ;

        $init = [ Arango::SEARCH => 'org-perm' , Arango::AUTHORIZER => static fn( string $s ) => $s === 'cust:list' ] ;

        // op1 ([Customer]) kept ; op2 ([Provider]) hidden ; op3 ([Provider,Customer]) kept
        // (carries Customer) ; op4 (typeless) hidden (fail-closed) → { op1, op3 }
        $results = $this->waitForSearchResults( $engine , $init , 2 ) ;

        $this->assertSame( [ 'op1' , 'op3' ] , $this->resultKeys( $results ) ) ;
        $this->assertSame( 2 , $engine->foundRows() ) ;
    }

    /**
     * Lot 2 (per-type permission) — the **string** shape on a real server: the same
     * library gate filters a collection whose `additionalType` is a plain string,
     * declared with a plain `additionalType` index (no `[*]`). Proves the engine is
     * shape-agnostic — only the index declaration differs.
     *
     * @throws ArangoException
     */
    public function testFederatedSearchTypeGateWorksOnAStringDiscriminator() :void
    {
        $engine = $this->permissionEngine( self::UNITS ,
        [
            self::UNITS => [ FederatedSearchParam::MAP => [ 'Customer' => 'cust:list' , 'Provider' => 'prov:list' ] ] , // strict
        ]) ;

        $init = [ Arango::SEARCH => 'unit-perm' , Arango::AUTHORIZER => static fn( string $s ) => $s === 'cust:list' ] ;

        // us1 ("Customer") kept ; us2 ("Provider") hidden → { us1 }
        $results = $this->waitForSearchResults( $engine , $init , 1 ) ;

        $this->assertSame( [ 'us1' ] , $this->resultKeys( $results ) ) ;
        $this->assertSame( 1 , $engine->foundRows() ) ;
    }

    /**
     * Builds a federated engine over one polymorphic collection with a composite
     * model (a single fallback model rebuilding every type) and the given structured
     * `requires`, for the per-type permission tests.
     *
     * @param string               $collection
     * @param array<string, mixed> $requires
     *
     * @return FederatedSearch
     */
    private function permissionEngine( string $collection , array $requires ) :FederatedSearch
    {
        $container = new Container() ;
        $facade    = $this->facade() ;

        $container->set( 'model.poly' , new Documents( $container , [ Arango::DATABASE => $facade , 'collection' => $collection ] ) ) ;

        return new FederatedSearch( $container ,
        [
            FederatedSearchParam::VIEW       => self::VIEW ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'tag' ] , Search::ANALYZER => 'identity' ] ,
            FederatedSearchParam::MODELS     => [ $collection => [ FederatedSearchParam::FALLBACK => 'model.poly' ] ] , // composite : discriminator additionalType, one model
            FederatedSearchParam::REQUIRES   => $requires ,
            Arango::DATABASE                 => $facade ,
        ]) ;
    }

    /**
     * The sorted `_key`s of a federated `search()` result set.
     *
     * @param array<int, array<string, mixed>> $results
     *
     * @return array<int, string>
     */
    private function resultKeys( array $results ) :array
    {
        $keys = array_map( static function( array $row )
        {
            $document = $row[ FederatedSearch::DOCUMENT ] ;
            return is_array( $document ) ? $document[ '_key' ] : $document->_key ;
        } , $results ) ;

        sort( $keys ) ;

        return $keys ;
    }

    /**
     * Polls the federated `search()` (with its request init, e.g. an authorizer)
     * until it returns the expected number of rows (inverted-index eventual
     * consistency).
     *
     * @param FederatedSearch      $engine
     * @param array<string, mixed> $init
     * @param int                  $expected
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException
     */
    private function waitForSearchResults( FederatedSearch $engine , array $init , int $expected ) :array
    {
        $results = [] ;

        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $results = $engine->search( $init ) ;

            if ( count( $results ) === $expected )
            {
                break ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        return $results ;
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
 * parent) that records both the `_key` set it was **asked** to rebuild (the
 * routing signal) and the `_key` set it actually **returned** (the restriction
 * signal — proving the trusted internal condition narrows the query).
 */
final class KeyCapturingModel extends Documents
{
    /** @var array<int, string> The keys requested by the last `list()` (the rebuild condition's bind). */
    public array $requestedKeys = [] ;

    /** @var array<int, string> The keys the last `list()` actually returned. */
    public array $returnedKeys = [] ;

    /**
     * @param array<string, mixed> $init
     * @return array<int, mixed>
     */
    public function list( array $init = [] ) : array
    {
        $binds = $init[ AQL::BINDS ] ?? [] ;

        $this->requestedKeys = $binds === [] ? [] : array_values( $binds )[ 0 ] ;

        $documents = parent::list( $init ) ;

        $this->returnedKeys = array_map( static fn( $document ) => is_array( $document ) ? $document[ '_key' ] : $document->_key , $documents ) ;

        return $documents ;
    }
}
