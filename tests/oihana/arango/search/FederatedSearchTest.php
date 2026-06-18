<?php

namespace tests\oihana\arango\search ;

use DI\Container ;

use oihana\arango\clients\Database ;
use oihana\arango\clients\cursor\Cursor ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\enums\Arango ;
use oihana\arango\models\enums\Search ;
use oihana\arango\search\FederatedSearch ;
use oihana\arango\search\enums\FederatedSearchParam ;

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

    public function testSearchableIgnoresANonArrayDeclaration() :void
    {
        $this->assertSame( [] , $this->make( [ FederatedSearchParam::SEARCHABLE => 'not-an-array' ] )->searchable ) ;
    }

    // ---- find (Lot C2) ------------------------------------------------------

    /**
     * A canned search spec + registry, paired with a database double whose
     * `query()` captures the AQL + binds and whose cursor returns `$rows`.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null        $captured Filled by reference with `[ aql, binds ]`.
     *
     * @return FederatedSearch
     */
    private function engineWithDatabase( array $rows , ?array &$captured = null ) :FederatedSearch
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'all' )->willReturn( $rows ) ;

        $database = $this->createMock( Database::class ) ;
        $database->method( 'query' )->willReturnCallback( function( $aql , $binds = [] , $options = [] ) use ( &$captured , $cursor )
        {
            $captured = [ 'aql' => $aql , 'binds' => $binds ] ;
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

    public function testFindRunsAScoredSearchAndReturnsTheRows() :void
    {
        $rows = [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 9.1 ] , [ 'collection' => 'products' , 'key' => 'p1' , 'score' => 7.4 ] ] ;

        $engine = $this->engineWithDatabase( $rows , $captured ) ;

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

    public function testSearchDelegatesToFind() :void
    {
        $rows   = [ [ 'collection' => 'customers' , 'key' => 'c1' , 'score' => 3.2 ] ] ;
        $engine = $this->engineWithDatabase( $rows ) ;

        $this->assertSame( $rows , $engine->search( [ Arango::SEARCH => 'dupont' ] ) ) ;
    }
}
