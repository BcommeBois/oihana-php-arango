<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;
use ReflectionMethod;

use oihana\arango\clients\view\ArangoSearchLink;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\results\DiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\Search;

/**
 * Tests for the model-level View search (Lot S4a): the `AQL::VIEW` declaration,
 * the `?search=` switch from the LIKE sweep to a relevance-ranked `SEARCH`
 * against the View, the synthetic `score` sort key, and the `list()`/`count()`
 * synchronization.
 */
#[AllowMockObjectsWithoutExpectations]
class ViewSearchTest extends TestCase
{
    private array $binds ;

    protected function setUp() :void
    {
        $this->binds = [] ;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function model( array $init = [] ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            AQL::COLLECTION => 'places' ,
            AQL::LAZY       => false ,
            ...$init ,
        ]) ;
    }

    private function viewModel( array $view = [] , array $init = [] ) :Documents
    {
        return $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'placesView' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 3 , 'description' => 1 ] ,
                ...$view ,
            ] ,
            ...$init ,
        ]) ;
    }

    // ---------------------------------------------------------------- initializeView

    public function testInitializeViewProvisionsTheViewWhenLazy() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewExists' )->willReturn( false ) ;
        $facade->expects( $this->once() )
               ->method( 'viewCreate' )
               ->with( 'placesView' , $this->callback(
                   fn( array $links ) => ( $links[ 'places' ] ?? null ) instanceof ArangoSearchLink
               ) )
               ->willReturn( true ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => true ,
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;
    }

    public function testInitializeViewSkipsProvisioningWhenTheViewExists() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewExists' )->willReturn( true ) ;
        $facade->expects( $this->never() )->method( 'viewCreate' ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => true ,
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;
    }

    public function testViewDelegationsAreNullSafeWithoutDatabase() :void
    {
        $model = $this->viewModel() ;
        $this->assertFalse( $model->viewExists( 'placesView' ) ) ;
        $this->assertFalse( $model->viewCreate( 'placesView' ) ) ;
    }

    public function testInitializeViewStoresTheDeclaration() :void
    {
        $model = $this->viewModel() ;
        $this->assertIsArray( $model->view ) ;
        $this->assertSame( 'placesView' , $model->view[ Search::NAME ] ) ;
    }

    public function testModelWithoutViewDeclarationKeepsNull() :void
    {
        $this->assertNull( $this->model()->view ) ;
    }

    // ---------------------------------------------------------------- hasViewSearch

    public function testHasViewSearchTrueWithTermAndDeclaration() :void
    {
        $model = $this->viewModel() ;
        $this->assertTrue( $model->hasViewSearch( [ Arango::SEARCH => 'bois' ] ) ) ;
        $this->assertTrue( $model->hasViewSearch( 'bois' ) ) ;
    }

    public function testHasViewSearchFalseWithoutTerm() :void
    {
        $model = $this->viewModel() ;
        $this->assertFalse( $model->hasViewSearch( [] ) ) ;
        $this->assertFalse( $model->hasViewSearch( [ Arango::SEARCH => '' ] ) ) ;
        $this->assertFalse( $model->hasViewSearch( null ) ) ;
    }

    public function testHasViewSearchFalseWithoutDeclaration() :void
    {
        $this->assertFalse( $this->model()->hasViewSearch( 'bois' ) ) ;
    }

    public function testHasViewSearchFalseWithoutName() :void
    {
        $model = $this->model( [ AQL::VIEW => [ Search::FIELDS => [ 'name' => 1 ] ] ] ) ;
        $this->assertFalse( $model->hasViewSearch( 'bois' ) ) ;
    }

    public function testHasViewSearchFalseWithoutAnyField() :void
    {
        $model = $this->model( [ AQL::VIEW => [ Search::NAME => 'placesView' ] ] ) ;
        $this->assertFalse( $model->hasViewSearch( 'bois' ) ) ;
    }

    // ---------------------------------------------------------------- prepareViewSearch

    public function testPrepareViewSearchReturnsNullWhenInactive() :void
    {
        $this->assertNull( $this->viewModel()->prepareViewSearch( [] , $this->binds ) ) ;
        $this->assertNull( $this->model()->prepareViewSearch( 'bois' , $this->binds ) ) ;
    }

    public function testPrepareViewSearchMinimalGrammar() :void
    {
        $model = $this->model(
        [
            AQL::VIEW => [ Search::NAME => 'v' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0' => 'bois' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchBoostsAndOptions() :void
    {
        $model = $this->viewModel( [ Search::PHRASE => true , Search::FUZZY => 1 ] ) ;

        $this->assertSame
        (
            'ANALYZER('
            . 'BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3)'
            . ' || BOOST(PHRASE(doc.name,@search_0),6)'
            . ' || LEVENSHTEIN_MATCH(doc.name,@search_0,1)'
            . ' || doc.description IN TOKENS(@search_0,"text_fr")'
            . ' || BOOST(PHRASE(doc.description,@search_0),2)'
            . ' || LEVENSHTEIN_MATCH(doc.description,@search_0,1)'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchMultipleTerms() :void
    {
        $model = $this->model(
        [
            AQL::VIEW => [ Search::NAME => 'v' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr") || doc.name IN TOKENS(@search_1,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois,fer' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0' => 'bois' , 'search_1' => 'fer' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchFallsBackOnSearchable() :void
    {
        $model = $this->model(
        [
            AQL::SEARCHABLE => [ 'name' ] ,
            AQL::VIEW       => [ Search::NAME => 'v' , Search::ANALYZER => 'text_fr' ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchDefaultsToIdentityAnalyzer() :void
    {
        $model = $this->model( [ AQL::VIEW => [ Search::NAME => 'v' , Search::FIELDS => [ 'tag' => 1 ] ] ] ) ;

        $this->assertSame
        (
            'ANALYZER(doc.tag IN TOKENS(@search_0,"identity"),"identity")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchAcceptsArrayFieldConfig() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => [ Search::BOOST => 2 ] ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(BOOST(doc.name IN TOKENS(@search_0,"text_fr"),2),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchFuzzyPerFieldOverridesTheGlobal() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FUZZY    => 2 ,
                Search::FIELDS   =>
                [
                    'name' => [ Search::BOOST => 1 , Search::FUZZY => 1 ] , // overrides the global 2
                    'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] , // opts out under a positive global
                    'tag'  => 1 ,                                           // inherits the global 2
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER('
            . 'doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || LEVENSHTEIN_MATCH(doc.name,@search_0,1)'
            . ' || doc.code IN TOKENS(@search_0,"text_fr")'
            . ' || doc.tag IN TOKENS(@search_0,"text_fr")'
            . ' || LEVENSHTEIN_MATCH(doc.tag,@search_0,2)'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchFuzzyPerFieldWithoutGlobal() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name' => [ Search::FUZZY => 1 ] , // boost defaults to 1
                    'code' => 1 ,                      // no fuzzy, no global → exact
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER('
            . 'doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || LEVENSHTEIN_MATCH(doc.name,@search_0,1)'
            . ' || doc.code IN TOKENS(@search_0,"text_fr")'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchCustomDocRef() :void
    {
        $model = $this->model(
        [
            AQL::VIEW => [ Search::NAME => 'v' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(d.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds , 'd' )
        ) ;
    }

    // ---------------------------------------------------------------- buildListQuery

    public function testListQueryWithoutSearchIsUnchanged() :void
    {
        $query = $this->viewModel()->buildListQuery( [ Arango::LIMIT => 10 ] , $this->binds ) ;

        $this->assertSame( 'FOR doc IN @@collection LIMIT 10 RETURN doc' , $query ) ;
        $this->assertArrayHasKey( '@collection' , $this->binds ) ;
        $this->assertArrayNotHasKey( '@view' , $this->binds ) ;
    }

    public function testListQuerySwitchesToTheView() :void
    {
        $query = $this->viewModel()->buildListQuery( [ Arango::SEARCH => 'bois' ] , $this->binds ) ;

        $this->assertStringStartsWith( 'FOR doc IN @@view SEARCH ANALYZER(' , $query ) ;
        $this->assertStringContainsString( 'SORT BM25(doc) DESC' , $query ) ;
        $this->assertStringNotContainsString( 'LIKE(' , $query ) ;
        $this->assertSame( 'placesView' , $this->binds[ '@view' ] ?? null ) ;
        $this->assertArrayNotHasKey( '@collection' , $this->binds ) ;
    }

    public function testListQueryKeepsTheLikeSweepWithoutViewDeclaration() :void
    {
        $model = $this->model( [ AQL::SEARCHABLE => [ 'name' ] ] ) ;
        $query = $model->buildListQuery( [ Arango::SEARCH => 'bois' ] , $this->binds ) ;

        $this->assertStringStartsWith( 'FOR doc IN @@collection FILTER (LIKE(doc.name' , $query ) ;
        $this->assertSame( '%bois%' , $this->binds[ 'search_0' ] ?? null ) ;
    }

    public function testListQueryKeepsFiltersInViewMode() :void
    {
        $query = $this->viewModel()->buildListQuery(
        [
            Arango::SEARCH  => 'bois' ,
            AQL::CONDITIONS => [ 'doc.active == true' ] ,
        ] , $this->binds ) ;

        $this->assertStringContainsString( 'FILTER doc.active == true' , $query ) ;
        $this->assertGreaterThan
        (
            strpos( $query , 'SEARCH ' ) ,
            strpos( $query , 'FILTER ' ) ,
            'The FILTER must come after the SEARCH segment.'
        ) ;
    }

    public function testListQueryExplicitSortOverridesTheScoreDefault() :void
    {
        $model = $this->viewModel( init: [ AQL::SORTABLE => [ 'name' => 'name' ] ] ) ;
        $query = $model->buildListQuery( [ Arango::SEARCH => 'bois' , Arango::SORT => 'name' ] , $this->binds ) ;

        $this->assertStringContainsString( 'SORT doc.name ASC' , $query ) ;
        $this->assertStringNotContainsString( 'BM25' , $query ) ;
    }

    public function testListQueryComposesScoreAndFieldSort() :void
    {
        $model = $this->viewModel( init: [ AQL::SORTABLE => [ 'name' => 'name' ] ] ) ;
        $query = $model->buildListQuery( [ Arango::SEARCH => 'bois' , Arango::SORT => '-score,name' ] , $this->binds ) ;

        $this->assertStringContainsString( 'SORT BM25(doc) DESC, doc.name ASC' , $query ) ;
    }

    public function testScoreSortIsDroppedWithoutActiveSearch() :void
    {
        $model = $this->viewModel( init: [ AQL::SORTABLE => [ 'name' => 'name' ] ] ) ;
        $query = $model->buildListQuery( [ Arango::SORT => 'score' ] , $this->binds ) ;

        $this->assertStringNotContainsString( 'BM25' , $query ) ;
        $this->assertStringStartsWith( 'FOR doc IN @@collection' , $query ) ;
    }

    // ---------------------------------------------------------------- buildCountQuery

    public function testCountQuerySwitchesToTheViewWithTheList() :void
    {
        $model  = $this->viewModel() ;
        $method = new ReflectionMethod( $model , 'buildCountQuery' ) ;

        $query = $method->invokeArgs( $model , [ [ Arango::SEARCH => 'bois' ] , &$this->binds ] ) ;

        $this->assertStringStartsWith( 'RETURN LENGTH(FOR doc IN @@view SEARCH ANALYZER(' , $query ) ;
        $this->assertStringNotContainsString( 'LIKE(' , $query ) ;
        $this->assertSame( 'placesView' , $this->binds[ '@view' ] ?? null ) ;
        $this->assertArrayNotHasKey( '@collection' , $this->binds ) ;
    }

    public function testCountQueryKeepsTheClassicShapeWithoutSearch() :void
    {
        $model  = $this->viewModel() ;
        $method = new ReflectionMethod( $model , 'buildCountQuery' ) ;

        $query = $method->invokeArgs( $model , [ [] , &$this->binds ] ) ;

        $this->assertSame( 'RETURN LENGTH(FOR doc IN @@collection RETURN 1)' , $query ) ;
        $this->assertArrayNotHasKey( '@view' , $this->binds ) ;
    }

    public function testCountQueryOptimizedIsUntouched() :void
    {
        $model  = $this->viewModel() ;
        $method = new ReflectionMethod( $model , 'buildCountQuery' ) ;

        $query = $method->invokeArgs( $model , [ [ Arango::OPTIMIZED => true ] , &$this->binds ] ) ;

        $this->assertSame( 'RETURN LENGTH(@@collection)' , $query ) ;
    }

    // ---------------------------------------------------------------- buildFacetCountsQuery

    public function testFacetCountsQuerySwitchesToTheView() :void
    {
        $model = $this->viewModel( init: [ AQL::FACETS => [ 'kind' => [ Facet::TYPE => Facet::FIELD ] ] ] ) ;

        $query = $model->buildFacetCountsQuery(
        [
            Arango::SEARCH       => 'bois' ,
            Arango::FACET_COUNTS => 'kind' ,
        ] , $this->binds ) ;

        $this->assertStringStartsWith( 'LET kind = (FOR doc IN @@view SEARCH ANALYZER(' , $query ) ;
        $this->assertStringNotContainsString( 'LIKE(' , $query ) ;
        $this->assertSame( 'placesView' , $this->binds[ '@view' ] ?? null ) ;
        $this->assertArrayNotHasKey( '@collection' , $this->binds ) ;
    }

    public function testFacetCountsQuerySharesOneSearchExpressionAcrossDimensions() :void
    {
        $model = $this->viewModel( init:
        [
            AQL::FACETS =>
            [
                'kind' => [ Facet::TYPE => Facet::FIELD ] ,
                'tags' => [ Facet::TYPE => Facet::IN    ] ,
            ] ,
        ]) ;

        $query = $model->buildFacetCountsQuery(
        [
            Arango::SEARCH       => 'bois' ,
            Arango::FACET_COUNTS => 'kind,tags' ,
        ] , $this->binds ) ;

        $this->assertSame( 2 , substr_count( $query , 'FOR doc IN @@view SEARCH ' ) , 'Every dimension iterates the View.' ) ;
        $this->assertStringContainsString( 'FOR item IN doc.tags' , $query , 'The IN facet still unwinds after the SEARCH.' ) ;
        $this->assertSame( [ 'search_0' , '@view' ] , array_keys( $this->binds ) , 'One shared search bind and one View bind.' ) ;
    }

    public function testFacetCountsQueryClassicWithoutSearch() :void
    {
        $model = $this->viewModel( init: [ AQL::FACETS => [ 'kind' => [ Facet::TYPE => Facet::FIELD ] ] ] ) ;

        $query = $model->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'kind' ] , $this->binds ) ;

        $this->assertSame
        (
            'LET kind = (FOR doc IN @@collection COLLECT value = doc.kind WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {kind}' ,
            $query
        ) ;
        $this->assertArrayNotHasKey( '@view' , $this->binds ) ;
    }

    public function testFacetCountsQueryKeepsTheLikeSweepWithoutViewDeclaration() :void
    {
        $model = $this->model(
        [
            AQL::SEARCHABLE => [ 'name' ] ,
            AQL::FACETS     => [ 'kind' => [ Facet::TYPE => Facet::FIELD ] ] ,
        ]) ;

        $query = $model->buildFacetCountsQuery(
        [
            Arango::SEARCH       => 'bois' ,
            Arango::FACET_COUNTS => 'kind' ,
        ] , $this->binds ) ;

        $this->assertStringContainsString( 'FOR doc IN @@collection FILTER (LIKE(doc.name' , $query ) ;
        $this->assertSame( '%bois%' , $this->binds[ 'search_0' ] ?? null ) ;
    }

    // ---------------------------------------------------------------- buildViewLink

    public function testBuildViewLinkNestsDottedPaths() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 , 'description.fr' => 1 ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        $this->assertInstanceOf( ArangoSearchLink::class , $link ) ;
        $this->assertSame
        (
            [
                'fields' =>
                [
                    'name'        => [ 'analyzers' => [ 'text_fr' ] ] ,
                    'description' => [ 'fields' => [ 'fr' => [ 'analyzers' => [ 'text_fr' ] ] ] ] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testGetViewSearchFieldsNormalizesEveryDeclarationShape() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name'        => 3 ,                        // numeric shorthand
                    'description' => [ Search::BOOST => 2 ] ,   // array with boost
                    'label'       => null ,                     // anything else → neutral boost
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'getViewSearchFields' ) ;

        $this->assertSame
        (
            [ 'name' => 3.0 , 'description' => 2.0 , 'label' => 1.0 ] ,
            $method->invoke( $model )
        ) ;
    }

    public function testGetViewFieldSpecsKeepsFuzzyOnlyWhenDeclared() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name' => 3 ,                                            // numeric shorthand → boost only
                    'code' => [ Search::BOOST => 1 , Search::FUZZY => 0 ] ,  // explicit fuzzy 0 is kept
                    'tag'  => [ Search::FUZZY => 2 ] ,                       // boost defaults to 1
                    'note' => [ Search::BOOST => 2 ] ,                       // no fuzzy key → inherits
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'getViewFieldSpecs' ) ;

        $this->assertSame
        (
            [
                'name' => [ Search::BOOST => 3.0 ] ,
                'code' => [ Search::BOOST => 1.0 , Search::FUZZY => 0 ] ,
                'tag'  => [ Search::BOOST => 1.0 , Search::FUZZY => 2 ] ,
                'note' => [ Search::BOOST => 2.0 ] ,
            ] ,
            $method->invoke( $model )
        ) ;
    }

    // ---------------------------------------------------------------- getViewName / getViewLinks

    public function testGetViewNameReturnsTheDeclaredName() :void
    {
        $this->assertSame( 'placesView' , $this->viewModel()->getViewName() ) ;
    }

    public function testGetViewNameIsNullWithoutDeclaration() :void
    {
        $this->assertNull( $this->model()->getViewName() ) ;
    }

    public function testGetViewLinksMapsTheCollectionToTheBuiltLink() :void
    {
        $links = $this->viewModel()->getViewLinks() ;

        $this->assertSame( [ 'places' ] , array_keys( $links ) ) ;
        $this->assertInstanceOf( ArangoSearchLink::class , $links[ 'places' ] ) ;
    }

    public function testGetViewLinksIsEmptyWithoutCollection() :void
    {
        $model = $this->model( [ AQL::COLLECTION => null ] ) ;
        $this->assertSame( [] , $model->getViewLinks() ) ;
    }

    // ---------------------------------------------------------------- viewDiff (model level)

    /**
     * A model bound to a mocked façade, declaring the fixture View.
     */
    private function facadeModel( ArangoDB $facade , array $view = [] , array $init = [] ) :Documents
    {
        return $this->viewModel( $view ,
        [
            Arango::DATABASE => $facade ,
            ...$init ,
        ]) ;
    }

    /**
     * A façade double answering a healthy server : analyzer and collection
     * known, `viewDiff()` returning the given report.
     */
    private function healthyFacade( DiffReport $report ) :ArangoDB
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerExists'   )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( $report ) ;
        return $facade ;
    }

    public function testViewDiffIsInvalidWithoutDeclaration() :void
    {
        $report = $this->model()->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( 'declaration : no View name (Search::NAME)' , $report->changes ) ;
    }

    public function testViewDiffIsInvalidWithoutSearchedFields() :void
    {
        $report = $this->model( [ AQL::VIEW => [ Search::NAME => 'placesView' ] ] )->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( 'declaration : no searched field (Search::FIELDS or searchable)' , $report->changes ) ;
    }

    public function testViewDiffIsInvalidWithoutCollection() :void
    {
        $model = $this->model(
        [
            AQL::COLLECTION => null ,
            AQL::VIEW       => [ Search::NAME => 'placesView' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;

        $report = $model->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( 'declaration : no collection' , $report->changes ) ;
    }

    public function testViewDiffIsUnreachableWithoutDatabase() :void
    {
        $report = $this->viewModel()->viewDiff() ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
        $this->assertSame( [ 'no database available' ] , $report->changes ) ;
    }

    public function testViewDiffForwardsAHealthyFacadeReport() :void
    {
        $expected = new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ;
        $report   = $this->facadeModel( $this->healthyFacade( $expected ) )->viewDiff() ;

        $this->assertSame( $expected , $report ) ;
    }

    public function testViewDiffIsInvalidWhenTheAnalyzerIsUnknown() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerExists'   )->willReturn( false ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ;

        $report = $this->facadeModel( $facade )->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( "analyzer 'text_fr' not found on the server" , $report->changes ) ;
    }

    public function testViewDiffIsInvalidWhenTheCollectionIsUnknownAndKeepsTheDriftLines() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerExists'   )->willReturn( true ) ;
        $facade->method( 'collectionExists' )->willReturn( false ) ;
        $facade->method( 'viewDiff'         )->willReturn( new DiffReport( 'placesView' , DiffStatus::DRIFTED , [ 'places.fields.name : not indexed on the server' ] ) ) ;

        $report = $this->facadeModel( $facade )->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( "collection 'places' not found on the server" , $report->changes ) ;
        $this->assertContains( 'places.fields.name : not indexed on the server' , $report->changes ) ;
    }

    public function testViewDiffReturnsAnUnreachableFacadeReportWithoutCoherenceChecks() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewDiff' )->willReturn( new DiffReport( 'placesView' , DiffStatus::UNREACHABLE , [ 'boom' ] ) ) ;
        $facade->expects( $this->never() )->method( 'analyzerExists' ) ;
        $facade->expects( $this->never() )->method( 'collectionExists' ) ;

        $report = $this->facadeModel( $facade )->viewDiff() ;

        $this->assertSame( DiffStatus::UNREACHABLE , $report->status ) ;
    }

    // ---------------------------------------------------------------- viewSync (model level)

    public function testViewSyncLeavesAnInSyncReportUntouched() :void
    {
        $facade = $this->healthyFacade( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ;
        $facade->expects( $this->never() )->method( 'viewSync' ) ;

        $report = $this->facadeModel( $facade )->viewSync() ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testViewSyncLeavesAnInvalidReportUntouched() :void
    {
        $report = $this->model()->viewSync() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testViewSyncDelegatesToTheFacadeWhenActionable() :void
    {
        $applied = new DiffReport( 'placesView' , DiffStatus::MISSING , [] , true ) ;

        $facade = $this->healthyFacade( new DiffReport( 'placesView' , DiffStatus::MISSING ) ) ;
        $facade->expects( $this->once() )
               ->method( 'viewSync' )
               ->with( 'placesView' , $this->callback(
                   fn( array $links ) => ( $links[ 'places' ] ?? null ) instanceof ArangoSearchLink
               ) )
               ->willReturn( $applied ) ;

        $this->assertSame( $applied , $this->facadeModel( $facade )->viewSync() ) ;
    }

    // ---------------------------------------------------------------- LazyTrait container kill-switch

    public function testContainerLazyEntryDisablesTheViewProvisioning() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewExists' )->willReturn( false ) ;
        $facade->expects( $this->never() )->method( 'viewCreate' ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        $container->set( Arango::LAZY , false ) ;

        new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => true ,
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;
    }

    public function testContainerLazyEntryDisablesTheCollectionProvisioning() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'collectionExists' )->willReturn( false ) ;
        $facade->expects( $this->never() )->method( 'collectionCreate' ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        $container->set( Arango::LAZY , false ) ;

        new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => true ,
        ]) ;
    }

    public function testContainerLazyEntryWinsOverTheInitKey() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'viewExists' )->willReturn( false ) ;
        $facade->expects( $this->once() )->method( 'viewCreate' )->willReturn( true ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;
        $container->set( Arango::LAZY , true ) ;

        new Documents( $container ,
        [
            Arango::DATABASE => $facade ,
            AQL::COLLECTION  => 'places' ,
            AQL::LAZY        => false ,
            AQL::VIEW        => [ Search::NAME => 'placesView' , Search::FIELDS => [ 'name' => 1 ] ] ,
        ]) ;
    }

    public function testAnalyzerExistsDelegationIsNullSafeWithoutDatabase() :void
    {
        $this->assertFalse( $this->viewModel()->analyzerExists( 'text_fr' ) ) ;
    }
}
