<?php

namespace tests\oihana\arango\models\traits\edges;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesCountTrait::countEdges()}
 * — the `_from`/`_to` filtered LENGTH count. The vertex-traversal count*Vertices()
 * methods need wired vertex models and are out of scope here.
 */
final class EdgesCountTraitTest extends TestCase
{
    public function testCountEdgesWithFromAndTo() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 7 ;

        $this->assertSame( 7 , $edges->countEdges( 'users/1' , 'roles/2' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._from == @from && doc._to == @to RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
    }

    public function testCountEdgesFromOnly() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 3 ;

        $this->assertSame( 3 , $edges->countEdges( 'users/1' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._from == @from RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
    }
}
