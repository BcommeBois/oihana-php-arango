<?php

namespace tests\oihana\arango\models\traits\edges;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesExistTrait}:
 * the `_from`/`_to` filter methods (existEdge / existEdgeFrom / existEdgeTo) and
 * the directional vertex-neighbour checks (has*Vertex → hasVertex → countVertices > 0).
 */
final class EdgesExistTraitTest extends TestCase
{
    public function testExistEdgeWithFromAndToFiltersBoth() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 1 ;

        $this->assertTrue( $edges->existEdge( 'users/1' , 'roles/2' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._from == @from && doc._to == @to RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
        $this->assertSame( 'users/1' , $edges->lastBinds[ 'from' ] ) ;
        $this->assertSame( 'roles/2' , $edges->lastBinds[ 'to' ] ) ;
    }

    public function testExistEdgeReturnsFalseWhenCountIsZero() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 0 ;

        $this->assertFalse( $edges->existEdge( 'users/1' , 'roles/2' ) ) ;
    }

    public function testExistEdgeFromFiltersFromOnly() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 2 ;

        $this->assertTrue( $edges->existEdgeFrom( 'users/1' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._from == @from RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
    }

    public function testExistEdgeToFiltersToOnly() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 1 ;

        $this->assertTrue( $edges->existEdgeTo( 'roles/2' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._to == @to RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
    }

    public function testHasInboundVertexBuildsInboundTraversalWithIdFilter() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 1 ;

        $this->assertTrue( $edges->hasInboundVertex( 'roles/2' , 'users/1' ) ) ;
        $this->assertStringContainsString( 'IN INBOUND @startVertex' , $edges->lastQuery ) ;
        $this->assertStringContainsString( 'FILTER vertex._id == @id' , $edges->lastQuery ) ;
        $this->assertSame( 'users/1' , $edges->lastBinds[ 'id' ] ) ;
    }

    public function testHasOutboundVertexBuildsOutboundTraversal() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 0 ;

        $this->assertFalse( $edges->hasOutboundVertex( 'users/1' , 'roles/2' ) ) ;
        $this->assertStringContainsString( 'IN OUTBOUND @startVertex' , $edges->lastQuery ) ;
    }

    public function testHasAnyVertexBuildsAnyTraversalWithOrFilter() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 1 ;

        $this->assertTrue( $edges->hasAnyVertex( 'users/1' , 'roles/2' ) ) ;
        $this->assertStringContainsString( 'IN ANY @startVertex' , $edges->lastQuery ) ;
        // ANY builds an OR over the from/to resolved ids of the target vertex.
        $this->assertStringContainsString( '||' , $edges->lastQuery ) ;
    }
}
