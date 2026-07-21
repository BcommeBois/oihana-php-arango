<?php

namespace tests\oihana\arango\models\traits\edges;

use InvalidArgumentException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
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

    // ---------------------------------------------------------------- target projection : inherited authorizer (T4)

    /**
     * Builds an edge whose traversal target is a Documents model projecting a
     * masked `salary` field, then runs `getVertices` with the given authorizer.
     */
    private function traversalWithGatedTarget( ?callable $authorizer ) :MockEdges
    {
        $target = new MockDocuments( 'people' ) ;
        $target->schema = null ;
        $target->fields = [ 'name' => [] , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $edges = new MockEdges( 'employs' ) ;
        $edges->documentsResult = [] ;

        $init = [ AQL::TARGET => $target ] ;
        if ( $authorizer !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }

        $edges->getVertices( Traversal::OUTBOUND , 'teams/1' , $init ) ;

        return $edges ;
    }

    public function testTargetProjectionInheritsFieldRequiresDroppedWhenDenied() :void
    {
        // The target's `salary` is masked from reading; a denying authorizer must
        // drop it from the traversal projection (no field oracle through the edge).
        $edges = $this->traversalWithGatedTarget( fn() => false ) ;

        $this->assertStringContainsString   ( 'name'   , $edges->lastQuery ) ;
        $this->assertStringNotContainsString( 'salary' , $edges->lastQuery ) ;
    }

    public function testTargetProjectionInheritsFieldRequiresKeptWhenGranted() :void
    {
        $edges = $this->traversalWithGatedTarget( fn( string $s ) => $s === 'hr:read' ) ;

        $this->assertStringContainsString( 'salary' , $edges->lastQuery ) ;
    }

    public function testTargetProjectionFailsOpenWithoutAuthorizer() :void
    {
        // No authorizer injected → the authorization layer is disabled, the gated
        // field stays projected (unchanged behaviour, symmetric with the model path).
        $edges = $this->traversalWithGatedTarget( null ) ;

        $this->assertStringContainsString( 'salary' , $edges->lastQuery ) ;
    }

    public function testPrepareTraversalThrowsOnEmptyVertex() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $init  = [] ;

        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'Vertex ID cannot be null or empty' ) ;
        $edges->prepareTraversal( Traversal::OUTBOUND , null , $init ) ;
    }

    // ---------- WITH clause (anonymous traversals, cluster-safe)

    /**
     * Builds an edge model whose `_from` / `_to` vertex collections are known,
     * which is what enables the automatic `WITH` declaration.
     */
    private function edgesWithVertexModels
    (
        string $from = 'users' ,
        string $to   = 'roles'
    )
    :MockEdges
    {
        $edges = new MockEdges( 'follows' ) ;
        // initializeDeleteSignals() makes the vertex doubles faithful to a real
        // Documents (whose afterDelete Signal the Edges destructor disconnects).
        $edges->from = ( new MockDocuments( $from ) )->initializeDeleteSignals() ;
        $edges->to   = ( new MockDocuments( $to   ) )->initializeDeleteSignals() ;
        $edges->documentsResult = [] ;
        return $edges ;
    }

    public function testGetOutboundVerticesPrependsWithToCollection() :void
    {
        $edges = $this->edgesWithVertexModels() ;

        $edges->getOutboundVertices( 'users/1' ) ;

        $this->assertSame
        (
            'WITH roles FOR vertex IN OUTBOUND @startVertex @@edgeCollection_X RETURN vertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetInboundVerticesPrependsWithFromCollection() :void
    {
        $edges = $this->edgesWithVertexModels() ;

        $edges->getInboundVertices( 'roles/2' ) ;

        $this->assertSame
        (
            'WITH users FOR vertex IN INBOUND @startVertex @@edgeCollection_X RETURN vertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetAnyVerticesPrependsWithBothCollections() :void
    {
        $edges = $this->edgesWithVertexModels() ;

        $edges->getAnyVertices( 'users/1' ) ;

        $this->assertStringStartsWith
        (
            'WITH users, roles FOR vertex IN ANY @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetAnyVerticesDeduplicatesIdenticalCollections() :void
    {
        $edges = $this->edgesWithVertexModels( 'users' , 'users' ) ;

        $edges->getAnyVertices( 'users/1' ) ;

        $this->assertStringStartsWith
        (
            'WITH users FOR vertex IN ANY @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetVerticesWithNamedGraphOmitsWith() :void
    {
        $edges = $this->edgesWithVertexModels() ;

        $edges->getOutboundVertices( 'users/1' , [ AQL::GRAPH => 'social' ] ) ;

        $this->assertStringStartsWith( 'FOR vertex IN OUTBOUND @startVertex' , $edges->lastQuery ) ;
        $this->assertStringNotContainsString( 'WITH' , $edges->lastQuery ) ;
    }

    public function testGetVerticesExplicitWithArrayOverridesDerivation() :void
    {
        $edges = $this->edgesWithVertexModels() ;

        $edges->getOutboundVertices( 'users/1' , [ AQL::WITH => [ 'alpha' , 'beta' ] ] ) ;

        $this->assertStringStartsWith
        (
            'WITH alpha, beta FOR vertex IN OUTBOUND @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetVerticesExplicitWithStringOverridesDerivation() :void
    {
        $edges = new MockEdges( 'follows' ) ; // no vertex models: override still wins
        $edges->documentsResult = [] ;

        $edges->getOutboundVertices( 'users/1' , [ AQL::WITH => 'solo' ] ) ;

        $this->assertStringStartsWith
        (
            'WITH solo FOR vertex IN OUTBOUND @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testGetVerticesWithoutVertexModelsOmitsWith() :void
    {
        $edges = new MockEdges( 'follows' ) ; // from / to stay null
        $edges->documentsResult = [] ;

        $edges->getOutboundVertices( 'users/1' ) ;

        $this->assertStringStartsWith( 'FOR vertex IN OUTBOUND @startVertex' , $edges->lastQuery ) ;
        $this->assertStringNotContainsString( 'WITH' , $edges->lastQuery ) ;
    }
}
