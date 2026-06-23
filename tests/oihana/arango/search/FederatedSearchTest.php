<?php

namespace tests\oihana\arango\search ;

use DI\Container ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\Documents ;
use oihana\arango\models\enums\Search ;
use oihana\arango\search\FederatedSearch ;
use oihana\arango\search\enums\FederatedSearchParam ;

use oihana\controllers\enums\Skin ;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations ;
use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Unit coverage for the {@see FederatedSearch} engine skeleton (Lot C1): the
 * container-aware construction, the collection → model registry normalization
 * and the not-yet-wired entry point.
 *
 * @package tests\oihana\arango\search
 * @author  Marc Alcaraz (ekameleon)
 */
#[CoversClass( FederatedSearch::class )]
#[AllowMockObjectsWithoutExpectations]
final class FederatedSearchTest extends TestCase
{
    /**
     * Builds an engine over a bare container double (Lot C1 never touches it).
     *
     * @param array<string, mixed> $init
     *
     * @return FederatedSearch
     */
    private function make( array $init = [] ) :FederatedSearch
    {
        return new FederatedSearch( $this->createMock( Container::class ) , $init ) ;
    }

    public function testConstructorReadsTheConfiguration() :void
    {
        $engine = $this->make(
        [
            FederatedSearchParam::VIEW       => 'global_search' ,
            FederatedSearchParam::SEARCHABLE => [ 'fields' => [ 'name' , 'label' ] , 'analyzer' => 'text_fr' ] ,
            FederatedSearchParam::MODELS     => [ 'customers' => 'model.customers' , 'products' => 'model.products' ] ,
        ]) ;

        $this->assertSame( 'global_search' , $engine->view ) ;
        $this->assertSame( [ 'fields' => [ 'name' , 'label' ] , 'analyzer' => 'text_fr' ] , $engine->searchable ) ;
        $this->assertSame( [ 'customers' => 'model.customers' , 'products' => 'model.products' ] , $engine->models ) ;
    }

    public function testDefaultsAreEmpty() :void
    {
        $engine = $this->make() ;

        $this->assertNull( $engine->view ) ;
        $this->assertSame( [] , $engine->searchable ) ;
        $this->assertSame( [] , $engine->models ) ;
    }

    public function testGetViewNameReturnsTheView() :void
    {
        $this->assertSame( 'global_search' , $this->make( [ FederatedSearchParam::VIEW => 'global_search' ] )->getViewName() ) ;
    }

    public function testGetViewNameIsNullWhenAbsentOrBlank() :void
    {
        $this->assertNull( $this->make()->getViewName() ) ;
        $this->assertNull( $this->make( [ FederatedSearchParam::VIEW => '' ] )->getViewName() ) ;
        $this->assertNull( $this->make( [ FederatedSearchParam::VIEW => 123 ] )->getViewName() ) ;
    }

    public function testModelsRegistryDropsMalformedEntries() :void
    {
        $engine = $this->make(
        [
            FederatedSearchParam::MODELS =>
            [
                'customers' => 'model.customers' , // kept
                'products'  => '' ,                // dropped : empty model id
                ''          => 'model.blank' ,     // dropped : empty collection
                7           => 'model.numeric' ,   // dropped : numeric collection key
                'places'    => [ 'not' , 'a' , 'string' ] , // dropped : non-string model id
                'sellers'   => 'model.sellers' ,   // kept
            ] ,
        ]) ;

        $this->assertSame( [ 'customers' => 'model.customers' , 'sellers' => 'model.sellers' ] , $engine->models ) ;
    }

    public function testModelsRegistryIgnoresANonArrayDeclaration() :void
    {
        $this->assertSame( [] , $this->make( [ FederatedSearchParam::MODELS => 'not-an-array' ] )->models ) ;
    }

    // ---- composite registry / type-aware resolution (Lot 7b) ----------------

    public function testCompositeModelEntryIsNormalised() :void
    {
        $engine = $this->make(
        [
            FederatedSearchParam::MODELS =>
            [
                'products'      => 'model.products' , // direct : kept verbatim
                'organizations' =>
                [
                    FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
                    FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' , 'Provider' => 'model.providers' ] ,
                    FederatedSearchParam::FALLBACK      => 'model.org' ,
                ] ,
            ] ,
        ]) ;

        $this->assertSame(
        [
            'products'      => 'model.products' ,
            'organizations' =>
            [
                FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
                FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' , 'Provider' => 'model.providers' ] ,
                FederatedSearchParam::FALLBACK      => 'model.org' ,
            ] ,
        ] , $engine->models ) ;
    }

