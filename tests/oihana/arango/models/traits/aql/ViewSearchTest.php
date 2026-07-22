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
use oihana\arango\db\enums\Logic;
use oihana\arango\db\results\DiffReport;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\Search;
use oihana\exceptions\ValidationException;

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

    public function testPrepareViewSearchGroupsExpressionsPerFieldAnalyzer() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'  => [ Search::BOOST => 1 ] ,                  // model analyzer (text_fr)
                    'title' => [ Search::ANALYZER => 'text_en' ] ,      // per-field override
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(doc.title IN TOKENS(@search_0,"text_en"),"text_en")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchPhrasePerFieldEnablesWithoutGlobal() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'        => [ Search::BOOST => 3 , Search::PHRASE => true ] , // phrase bonus on
                    'description' => 1 ,                                              // no phrase (no global)
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER('
            . 'BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3)'
            . ' || BOOST(PHRASE(doc.name,@search_0),6)'
            . ' || doc.description IN TOKENS(@search_0,"text_fr")'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchPhrasePerFieldOptsOutOfTheGlobal() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::PHRASE   => true , // View-level phrase bonus
                Search::FIELDS   =>
                [
                    'name' => 1 ,                              // inherits the global → phrase on
                    'code' => [ Search::PHRASE => false ] ,    // opts out
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER('
            . 'doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || BOOST(PHRASE(doc.name,@search_0),2)'
            . ' || doc.code IN TOKENS(@search_0,"text_fr")'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    /**
     * A model whose View mixes a locale-agnostic field and two localized
     * sub-fields, each with its own Analyzer.
     */
    private function i18nModel() :Documents
    {
        return $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'           => 1 ,                                                     // locale-agnostic
                    'description.fr' => [ Search::LANG => 'fr' ] ,                              // French (model analyzer)
                    'description.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] , // English
                ] ,
            ] ,
        ]) ;
    }

    public function testPrepareViewSearchWithoutLangSearchesEveryField() :void
    {
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.description.fr IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(doc.description.en IN TOKENS(@search_0,"text_en"),"text_en")' ,
            $this->i18nModel()->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchLangKeepsTheMatchingLocaleAndAgnosticFields() :void
    {
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.description.fr IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $this->i18nModel()->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::LANG => 'fr' ] , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchLangSelectsTheOtherLocale() :void
    {
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(doc.description.en IN TOKENS(@search_0,"text_en"),"text_en")' ,
            $this->i18nModel()->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::LANG => 'en' ] , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchUnknownLangFallsBackToEveryField() :void
    {
        // Every field is localized and none matches `de` → the filter is ignored.
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'description.fr' => [ Search::LANG => 'fr' ] ,
                    'description.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.description.fr IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(doc.description.en IN TOKENS(@search_0,"text_en"),"text_en")' ,
            $model->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::LANG => 'de' ] , $this->binds )
        ) ;
    }

    /**
     * A model whose View mixes a public field and a permission-gated one.
     */
    private function gatedModel() :Documents
    {
        return $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'   => 1 ,                                       // public
                    'salary' => [ Search::REQUIRES => 'hr:salary' ] ,     // gated
                ] ,
            ] ,
        ]) ;
    }

    public function testPrepareViewSearchGatedFieldKeptWhenAuthorized() :void
    {
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.salary IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $this->gatedModel()->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => true ] ,
                $this->binds
            )
        ) ;
    }

    public function testPrepareViewSearchGatedFieldDroppedWhenDenied() :void
    {
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $this->gatedModel()->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => false ] ,
                $this->binds
            )
        ) ;
    }

    public function testPrepareViewSearchGateIsOrOverSubjects() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'ssn' => [ Search::REQUIRES => [ 'hr:admin' , 'hr:audit' ] ] ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.ssn IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:audit' ],
                $this->binds
            )
        ) ;
    }

    public function testPrepareViewSearchMatchesNothingWhenEveryFieldIsDenied() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'salary' => [ Search::REQUIRES => 'hr:salary' ] ] , // every field gated
            ] ,
        ]) ;

        // Denied everything → SEARCH false (no result), never a fallback to all.
        $this->assertSame
        (
            'false' ,
            $model->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => false ],
                $this->binds
            )
        ) ;
        $this->assertSame( [] , $this->binds , 'No term is bound when the search matches nothing.' ) ;
    }

    public function testPrepareViewSearchGateFailsOpenWithoutAuthorizer() :void
    {
        // No Arango::AUTHORIZER injected → the gated field stays searchable.
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.salary IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $this->gatedModel()->prepareViewSearch( [ Arango::SEARCH => 'bois' ] , $this->binds )
        ) ;
    }

    // ---------------------------------------------------------------- View search : inherited projection gate (T2)

    public function testViewSearchInheritsProjectionRequiresDroppedWhenDenied() :void
    {
        // `salary` is searchable WITHOUT a Search::REQUIRES, but masked from
        // reading by the projection's Field::REQUIRES. The View search must
        // inherit that gate.
        $model = $this->model(
        [
            AQL::FIELDS => [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ,
            AQL::VIEW   =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 , 'salary' => 1 ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => false ] , $this->binds )
        ) ;
    }

    public function testViewSearchInheritsProjectionRequiresKeptWhenGranted() :void
    {
        $model = $this->model(
        [
            AQL::FIELDS => [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ,
            AQL::VIEW   =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 , 'salary' => 1 ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.salary IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => true ] , $this->binds )
        ) ;
    }

    public function testViewSearchSubfieldExpansionGatedInDepthWhenDenied() :void
    {
        // A searchable array-of-objects sub-field (`contactPoints[*].email`) whose
        // exact sub-field is masked by the projection is gated in depth: the `[*]`
        // marker is stripped and isPathAuthorized descends Field::FIELDS.
        $model = $this->model(
        [
            AQL::FIELDS =>
            [
                'name'          => true ,
                'contactPoints' => [ Field::FIELDS => [ 'email' => [ Field::REQUIRES => 'c:read' ] ] ] ,
            ] ,
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 , 'contactPoints[*].email' => 1 ] ,
            ] ,
        ]) ;

        $denied = $model->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => false ] , $this->binds ) ;
        $this->assertStringNotContainsString( 'contactPoints' , $denied , 'The masked sub-field must not be searched.' ) ;
        $this->assertStringContainsString( 'doc.name IN TOKENS' , $denied ) ;

        $this->binds = [] ;
        $granted = $model->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => true ] , $this->binds ) ;
        $this->assertStringContainsString( 'contactPoints.email IN TOKENS' , $granted , 'Granted → the sub-field is searched.' ) ;
    }

    public function testViewSearchProjectionGateComposesWithSearchRequires() :void
    {
        // Both gates must grant: the field's Search::REQUIRES ('s:x') AND the
        // projection's Field::REQUIRES ('p:y'). The authorizer grants the search
        // subject but denies the projection one → the field is dropped (AND).
        $model = $this->model(
        [
            AQL::FIELDS => [ 'name' => true , 'salary' => [ Field::REQUIRES => 'p:y' ] ] ,
            AQL::VIEW   =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 , 'salary' => [ Search::REQUIRES => 's:x' ] ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn( $s ) => $s === 's:x' ] , $this->binds )
        ) ;
    }

    public function testViewSearchInheritedProjectionGateFailsOpenWithoutAuthorizer() :void
    {
        // No authorizer → the projection gate is disabled, the masked field stays searchable.
        $model = $this->model(
        [
            AQL::FIELDS => [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ,
            AQL::VIEW   =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => 1 , 'salary' => 1 ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.salary IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( [ Arango::SEARCH => 'bois' ] , $this->binds )
        ) ;
    }

    /**
     * A model whose View carries a View-level REQUIRES (whole-search gate) on
     * top of a per-field gate.
     */
    private function viewGatedModel( array $view = [] ) :Documents
    {
        return $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::REQUIRES => 'app:search' ,
                Search::FIELDS   =>
                [
                    'name'   => 1 ,
                    'salary' => [ Search::REQUIRES => 'hr:salary' ] ,
                ] ,
                ...$view ,
            ] ,
        ]) ;
    }

    public function testPrepareViewSearchViewLevelRequiresGrantedSearchesEveryAllowedField() :void
    {
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr")'
            . ' || doc.salary IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $this->viewGatedModel()->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn() => true ] ,
                $this->binds
            )
        ) ;
    }

    public function testPrepareViewSearchViewLevelRequiresDeniedMatchesNothing() :void
    {
        // Everything granted EXCEPT the View-level subject → whole search denied,
        // even though the salary field's own subject would pass.
        $this->assertSame
        (
            'false' ,
            $this->viewGatedModel()->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn( string $s ) => $s !== 'app:search' ] ,
                $this->binds
            )
        ) ;
        $this->assertSame( [] , $this->binds ) ;
    }

    public function testPrepareViewSearchViewAndFieldLevelsCombineWithAnd() :void
    {
        // View-level granted but the field's own subject denied → only the
        // public field is searched (AND between the two levels).
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $this->viewGatedModel()->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn( string $s ) => $s === 'app:search' ] ,
                $this->binds
            )
        ) ;
    }

    public function testPrepareViewSearchViewLevelRequiresIsOrOverSubjects() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::REQUIRES => [ 'app:a' , 'app:b' ] ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch(
                [ Arango::SEARCH => 'bois' , Arango::AUTHORIZER => fn( string $s ) => $s === 'app:b' ] ,
                $this->binds
            )
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

    // ---------------------------------------------------------------- prepareViewSearch (Search::OPERATOR)

    public function testPrepareViewSearchOperatorAndRequiresEveryWordInTheField() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        // « fourcade marc » → both words must match the same field, so a record
        // holding only « marc » no longer matches.
        $this->assertSame
        (
            'ANALYZER('
            . '(doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr"))'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'fourcade marc' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0_0' => 'fourcade' , 'search_0_1' => 'marc' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchOperatorAndSingleWordHasNoConjunction() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        // A one-word term is identical under AND and OR (no `&&`, no wrapping).
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0_0' => 'bois' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchOperatorAndTrimsAndCollapsesWhitespace() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        // Trailing space + double space (the shape of the reported URL) split into
        // exactly two words — no empty word, no dangling bind.
        $this->assertSame
        (
            'ANALYZER('
            . '(doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr"))'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'fourcade  marc ' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0_0' => 'fourcade' , 'search_0_1' => 'marc' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchOperatorAndWidensEachWordWithFuzzy() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FUZZY    => 1 ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        // Typo tolerance applies per word : each word is its own IN TOKENS ∪ Levenshtein.
        $this->assertSame
        (
            'ANALYZER('
            . '((doc.name IN TOKENS(@search_0_0,"text_fr") || LEVENSHTEIN_MATCH(doc.name,@search_0_0,1))'
            . ' && (doc.name IN TOKENS(@search_0_1,"text_fr") || LEVENSHTEIN_MATCH(doc.name,@search_0_1,1)))'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'fourcade marc' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchOperatorAndKeepsThePhraseBonusOnTheWholeTerm() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::PHRASE   => true ,
                Search::FIELDS   => [ 'name' => [ Search::BOOST => 3 ] ] ,
            ] ,
        ]) ;

        // The AND constraint uses per-word binds ; the phrase bonus stays on the
        // whole term (@search_0), OR-ed on top — it only reorders, never widens.
        $this->assertSame
        (
            'ANALYZER('
            . 'BOOST((doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr")),3)'
            . ' || BOOST(PHRASE(doc.name,@search_0),6)'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'fourcade marc' , $this->binds )
        ) ;
        $this->assertSame
        (
            [ 'search_0' => 'fourcade marc' , 'search_0_0' => 'fourcade' , 'search_0_1' => 'marc' ] ,
            $this->binds
        ) ;
    }

    public function testPrepareViewSearchOperatorAndConjoinsTheNgramBranch() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FIELDS   =>
                [
                    'name' =>
                    [
                        Search::ANALYZER => 'text_fr' ,
                        Search::NGRAM    => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 0.6 ] ,
                    ] ,
                ] ,
            ] ,
        ]) ;

        // Both the IN TOKENS group and the NGRAM_MATCH group require every word.
        $this->assertSame
        (
            'ANALYZER('
            . '(doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr"))'
            . ',"text_fr")'
            . ' || ANALYZER('
            . '(NGRAM_MATCH(doc.name,@search_0_0,0.6,"autocomplete") && NGRAM_MATCH(doc.name,@search_0_1,0.6,"autocomplete"))'
            . ',"autocomplete")' ,
            $model->prepareViewSearch( 'fourcade marc' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchOperatorAndKeepsCommaTermsOr() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        // Comma separates alternatives (OR) ; whitespace conjoins (AND). So
        // « fourcade marc,dupont » = (fourcade AND marc) OR dupont.
        $this->assertSame
        (
            'ANALYZER('
            . '(doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr"))'
            . ' || doc.name IN TOKENS(@search_1_0,"text_fr")'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'fourcade marc,dupont' , $this->binds )
        ) ;
        $this->assertSame
        (
            [ 'search_0_0' => 'fourcade' , 'search_0_1' => 'marc' , 'search_1_0' => 'dupont' ] ,
            $this->binds
        ) ;
    }

    public function testPrepareViewSearchOperatorMixedPerFieldOverride() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name' => [ Search::OPERATOR => Logic::AND ] , // both words on the name
                    'code' => 1 ,                                 // loose (View default OR)
                ] ,
            ] ,
        ]) ;

        // The name conjoins the words while the code keeps the whole-term match ;
        // both branches share the whole-term bind (@search_0) for the code.
        $this->assertSame
        (
            'ANALYZER('
            . '(doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr"))'
            . ' || doc.code IN TOKENS(@search_0,"text_fr")'
            . ',"text_fr")' ,
            $model->prepareViewSearch( 'fourcade marc' , $this->binds )
        ) ;
        $this->assertSame
        (
            [ 'search_0' => 'fourcade marc' , 'search_0_0' => 'fourcade' , 'search_0_1' => 'marc' ] ,
            $this->binds
        ) ;
    }

    public function testPrepareViewSearchOperatorOrIsTheDefaultGrammar() :void
    {
        // An explicit View-level OR is the historical grammar : whole-term match,
        // single @search_0 bind — identical to declaring no operator at all.
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::OR ,
                Search::FIELDS   => [ 'name' => 1 ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'fourcade marc' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0' => 'fourcade marc' ] , $this->binds ) ;
    }

    // ---------------------------------------------------------------- prepareViewSearch (Search::SEPARATORS)

    private function operatorModel( array $view = [] ) :Documents
    {
        return $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::OPERATOR => Logic::AND ,
                Search::FIELDS   => [ 'name' => 1 ] ,
                ...$view ,
            ] ,
        ]) ;
    }

    public function testPrepareViewSearchOperatorSplitsOnHyphenByDefault() :void
    {
        // No Search::SEPARATORS → hyphen default : "Jean-Marc" behaves like "Jean Marc".
        $this->assertSame
        (
            'ANALYZER('
            . '(doc.name IN TOKENS(@search_0_0,"text_fr") && doc.name IN TOKENS(@search_0_1,"text_fr"))'
            . ',"text_fr")' ,
            $this->operatorModel()->prepareViewSearch( 'Jean-Marc' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0_0' => 'Jean' , 'search_0_1' => 'Marc' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchOperatorEmptySeparatorsKeepsHyphenatedWordWhole() :void
    {
        // Explicit empty → whitespace only : a hyphenated code stays one word.
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0_0,"text_fr"),"text_fr")' ,
            $this->operatorModel( [ Search::SEPARATORS => '' ] )->prepareViewSearch( 'Jean-Marc' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0_0' => 'Jean-Marc' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchOperatorCustomSeparatorsString() :void
    {
        // A string of characters replaces the default extra set (whitespace always splits).
        $this->operatorModel( [ Search::SEPARATORS => '-.' ] )->prepareViewSearch( 'Jean-Marc.Paul' , $this->binds ) ;
        $this->assertSame
        (
            [ 'search_0_0' => 'Jean' , 'search_0_1' => 'Marc' , 'search_0_2' => 'Paul' ] ,
            $this->binds
        ) ;
    }

    public function testPrepareViewSearchOperatorCustomSeparatorsArray() :void
    {
        // A list of characters is normalized to the same set as the string form.
        $this->operatorModel( [ Search::SEPARATORS => [ '-' , '.' ] ] )->prepareViewSearch( 'Jean-Marc.Paul' , $this->binds ) ;
        $this->assertSame
        (
            [ 'search_0_0' => 'Jean' , 'search_0_1' => 'Marc' , 'search_0_2' => 'Paul' ] ,
            $this->binds
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

    public function testBuildViewLinkStripsArrayExpansionMarkers() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'                       => 1 ,
                    'contactPoints[*].email'     => 1 ,
                    'contactPoints[*].telephone' => 1 ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        // The `[*]` markers are dropped : the array sub-fields are declared as a
        // plain nested path so ArangoSearch descends into the array itself.
        $this->assertSame
        (
            [
                'fields' =>
                [
                    'name'          => [ 'analyzers' => [ 'text_fr' ] ] ,
                    'contactPoints' =>
                    [
                        'fields' =>
                        [
                            'email'     => [ 'analyzers' => [ 'text_fr' ] ] ,
                            'telephone' => [ 'analyzers' => [ 'text_fr' ] ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testBuildViewLinkStripsMultiLevelArrayExpansion() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'employee[*].contactPoint[*].email' => 1 ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        // Every marker is stripped, whatever the nesting depth.
        $this->assertSame
        (
            [
                'fields' =>
                [
                    'employee' =>
                    [
                        'fields' =>
                        [
                            'contactPoint' =>
                            [
                                'fields' => [ 'email' => [ 'analyzers' => [ 'text_fr' ] ] ] ,
                            ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testBuildViewLinkKeepsPerFieldAnalyzerOnArrayExpansion() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'contactPoints[*].email' => [ Search::ANALYZER => 'text_en' ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        // The per-field analyzer override lands on the leaf of the stripped path.
        $this->assertSame
        (
            [
                'fields' =>
                [
                    'contactPoints' =>
                    [
                        'fields' => [ 'email' => [ 'analyzers' => [ 'text_en' ] ] ] ,
                    ] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testBuildViewLinkEmitsMultipleAnalyzersPerField() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'  => 1 ,                                                       // single, inherits text_fr
                    'title' => [ Search::ANALYZER => [ 'text_fr' , 'autocomplete' ] ] ,  // list
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        $this->assertSame
        (
            [
                'fields' =>
                [
                    'name'  => [ 'analyzers' => [ 'text_fr' ] ] ,
                    'title' => [ 'analyzers' => [ 'text_fr' , 'autocomplete' ] ] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testPrepareViewSearchEmitsOneBranchPerAnalyzer() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'title' => [ Search::ANALYZER => [ 'text_fr' , 'autocomplete' ] ] ] ,
            ] ,
        ]) ;

        // One ANALYZER(...) group per Analyzer, OR-ed together.
        $this->assertSame
        (
            'ANALYZER(doc.title IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(doc.title IN TOKENS(@search_0,"autocomplete"),"autocomplete")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testMultiAnalyzerListOfOneIsIdenticalToSingle() :void
    {
        $single = $this->model(
        [
            AQL::VIEW => [ Search::NAME => 'v' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => [ Search::ANALYZER => 'text_en' ] ] ] ,
        ]) ;
        $list = $this->model(
        [
            AQL::VIEW => [ Search::NAME => 'v' , Search::ANALYZER => 'text_fr' , Search::FIELDS => [ 'name' => [ Search::ANALYZER => [ 'text_en' ] ] ] ] ,
        ]) ;

        $buildViewLink = new ReflectionMethod( $single , 'buildViewLink' ) ;

        // A one-element list is byte-for-byte identical to the single form.
        $this->assertSame
        (
            $buildViewLink->invoke( $single )->toArray() ,
            $buildViewLink->invoke( $list )->toArray()
        ) ;

        $bindsA = $bindsB = [] ;
        $this->assertSame
        (
            $single->prepareViewSearch( 'bois' , $bindsA ) ,
            $list->prepareViewSearch( 'bois' , $bindsB )
        ) ;
    }

    public function testNgramFacetMergesAnalyzerIntoTheLink() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name' =>
                    [
                        Search::ANALYZER => 'text_fr' ,
                        Search::NGRAM    => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 0.6 ] ,
                    ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;

        // The ngram analyzer is merged into the field's indexed analyzers list.
        $this->assertSame
        (
            [ 'fields' => [ 'name' => [ 'analyzers' => [ 'text_fr' , 'autocomplete' ] ] ] ] ,
            $method->invoke( $model )->toArray()
        ) ;
    }

    public function testPrepareViewSearchEmitsNgramMatchBranch() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name' =>
                    [
                        Search::ANALYZER => 'text_fr' ,
                        Search::NGRAM    => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 0.6 ] ,
                    ] ,
                ] ,
            ] ,
        ]) ;

        // The IN TOKENS branch (text_fr) and the NGRAM_MATCH branch (autocomplete,
        // bound term, threshold inlined) sit in their own ANALYZER() groups.
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(NGRAM_MATCH(doc.name,@search_0,0.6,"autocomplete"),"autocomplete")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0' => 'bois' ] , $this->binds ) ;
    }

    public function testNgramShorthandOmitsThresholdForTheServerDefault() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'name' => [ Search::NGRAM => 'autocomplete' ] ] ,
            ] ,
        ]) ;

        // Shorthand string → no threshold argument (server default 0.7 applies).
        $this->assertSame
        (
            'ANALYZER(doc.name IN TOKENS(@search_0,"text_fr"),"text_fr")'
            . ' || ANALYZER(NGRAM_MATCH(doc.name,@search_0,"autocomplete"),"autocomplete")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testNgramBranchCarriesTheFieldBoost() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name' => [ Search::BOOST => 3 , Search::NGRAM => 'autocomplete' ] ,
                ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(BOOST(doc.name IN TOKENS(@search_0,"text_fr"),3),"text_fr")'
            . ' || ANALYZER(BOOST(NGRAM_MATCH(doc.name,@search_0,"autocomplete"),3),"autocomplete")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testNgramRejectsAThresholdOutOfRange() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS => [ 'name' => [ Search::NGRAM => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 1.5 ] ] ] ,
            ] ,
        ]) ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareViewSearch( 'bois' , $this->binds ) ;
    }

    public function testBuildViewLinkRejectsMalformedArrayField() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS => [ 'contact-points[*].email' => 1 ] , // hyphen → invalid
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;

        $this->expectException( ValidationException::class ) ;
        $method->invoke( $model ) ;
    }

    public function testPrepareViewSearchStripsArrayExpansionMarkers() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'contactPoints[*].email' => 1 ] ,
            ] ,
        ]) ;

        // The `[*]` marker is stripped : the SEARCH grammar rejects array
        // expansion, and the flat path already matches any array element.
        $this->assertSame
        (
            'ANALYZER(doc.contactPoints.email IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
        $this->assertSame( [ 'search_0' => 'bois' ] , $this->binds ) ;
    }

    public function testPrepareViewSearchStripsMultiLevelArrayExpansion() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'employee[*].contactPoint[*].email' => 1 ] ,
            ] ,
        ]) ;

        $this->assertSame
        (
            'ANALYZER(doc.employee.contactPoint.email IN TOKENS(@search_0,"text_fr"),"text_fr")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchKeepsPerFieldOptionsOnArrayExpansion() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'contactPoints[*].email' => [ Search::ANALYZER => 'text_en' , Search::FUZZY => 2 ] ,
                ] ,
            ] ,
        ]) ;

        // Per-field analyzer + fuzzy still apply on the stripped (flat) path.
        $this->assertSame
        (
            'ANALYZER('
            . 'doc.contactPoints.email IN TOKENS(@search_0,"text_en")'
            . ' || LEVENSHTEIN_MATCH(doc.contactPoints.email,@search_0,2)'
            . ',"text_en")' ,
            $model->prepareViewSearch( 'bois' , $this->binds )
        ) ;
    }

    public function testPrepareViewSearchRejectsMalformedArrayField() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   => [ 'contact-points[*].email' => 1 ] , // hyphen → invalid
            ] ,
        ]) ;

        $this->expectException( ValidationException::class ) ;
        $model->prepareViewSearch( 'bois' , $this->binds ) ;
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

    public function testGetViewFieldSpecsKeepsAnalyzerWhenDeclared() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name'  => 3 ,                                                              // shorthand → boost only
                    'title' => [ Search::ANALYZER => 'text_en' ] ,                              // boost defaults to 1
                    'body'  => [ Search::BOOST => 2 , Search::FUZZY => 1 , Search::ANALYZER => 'text_fr' ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'getViewFieldSpecs' ) ;

        $this->assertSame
        (
            [
                'name'  => [ Search::BOOST => 3.0 ] ,
                'title' => [ Search::BOOST => 1.0 , Search::ANALYZER => 'text_en' ] ,
                'body'  => [ Search::BOOST => 2.0 , Search::FUZZY => 1 , Search::ANALYZER => 'text_fr' ] ,
            ] ,
            $method->invoke( $model )
        ) ;
    }

    public function testBuildViewLinkUsesPerFieldAnalyzers() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME     => 'v' ,
                Search::ANALYZER => 'text_fr' ,
                Search::FIELDS   =>
                [
                    'name'           => 1 ,                              // inherits the View analyzer
                    'description.en' => [ Search::ANALYZER => 'text_en' ] , // dotted path + override
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        $this->assertSame
        (
            [
                'fields' =>
                [
                    'name'        => [ 'analyzers' => [ 'text_fr' ] ] ,
                    'description' => [ 'fields' => [ 'en' => [ 'analyzers' => [ 'text_en' ] ] ] ] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testBuildViewLinkOmitsAnalyzersWhenMatchingTheLinkDefault() :void
    {
        // `code` resolves to the identity analyzer — the link default — so it
        // must be emitted as an empty node (no `analyzers` key) : the server
        // stores such a field as `{}`, and spelling `["identity"]` out would
        // make viewDiff() report a permanent false drift. `name` keeps its
        // explicit analyzers since `text_fr` differs from the default.
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name' => [ Search::ANALYZER => 'text_fr' ] ,
                    'code' => [ Search::ANALYZER => 'identity' ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        $this->assertSame
        (
            [
                'fields' =>
                [
                    'name' => [ 'analyzers' => [ 'text_fr' ] ] ,
                    'code' => [] ,
                ] ,
            ] ,
            $link->toArray()
        ) ;
    }

    public function testBuildViewLinkOmitsAnalyzersForFieldsInheritingTheDefaultAnalyzer() :void
    {
        // No View-level analyzer → every field inherits the identity default,
        // so the whole link is emitted with empty nodes (the server would
        // normalize `["identity"]` to `{}` either way).
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS => [ 'name' => 3 , 'code' => 1 ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'buildViewLink' ) ;
        $link   = $method->invoke( $model ) ;

        $this->assertSame
        (
            [ 'fields' => [ 'name' => [] , 'code' => [] ] ] ,
            $link->toArray()
        ) ;
    }

    public function testGetViewFieldSpecsKeepsRequiresWhenDeclared() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name'   => 3 ,
                    'salary' => [ Search::REQUIRES => 'hr:salary' ] ,
                    'ssn'    => [ Search::REQUIRES => [ 'hr:admin' , 'hr:audit' ] ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'getViewFieldSpecs' ) ;

        $this->assertSame
        (
            [
                'name'   => [ Search::BOOST => 3.0 ] ,
                'salary' => [ Search::BOOST => 1.0 , Search::REQUIRES => 'hr:salary' ] ,
                'ssn'    => [ Search::BOOST => 1.0 , Search::REQUIRES => [ 'hr:admin' , 'hr:audit' ] ] ,
            ] ,
            $method->invoke( $model )
        ) ;
    }

    public function testGetViewFieldSpecsKeepsPhraseWhenDeclared() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name'  => 3 ,
                    'title' => [ Search::PHRASE => true ] ,
                    'code'  => [ Search::BOOST => 2 , Search::PHRASE => false ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'getViewFieldSpecs' ) ;

        $this->assertSame
        (
            [
                'name'  => [ Search::BOOST => 3.0 ] ,
                'title' => [ Search::BOOST => 1.0 , Search::PHRASE => true ] ,
                'code'  => [ Search::BOOST => 2.0 , Search::PHRASE => false ] ,
            ] ,
            $method->invoke( $model )
        ) ;
    }

    public function testGetViewFieldSpecsKeepsLangWhenDeclared() :void
    {
        $model = $this->model(
        [
            AQL::VIEW =>
            [
                Search::NAME   => 'v' ,
                Search::FIELDS =>
                [
                    'name'           => 3 ,
                    'description.fr' => [ Search::LANG => 'fr' ] ,
                    'description.en' => [ Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
                ] ,
            ] ,
        ]) ;

        $method = new ReflectionMethod( $model , 'getViewFieldSpecs' ) ;

        $this->assertSame
        (
            [
                'name'           => [ Search::BOOST => 3.0 ] ,
                'description.fr' => [ Search::BOOST => 1.0 , Search::LANG => 'fr' ] ,
                'description.en' => [ Search::BOOST => 1.0 , Search::ANALYZER => 'text_en' , Search::LANG => 'en' ] ,
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

    public function testViewDiffIsInvalidWhenAPerFieldAnalyzerIsUnknown() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerExists'   )->willReturnCallback( fn( string $a ) => $a === 'text_fr' ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ;

        $model = $this->facadeModel( $facade ,
        [
            Search::FIELDS =>
            [
                'name'  => 1 ,                                  // text_fr (known)
                'title' => [ Search::ANALYZER => 'text_en' ] ,  // text_en (unknown)
            ] ,
        ]) ;

        $report = $model->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( "analyzer 'text_en' not found on the server" , $report->changes ) ;
    }

    public function testViewDiffIsInvalidWhenAnNgramAnalyzerIsUnknown() :void
    {
        $facade = $this->createMock( ArangoDB::class ) ;
        $facade->method( 'analyzerExists'   )->willReturnCallback( fn( string $a ) => $a === 'text_fr' ) ;
        $facade->method( 'collectionExists' )->willReturn( true ) ;
        $facade->method( 'viewDiff'         )->willReturn( new DiffReport( 'placesView' , DiffStatus::IN_SYNC ) ) ;

        $model = $this->facadeModel( $facade ,
        [
            Search::FIELDS =>
            [
                // The IN TOKENS analyzer is known, but the field's NGRAM_MATCH
                // analyzer (queried by similarity threshold) is not — the diff
                // must collect the ngram analyzer too and report it missing.
                'name' => [ Search::ANALYZER => 'text_fr' , Search::NGRAM => [ Search::ANALYZER => 'autocomplete' , Search::THRESHOLD => 0.6 ] ] ,
            ] ,
        ]) ;

        $report = $model->viewDiff() ;

        $this->assertSame( DiffStatus::INVALID , $report->status ) ;
        $this->assertContains( "analyzer 'autocomplete' not found on the server" , $report->changes ) ;
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
