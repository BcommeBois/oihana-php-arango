<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\http\Error409;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesInsertTrait::insertEdge()}:
 * vertex-id validation, the uniqueness guard (existEdge), and the delegation to
 * insert() with the _from/_to attributes appended to the document.
 */
final class EdgesInsertTraitTest extends TestCase
{
    public function testInsertEdgeAppendsFromAndToAndReturnsObject() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->objectResult = (object) [ '_key' => 'edge1' ] ;
        $edges->firstResult  = 0 ; // existEdge → false

        $result = $edges->insertEdge( 'users/1' , 'roles/2' , [ 'weight' => 5 ] , [ AQL::UNIQUE => false ] ) ;

        $this->assertSame( $edges->objectResult , $result ) ;
        $this->assertSame( 'INSERT @insert INTO @@collection RETURN NEW' , $edges->lastQuery ) ;

        $insert = $edges->lastBinds[ 'insert' ] ;
        $this->assertSame( 5 , $insert[ 'weight' ] ) ;
        $this->assertSame( 'users/1' , $insert[ '_from' ] ) ;
        $this->assertSame( 'roles/2' , $insert[ '_to' ] ) ;
    }

    public function testInsertEdgeThrowsConflictWhenUniqueEdgeAlreadyExists() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 1 ; // existEdge → true

        $this->expectException( Error409::class ) ;
        $edges->insertEdge( 'users/1' , 'roles/2' ) ;
    }

    public function testInsertEdgeRejectsInvalidFromId() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $this->expectException( InvalidArgumentException::class ) ;
        $edges->insertEdge( 'no-slash' , 'roles/2' , [] , [ AQL::UNIQUE => false ] ) ;
    }

    public function testInsertEdgeRejectsInvalidToId() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $this->expectException( InvalidArgumentException::class ) ;
        $edges->insertEdge( 'users/1' , 'no-slash' , [] , [ AQL::UNIQUE => false ] ) ;
    }
}
