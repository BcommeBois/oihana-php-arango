<?php

namespace tests\oihana\arango\models\traits\edges;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for the `_from`/`_to` filter-based methods of
 * {@see \oihana\arango\models\traits\edges\EdgesExistTrait}: existEdge,
 * existEdgeFrom and existEdgeTo (existence = COUNT > 0). The vertex-traversal
 * has*Vertex() methods need wired vertex models and are out of scope here.
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
}
