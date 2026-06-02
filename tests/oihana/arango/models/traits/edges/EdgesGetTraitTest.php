<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesGetTrait}:
 * getVertices and its directional wrappers (Inbound/Outbound/Any), plus the
 * getFirst*Vertex() variants that return only the first matched vertex.
 */
final class EdgesGetTraitTest extends TestCase
{
    /**
     * Replaces the random `edgeCollection_<hex>` bind name with a stable token.
     *
     * @param string $query The query to normalise.
     *
     * @return string The normalised query.
     */
    private function normalize( string $query ) :string
    {
        return preg_replace( '/edgeCollection_[0-9a-f]+/' , 'edgeCollection_X' , $query ) ;
    }

    public function testGetInboundVerticesBuildsInboundTraversal() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $result = $edges->getInboundVertices( 'roles/2' ) ;

        $this->assertSame( $edges->documentsResult , $result ) ;
        $this->assertSame
        (
            'FOR vertex IN INBOUND @startVertex @@edgeCollection_X RETURN vertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetOutboundVerticesBuildsOutboundTraversal() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [] ;

        $edges->getOutboundVertices( 'users/1' ) ;

        $this->assertSame
        (
            'FOR vertex IN OUTBOUND @startVertex @@edgeCollection_X RETURN vertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetAnyVerticesBuildsAnyTraversal() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [] ;

        $edges->getAnyVertices( 'users/1' ) ;

        $this->assertStringContainsString( 'IN ANY @startVertex' , $edges->lastQuery ) ;
    }

    public function testGetVerticesDirectExplicitDirection() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [ (object) [ '_key' => 'a' ] ] ;

        $result = $edges->getVertices( Traversal::INBOUND , 'roles/2' ) ;

        $this->assertSame( $edges->documentsResult , $result ) ;
    }

    public function testGetFirstInboundVertexReturnsTheFirstDocument() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $this->assertSame( $edges->documentsResult[ 0 ] , $edges->getFirstInboundVertex( 'roles/2' ) ) ;
    }

    public function testGetFirstOutboundVertexReturnsNullWhenEmpty() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [] ;

        $this->assertNull( $edges->getFirstOutboundVertex( 'users/1' ) ) ;
    }

    public function testGetFirstAnyVertexReturnsTheFirstDocument() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [ (object) [ '_key' => 'x' ] ] ;

        $this->assertSame( $edges->documentsResult[ 0 ] , $edges->getFirstAnyVertex( 'users/1' ) ) ;
    }

    public function testGetVerticesWithTargetModelProjectsThroughItsReturnFields() :void
    {
        $target = new MockEdges( 'roles' ) ;
        $target->schema = null ;

        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [ (object) [ '_key' => 'a' ] ] ;

        $result = $edges->getVertices( Traversal::INBOUND , 'roles/2' , [ AQL::TARGET => $target ] ) ;

        $this->assertSame( $edges->documentsResult , $result ) ;
        $this->assertSame
        (
            'FOR vertex IN INBOUND @startVertex @@edgeCollection_X RETURN vertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetVerticesWithTargetAndExplicitReturnUsesThatReturn() :void
    {
        $target = new MockEdges( 'roles' ) ;
        $target->schema = null ;

        $edges = new MockEdges( 'follows' ) ;
        $edges->documentsResult = [] ;

        $edges->getVertices( Traversal::INBOUND , 'roles/2' , [ AQL::TARGET => $target , AQL::RETURN => 'vertex._key' ] ) ;

        $this->assertStringEndsWith( 'RETURN vertex._key' , $edges->lastQuery ) ;
    }
}
