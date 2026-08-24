<?php

namespace tests\oihana\arango\models\traits\queries;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\queries\FacetCountsQueryTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

/**
 * Self-contained host for the facet-counts permission gate.
 */
class FacetCountsGateStub
{
    use ListQueryTrait , FacetCountsQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'articles' ;
        $this->facets     = [ 'category' => [ Facet::TYPE => Facet::FIELD ] ] ;
    }
}

/**
 * Permission gate on `?facetCounts=` (lot 2): a countable dimension on a field
 * hidden from the projection is dropped — its distinct values and counts would
 * otherwise leak the hidden field in clear (a direct facet-counts oracle).
 */
class FacetCountsRequiresGateTest extends TestCase
{
    private function stub(): FacetCountsGateStub
    {
        return new FacetCountsGateStub() ;
    }

    public function testRefusedDimensionIsDropped(): void
    {
        $stub = $this->stub() ;
        $stub->fields = [ 'category' => [ Field::REQUIRES => 'cat:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::FACET_COUNTS => 'category' , Arango::AUTHORIZER => fn() => false ] ;

        // The only requested dimension is refused → no LET, empty query.
        $this->assertSame( '' , $stub->buildFacetCountsQuery( $init , $binds ) ) ;
    }

    public function testGrantedDimensionIsCounted(): void
    {
        $stub = $this->stub() ;
        $stub->fields = [ 'category' => [ Field::REQUIRES => 'cat:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::FACET_COUNTS => 'category' , Arango::AUTHORIZER => fn( string $s ) => $s === 'cat:read' ] ;
        $query = $stub->buildFacetCountsQuery( $init , $binds ) ;

        $this->assertStringContainsString( 'category' , $query ) ;
        $this->assertStringContainsString( 'COLLECT' , $query ) ;
    }

    public function testUngatedDimensionIsUnaffected(): void
    {
        $stub = $this->stub() ; // no $fields REQUIRES on 'category'

        $binds = [] ;
        $init  = [ Arango::FACET_COUNTS => 'category' , Arango::AUTHORIZER => fn() => false ] ;

        $this->assertStringContainsString( 'category' , $stub->buildFacetCountsQuery( $init , $binds ) ) ;
    }

    public function testExplicitRequiresOnTheFacetDefinitionDropsTheDimension(): void
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'secret' => [ Facet::TYPE => Facet::FIELD , Field::REQUIRES => 'ops:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::FACET_COUNTS => 'secret' , Arango::AUTHORIZER => fn() => false ] ;

        $this->assertSame( '' , $stub->buildFacetCountsQuery( $init , $binds ) ) ;
    }

    public function testRefusedLinkedDimensionIsDropped(): void
    {
        // A linked dimension buckets on a field of ANOTHER collection, which the
        // main projection cannot speak for: the gate that answers for it is the
        // REQUIRES declared on the facet itself — the same one the filtering side
        // obeys. Both linked types are measured, so neither can be the door left open.
        $stub = $this->stub() ;
        $stub->facets =
        [
            'location' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'places_edges' , Field::REQUIRES => 'geo:read' ] ,
            'author'   => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Field::REQUIRES => 'hr:read' ] ,
        ] ;

        $binds = [] ;
        $init  = [ Arango::FACET_COUNTS => 'location,author' , Arango::AUTHORIZER => fn() => false ] ;

        $this->assertSame( '' , $stub->buildFacetCountsQuery( $init , $binds ) ) ;
    }

    public function testGrantedLinkedDimensionIsCounted(): void
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'location' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'places_edges' , Field::REQUIRES => 'geo:read' ] ] ;

        $binds = [] ;
        $init  = [ Arango::FACET_COUNTS => 'location' , Arango::AUTHORIZER => fn( string $s ) => $s === 'geo:read' ] ;

        $this->assertStringContainsString( 'INBOUND doc places_edges' , $stub->buildFacetCountsQuery( $init , $binds ) ) ;
    }
}
