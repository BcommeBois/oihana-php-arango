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
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\Search;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of the model-level View search (Lot S4a): the `AQL::VIEW`
 * declaration provisions a real `arangosearch` View at model initialization,
 * `?search=` switches from the LIKE sweep to a relevance-ranked `SEARCH`, the
 * synthetic `score` sort key drives the ordering, and `count()` agrees with
 * `list()`. A model **without** the declaration keeps the classic LIKE sweep
 * on the same collection (backward compatibility).
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ViewSearchIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_viewsearch_it' ;

    private const string COLLECTION = 'places' ;

    private const string VIEW = 'placesView' ;

    /**
     * Seeds three documents — the View itself is provisioned later, by the
     * first model construction (that is the point of the test).
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $places = $db->collection( self::COLLECTION ) ;
        $places->create() ;

        $places->insert( [ 'label' => 'A' , 'kind' => 'scierie' , 'code' => 'REF001' , 'name' => 'Scierie de la Loire' , 'description' => 'le bois de chêne et de sapin' , 'summary' => 'sawmill and timber'         ] ) ;
        $places->insert( [ 'label' => 'B' , 'kind' => 'atelier' , 'code' => 'REF002' , 'name' => 'Atelier du bois'     , 'description' => 'menuiserie fine'             , 'summary' => 'fine woodworking workshops' ] ) ;
        $places->insert( [ 'label' => 'C' , 'kind' => 'atelier' , 'code' => 'REF003' , 'name' => 'Ferronnerie d\'art'  , 'description' => 'le métal forgé'              , 'summary' => 'metal forging studio'       ] ) ;
    }

    /**
     * A Documents model wired to the disposable database, with the `AQL::VIEW`
     * declaration (name boosted ×3, exact-phrase bonus, fuzzy distance 1).
     * Lazy mode is ON so the construction provisions the View.
     *
     * @throws TomlError
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     */
    private function model( ?array $view = null ) :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::FACETS      => [ 'kind' => [ Facet::TYPE => Facet::FIELD ] ] ,
            AQL::SEARCHABLE  => [ 'name' , 'description' ] ,
            AQL::SORTABLE    => [ 'name' => 'name' ] ,
            AQL::VIEW        => $view ??
            [
                Search::NAME     => self::VIEW ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 3 , 'description' => 1 ] ,
                Search::PHRASE   => true ,
                Search::FUZZY    => 1 ,
            ] ,
        ]) ;
    }

    /**
     * Polls the View until it exposes the expected document count (eventual consistency).
     *
     * @throws ArangoException When the count is still wrong after ~15 seconds.
     */
    private function waitForIndexing( int $expected , string $view = self::VIEW ) :void
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

            usleep( 100_000 ) ; // 100 ms
        }

        throw new ArangoException( 'The view never reached ' . $expected . ' indexed documents.' ) ;
    }

    /**
     * The model construction lazily provisions the declared View, and the
     * relevance-ranked search works end to end: « bois » matches the boosted
     * name of B before the description of A, and leaves C out.
     */
    public function testProvisioningAndRelevanceRankedSearch() :void
    {
        $model = $this->model() ;

        $this->assertTrue( $model->viewExists( self::VIEW ) , 'The model construction must create the declared View.' ) ;

        $this->waitForIndexing( 3 ) ;

        $rows   = $model->list( [ Arango::SEARCH => 'bois' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $this->assertSame( [ 'B' , 'A' ] , $labels , 'The boosted name match must rank before the description match.' ) ;
    }

    /**
     * `count()` agrees with `list()` for the same search.
     */
    public function testCountAgreesWithList() :void
    {
        $model = $this->model() ;
        $this->waitForIndexing( 3 ) ;

        $this->assertSame( 2 , $model->count( [ Arango::SEARCH => 'bois' ] ) ) ;
        $this->assertSame( 3 , $model->count() ) ;
    }

    /**
     * `?sort=name` overrides the relevance default; the score key composes.
     */
    public function testExplicitSortOverridesRelevance() :void
    {
        $model = $this->model() ;
        $this->waitForIndexing( 3 ) ;

        $rows   = $model->list( [ Arango::SEARCH => 'bois' , Arango::SORT => 'name' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $this->assertSame( [ 'B' , 'A' ] , $labels ) ; // Atelier du bois < Scierie de la Loire

        $rows   = $model->list( [ Arango::SEARCH => 'bois' , Arango::SORT => '-score,name' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $this->assertSame( [ 'B' , 'A' ] , $labels ) ;
    }

    /**
     * The fuzzy tolerance matches a typo (`boys` → `bois`, Damerau distance 1).
     */
    public function testFuzzySearchToleratesTypo() :void
    {
        $model = $this->model() ;
        $this->waitForIndexing( 3 ) ;

        $rows = $model->list( [ Arango::SEARCH => 'boi' ] ) ;
        $this->assertNotEmpty( $rows , 'A 1-edit typo must still match through LEVENSHTEIN_MATCH.' ) ;
    }

    /**
     * Per-field fuzzy (Lot VF1): under a positive View-level tolerance, a field
     * declaring `Search::FUZZY => 0` stays exact (an identifier must not match
     * a near-miss), while a text field keeps tolerating typos.
     *
     * @throws Throwable
     */
    public function testPerFieldFuzzyKeepsCodeExactWhileNameStaysTolerant() :void
    {
        $view =
        [
            Search::NAME     => 'placesFuzzyView' ,
            Search::ANALYZER => 'text_fr' ,
            Search::FIELDS   =>
            [
                'name' => [ Search::BOOST => 1 , Search::FUZZY => 1 ] , // text : typo-tolerant
                'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] , // code : exact only
            ] ,
            Search::FUZZY => 1 , // positive View-level default, overridden on `code`
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'placesFuzzyView' ) , 'The per-field View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'placesFuzzyView' ) ;

        $labels = fn( array $rows ) => array_map( fn( $r ) => is_array( $r ) ? $r[ 'code' ] : $r->code , $rows ) ;

        $exact = $model->list( [ Arango::SEARCH => 'REF001' ] ) ;
        $this->assertSame( [ 'REF001' ] , $labels( $exact ) , 'An exact code must match through IN TOKENS.' ) ;

        $typo = $model->list( [ Arango::SEARCH => 'REF00' ] ) ;
        $this->assertSame( [] , $labels( $typo ) , 'A near-miss code must NOT match : fuzzy is opted out on `code`.' ) ;

        $name = $model->list( [ Arango::SEARCH => 'boi' ] ) ;
        $this->assertNotEmpty( $name , 'A 1-edit typo on the name must still match : fuzzy stays on for `name`.' ) ;
    }

    /**
     * Per-field Analyzer (Lot VF2): a single View indexes `name` with `text_fr`
     * and `summary` with `text_en`. The French name still matches « bois », and
     * the English summary matches « workshop » through English stemming (its
     * indexed form is `workshops`) — proving each field is queried through its
     * own Analyzer.
     *
     * @throws Throwable
     */
    public function testPerFieldAnalyzerRoutesEachFieldThroughItsAnalyzer() :void
    {
        $view =
        [
            Search::NAME     => 'placesMultiAzView' ,
            Search::ANALYZER => 'text_fr' ,
            Search::FIELDS   =>
            [
                'name'    => 1 ,                                  // View Analyzer : text_fr
                'summary' => [ Search::ANALYZER => 'text_en' ] ,  // per-field override : text_en
            ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'placesMultiAzView' ) , 'The multi-Analyzer View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'placesMultiAzView' ) ;

        $labels = fn( array $rows ) => array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $fr = $model->list( [ Arango::SEARCH => 'bois' ] ) ;
        $this->assertSame( [ 'B' ] , $labels( $fr ) , 'The French name field stays matched through text_fr.' ) ;

        $en = $model->list( [ Arango::SEARCH => 'workshop' ] ) ;
        $this->assertSame( [ 'B' ] , $labels( $en ) , 'The English summary matches through text_en stemming (workshops → workshop).' ) ;
    }

    /**
     * Facet counts follow the same `SEARCH` as the list (Lot S4b): with
     * `?search=bois` the `kind` buckets only count the two matching documents
     * (and the View `SEARCH` is accepted inside the `LET` sub-queries on a
     * real server); without a search the buckets cover the whole collection.
     */
    public function testFacetCountsFollowTheViewSearch() :void
    {
        $model = $this->model() ;
        $this->waitForIndexing( 3 ) ;

        // Buckets are sorted by count DESC: ties have no deterministic order,
        // so the map is re-keyed and sorted by value before asserting.
        $this->assertSame
        (
            [ 'atelier' => 1 , 'scierie' => 1 ] ,
            $this->buckets( $model->facetCounts( [ Arango::SEARCH => 'bois' , Arango::FACET_COUNTS => 'kind' ] ) ) ,
            'Buckets must only count the SEARCH-matched documents.'
        ) ;

        $this->assertSame
        (
            [ 'atelier' => 2 , 'scierie' => 1 ] ,
            $this->buckets( $model->facetCounts( [ Arango::FACET_COUNTS => 'kind' ] ) ) ,
            'Without a search the buckets cover the whole collection.'
        ) ;
    }

    /**
     * Re-keys one dimension's buckets as a `value => count` map, sorted by value.
     *
     * @return array<string,int>
     */
    private function buckets( array $counts , string $dimension = 'kind' ) :array
    {
        $buckets = [] ;
        foreach ( (array) ( $counts[ $dimension ] ?? [] ) as $bucket )
        {
            $bucket = (array) $bucket ;
            $buckets[ $bucket[ 'value' ] ] = $bucket[ 'count' ] ;
        }
        ksort( $buckets ) ;
        return $buckets ;
    }

    /**
     * Without the `AQL::VIEW` declaration the classic LIKE sweep still works,
     * untouched, on the same collection (backward compatibility).
     */
    public function testLikeFallbackWithoutViewDeclaration() :void
    {
        $model = $this->model( view: [] ) ; // empty block → no Search::NAME → inactive

        $rows   = $model->list( [ Arango::SEARCH => 'bois' , Arango::SORT => 'name' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $this->assertSame( [ 'B' , 'A' ] , $labels , 'The LIKE sweep must keep matching name and description.' ) ;
    }
}
