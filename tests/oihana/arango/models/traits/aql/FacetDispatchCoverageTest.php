<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterParam;

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the {@see \oihana\arango\models\traits\aql\FacetTrait::prepareFacets()}
 * dispatcher: drives every Facet::TYPE match arm through prepareFacets (the
 * builders themselves are asserted in detail elsewhere — here we only prove each
 * arm is routed and produces a predicate). Also exercises the two empty-guard
 * returns of HasFacetField and HasFacetSimpleConditions.
 */
final class FacetDispatchCoverageTest extends TestCase
{
    private function stub() :FacetTraitStub
    {
        return new FacetTraitStub() ;
    }

    /**
     * Routes one facet definition + request value through prepareFacets and
     * returns the produced predicate.
     *
     * @param array $facet The model-side facet definition (must carry Facet::TYPE).
     * @param mixed $value The request-side facet value.
     *
     * @return ?string
     */
    private function dispatch( array $facet , mixed $value ) :?string
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'k' => $facet ] ;
        $binds = [] ;
        return $stub->callPrepareFacets( [ Arango::FACETS => [ 'k' => $value ] ] , $binds ) ;
    }

    /**
     * Every facet type, with the minimal config + value that yields a predicate.
     *
     * @return array<string,array{0:array,1:mixed}>
     */
    public static function facetTypeProvider() :array
    {
        return
        [
            'FIELD'            => [ [ Facet::TYPE => Facet::FIELD ] , 'draft' ] ,
            'ARRAY_COMPLEX'    => [ [ Facet::TYPE => Facet::ARRAY_COMPLEX ] , [ 'breeding_alternateName' => 'pig' ] ] ,
            'EDGE'             => [ [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'orgs_places' ] , 1234 ] ,
            'EDGE_COMPLEX'     => [ [ Facet::TYPE => Facet::EDGE_COMPLEX , AQL::EDGE => 'live_numbers' ] , [ 'value' => '459' ] ] ,
            'EDGE_AGGREGATE'   => [ [ Facet::TYPE => Facet::EDGE_AGGREGATE , AQL::EDGE => 'balance_edges' ] , [ 'agg' => 'sum' , 'field' => 'revenue' , 'val' => 5000000 ] ] ,
            'IN'               => [ [ Facet::TYPE => Facet::IN ] , 'k1,k2' ] ,
            'LIST'             => [ [ Facet::TYPE => Facet::LIST ] , 'k1,k2' ] ,
            'LIST_FIELD'       => [ [ Facet::TYPE => Facet::LIST_FIELD ] , 'k1,k2' ] ,
            'LIST_FIELD_SORTED'=> [ [ Facet::TYPE => Facet::LIST_FIELD_SORTED ] , 'k1,k2' ] ,
            'JOIN'             => [ [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name' ] , 'alice' ] ,
            'JOIN_COMPLEX'     => [ [ Facet::TYPE => Facet::JOIN_COMPLEX , AQL::COLLECTION => 'comments' ] , [ 'status' => 'approved' ] ] ,
            'JOIN_AGGREGATE'   => [ [ Facet::TYPE => Facet::JOIN_AGGREGATE , AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , Facet::AGG => 'avg' , AQL::FIELDS => 'score' , Facet::OP => 'ge' ] , [ 'val' => 4 ] ] ,
        ] ;
    }

    /**
     * @dataProvider facetTypeProvider
     */
    public function testPrepareFacetsRoutesEveryType( array $facet , mixed $value ) :void
    {
        $result = $this->dispatch( $facet , $value ) ;
        $this->assertNotNull( $result ) ;
        $this->assertNotSame( '' , $result ) ;
    }

    // ---------------------------------------------------------------- empty guards

    public function testFieldObjectWithoutValReturnsEmpty() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->callField( 'k' , [ FilterParam::OP => 'eq' ] , $binds , [] , AQL::DOC ) ) ;
    }

    public function testFieldEmptyValuesReturnsEmpty() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->callField( 'k' , [] , $binds , [] , AQL::DOC ) ) ;
    }

    public function testSimpleConditionsObjectWithoutValReturnsEmpty() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->callEdge( 'k' , [ FilterParam::OP => 'eq' ] , $binds , [ AQL::EDGE => 'e' ] , AQL::DOC ) ) ;
    }

    public function testSimpleConditionsEmptyClausesReturnsEmpty() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->callEdge( 'k' , [] , $binds , [ AQL::EDGE => 'e' ] , AQL::DOC ) ) ;
    }
}