    public function testCompositeModelDefaultsTheDiscriminatorAndNullsAnAbsentFallback() :void
    {
        $engine = $this->make(
        [
            FederatedSearchParam::MODELS =>
            [
                'organizations' => // no 'key' → additionalType ; no 'default' → null fallback
                [
                    FederatedSearchParam::MAP => [ 'Customer' => 'model.customers' ] ,
                ] ,
            ] ,
        ]) ;

        $this->assertSame(
        [
            'organizations' =>
            [
                FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
                FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' ] ,
                FederatedSearchParam::FALLBACK      => null ,
            ] ,
        ] , $engine->models ) ;
    }

    public function testCompositeModelCleansItsMapAndDropsAnUnresolvableEntry() :void
    {
        $engine = $this->make(
        [
            FederatedSearchParam::MODELS =>
            [
                'people' => // map cleaned to string => non-empty-string ; rescued by the fallback
                [
                    FederatedSearchParam::MAP      => [ 'Person' => 'model.people' , 'Bad' => '' , 5 => 'model.numeric' , 'Store' => 'model.stores' ] ,
                    FederatedSearchParam::FALLBACK => 'model.default' ,
                ] ,
                'ghosts' => // no map and no fallback → can never resolve → dropped
                [
                    FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
                ] ,
            ] ,
        ]) ;

        $this->assertSame(
        [
            'people' =>
            [
                FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
                FederatedSearchParam::MAP           => [ 'Person' => 'model.people' , 'Store' => 'model.stores' ] ,
                FederatedSearchParam::FALLBACK      => 'model.default' ,
            ] ,
        ] , $engine->models ) ;
    }

    /**
     * Invokes the private {@see FederatedSearch::resolveModelId()} resolver (it is
     * unit-tested in isolation here; rebuild() wires it in the next lot).
     *
     * @param string|array<string, mixed> $spec
     */
    private function resolveModelId( FederatedSearch $engine , string|array $spec , mixed $type ) :?string
    {
        return ( new \ReflectionMethod( FederatedSearch::class , 'resolveModelId' ) )->invoke( $engine , $spec , $type ) ;
    }

    public function testResolveModelIdReturnsADirectModel() :void
    {
        $this->assertSame( 'model.products' , $this->resolveModelId( $this->make() , 'model.products' , 'whatever' ) ) ;
    }

    public function testResolveModelIdMapsAScalarType() :void
    {
        $spec =
        [
            FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
            FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' , 'Provider' => 'model.providers' ] ,
            FederatedSearchParam::FALLBACK      => 'model.org' ,
        ] ;

        $this->assertSame( 'model.providers' , $this->resolveModelId( $this->make() , $spec , 'Provider' ) ) ;
    }

    public function testResolveModelIdUsesMapOrderAsPriorityForAMultiTypedDocument() :void
    {
        // the map declares Customer first ; the document lists Provider first → Customer still wins
        $spec =
        [
            FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
            FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' , 'Provider' => 'model.providers' ] ,
            FederatedSearchParam::FALLBACK      => 'model.org' ,
        ] ;

        $this->assertSame( 'model.customers' , $this->resolveModelId( $this->make() , $spec , [ 'Provider' , 'Customer' ] ) ) ;
    }

    public function testResolveModelIdFallsBackToTheDefault() :void
    {
        $spec =
        [
            FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
            FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' ] ,
            FederatedSearchParam::FALLBACK      => 'model.org' ,
        ] ;

        $this->assertSame( 'model.org' , $this->resolveModelId( $this->make() , $spec , 'Unknown' ) ) ; // unmapped type
        $this->assertSame( 'model.org' , $this->resolveModelId( $this->make() , $spec , null ) ) ;       // null type
    }

    public function testResolveModelIdDropsWhenNoMatchAndNoFallback() :void
    {
        $spec =
        [
            FederatedSearchParam::DISCRIMINATOR => 'additionalType' ,
            FederatedSearchParam::MAP           => [ 'Customer' => 'model.customers' ] ,
            FederatedSearchParam::FALLBACK      => null ,
        ] ;

        $this->assertNull( $this->resolveModelId( $this->make() , $spec , 'Unknown' ) ) ;
    }

