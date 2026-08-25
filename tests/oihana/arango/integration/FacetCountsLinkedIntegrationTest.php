<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;
use Throwable;

use Devium\Toml\TomlError;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterType;
use oihana\arango\models\enums\Search;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use oihana\reflect\exceptions\ConstantException;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the **linked** facet counts: a `?facetCounts=` dimension
 * whose values live at the other end of a relation — an `INBOUND` edge
 * traversal ({@see Facet::EDGE}) or a key-join ({@see Facet::JOIN}).
 *
 * The unit suite freezes the generated AQL character for character; it cannot
 * say whether a real server accepts a traversal inside a `LET` sub-query, nor
 * whether `COUNT_DISTINCT( doc._key )` actually collapses the duplicate rows.
 * The seed is therefore built around **measurable divergences**: an
 * organization reached twice through two parallel edges to the same place, and
 * an organization referenced by two notes of the same language. Both make the
 * per-row and per-document counts differ by a real number, so `Facet::DISTINCT`
 * cannot pass by returning the default.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class FacetCountsLinkedIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_facet_counts_linked_it' ;

    private const string COLLECTION = 'organizations' ;

    private const int EDGE_TYPE = 3 ;

    private const string VIEW = 'organizationsView' ;

    /**
     * Seeds one main collection and the four relations counted below.
     *
     * The edge graph is the heart of the seed: **o2 reaches Paris through TWO
     * parallel edges**, so the Paris bucket counts 4 rows for 3 documents — the
     * divergence `Facet::DISTINCT` has to close.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        // --- The counted documents ------------------------------------------
        // `kind` narrows the set (a count inherits the list filter), `name`
        // feeds the View search, `authorId` / `tagIds` anchor the joins.
        $organizations = $db->collection( self::COLLECTION ) ;
        $organizations->create() ;
        $organizations->insert( [ '_key' => 'o1' , 'kind' => 'ngo'     , 'name' => 'Alpha bois'    , 'authorId' => 'a1' , 'tagIds' => [ 'php' ] ] ) ;
        $organizations->insert( [ '_key' => 'o2' , 'kind' => 'ngo'     , 'name' => 'Beta bois'     , 'authorId' => 'a1' , 'tagIds' => [ 'db' ] ] ) ;
        $organizations->insert( [ '_key' => 'o3' , 'kind' => 'company' , 'name' => 'Gamma metal'   , 'authorId' => 'a2' , 'tagIds' => [ 'php' , 'db' ] ] ) ;
        $organizations->insert( [ '_key' => 'o4' , 'kind' => 'company' , 'name' => 'Delta bois'    , 'authorId' => 'a2' , 'tagIds' => [] ] ) ;
        $organizations->insert( [ '_key' => 'o5' , 'kind' => 'company' , 'name' => 'Epsilon metal' ] ) ; // no relation at all

        // --- Facet::EDGE : (place)-[orgs_places]->(organization) ------------
        $places = $db->collection( 'places' ) ;
        $places->create() ;
        $places->insert( [ '_key' => 'pParis'  , 'name' => 'Paris'  ] ) ;
        $places->insert( [ '_key' => 'pLyon'   , 'name' => 'Lyon'   ] ) ;
        $places->insert( [ '_key' => 'pBerlin' , 'name' => 'Berlin' ] ) ; // linked to nobody: never a bucket

        $edges = $db->collection( 'orgs_places' ) ;
        $edges->create( [ 'type' => self::EDGE_TYPE ] ) ;
        $edges->insert( [ '_from' => 'places/pParis' , '_to' => 'organizations/o1' ] ) ;
        $edges->insert( [ '_from' => 'places/pParis' , '_to' => 'organizations/o2' ] ) ; // ⚠ o2 → Paris, twice
        $edges->insert( [ '_from' => 'places/pParis' , '_to' => 'organizations/o2' ] ) ; // ⚠ the duplicate row
        $edges->insert( [ '_from' => 'places/pParis' , '_to' => 'organizations/o3' ] ) ;
        $edges->insert( [ '_from' => 'places/pLyon'  , '_to' => 'organizations/o3' ] ) ;
        $edges->insert( [ '_from' => 'places/pLyon'  , '_to' => 'organizations/o4' ] ) ;

        // --- Facet::JOIN : organization.authorId == author._key -------------
        $authors = $db->collection( 'authors' ) ;
        $authors->create() ;
        $authors->insert( [ '_key' => 'a1' , 'name' => 'Alice' ] ) ;
        $authors->insert( [ '_key' => 'a2' , 'name' => 'Bob'   ] ) ;

        // --- Facet::JOIN + AQL::ARRAY : tag._key IN organization.tagIds -----
        $tags = $db->collection( 'tags' ) ;
        $tags->create() ;
        $tags->insert( [ '_key' => 'php' , 'name' => 'PHP'      ] ) ;
        $tags->insert( [ '_key' => 'db'  , 'name' => 'Database' ] ) ;

        // --- Facet::JOIN + AQL::KEY : note.orgId == organization._key -------
        // The reverse one-to-many, and the second divergence: o1 carries two
        // French notes, so `fr` counts 3 rows for 2 documents.
        $notes = $db->collection( 'notes' ) ;
        $notes->create() ;
        $notes->insert( [ '_key' => 'n1' , 'orgId' => 'o1' , 'lang' => 'fr' ] ) ;
        $notes->insert( [ '_key' => 'n2' , 'orgId' => 'o1' , 'lang' => 'fr' ] ) ; // ⚠ the duplicate row
        $notes->insert( [ '_key' => 'n3' , 'orgId' => 'o2' , 'lang' => 'en' ] ) ;
        $notes->insert( [ '_key' => 'n4' , 'orgId' => 'o3' , 'lang' => 'fr' ] ) ;
    }

    /**
     * A `Documents` model wired to the disposable database, declaring every
     * linked dimension the tests count. The `$facets` overrides let one test
     * swap a single declaration (a permission subject, a View) without
     * duplicating the whole map.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    private function model( ?array $facets = null , ?array $view = null ) :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => self::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $init =
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::FILTERS     => [ 'kind' => FilterType::STRING ] ,
            AQL::FACETS      => $facets ?? self::facets() ,
        ] ;

        if ( $view !== null )
        {
            $init[ AQL::SEARCHABLE ] = [ 'name' ] ;
            $init[ AQL::VIEW       ] = $view ;
        }

        return new Documents( $container , $init ) ;
    }

    /**
     * The declared dimensions: the same relation counted with and without
     * `Facet::DISTINCT`, so one query can measure both sides of the divergence.
     *
     * @return array<string,array>
     */
    private static function facets() :array
    {
        return
        [
            // Edge: the vertices reached INBOUND, bucketed on their _key or name.
            'placeKey'      => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' ] ,
            'place'         => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' , Facet::VALUE => 'name' ] ,
            'placeDistinct' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' , Facet::VALUE => 'name' , Facet::DISTINCT => true ] ,

            // Key-join: the main document holds the foreign key.
            'author'        => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , Facet::VALUE => 'name' ] ,

            // Key-join over an array of keys (membership).
            'tag'           => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' , Facet::VALUE => 'name' ] ,

            // Reverse one-to-many: the joined side carries the foreign key.
            'lang'          => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'notes' , AQL::KEY => 'orgId' , Facet::PROPERTY => '_key' , Facet::VALUE => 'lang' ] ,
            'langDistinct'  => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'notes' , AQL::KEY => 'orgId' , Facet::PROPERTY => '_key' , Facet::VALUE => 'lang' , Facet::DISTINCT => true ] ,

            // Top-N buckets: the sidebar shows one place, not all of them.
            'placeTop'      => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' , Facet::VALUE => 'name' , Facet::LIMIT => 1 ] ,
            'authorTop'     => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , Facet::VALUE => 'name' , Facet::LIMIT => 1 ] , // ⚠ Alice and Bob are TIED
            'langTop'       => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'notes' , AQL::KEY => 'orgId' , Facet::PROPERTY => '_key' , Facet::VALUE => 'lang' , Facet::LIMIT => 1 ] ,
        ] ;
    }

    /**
     * Re-keys one dimension's buckets as a `value => count` map, sorted by
     * value: buckets are sorted by count DESC, and ties have no deterministic
     * order.
     *
     * @return array<string,int>
     */
    private function buckets( array $counts , string $dimension ) :array
    {
        $buckets = [] ;
        foreach ( (array) ( $counts[ $dimension ] ?? [] ) as $bucket )
        {
            $bucket = (array) $bucket ;
            $buckets[ $bucket[ 'value' ] ] = (int) $bucket[ 'count' ] ;
        }
        ksort( $buckets ) ;
        return $buckets ;
    }

    /**
     * The bucket values of one dimension, **in the order the server returned
     * them** — unlike {@see buckets()}, which re-keys and sorts, hiding exactly
     * what the tie-breaker is there to fix.
     *
     * @return array<int,string>
     */
    private function orderedValues( array $counts , string $dimension ) :array
    {
        return array_map( fn( $bucket ) => (string) ( (array) $bucket )[ 'value' ] , (array) ( $counts[ $dimension ] ?? [] ) ) ;
    }

    /**
     * Polls the View until it exposes the expected document count (eventual consistency).
     *
     * @throws ArangoException When the count is still wrong after ~15 seconds.
     */
    private function waitForIndexing( int $expected ) :void
    {
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $rows = iterator_to_array
            (
                self::$db->query( 'FOR d IN ' . self::VIEW . ' COLLECT WITH COUNT INTO total RETURN total' ) ,
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
     * A traversal inside a `LET` sub-query is accepted by a real server, and
     * the vertices are bucketed on their `_key` by default.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testEdgeDimensionCountsLinkedVerticesByKey() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'placeKey' ] ) ;

        // Paris: o1 + o2 (twice) + o3 = 4 rows. Lyon: o3 + o4 = 2 rows.
        // Berlin is linked to nobody and is therefore never a bucket.
        $this->assertSame
        (
            [ 'pLyon' => 2 , 'pParis' => 4 ] ,
            $this->buckets( $counts , 'placeKey' ) ,
            'The default bucket is the linked vertex _key.'
        ) ;
    }

    /**
     * `Facet::VALUE` buckets on a field of the vertex, so the sidebar receives
     * labels rather than keys it would have to resolve again.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testEdgeDimensionBucketsOnTheDeclaredVertexField() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'place' ] ) ;

        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ] , $this->buckets( $counts , 'place' ) ) ;
    }

    /**
     * 🔑 The measurement the unit suite cannot make: `COUNT_DISTINCT( doc._key )`
     * really collapses the duplicate rows. Two parallel edges from o2 to Paris
     * make the per-row count say 4 where the documents are 3.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testEdgeDistinctCollapsesTheParallelEdges() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'place,placeDistinct' ] ) ;

        $this->assertSame
        (
            [ 'Lyon' => 2 , 'Paris' => 4 ] ,
            $this->buckets( $counts , 'place' ) ,
            'Per-row: the duplicate edge counts o2 twice.'
        ) ;

        $this->assertSame
        (
            [ 'Lyon' => 2 , 'Paris' => 3 ] ,
            $this->buckets( $counts , 'placeDistinct' ) ,
            'Per-document: o2 is counted once, whatever the number of edges.'
        ) ;
    }

    /**
     * A linked count inherits the list filter, so the buckets tally over
     * exactly the displayed set.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testEdgeDimensionInheritsTheListFilter() :void
    {
        $init   = [ Arango::FACET_COUNTS => 'place,placeDistinct' , Arango::FILTER => [ 'key' => 'kind' , 'val' => 'ngo' ] ] ;
        $counts = $this->model()->facetCounts( $init ) ;

        // Only o1 and o2 are NGOs: Paris keeps 3 rows for 2 documents, Lyon drops out.
        $this->assertSame( [ 'Paris' => 3 ] , $this->buckets( $counts , 'place' ) ) ;
        $this->assertSame( [ 'Paris' => 2 ] , $this->buckets( $counts , 'placeDistinct' ) ) ;
    }

    /**
     * A key-join dimension counts the joined documents, bucketed on one of
     * their fields.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testJoinDimensionCountsJoinedDocuments() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'author' ] ) ;

        // o1 + o2 → Alice ; o3 + o4 → Bob ; o5 has no author and is not counted.
        $this->assertSame( [ 'Alice' => 2 , 'Bob' => 2 ] , $this->buckets( $counts , 'author' ) ) ;
    }

    /**
     * `AQL::ARRAY => true`: the main side holds an array of keys, so the join
     * predicate is a membership test — a document with two tags feeds two buckets.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testJoinDimensionOnAnArrayOfKeys() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'tag' ] ) ;

        // php: o1 + o3 ; db: o2 + o3. o4 has an empty array, o5 none at all.
        $this->assertSame( [ 'Database' => 2 , 'PHP' => 2 ] , $this->buckets( $counts , 'tag' ) ) ;
    }

    /**
     * `AQL::KEY`: the joined side carries the foreign key (the reverse
     * one-to-many), and the same divergence appears — o1 has two French notes.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testJoinDimensionOnAReverseOneToManyAndItsDistinct() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'lang,langDistinct' ] ) ;

        $this->assertSame
        (
            [ 'en' => 1 , 'fr' => 3 ] ,
            $this->buckets( $counts , 'lang' ) ,
            'Per-row: o1 carries two French notes.'
        ) ;

        $this->assertSame
        (
            [ 'en' => 1 , 'fr' => 2 ] ,
            $this->buckets( $counts , 'langDistinct' ) ,
            'Per-document: o1 and o3 — the second note of o1 does not add a document.'
        ) ;
    }

    /**
     * Linked and document-side dimensions live in the same query: one `LET` per
     * dimension, whatever the source.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testSeveralLinkedDimensionsInOneQuery() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'place,author,tag,lang' ] ) ;

        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ]     , $this->buckets( $counts , 'place'  ) ) ;
        $this->assertSame( [ 'Alice' => 2 , 'Bob' => 2 ]      , $this->buckets( $counts , 'author' ) ) ;
        $this->assertSame( [ 'Database' => 2 , 'PHP' => 2 ]   , $this->buckets( $counts , 'tag'    ) ) ;
        $this->assertSame( [ 'en' => 1 , 'fr' => 3 ]          , $this->buckets( $counts , 'lang'   ) ) ;
    }

    /**
     * 🔑 The open question of the audit: when the root `FOR` iterates an
     * **ArangoSearch View** rather than the collection, is the View row still a
     * valid start vertex for `INBOUND doc <edge>`? It is — the traversal
     * follows the same `SEARCH`-narrowed set as the list.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLinkedDimensionsUnderAViewSearch() :void
    {
        $model = $this->model( null ,
        [
            Search::NAME     => self::VIEW ,
            Search::ANALYZER => 'text_en' ,
            Search::FIELDS   => [ 'name' => 1 ] ,
        ]) ;

        $this->waitForIndexing( 5 ) ;

        $init = [ Arango::SEARCH => 'bois' , Arango::FACET_COUNTS => 'place,placeDistinct,lang' ] ;

        // ⚠ The counts alone cannot prove the View was used: without it, the
        // classic LIKE sweep on `name` matches the very same three documents.
        // What separates the two is the query itself, so it is measured.
        $binds = [] ;
        $query = $model->buildFacetCountsQuery( $init , $binds ) ;

        $this->assertStringContainsString( 'FOR doc IN @@view SEARCH' , $query , 'The root FOR must iterate the View, not the collection.' ) ;
        $this->assertStringContainsString( 'FOR doc_place IN INBOUND doc orgs_places' , $query , 'The traversal must start from the View row.' ) ;
        $this->assertArrayHasKey( '@view' , $binds ) ;

        $counts = $model->facetCounts( $init ) ;

        // « bois » matches o1, o2 and o4 only.
        $this->assertSame
        (
            [ 'Lyon' => 1 , 'Paris' => 3 ] ,
            $this->buckets( $counts , 'place' ) ,
            'The traversal starts from the View rows, narrowed by the same SEARCH.'
        ) ;

        $this->assertSame
        (
            [ 'Lyon' => 1 , 'Paris' => 2 ] ,
            $this->buckets( $counts , 'placeDistinct' ) ,
            'COUNT_DISTINCT( doc._key ) works on a View row too — _key is available.'
        ) ;

        $this->assertSame
        (
            [ 'en' => 1 , 'fr' => 2 ] ,
            $this->buckets( $counts , 'lang' ) ,
            'A key-join under a View search joins on the View row _key.'
        ) ;
    }

    /**
     * A linked dimension is gated by the `Field::REQUIRES` declared **on the
     * facet**: refused, it is dropped from the response — never emitted empty,
     * which would itself answer "no linked values".
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testRefusedLinkedDimensionIsDroppedLive() :void
    {
        $facets = self::facets() ;
        $facets[ 'place'  ][ Field::REQUIRES ] = 'geo:read' ;
        $facets[ 'author' ][ Field::REQUIRES ] = 'hr:read' ;

        $model = $this->model( $facets ) ;

        $refused = $model->facetCounts
        ([
            Arango::FACET_COUNTS => 'place,author,tag' ,
            Arango::AUTHORIZER   => fn() => false ,
        ]) ;

        $this->assertArrayNotHasKey( 'place'  , $refused ) ;
        $this->assertArrayNotHasKey( 'author' , $refused ) ;
        $this->assertSame( [ 'Database' => 2 , 'PHP' => 2 ] , $this->buckets( $refused , 'tag' ) , 'The ungated dimension is unaffected.' ) ;

        $granted = $model->facetCounts
        ([
            Arango::FACET_COUNTS => 'place,author,tag' ,
            Arango::AUTHORIZER   => fn( string $subject ) => $subject === 'geo:read' ,
        ]) ;

        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ] , $this->buckets( $granted , 'place' ) , 'The granted subject restores its dimension.' ) ;
        $this->assertArrayNotHasKey( 'author' , $granted , 'The other subject stays refused.' ) ;
    }

    /**
     * `Facet::LIMIT` keeps the **biggest** buckets, which is the one thing an
     * assertion on the AQL string cannot establish: a `LIMIT 1` placed before
     * the sort — or a sort the server ordered otherwise — would return the same
     * *number* of buckets and the wrong one. Both relations are asked for their
     * single top bucket, and both answer the value with the highest count.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLimitKeepsTheBiggestBucketLive() :void
    {
        $model = $this->model() ;

        // Unlimited, the two dimensions have two buckets each, and the biggest
        // is unambiguous: Paris (4) over Lyon (2), fr (3) over en (1).
        $counts = $model->facetCounts( [ Arango::FACET_COUNTS => 'place,lang' ] ) ;

        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ] , $this->buckets( $counts , 'place' ) ) ;
        $this->assertSame( [ 'en' => 1 , 'fr' => 3 ]      , $this->buckets( $counts , 'lang'  ) ) ;

        $top = $model->facetCounts( [ Arango::FACET_COUNTS => 'placeTop,langTop' ] ) ;

        $this->assertSame( [ 'Paris' => 4 ] , $this->buckets( $top , 'placeTop' ) , 'The kept bucket must be the biggest, not the first met.' ) ;
        $this->assertSame( [ 'fr' => 3 ]    , $this->buckets( $top , 'langTop'  ) ) ;
    }

    /**
     * 🔑 Equal-count buckets come back in a **stable, reproducible** order, and
     * a `LIMIT` cutting through them keeps the same subset every time.
     *
     * Two dimensions of the seed are genuinely tied — Alice and Bob hold two
     * organizations each, PHP and Database two each — which is what makes this
     * measurable at all: sorted on the count alone, either value could lead, and
     * `authorTop` (`LIMIT 1`) would answer Alice or Bob depending on the plan.
     * The query is run several times, because a single green run proves nothing
     * about an order that is merely *usually* the same.
     *
     * ⚠ This case was **checked against the code it guards**: with the second
     * sort criterion removed, the server answers `Bob, Alice` here — the reverse
     * — so the assertion fails as it should. A tie order that happened to be
     * alphabetical anyway would have made a green run meaningless.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTiedBucketsKeepAStableOrderLive() :void
    {
        $model = $this->model() ;

        for ( $run = 0 ; $run < 5 ; $run++ )
        {
            $counts = $model->facetCounts( [ Arango::FACET_COUNTS => 'author,tag,authorTop' ] ) ;

            $this->assertSame( [ 'Alice' , 'Bob' ] , $this->orderedValues( $counts , 'author' ) , 'Tied buckets are ordered by value (run ' . $run . ').' ) ;
            $this->assertSame( [ 'Database' , 'PHP' ] , $this->orderedValues( $counts , 'tag' ) ) ;

            // The whole point: a LIMIT cutting through a run of equal counts
            // keeps the same bucket every time, not an arbitrary one.
            $this->assertSame( [ 'Alice' ] , $this->orderedValues( $counts , 'authorTop' ) ) ;
        }
    }

    /**
     * The count still leads the order: a bigger bucket precedes a smaller one
     * whatever their values, so the tie-breaker never becomes the main sort.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheCountStillLeadsTheOrder() :void
    {
        $counts = $this->model()->facetCounts( [ Arango::FACET_COUNTS => 'place,lang' ] ) ;

        // Paris (4) before Lyon (2), though "Lyon" sorts first alphabetically.
        $this->assertSame( [ 'Paris' , 'Lyon' ] , $this->orderedValues( $counts , 'place' ) ) ;

        // fr (3) before en (1), though "en" sorts first alphabetically.
        $this->assertSame( [ 'fr' , 'en' ] , $this->orderedValues( $counts , 'lang' ) ) ;
    }

    /**
     * The request has the last word over the declaration: `?facetCountsLimit=`
     * lowers it, raises it, and `all` cancels it — measured against a real
     * server, on a dimension that declares `Facet::LIMIT => 1`.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testRequestLimitOverridesTheDeclarationLive() :void
    {
        $model = $this->model() ;

        // Declared: 1 bucket. The request raises it to 2 — both places come back.
        $raised = $model->facetCounts([ Arango::FACET_COUNTS => 'placeTop' , Arango::FACET_COUNTS_LIMIT => 2 ]) ;
        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ] , $this->buckets( $raised , 'placeTop' ) ) ;

        // `all` (which the controller sends as false) cancels the declaration.
        $all = $model->facetCounts([ Arango::FACET_COUNTS => 'placeTop' , Arango::FACET_COUNTS_LIMIT => false ]) ;
        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ] , $this->buckets( $all , 'placeTop' ) ) ;

        // And the request lowers an undeclared dimension just as well.
        $lowered = $model->facetCounts([ Arango::FACET_COUNTS => 'place' , Arango::FACET_COUNTS_LIMIT => 1 ]) ;
        $this->assertSame( [ 'Paris' => 4 ] , $this->buckets( $lowered , 'place' ) , 'The biggest bucket survives.' ) ;

        // Asking for more buckets than exist returns the ones that exist.
        $excess = $model->facetCounts([ Arango::FACET_COUNTS => 'place' , Arango::FACET_COUNTS_LIMIT => 10_000 ]) ;
        $this->assertSame( [ 'Lyon' => 2 , 'Paris' => 4 ] , $this->buckets( $excess , 'place' ) ) ;
    }

    /**
     * `?metaOnly=` comes for free with the linked dimensions: the sidebar gets
     * its buckets and the exact total, without a single document.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLinkedCountsAgreeWithTheListAndItsCount() :void
    {
        $model = $this->model() ;

        $init = [ Arango::FILTER => [ 'key' => 'kind' , 'val' => 'ngo' ] ] ;

        // The count of documents and the sum of the DISTINCT buckets describe
        // the same set: 2 NGOs, both in Paris.
        $this->assertSame( 2 , $model->count( $init ) ) ;

        $buckets = $this->buckets( $model->facetCounts( [ ...$init , Arango::FACET_COUNTS => 'placeDistinct' ] ) , 'placeDistinct' ) ;

        $this->assertSame( 2 , array_sum( $buckets ) , 'Each NGO is counted once across the buckets it belongs to.' ) ;
    }
}
