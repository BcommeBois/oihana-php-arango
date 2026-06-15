<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
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

        $places->insert( [ 'label' => 'A' , 'kind' => 'scierie' , 'code' => 'REF001' , 'name' => 'Scierie de la Loire' , 'description' => 'le bois de chêne et de sapin' , 'summary' => 'sawmill and timber'        , 'intro' => [ 'fr' => 'scierie de bois'       , 'en' => 'timber sawmill'        ] , 'tagline' => 'red oak'      , 'secret' => 'dossier alpha'        ] ) ;
        $places->insert( [ 'label' => 'B' , 'kind' => 'atelier' , 'code' => 'REF002' , 'name' => 'Atelier du bois'     , 'description' => 'menuiserie fine'              , 'summary' => 'fine woodworking workshops' , 'intro' => [ 'fr' => 'atelier de menuiserie' , 'en' => 'woodworking workshop'  ] , 'tagline' => 'oak red'     , 'secret' => 'projet confidentiel'  ] ) ;
        $places->insert( [ 'label' => 'C' , 'kind' => 'atelier' , 'code' => 'REF003' , 'name' => 'Ferronnerie d\'art'  , 'description' => 'le métal forgé'               , 'summary' => 'metal forging studio'       , 'intro' => [ 'fr' => 'ferronnerie d art'     , 'en' => 'metal forging'         ] , 'tagline' => 'green metal' , 'secret' => 'note publique'        ] ) ;
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

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => ViewSearchIntegrationTest::$database ] , new NullLogger() ) ;

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
     *
     * @return void
     *
     * @throws ArangoException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
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
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
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
     * @return void
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
     * @return void
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
     * Localized search driven by `?lang=` (Lot VF3): a View indexes the `fr`
     * and `en` sub-fields of an i18n `intro` object, each with its own
     * Analyzer and `Search::LANG` marker. `?lang=fr` searches only the French
     * side, `?lang=en` only the English one.
     *
     * @throws Throwable
     */
    public function testLangRestrictsTheSearchToTheMatchingLocale() :void
    {
        $view =
        [
            Search::NAME     => 'placesI18nView' ,
            Search::ANALYZER => 'text_fr' ,
            Search::FIELDS   =>
            [
                'name'     => 1 ,                                                       // locale-agnostic
                'intro.fr' => [ Search::ANALYZER => 'text_fr' , Search::LANG => 'fr' ] ,
                'intro.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
            ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'placesI18nView' ) , 'The i18n View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'placesI18nView' ) ;

        $labels = fn( array $rows ) => array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        // « menuiserie » lives in intro.fr only.
        $this->assertSame( [ 'B' ] , $labels( $model->list( [ Arango::SEARCH => 'menuiserie' , Arango::LANG => 'fr' ] ) ) , 'lang=fr matches the French side.' ) ;
        $this->assertSame( []      , $labels( $model->list( [ Arango::SEARCH => 'menuiserie' , Arango::LANG => 'en' ] ) ) , 'lang=en excludes the French side.' ) ;

        // « workshop » lives in intro.en only (indexed through text_en).
        $this->assertSame( [ 'B' ] , $labels( $model->list( [ Arango::SEARCH => 'workshop' , Arango::LANG => 'en' ] ) ) , 'lang=en matches the English side.' ) ;
        $this->assertSame( []      , $labels( $model->list( [ Arango::SEARCH => 'workshop' , Arango::LANG => 'fr' ] ) ) , 'lang=fr excludes the English side.' ) ;
    }

    /**
     * Per-field phrase bonus (Lot VF4a): the `tagline` field opts into the
     * exact-phrase bonus while the View-level flag stays off. Searching
     * « red oak » still matches both `red oak` (A) and `oak red` (B) through
     * the token match, but only A — where the phrase is adjacent — receives
     * the `PHRASE()` boost, so it ranks first.
     *
     * @throws Throwable
     */
    public function testPerFieldPhraseBoostsTheAdjacentMatch() :void
    {
        $view =
        [
            Search::NAME     => 'placesPhraseView' ,
            Search::ANALYZER => 'text_fr' ,
            Search::FIELDS   =>
            [
                'tagline' => [ Search::ANALYZER => 'text_en' , Search::PHRASE => true ] , // per-field phrase, global off
            ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'placesPhraseView' ) , 'The phrase View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'placesPhraseView' ) ;

        $rows   = $model->list( [ Arango::SEARCH => 'red oak' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $this->assertSame( [ 'A' , 'B' ] , $labels , 'The adjacent phrase (A) ranks before the scattered match (B); C is out.' ) ;
    }

    /**
     * Per-field search permissions (Lot VF4b): the `secret` field declares
     * `Search::REQUIRES`. The word « confidentiel » lives only there, so the
     * record surfaces only when the request authorizer grants the subject;
     * a denying authorizer drops the field and the search returns nothing.
     *
     * @throws Throwable
     */
    public function testPerFieldRequiresGatesTheSearchableField() :void
    {
        $view =
        [
            Search::NAME     => 'placesGatedView' ,
            Search::ANALYZER => 'text_fr' ,
            Search::FIELDS   =>
            [
                'name'   => 1 ,
                'secret' => [ Search::REQUIRES => 'places:secret' ] ,
            ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'placesGatedView' ) , 'The gated View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'placesGatedView' ) ;

        $labels = fn( array $rows ) => array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $granted = $model->list( [ Arango::SEARCH => 'confidentiel' , Arango::AUTHORIZER => fn() => true  ] ) ;
        $this->assertSame( [ 'B' ] , $labels( $granted ) , 'With the permission, the gated field is searched.' ) ;

        $denied = $model->list( [ Arango::SEARCH => 'confidentiel' , Arango::AUTHORIZER => fn() => false ] ) ;
        $this->assertSame( [] , $labels( $denied ) , 'Without the permission, the gated field is not searched.' ) ;
    }

    /**
     * View-level search permission (Lot VF4c): a `Search::REQUIRES` on the
     * `AQL::VIEW` block gates the whole search. Without the granted subject the
     * search returns nothing even on a public field; with it, it works normally.
     *
     * @throws Throwable
     */
    public function testViewLevelRequiresGatesTheWholeSearch() :void
    {
        $view =
        [
            Search::NAME     => 'placesViewGate' ,
            Search::ANALYZER => 'text_fr' ,
            Search::REQUIRES => 'app:search' ,
            Search::FIELDS   => [ 'name' => 1 ] ,
        ] ;

        $model = $this->model( $view ) ;

        $this->assertTrue( $model->viewExists( 'placesViewGate' ) , 'The gated View must be provisioned.' ) ;

        $this->waitForIndexing( 3 , 'placesViewGate' ) ;

        $labels = fn( array $rows ) => array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $granted = $model->list( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => true  ] ) ;
        $this->assertSame( [ 'B' ] , $labels( $granted ) , 'With the View-level subject, the search works.' ) ;

        $denied = $model->list( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => false ] ) ;
        $this->assertSame( [] , $labels( $denied ) , 'Without it, the whole search returns nothing.' ) ;
    }

    /**
     * Facet counts follow the same `SEARCH` as the list (Lot S4b): with
     * `?search=bois` the `kind` buckets only count the two matching documents
     * (and the View `SEARCH` is accepted inside the `LET` sub-queries on a
     * real server); without a search the buckets cover the whole collection.
     * @return void
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
     * @return void
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
    public function testLikeFallbackWithoutViewDeclaration() :void
    {
        $model = $this->model( view: [] ) ; // empty block → no Search::NAME → inactive

        $rows   = $model->list( [ Arango::SEARCH => 'bois' , Arango::SORT => 'name' ] ) ;
        $labels = array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;

        $this->assertSame( [ 'B' , 'A' ] , $labels , 'The LIKE sweep must keep matching name and description.' ) ;
    }
}
