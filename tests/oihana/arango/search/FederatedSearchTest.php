<?php

namespace tests\oihana\arango\search ;

use DI\Container ;

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

    public function testSearchReturnsAnEmptyResultSetForNow() :void
    {
        // Lot C1 skeleton : the find / rebuild stages are not wired yet.
        $engine = $this->make(
        [
            FederatedSearchParam::VIEW   => 'global_search' ,
            FederatedSearchParam::MODELS => [ 'customers' => 'model.customers' ] ,
        ]) ;

        $this->assertSame( [] , $engine->search( [ 'q' => 'dupont' ] ) ) ;
    }
}
