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
}