    /**
     * A composite-registry engine: a container resolving each model-service-id to
     * a {@see FakeFederatedModel}, and a database double whose `query()` returns
     * `$typeRows` (the discriminator lookup) and captures the issued AQL.
     *
     * @param array<string, mixed>             $models
     * @param array<int, array<string, mixed>> $typeRows
     * @param array<string, Documents>         $services
     * @param string|null                      $typeAql Captured by reference.
     *
     * @return FederatedSearch
     */
    private function compositeEngine( array $models , array $typeRows , array $services , ?string &$typeAql = null ) :FederatedSearch
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( $typeRows ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'query' )->willReturnCallback( function( $aql , $binds = [] ) use ( &$typeAql , $cursor )
        {
            $typeAql = $aql ;
            return $cursor ;
        } ) ;

        $arango = $this->createMock( ArangoDB::class ) ;
        $arango->method( 'database' )->willReturn( $database ) ;

        return new FederatedSearch( $this->containerWith( $services ) ,
        [
            FederatedSearchParam::MODELS => $models ,
            Arango::DATABASE             => $arango ,
        ]) ;
    }

    public function testRebuildRoutesAPolymorphicCollectionByType() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'o1' , 'name' => 'Acme' ] , [ '_key' => 'o3' , 'name' => 'Multi' ] ] ) ;
        $providers = $this->modelReturning( [ [ '_key' => 'o2' , 'name' => 'Globex' ] ] ) ;

        $engine = $this->compositeEngine(
            [ 'organizations' => [ FederatedSearchParam::MAP => [ 'Customer' => 'model.customers' , 'Provider' => 'model.providers' ] ] ] ,
            [
                [ '_key' => 'o1' , 'discriminator' => 'Customer' ] ,
                [ '_key' => 'o2' , 'discriminator' => 'Provider' ] ,
                [ '_key' => 'o3' , 'discriminator' => [ 'Provider' , 'Customer' ] ] , // multi-typed → map order (Customer) wins
            ] ,
            [ 'model.customers' => $customers , 'model.providers' => $providers ] ,
            $typeAql
        ) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'organizations' , 'key' => 'o1' , 'score' => 9.0 ] ,
            [ 'collection' => 'organizations' , 'key' => 'o2' , 'score' => 8.0 ] ,
            [ 'collection' => 'organizations' , 'key' => 'o3' , 'score' => 7.0 ] ,
        ]) ;

        // each routed to its type's model, kept in find (score) order
        $this->assertSame(
        [
            [ 'collection' => 'organizations' , 'score' => 9.0 , 'document' => [ '_key' => 'o1' , 'name' => 'Acme'   ] ] ,
            [ 'collection' => 'organizations' , 'score' => 8.0 , 'document' => [ '_key' => 'o2' , 'name' => 'Globex' ] ] ,
            [ 'collection' => 'organizations' , 'score' => 7.0 , 'document' => [ '_key' => 'o3' , 'name' => 'Multi'  ] ] ,
        ] , $results ) ;

        // customers got o1 + o3 (o3 by map-order priority), providers got o2
        $this->assertSame( [ 'o1' , 'o3' ] , $customers->captured[ Arango::FILTER ][ 'val' ] ) ;
        $this->assertSame( [ 'o2' ] , $providers->captured[ Arango::FILTER ][ 'val' ] ) ;

        // the lightweight type lookup shape
        $this->assertStringContainsString( 'FOR doc IN @@' , $typeAql ) ;
        $this->assertStringContainsString( 'FILTER doc._key IN @' , $typeAql ) ;
        $this->assertStringContainsString( 'doc.additionalType' , $typeAql ) ;
    }

    public function testRebuildUsesTheFallbackModelForAnUnmappedType() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'o1' , 'name' => 'Acme'  ] ] ) ;
        $generic   = $this->modelReturning( [ [ '_key' => 'o2' , 'name' => 'Other' ] ] ) ;

        $engine = $this->compositeEngine(
            [ 'organizations' =>
            [
                FederatedSearchParam::MAP      => [ 'Customer' => 'model.customers' ] ,
                FederatedSearchParam::FALLBACK => 'model.generic' ,
            ] ] ,
            [
                [ '_key' => 'o1' , 'discriminator' => 'Customer' ] ,
                [ '_key' => 'o2' , 'discriminator' => 'Unknown'  ] , // unmapped → fallback
            ] ,
            [ 'model.customers' => $customers , 'model.generic' => $generic ]
        ) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'organizations' , 'key' => 'o1' , 'score' => 9.0 ] ,
            [ 'collection' => 'organizations' , 'key' => 'o2' , 'score' => 8.0 ] ,
        ]) ;

        $this->assertSame( [ 'o1' , 'o2' ] , array_map( static fn( $r ) => $r[ 'document' ][ '_key' ] , $results ) ) ;
        $this->assertSame( [ 'o2' ] , $generic->captured[ Arango::FILTER ][ 'val' ] ) ; // routed to the fallback model
    }

    public function testRebuildDropsAnUnmappedTypeWithoutAFallback() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'o1' , 'name' => 'Acme' ] ] ) ;

        $engine = $this->compositeEngine(
            [ 'organizations' => [ FederatedSearchParam::MAP => [ 'Customer' => 'model.customers' ] ] ] , // no fallback
            [
                [ '_key' => 'o1' , 'discriminator' => 'Customer' ] ,
                [ '_key' => 'o2' , 'discriminator' => 'Unknown'  ] , // unmapped, no fallback → dropped
            ] ,
            [ 'model.customers' => $customers ]
        ) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'organizations' , 'key' => 'o1' , 'score' => 9.0 ] ,
            [ 'collection' => 'organizations' , 'key' => 'o2' , 'score' => 8.0 ] ,
        ]) ;

        $this->assertSame( [ 'o1' ] , array_map( static fn( $r ) => $r[ 'document' ][ '_key' ] , $results ) ) ;
    }

    public function testRebuildCompositeFallsBackWhenNoDatabaseIsConfigured() :void
    {
        // no Arango::DATABASE → the type lookup returns nothing → every key uses the fallback
        $generic = $this->modelReturning( [ [ '_key' => 'o1' , 'name' => 'Acme' ] , [ '_key' => 'o2' , 'name' => 'Globex' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.generic' => $generic ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'organizations' =>
            [
                FederatedSearchParam::MAP      => [ 'Customer' => 'model.customers' ] ,
                FederatedSearchParam::FALLBACK => 'model.generic' ,
            ] ] ,
        ]) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'organizations' , 'key' => 'o1' , 'score' => 9.0 ] ,
            [ 'collection' => 'organizations' , 'key' => 'o2' , 'score' => 8.0 ] ,
        ]) ;

        $this->assertSame( [ 'o1' , 'o2' ] , array_map( static fn( $r ) => $r[ 'document' ][ '_key' ] , $results ) ) ;
        $this->assertSame( [ 'o1' , 'o2' ] , $generic->captured[ Arango::FILTER ][ 'val' ] ) ;
    }

    public function testSearchableIgnoresANonArrayDeclaration() :void
    {
        $this->assertSame( [] , $this->make( [ FederatedSearchParam::SEARCHABLE => 'not-an-array' ] )->searchable ) ;
    }

    // ---- find (Lot C2) ------------------------------------------------------

    /**
     * A canned search spec + registry, paired with a database double whose
     * `query()` captures the AQL + binds + options and whose cursor returns
     * `$rows` and reports `$fullCount` total matches.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null        $captured  Filled by reference with `[ aql, binds, options ]`.
     * @param int                              $fullCount The total reported by `getFullCount()`.
     *
     * @return FederatedSearch
     */
    private function engineWithDatabase( array $rows , ?array &$captured = null , int $fullCount = 0 ) :FederatedSearch
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( $rows ) ;
        $cursor->method( 'getFullCount' )->willReturn( $fullCount ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'query' )->willReturnCallback( function( $aql , $binds = [] , $options = [] ) use ( &$captured , $cursor )
        {
            $captured = [ 'aql' => $aql , 'binds' => $binds , 'options' => $options ] ;
            return $cursor ;
        } ) ;

        $arango = $this->createMock( ArangoDB::class ) ;
        $arango->method( 'database' )->willReturn( $database ) ;

        return new FederatedSearch( $this->createMock( Container::class ) ,
        [
            FederatedSearchParam::VIEW       => 'global_search' ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' , 'label' ] , Search::ANALYZER => 'text_fr' ] ,
            FederatedSearchParam::MODELS     => [ 'customers' => 'model.customers' ] ,
            Arango::DATABASE                 => $arango ,
        ]) ;
    }

    /**
     * A {@see Documents} test double (a real subtype, so it satisfies the
     * engine's `instanceof Documents` guard) whose `list()` captures its init
     * (read back via `$model->captured`) and returns the given documents.
     *
     * @param array<int, mixed> $documents
     *
     * @return FakeFederatedModel
     */
    private function modelReturning( array $documents ) :FakeFederatedModel
    {
        return new FakeFederatedModel( $documents ) ;
    }

    /**
     * A container double resolving each `model-service-id => Documents` entry.
     *
     * @param array<string, Documents> $services
     *
     * @return Container
     */
    private function containerWith( array $services ) :Container
    {
        $container = $this->createMock( Container::class ) ;
        $container->method( 'has' )->willReturnCallback( static fn( $id ) => isset( $services[ $id ] ) ) ;
        $container->method( 'get' )->willReturnCallback( static fn( $id ) => $services[ $id ] ?? null ) ;
        return $container ;
    }

    public function testFindRunsAScoredSearchAndReturnsTheRows() :void
    {
        $rows = [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] , [ 'collection' => 'products' , 'key' => 'p1' , 'score' => 7.4 ] ] ;

        $engine = $this->engineWithDatabase( $rows , $captured , 936 ) ;

        $this->assertSame( $rows , $engine->find( [ Arango::SEARCH => 'dupont' , Arango::LIMIT => 10 ] ) ) ;

        // the bound term, never inlined
        $this->assertSame( [ 'search' => 'dupont' ] , $captured[ 'binds' ] ) ;

        // the query shape : view, tokenized + analyzer-wrapped match, BM25 ranking, pagination, provenance return
        $aql = $captured[ 'aql' ] ;
        $this->assertStringContainsString( 'FOR doc IN global_search' , $aql ) ;
        $this->assertStringContainsString( 'TOKENS(@search,"text_fr")' , $aql ) ;
        $this->assertStringContainsString( 'ANALYZER(' , $aql ) ;
        $this->assertStringContainsString( 'BM25(doc)' , $aql ) ;
        $this->assertStringContainsString( 'SORT score DESC' , $aql ) ;
        $this->assertStringContainsString( 'LIMIT 10' , $aql ) ;
        $this->assertStringContainsString( 'MERGE(PARSE_IDENTIFIER(doc._id)' , $aql ) ;

        // fullCount requested → foundRows() exposes the total (before the LIMIT)
        $this->assertSame( [ 'options' => [ 'fullCount' => true ] ] , $captured[ 'options' ] ) ;
        $this->assertSame( 936 , $engine->foundRows() ) ;
    }

    public function testFindUsesTheDefaultLimitWhenNoneGiven() :void
    {
        $engine = $this->engineWithDatabase( [] , $captured ) ;

        $engine->find( [ Arango::SEARCH => 'dupont' ] ) ;

        $this->assertStringContainsString( 'LIMIT ' . FederatedSearch::DEFAULT_LIMIT , $captured[ 'aql' ] ) ;
    }

    public function testFindReturnsEmptyWithoutATerm() :void
    {
        $this->assertSame( [] , $this->engineWithDatabase( [ [ 'collection' => 'x' ] ] )->find() ) ;
    }

    public function testFindReturnsEmptyWithoutAView() :void
    {
        $arango = $this->createMock( ArangoDB::class ) ;
        $engine = new FederatedSearch( $this->createMock( Container::class ) ,
        [
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' ] ] ,
            Arango::DATABASE                 => $arango ,
        ]) ;

        $this->assertSame( [] , $engine->find( [ Arango::SEARCH => 'dupont' ] ) ) ;
    }

    public function testFindReturnsEmptyWithoutADatabase() :void
    {
        // no Arango::DATABASE → the engine cannot run anything
        $engine = $this->make(
        [
            FederatedSearchParam::VIEW       => 'global_search' ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' ] ] ,
        ]) ;

        $this->assertSame( [] , $engine->find( [ Arango::SEARCH => 'dupont' ] ) ) ;
    }

    public function testFindReturnsEmptyWhenNoFieldIsDeclared() :void
    {
        $arango = $this->createMock( ArangoDB::class ) ;
        $engine = new FederatedSearch( $this->createMock( Container::class ) ,
        [
            FederatedSearchParam::VIEW => 'global_search' ,
            Arango::DATABASE           => $arango , // searchable spec without fields
        ]) ;

        $this->assertSame( [] , $engine->find( [ Arango::SEARCH => 'dupont' ] ) ) ;
    }

    public function testDatabaseResolvedFromAContainerId() :void
    {
        $arango    = $this->createMock( ArangoDB::class ) ;
        $container = $this->createMock( Container::class ) ;
        $container->method( 'has' )->with( 'db.arango' )->willReturn( true ) ;
        $container->method( 'get' )->with( 'db.arango' )->willReturn( $arango ) ;

        $engine = new FederatedSearch( $container , [ Arango::DATABASE => 'db.arango' ] ) ;

        $this->assertSame( $arango , $engine->arangodb ) ;
    }

    public function testFoundRowsIsZeroBeforeAnySearch() :void
    {
        $this->assertSame( 0 , $this->make()->foundRows() ) ;
    }

    // ---- rebuild (Lot C3) ---------------------------------------------------

    public function testRebuildGroupsByCollectionOrdersByScoreAndWraps() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] , [ '_key' => 'c2' , 'name' => 'Dupont & Fils' ] ] ) ;
        $products  = $this->modelReturning( [ [ '_key' => 'p7' , 'name' => 'Colle Dupont' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers , 'model.products' => $products ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' , 'products' => 'model.products' ] ,
            FederatedSearchParam::SKIN   => Skin::LIST ,
        ]) ;

        $matches =
        [
            [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ,
            [ 'collection' => 'products'  , 'key' => 'p7' , 'score' => 7.4 ] ,
            [ 'collection' => 'customers' , 'key' => 'c2' , 'score' => 5.2 ] ,
        ] ;

        // wrapped { collection, score, document }, in the find (score) order
        $this->assertSame(
        [
            [ 'collection' => 'customers' , 'score' => 9.1 , 'document' => [ '_key' => 'c1' , 'name' => 'Dupont SARL'  ] ] ,
            [ 'collection' => 'products'  , 'score' => 7.4 , 'document' => [ '_key' => 'p7' , 'name' => 'Colle Dupont' ] ] ,
            [ 'collection' => 'customers' , 'score' => 5.2 , 'document' => [ '_key' => 'c2' , 'name' => 'Dupont & Fils' ] ] ,
        ] , $engine->rebuild( $matches ) ) ;

        // one batched list() per collection : a `_key IN [...]` filter + the resolved skin
        $this->assertSame( [ 'key' => '_key' , 'op' => 'in' , 'val' => [ 'c1' , 'c2' ] ] , $customers->captured[ Arango::FILTER ] ) ;
        $this->assertSame( Skin::LIST , $customers->captured[ Arango::SKIN ] ) ;
        $this->assertSame( [ 'p7' ] , $products->captured[ Arango::FILTER ][ 'val' ] ) ;
    }

    public function testRebuildSkipsAnUnregisteredCollection() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] , // 'products' is NOT registered
        ]) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ,
            [ 'collection' => 'products'  , 'key' => 'p7' , 'score' => 7.4 ] , // dropped : no model
        ]) ;

        $this->assertCount( 1 , $results ) ;
        $this->assertSame( 'customers' , $results[ 0 ][ 'collection' ] ) ;
    }

    public function testRebuildSkipsAMatchTheModelDoesNotReturn() :void
    {
        // the model returns c1 but not c2 (filtered out by its own rules) → c2 dropped
        $customers = $this->modelReturning( [ [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] ,
        ]) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ,
            [ 'collection' => 'customers' , 'key' => 'c2' , 'score' => 5.2 ] ,
        ]) ;

        $this->assertSame( [ 'c1' ] , array_map( static fn( $r ) => $r[ 'document' ][ '_key' ] , $results ) ) ;
    }

    public function testRebuildSkipsANonDocumentsService() :void
    {
        $container = $this->createMock( Container::class ) ;
        $container->method( 'has' )->willReturn( true ) ;
        $container->method( 'get' )->willReturn( new \stdClass() ) ; // not a Documents

        $engine = new FederatedSearch( $container , [ FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] ] ) ;

        $this->assertSame( [] , $engine->rebuild( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ] ) ) ;
    }

    public function testRebuildReturnsEmptyOnNoMatches() :void
    {
        $this->assertSame( [] , $this->make()->rebuild( [] ) ) ;
    }

    public function testRebuildRequestSkinOverridesTheEngineDefault() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'c1' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] ,
            FederatedSearchParam::SKIN   => Skin::LIST , // engine default
        ]) ;

        $engine->rebuild( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 1.0 ] ] , [ Arango::SKIN => Skin::COMPACT ] ) ;

        $this->assertSame( Skin::COMPACT , $customers->captured[ Arango::SKIN ] ) ; // request wins
    }

    public function testRebuildFallsBackToSkinDefaultWhenNoneConfigured() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'c1' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] , // no SKIN configured
        ]) ;

        $engine->rebuild( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 1.0 ] ] ) ;

        $this->assertSame( Skin::DEFAULT , $customers->captured[ Arango::SKIN ] ) ;
    }

    public function testRebuildReadsTheKeyFromObjectDocuments() :void
    {
        // a model that hydrates to objects (not arrays) is still placed back by its _key
        $document  = (object) [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] ;
        $customers = $this->modelReturning( [ $document ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] ,
        ]) ;

        $results = $engine->rebuild( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ] ) ;

        $this->assertSame( $document , $results[ 0 ][ 'document' ] ) ;
    }

    public function testRebuildDropsADocumentWithoutAResolvableKey() :void
    {
        // a key-less document cannot be matched back to its score → dropped
        $customers = $this->modelReturning( [ 'orphan-without-key' ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] ,
        ]) ;

        $this->assertSame( [] , $engine->rebuild( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ] ) ) ;
    }

    // ---- search (find + rebuild) --------------------------------------------

    public function testSearchFindsThenRebuilds() :void
    {
        // database double feeding find() ...
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ] ) ;
        $cursor->method( 'getFullCount' )->willReturn( 1 ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'query' )->willReturn( $cursor ) ;

        $arango = $this->createMock( ArangoDB::class ) ;
        $arango->method( 'database' )->willReturn( $database ) ;

        // ... and a model rebuilding the matched key
        $customers = $this->modelReturning( [ [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'model.customers' => $customers ] ) ,
        [
            FederatedSearchParam::VIEW       => 'global_search' ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' ] ] ,
            FederatedSearchParam::MODELS     => [ 'customers' => 'model.customers' ] ,
            Arango::DATABASE                 => $arango ,
        ]) ;

        $this->assertSame(
        [
            [ 'collection' => 'customers' , 'score' => 9.1 , 'document' => [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] ] ,
        ] , $engine->search( [ Arango::SEARCH => 'dupont' ] ) ) ;

        $this->assertSame( 1 , $engine->foundRows() ) ;
    }

    // ---- permissions / per-collection gate (Lot C4) -------------------------

    /**
     * A db-backed engine with a custom registry + `requires`, capturing the AQL.
     *
     * @param array<string, string>                       $models
     * @param array<string, string|array<int, string>>    $requires
     * @param array<string, mixed>|null                   $captured
     *
     * @return FederatedSearch
     */
    private function gateEngine( array $models , array $requires , ?array &$captured = null ) :FederatedSearch
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( [] ) ;
        $cursor->method( 'getFullCount' )->willReturn( 0 ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'query' )->willReturnCallback( function( $aql , $binds = [] , $options = [] ) use ( &$captured , $cursor )
        {
            $captured = [ 'aql' => $aql ] ;
            return $cursor ;
        } ) ;

        $arango = $this->createMock( ArangoDB::class ) ;
        $arango->method( 'database' )->willReturn( $database ) ;

        return new FederatedSearch( $this->createMock( Container::class ) ,
        [
            FederatedSearchParam::VIEW       => 'global_search' ,
            FederatedSearchParam::SEARCHABLE => [ Search::FIELDS => [ 'name' ] ] ,
            FederatedSearchParam::MODELS     => $models ,
            FederatedSearchParam::REQUIRES   => $requires ,
            Arango::DATABASE                 => $arango ,
        ]) ;
    }

    public function testFindRestrictsTheSearchToAuthorizedCollections() :void
    {
        $engine = $this->gateEngine(
            [ 'customers' => 'm.c' , 'products' => 'm.p' , 'sellers' => 'm.s' ] ,
            [ 'customers' => 'customers:list' , 'sellers' => 'sellers:list' ] , // products has no requirement → public
            $captured
        ) ;

        // a sales rep : granted customers:list, not sellers:list
        $engine->find( [ Arango::SEARCH => 'dupont' , Arango::AUTHORIZER => static fn( string $s ) => $s === 'customers:list' ] ) ;

        $aql = $captured[ 'aql' ] ;
        $this->assertStringContainsString( 'OPTIONS {"collections":[' , $aql ) ;
        $this->assertStringContainsString( '"customers"' , $aql ) ; // granted
        $this->assertStringContainsString( '"products"'  , $aql ) ; // public
        $this->assertStringNotContainsString( 'sellers'  , $aql ) ; // denied → excluded
    }

    public function testFindIsFailOpenWithoutAnAuthorizer() :void
    {
        $engine = $this->gateEngine( [ 'customers' => 'm.c' , 'sellers' => 'm.s' ] , [ 'sellers' => 'sellers:list' ] , $captured ) ;

        $engine->find( [ Arango::SEARCH => 'dupont' ] ) ; // no authorizer

        $this->assertStringContainsString( '"sellers"' , $captured[ 'aql' ] ) ; // fail-open : everything allowed
    }

    public function testFindReturnsEmptyWhenNoCollectionIsAuthorized() :void
    {
        $engine = $this->gateEngine( [ 'customers' => 'm.c' ] , [ 'customers' => 'customers:list' ] , $captured ) ;

        $result = $engine->find( [ Arango::SEARCH => 'dupont' , Arango::AUTHORIZER => static fn() => false ] ) ;

        $this->assertSame( [] , $result ) ;
        $this->assertNull( $captured ) ; // the query is never even issued
    }

    public function testRebuildSkipsAnUnauthorizedCollection() :void
    {
        $customers = $this->modelReturning( [ [ '_key' => 'c1' , 'name' => 'Dupont SARL' ] ] ) ;
        $sellers   = $this->modelReturning( [ [ '_key' => 's1' , 'name' => 'Jean Dupont'  ] ] ) ;

        $engine = new FederatedSearch( $this->containerWith( [ 'm.c' => $customers , 'm.s' => $sellers ] ) ,
        [
            FederatedSearchParam::MODELS   => [ 'customers' => 'm.c' , 'sellers' => 'm.s' ] ,
            FederatedSearchParam::REQUIRES => [ 'sellers' => 'sellers:list' ] , // customers public
        ]) ;

        $results = $engine->rebuild(
        [
            [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ,
            [ 'collection' => 'sellers'   , 'key' => 's1' , 'score' => 7.4 ] , // denied → dropped
        ] , [ Arango::AUTHORIZER => static fn() => false ] ) ;

        $this->assertCount( 1 , $results ) ;
        $this->assertSame( 'customers' , $results[ 0 ][ 'collection' ] ) ;
    }

    public function testRequiresRegistryKeepsStringAndListEntriesAndDropsTheRest() :void
    {
        $engine = $this->make(
        [
            FederatedSearchParam::REQUIRES =>
            [
                'customers' => 'customers:list' ,                  // string : kept
                'users'     => [ 'users:list' , 'users:admin' ] ,  // OR-list : kept
                ''          => 'x' ,                               // empty collection : dropped
                'sellers'   => 123 ,                               // non-string / non-array : dropped
            ] ,
        ]) ;

        $this->assertSame( [ 'customers' => 'customers:list' , 'users' => [ 'users:list' , 'users:admin' ] ] , $engine->requires ) ;
    }

    public function testRequiresIgnoresANonArrayDeclaration() :void
    {
        $this->assertSame( [] , $this->make( [ FederatedSearchParam::REQUIRES => 'not-an-array' ] )->requires ) ;
    }

    public function testRebuildSkipsACollectionWhoseModelServiceIsMissing() :void
    {
        // 'customers' is registered and authorized, but the container cannot resolve its service
        $container = $this->createMock( Container::class ) ;
        $container->method( 'has' )->willReturn( false ) ;

        $engine = new FederatedSearch( $container , [ FederatedSearchParam::MODELS => [ 'customers' => 'm.c' ] ] ) ;

        $this->assertSame( [] , $engine->rebuild( [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] ] ) ) ;
    }
}

/**
 * A minimal {@see Documents} subtype for the rebuild tests: it satisfies the
 * engine's `instanceof Documents` guard without booting the real model
 * (the parent constructor is bypassed), captures its `list()` init in
 * `$captured`, and returns canned documents.
 */
final class FakeFederatedModel extends Documents
{
    /** @var array<string, mixed> The last `list()` init. */
    public array $captured = [] ;

    /** @param array<int, mixed> $documents */
    public function __construct( public array $documents = [] ) {}

    /**
     * @param array<string, mixed> $init
     * @return array<int, mixed>
     */
    public function list( array $init = [] ) :array
    {
        $this->captured = $init ;
        return $this->documents ;
    }
}
